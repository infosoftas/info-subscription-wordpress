<?php
/**
 * Sign-in for the INFO-Subscription plugin (Azure AD B2C, OpenID Connect id_token flow).
 *
 * Routes (REST API, namespace infosub/v1):
 *   GET  /login     ?return=<url>&force=1   start sign-in (force=1 asks for credentials again)
 *   POST /callback                          redirect URI registered with INFO-Subscription
 *   GET  /logout    ?return=<url>           end the local session (and the IdP session)
 *
 * The identity provider posts the id_token straight to /callback (response_type=id_token,
 * response_mode=form_post). Only the id_token is needed, so there is no token exchange and
 * no client secret. The application registration must allow ID tokens (implicit grant).
 *
 * Configuration comes from Infosub_Settings (Settings -> INFO-Subscription): tenant name,
 * policy and client ID, entered separately for each installation.
 * Constants in wp-config.php (INFOSUB_TENANT, INFOSUB_POLICY, INFOSUB_CLIENT_ID,
 * INFOSUB_REDIRECT_URI) override the saved values. The redirect URI
 * (default rest_url('infosub/v1/callback')) must match the one registered for the application
 * character for character.
 *
 * Needs firebase/php-jwt ^7.0 (see composer.json) and PHP 8.0+.
 *
 * Design notes:
 *  - Endpoints, issuer and signing keys come from the OpenID metadata document
 *    (cached 24 h), not from hard-coded values.
 *  - No refresh tokens are requested (scope is just "openid"). Fresh entitlements
 *    come from a fresh sign-in round trip: send the visitor through /login again.
 *  - Entitlements are read from the id_token claims extension_SubscriberId and
 *    extension_Products and handed to Infosub_Session.
 *  - On failures the visitor is redirected back with ?infosub_error=<code> instead of
 *    seeing a raw error. Hook 'infosub_auth_error' ($code, $detail) to log details.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Infosub_Auth
{
    const REST_NS      = 'infosub/v1';
    const STATE_COOKIE = 'infosub_oidc';
    const STATE_PREFIX = 'infosub_oidc_state_';
    const STATE_TTL    = 600; // 10 minutes to complete the sign-in

    public static function init()
    {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes()
    {
        $args = array(
            'return' => array('required' => false),
            'force'  => array('required' => false),
        );

        register_rest_route(self::REST_NS, '/login', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_login'),
            'permission_callback' => '__return_true',
            'args'                => $args,
        ));
        register_rest_route(self::REST_NS, '/callback', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'handle_callback'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NS, '/logout', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_logout'),
            'permission_callback' => '__return_true',
            'args'                => $args,
        ));
    }

    /** URL of the login route, for links and buttons. */
    public static function login_url($return = '', $force = false)
    {
        $params = array();
        if ($return !== '') {
            $params['return'] = $return;
        }
        if ($force) {
            $params['force'] = 1;
        }
        return add_query_arg(array_map('rawurlencode', $params), rest_url(self::REST_NS . '/login'));
    }

    /* ------------------------------------------------------------------
     * Route handlers (each one ends in a redirect)
     * ---------------------------------------------------------------- */

    public static function handle_login(WP_REST_Request $request)
    {
        nocache_headers();
        $return = self::safe_return($request->get_param('return'));
        $force  = (bool) $request->get_param('force');
        self::start_login($return, $force, false);
    }

    public static function handle_callback(WP_REST_Request $request)
    {
        nocache_headers();

        // 1. State: must match the cookie set when the sign-in started (binds it to this browser).
        $state  = (string) $request->get_param('state');
        $cookie = (isset($_COOKIE[self::STATE_COOKIE]) && is_string($_COOKIE[self::STATE_COOKIE]))
            ? $_COOKIE[self::STATE_COOKIE]
            : '';
        self::set_state_cookie('', time() - 3600);

        if (!preg_match('/^[a-f0-9]{32}$/', $state) || $cookie === '' || !hash_equals($cookie, $state)) {
            self::fail('state', home_url('/'));
        }
        $record = get_transient(self::STATE_PREFIX . $state);
        delete_transient(self::STATE_PREFIX . $state); // single use
        if (!is_array($record)) {
            self::fail('state', home_url('/'));
        }
        $return = $record['return'];

        // 2. Errors reported by the identity provider.
        $error = (string) $request->get_param('error');
        if ($error !== '') {
            $description = (string) $request->get_param('error_description');
            if (strpos($description, 'AADB2C90091') !== false) { // user cancelled the sign-in
                wp_safe_redirect($return);
                exit;
            }
            self::fail('provider', $return, $error . ': ' . $description);
        }

        // 3. The id_token posted by the identity provider (form_post).
        $id_token = $request->get_param('id_token');
        $id_token = is_string($id_token) ? $id_token : '';
        $cfg      = self::config();
        $meta     = $cfg ? self::metadata($cfg) : null;
        if ($id_token === '' || !$cfg || !$meta) {
            self::fail('provider', $return, 'missing id_token, configuration or metadata');
        }

        // 4. Verify the ID token (signature, issuer, audience, expiry, nonce).
        $claims = self::verify_id_token($id_token, $meta, $cfg['client_id'], $record['nonce']);
        if (is_wp_error($claims)) {
            self::fail('token_invalid', $return, $claims->get_error_message());
        }

        // 5. Forgotten-password journey: the token then lacks the subscription claims.
        //    Documented workaround: clear local session cookies and run the login again,
        //    prefilling the verified email so the subscriber only enters the new password.
        if (!empty($claims->isForgotPassword) && filter_var($claims->isForgotPassword, FILTER_VALIDATE_BOOLEAN)) {
            Infosub_Session::destroy();
            if (empty($record['forced'])) {
                self::start_login($return, true, true, self::login_hint($claims)); // ends in a redirect
            }
            self::fail('password_reset', $return, 'isForgotPassword persisted after a forced login');
        }

        // 6. Start the local session from the claims.
        $subscriber_id = isset($claims->extension_SubscriberId) ? (string) $claims->extension_SubscriberId : '';
        $products      = isset($claims->extension_Products) ? $claims->extension_Products : '[]';

        Infosub_Session::destroy(); // drop any older session first
        if (!Infosub_Session::create($subscriber_id, $products)) {
            self::fail('session', $return, 'could not create session');
        }

        wp_safe_redirect($return);
        exit;
    }

    public static function handle_logout(WP_REST_Request $request)
    {
        nocache_headers();
        $return = self::safe_return($request->get_param('return'));

        Infosub_Session::destroy();

        // Also end the identity provider session, otherwise the next sign-in may be silent.
        if (apply_filters('infosub_idp_logout', true)) {
            $cfg  = self::config();
            $meta = $cfg ? self::metadata($cfg) : null;
            if ($meta && !empty($meta['end_session_endpoint'])) {
                $url = self::append_query($meta['end_session_endpoint'], array('post_logout_redirect_uri' => $return));
                wp_redirect(esc_url_raw($url), 302);
                exit;
            }
        }
        wp_safe_redirect($return);
        exit;
    }

    /* ------------------------------------------------------------------
     * Sign-in start
     * ---------------------------------------------------------------- */

    /**
     * Redirects to the identity provider. Does not return.
     *
     * @param string $return Where to send the visitor afterwards (already validated).
     * @param bool   $force  Ask for credentials again (prompt=login).
     * @param bool   $forced True when this is the retry after a forgotten-password journey.
     * @param string $login_hint Optional sign-in name to prefill on the sign-in page.
     */
    private static function start_login($return, $force, $forced, $login_hint = '')
    {
        $cfg  = self::config();
        $meta = $cfg ? self::metadata($cfg) : null;
        if (!$cfg || !$meta) {
            self::fail('config', $return, 'missing constants or unreachable metadata');
        }

        try {
            $state = bin2hex(random_bytes(16));
            $nonce = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            self::fail('session', $return, 'no secure random source');
        }

        set_transient(self::STATE_PREFIX . $state, array(
            'nonce'  => $nonce,
            'return' => $return,
            'forced' => (bool) $forced,
        ), self::STATE_TTL);
        self::set_state_cookie($state, time() + self::STATE_TTL);

        $params = array(
            'client_id'     => $cfg['client_id'],
            'response_type' => 'id_token',
            'redirect_uri'  => $cfg['redirect_uri'],
            'response_mode' => 'form_post',
            'scope'         => 'openid', // no offline_access: we do not want refresh tokens
            'state'         => $state,
            'nonce'         => $nonce,
        );
        if ($force) {
            $params['prompt'] = 'login';
        }
        if ($login_hint !== '') {
            $params['login_hint'] = $login_hint;
        }

        wp_redirect(esc_url_raw(self::append_query($meta['authorization_endpoint'], $params)), 302);
        exit;
    }

    /* ------------------------------------------------------------------
     * Token verification
     * ---------------------------------------------------------------- */

    /**
     * @return object|WP_Error The verified claims, or an error.
     */
    public static function verify_id_token($jwt, array $meta, $client_id, $expected_nonce)
    {
        if (!class_exists('\Infosub\Vendor\Firebase\JWT\JWT') || !class_exists('\Infosub\Vendor\Firebase\JWT\JWK')) {
            return new WP_Error('infosub_lib', 'firebase/php-jwt is not installed (run composer install in the plugin).');
        }
        if (substr_count($jwt, '.') !== 2) {
            return new WP_Error('infosub_jwt', 'Malformed token.');
        }

        $claims = null;
        $last   = null;
        // Second attempt refetches the signing keys, in case the IdP rotated them.
        for ($attempt = 0; $attempt < 2 && $claims === null; $attempt++) {
            $jwks = self::jwks($meta['jwks_uri'], $attempt === 1);
            if (!$jwks) {
                return new WP_Error('infosub_jwks', 'Could not load the signing keys.');
            }
            try {
                \Infosub\Vendor\Firebase\JWT\JWT::$leeway = 60;
                $keys   = \Infosub\Vendor\Firebase\JWT\JWK::parseKeySet($jwks, 'RS256'); // B2C keys carry no "alg"
                $claims = \Infosub\Vendor\Firebase\JWT\JWT::decode($jwt, $keys);         // checks signature, exp, nbf
            } catch (\Throwable $e) {
                $last = $e;
            }
        }
        if ($claims === null) {
            return new WP_Error('infosub_jwt', $last ? $last->getMessage() : 'Token could not be verified.');
        }

        // Issuer comes from the metadata document, as the documentation recommends.
        if (!isset($claims->iss) || $claims->iss !== $meta['issuer']) {
            return new WP_Error('infosub_iss', 'Unexpected issuer.');
        }
        $aud = isset($claims->aud) ? (array) $claims->aud : array();
        if (!in_array($client_id, $aud, true)) {
            return new WP_Error('infosub_aud', 'Token was not issued for this application.');
        }
        if (!isset($claims->nonce) || !hash_equals((string) $expected_nonce, (string) $claims->nonce)) {
            return new WP_Error('infosub_nonce', 'Nonce mismatch.');
        }
        return $claims;
    }

    private static function jwks($uri, $refresh = false)
    {
        $key = 'infosub_jwks_' . md5($uri);
        if (!$refresh) {
            $cached = get_transient($key);
            if (is_array($cached) && !empty($cached['keys'])) {
                return $cached;
            }
        }
        $response = wp_remote_get($uri, array('timeout' => 10));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $jwks = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($jwks) || empty($jwks['keys'])) {
            return null;
        }
        set_transient($key, $jwks, DAY_IN_SECONDS);
        return $jwks;
    }

    /* ------------------------------------------------------------------
     * Configuration and metadata
     * ---------------------------------------------------------------- */

    private static function config()
    {
        $conn      = Infosub_Settings::connection();
        $tenant    = $conn['tenant'];
        $client_id = $conn['client_id'];
        $policy    = $conn['policy'];

        if (!preg_match('/^[A-Za-z0-9-]+$/', $tenant) || !preg_match('/^[A-Za-z0-9_]+$/', $policy)) {
            return null;
        }
        if ($client_id === '') {
            return null;
        }
        return array(
            'tenant'        => $tenant,
            'policy'        => $policy,
            'client_id'     => $client_id,
            'redirect_uri'  => $conn['redirect_uri'],
        );
    }

    /**
     * Used by the settings page: can the tenant and policy be resolved right now?
     * Bypasses the cache so a freshly corrected tenant name is checked for real.
     *
     * @return string 'ok' | 'unreachable' | 'incomplete'
     */
    public static function test_connection()
    {
        $cfg = self::config();
        if (!$cfg) {
            return 'incomplete';
        }
        delete_transient('infosub_oidc_meta_' . md5(self::metadata_url($cfg)));
        return self::metadata($cfg) !== null ? 'ok' : 'unreachable';
    }

    /**
     * Standard Azure AD B2C location of the OpenID Connect metadata document.
     */
    private static function metadata_url(array $cfg)
    {
        return sprintf(
            'https://%1$s.b2clogin.com/%1$s.onmicrosoft.com/%2$s/v2.0/.well-known/openid-configuration',
            $cfg['tenant'],
            $cfg['policy']
        );
    }

    /**
     * The OpenID Connect metadata document for the tenant and policy (cached 24 h).
     */
    private static function metadata(array $cfg)
    {
        return self::discover(self::metadata_url($cfg));
    }

    /**
     * Fetches and caches (24 h) any OpenID Connect metadata document. Also used by
     * Infosub_Api for the separate admin/API identity provider.
     *
     * @return array|null
     */
    public static function discover($url)
    {
        $key    = 'infosub_oidc_meta_' . md5($url);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get($url, array('timeout' => 10));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $meta = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($meta)) {
            return null;
        }
        foreach (array('issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri') as $required) {
            if (empty($meta[$required]) || !is_string($meta[$required]) || strpos($meta[$required], 'https://') !== 0) {
                return null;
            }
        }
        set_transient($key, $meta, DAY_IN_SECONDS);
        return $meta;
    }

    /* ------------------------------------------------------------------
     * Small helpers
     * ---------------------------------------------------------------- */

    /** Only same-site return URLs are accepted (prevents open redirects). */
    private static function safe_return($url)
    {
        $url = is_string($url) ? $url : '';
        return wp_validate_redirect(esc_url_raw($url), home_url('/'));
    }

    /** First valid email from the verified id_token, or ''. */
    private static function login_hint($claims)
    {
        $emails = isset($claims->emails) ? (array) $claims->emails : array();
        $email  = isset($emails[0]) && is_string($emails[0]) ? $emails[0] : '';
        return is_email($email) ? $email : '';
    }

    /** B2C endpoints already contain "?p=policy", so append with the right separator. */
    public static function append_query($url, array $params)
    {
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private static function set_state_cookie($value, $expires)
    {
        if (headers_sent()) {
            return false;
        }
        return setcookie(self::STATE_COOKIE, $value, array(
            'expires'  => $expires,
            'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            // The identity provider returns with a cross-site POST, which drops Lax cookies.
            // Browsers only accept SameSite=None on secure cookies.
            'samesite' => is_ssl() ? 'None' : 'Lax',
        ));
    }

    /**
     * Send the visitor back with a short error code. Does not return.
     */
    private static function fail($code, $return, $detail = '')
    {
        do_action('infosub_auth_error', $code, $detail);
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[infosub] ' . $code . ($detail !== '' ? ': ' . $detail : ''));
        }
        $target = add_query_arg('infosub_error', rawurlencode($code), $return ? $return : home_url('/'));
        wp_safe_redirect($target);
        exit;
    }
}
