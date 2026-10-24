<?php
/**
 * Subscriber sessions for the INFO-Subscription plugin.
 *
 * The browser only holds an opaque, random session ID in a cookie. The claims
 * (subscriber id + products with validity) live server-side in a transient,
 * keyed by a SHA-256 hash of that ID, so a leaked database copy does not
 * contain usable cookie values.
 *
 * Requires PHP 7.3+ (setcookie() with an options array).
 *
 * Note: transients can be evicted (e.g. by an object cache). That is fine here:
 * the visitor simply has to sign in again.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Infosub_Session
{
    const COOKIE      = 'infosub_sid';
    const PREFIX      = 'infosub_sess_';
    const DEFAULT_TTL = 43200; // 12 hours. Override with the 'infosub_session_ttl' filter.

    /** @var bool Whether the session was already loaded during this request. */
    private static $loaded = false;

    /** @var array|null Cached session data for this request. */
    private static $cache = null;

    /* ------------------------------------------------------------------
     * Create / destroy
     * ---------------------------------------------------------------- */

    /**
     * Create a session after a successful sign-in and send the cookie.
     * Must be called before any output is sent (headers).
     *
     * @param string       $subscriber_id The extension_SubscriberId claim (may be empty for shared users).
     * @param array|string $products      The extension_Products claim (JSON string or decoded array).
     * @param int          $cap_seconds   Optional upper bound on the session lifetime, e.g. the
     *                                    seconds left on the token. 0 = no extra cap.
     * @return string|false The new session ID, or false on failure.
     */
    public static function create($subscriber_id, $products, $cap_seconds = 0)
    {
        $ttl = (int) apply_filters('infosub_session_ttl', self::DEFAULT_TTL);
        if ($cap_seconds > 0) {
            $ttl = min($ttl, (int) $cap_seconds);
        }
        if ($ttl < 60) {
            return false;
        }

        try {
            $sid = bin2hex(random_bytes(32)); // 64 hex chars
        } catch (Exception $e) {
            return false;
        }

        $now  = time();
        $data = array(
            'subscriber_id' => sanitize_text_field((string) $subscriber_id),
            'products'      => self::normalize_products($products),
            'created'       => $now,
            'expires'       => $now + $ttl,
        );

        if (!set_transient(self::key($sid), $data, $ttl)) {
            return false;
        }
        if (!self::send_cookie($sid, $data['expires'])) {
            delete_transient(self::key($sid));
            return false;
        }

        self::$loaded = true;
        self::$cache  = $data;
        return $sid;
    }

    /**
     * Log out: remove server-side data and expire the cookie.
     */
    public static function destroy()
    {
        $sid = self::cookie_sid();
        if ($sid) {
            delete_transient(self::key($sid));
        }
        self::clear_cookie();
        self::$loaded = true;
        self::$cache  = null;
    }

    /* ------------------------------------------------------------------
     * Read
     * ---------------------------------------------------------------- */

    /**
     * @return array|null Session data, or null when there is no valid session.
     */
    public static function get()
    {
        if (self::$loaded) {
            return self::$cache;
        }
        self::$loaded = true;
        self::$cache  = null;

        $sid = self::cookie_sid();
        if (!$sid) {
            return null;
        }

        $data = get_transient(self::key($sid));
        if (is_array($data) && !empty($data['expires']) && $data['expires'] > time()) {
            self::$cache = $data;
        }
        return self::$cache;
    }

    public static function is_active()
    {
        return self::get() !== null;
    }

    /**
     * Does the session include this product, currently valid?
     * Product IDs are GUIDs, so the comparison is case-insensitive.
     */
    public static function has_product($product_id)
    {
        $session = self::get();
        if (!$session || $product_id === '' || $product_id === null) {
            return false;
        }
        foreach ($session['products'] as $product) {
            if (strcasecmp($product['Id'], (string) $product_id) === 0 && self::product_is_valid($product)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does the session include any currently valid product?
     */
    public static function has_any_product()
    {
        $session = self::get();
        if (!$session) {
            return false;
        }
        foreach ($session['products'] as $product) {
            if (self::product_is_valid($product)) {
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------------
     * Update (for later revalidation, e.g. after a webhook or API call)
     * ---------------------------------------------------------------- */

    /**
     * Replace the product list of the current session, keeping its expiry.
     */
    public static function replace_products($products)
    {
        $session = self::get();
        $sid     = self::cookie_sid();
        if (!$session || !$sid) {
            return false;
        }
        $remaining = $session['expires'] - time();
        if ($remaining < 1) {
            return false;
        }
        $session['products'] = self::normalize_products($products);
        self::$cache         = $session;
        return set_transient(self::key($sid), $session, $remaining);
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------- */

    /**
     * Accepts the raw claim (a JSON string) or an already decoded array and
     * returns a clean list of products: [ ['Id'=>, 'ValidFrom'=>, 'ValidTo'=>], ... ].
     */
    public static function normalize_products($raw)
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return array();
        }

        $clean = array();
        foreach ($raw as $item) {
            if (!is_array($item) || empty($item['Id'])) {
                continue;
            }
            $clean[] = array(
                'Id'        => sanitize_text_field((string) $item['Id']),
                'ValidFrom' => isset($item['ValidFrom']) ? sanitize_text_field((string) $item['ValidFrom']) : '',
                'ValidTo'   => isset($item['ValidTo']) ? sanitize_text_field((string) $item['ValidTo']) : '',
            );
        }
        return $clean;
    }

    /**
     * A product is valid when it has started and has not ended.
     * A missing ValidTo is treated as invalid (fail closed). If your tenant
     * issues open-ended products, change that here deliberately.
     */
    private static function product_is_valid(array $product)
    {
        $now  = time();
        $to   = $product['ValidTo'] !== '' ? strtotime($product['ValidTo']) : false;
        $from = $product['ValidFrom'] !== '' ? strtotime($product['ValidFrom']) : false;

        if (!$to || $to <= $now) {
            return false;
        }
        if ($from && $from > $now) {
            return false;
        }
        return true;
    }

    private static function key($sid)
    {
        return self::PREFIX . hash('sha256', $sid);
    }

    /**
     * The session ID from the cookie, only if it has exactly the expected shape.
     */
    private static function cookie_sid()
    {
        if (empty($_COOKIE[self::COOKIE]) || !is_string($_COOKIE[self::COOKIE])) {
            return null;
        }
        $sid = $_COOKIE[self::COOKIE];
        return preg_match('/^[a-f0-9]{64}$/', $sid) ? $sid : null;
    }

    private static function cookie_options($expires)
    {
        return array(
            'expires'  => $expires,
            'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(), // behind a proxy, make sure WordPress sees HTTPS correctly
            'httponly' => true,
            'samesite' => 'Lax',    // Lax so the cookie survives the redirect back from the sign-in page
        );
    }

    private static function send_cookie($sid, $expires)
    {
        if (headers_sent()) {
            return false;
        }
        $ok = setcookie(self::COOKIE, $sid, self::cookie_options($expires));
        if ($ok) {
            $_COOKIE[self::COOKIE] = $sid; // visible to the rest of this request
        }
        return $ok;
    }

    private static function clear_cookie()
    {
        if (!headers_sent()) {
            setcookie(self::COOKIE, '', self::cookie_options(time() - YEAR_IN_SECONDS));
        }
        unset($_COOKIE[self::COOKIE]);
    }
}
