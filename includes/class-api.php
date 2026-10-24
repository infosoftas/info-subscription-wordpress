<?php
/**
 * Product list from the INFO-Subscription API.
 *
 * Product discovery is an interactive administrator authorization-code flow.
 * Its application and identity provider are separate from subscriber sign-in.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Infosub_Api
{
    const DETAIL_TTL   = 600;
    const OPTION       = 'infosub_api_products';
    const MAX_PRODUCTS = 2000;
    const STATE_COOKIE = 'infosub_api_oidc';
    const STATE_PREFIX = 'infosub_api_state_';
    const STATE_TTL    = 600;

    private static $diagnostic_user = 0;

    public static function init()
    {
        add_action('admin_post_infosub_api_connect', array(__CLASS__, 'handle_connect'));
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes()
    {
        register_rest_route('infosub/v1', '/api-callback', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'handle_callback'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * @return array List of ['id' =>, 'name' =>, 'part_no' =>, 'external_part_no' =>]
     */
    public static function products()
    {
        $data = get_option(self::OPTION, array());
        return (is_array($data) && !empty($data['items']) && is_array($data['items'])) ? $data['items'] : array();
    }

    /** @return int Unix time of the last successful load, or 0. */
    public static function fetched_at()
    {
        $data = get_option(self::OPTION, array());
        return (is_array($data) && !empty($data['fetched'])) ? (int) $data['fetched'] : 0;
    }

    /** Start the API application's interactive sign-in. */
    public static function handle_connect()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'infosub'), '', array('response' => 403));
        }
        check_admin_referer('infosub_api_connect');
        self::$diagnostic_user = get_current_user_id();

        $cfg = Infosub_Settings::api_connection();
        if ($cfg['tenant_id'] === '' || $cfg['client_id'] === '' || $cfg['scope'] === '') {
            self::done('incomplete');
        }
        $meta = Infosub_Auth::discover($cfg['metadata_url']);
        if (!$meta) {
            self::done('unreachable');
        }

        try {
            $state = bin2hex(random_bytes(16));
            // PKCE (RFC 7636) replaces the client secret: the app is a public client.
            $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } catch (Exception $e) {
            self::done('state', array(), 'Could not generate secure authorization state.');
        }
        $scope = self::scope($cfg);
        $record = array(
            'user'         => self::$diagnostic_user,
            'client_id'    => $cfg['client_id'],
            'redirect_uri' => $cfg['redirect_uri'],
            'scope'        => $scope,
            'verifier'     => $verifier,
        );
        if (!set_transient(self::STATE_PREFIX . $state, $record, self::STATE_TTL)) {
            self::done('state', array(), 'Could not store authorization state.');
        }
        self::set_state_cookie($state, time() + self::STATE_TTL);

        $params = array(
            'client_id'             => $cfg['client_id'],
            'response_type'         => 'code',
            'redirect_uri'          => $cfg['redirect_uri'],
            'response_mode'         => 'query',
            'scope'                 => $scope,
            'state'                 => $state,
            'prompt'                => 'select_account',
            'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        );
        wp_redirect(esc_url_raw(self::append_query($meta['authorization_endpoint'], $params)), 302);
        exit;
    }

    /** Complete the API authorization-code flow and load products. */
    public static function handle_callback(WP_REST_Request $request)
    {
        nocache_headers();
        $state = (string) $request->get_param('state');
        $cookie = (isset($_COOKIE[self::STATE_COOKIE]) && is_string($_COOKIE[self::STATE_COOKIE]))
            ? $_COOKIE[self::STATE_COOKIE]
            : '';
        self::set_state_cookie('', time() - HOUR_IN_SECONDS);

        if (!preg_match('/^[a-f0-9]{32}$/', $state) || $cookie === '' || !hash_equals($cookie, $state)) {
            self::done('state');
        }
        $record = get_transient(self::STATE_PREFIX . $state);
        delete_transient(self::STATE_PREFIX . $state);
        if (!is_array($record) || empty($record['user']) || empty($record['verifier'])) {
            self::done('state');
        }
        self::$diagnostic_user = (int) $record['user'];
        // REST ignores the login cookie without a REST nonce, so validate the cookie directly.
        $user = (int) wp_validate_auth_cookie('', 'logged_in');
        if ($user !== self::$diagnostic_user || !user_can($user, 'manage_options')) {
            self::done('state');
        }
        wp_set_current_user($user);

        $error = (string) $request->get_param('error');
        if ($error !== '') {
            $description = (string) $request->get_param('error_description');
            self::done('provider', array(), $error . ($description !== '' ? ': ' . $description : ''));
        }

        $code = $request->get_param('code');
        $code = is_string($code) ? $code : '';
        if ($code === '') {
            self::done('provider', array(), 'Authorization response did not contain a code.');
        }

        $cfg = Infosub_Settings::api_connection();
        if ($cfg['tenant_id'] === '' || $cfg['client_id'] !== $record['client_id']
            || $cfg['redirect_uri'] !== $record['redirect_uri'] || self::scope($cfg) !== $record['scope']) {
            self::done('incomplete', array(), 'API settings changed or are incomplete during sign-in.');
        }
        $meta = Infosub_Auth::discover($cfg['metadata_url']);
        if (!$meta) {
            self::done('unreachable');
        }

        $response = wp_remote_post($meta['token_endpoint'], array(
            'timeout' => 15,
            'body'    => array(
                'grant_type'    => 'authorization_code',
                'client_id'     => $cfg['client_id'],
                'code'          => $code,
                'redirect_uri'  => $cfg['redirect_uri'],
                'scope'         => $record['scope'],
                'code_verifier' => $record['verifier'],
            ),
        ));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            $detail = self::token_error_detail($response, array($record['verifier'], $code));
            self::done('token_exchange', array(), $detail);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        unset($response);
        $access_token = (is_array($body) && !empty($body['access_token']) && is_string($body['access_token']))
            ? $body['access_token']
            : '';
        if ($access_token === '') {
            $fields = is_array($body) ? implode(', ', array_map('strval', array_keys($body))) : 'none (body is not JSON)';
            self::done('token_exchange', array(), sprintf(
                'HTTP 200 but access_token missing. Returned fields: %s. Requested scope: %s',
                $fields === '' ? 'none' : $fields,
                $record['scope']
            ));
        }

        $items = self::fetch_products($access_token, $cfg);
        unset($access_token, $body);
        if (is_string($items)) {
            self::done($items);
        }
        update_option(self::OPTION, array('fetched' => time(), 'items' => $items), false);
        self::done($items ? 'ok' : 'empty', array('count' => count($items)));
    }

    private static function set_state_cookie($value, $expires)
    {
        $options = array(
            'expires'  => $expires,
            'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        );
        setcookie(self::STATE_COOKIE, $value, $options);
        if ($value === '') {
            unset($_COOKIE[self::STATE_COOKIE]);
        } else {
            $_COOKIE[self::STATE_COOKIE] = $value;
        }
    }

    private static function append_query($url, array $params)
    {
        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * GET {base}/product with the bearer token and the S4-TenantId header.
     *
     * @return array|string The normalized product list, or an error code.
     */
    private static function fetch_products($access_token, array $cfg)
    {
        $response = wp_remote_get(rtrim($cfg['base_url'], '/') . '/product', array(
            'timeout' => 20,
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'S4-TenantId'   => $cfg['tenant_id'],
                'Accept'        => 'application/json',
            ),
        ));
        if (is_wp_error($response)) {
            do_action('infosub_api_error', 'api_error', $response->get_error_message());
            return 'api_error';
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 401) {
            return 'unauthorized';
        }
        if ($status === 403) {
            return 'forbidden';
        }
        if ($status !== 200) {
            do_action('infosub_api_error', 'api_error', 'GET /product returned HTTP ' . $status);
            return 'api_error';
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            do_action('infosub_api_error', 'api_error', 'GET /product returned unexpected content');
            return 'api_error';
        }
        $items = array();
        foreach ($data as $product) {
            if (!is_array($product) || empty($product['id']) || !is_string($product['id'])) {
                continue;
            }
            $items[] = array(
                'id'               => sanitize_text_field($product['id']),
                'name'             => isset($product['name']) ? sanitize_text_field((string) $product['name']) : '',
                'part_no'          => isset($product['partNo']) ? sanitize_text_field((string) $product['partNo']) : '',
                'external_part_no' => isset($product['externalPartNo']) ? sanitize_text_field((string) $product['externalPartNo']) : '',
            );
            if (count($items) >= self::MAX_PRODUCTS) {
                break;
            }
        }
        usort($items, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        return $items;
    }

    private static function token_error_detail($response, array $sensitive)
    {
        if (is_wp_error($response)) {
            return self::redact_detail($response->get_error_code() . ': ' . $response->get_error_message(), $sensitive);
        }
        $detail = 'HTTP ' . (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (is_array($body)) {
            foreach (array('access_token', 'id_token', 'refresh_token', 'code_verifier', 'code') as $key) {
                if (isset($body[$key]) && is_string($body[$key])) {
                    $sensitive[] = $body[$key];
                }
            }
            foreach (array('error', 'error_description', 'correlation_id', 'trace_id', 'timestamp') as $key) {
                if (isset($body[$key]) && is_string($body[$key])) {
                    $detail .= "\n" . $key . ': ' . $body[$key];
                }
            }
        }
        return self::redact_detail($detail, $sensitive);
    }

    private static function redact_detail($detail, array $sensitive)
    {
        foreach ($sensitive as $value) {
            if (is_string($value) && $value !== '') {
                $detail = str_replace(array($value, rawurlencode($value), urlencode($value)), '[redacted]', $detail);
            }
        }
        $detail = preg_replace('/\bBearer\s+\S+/i', '******', $detail);
        $detail = preg_replace('/\beyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/', '[redacted token]', $detail);
        $detail = preg_replace('/\b(client_secret|code_verifier|access_token|id_token|refresh_token|code)\s*[=:]\s*("[^"]*"|\'[^\']*\'|[^\s&,;]+)/i', '$1=[redacted]', $detail);
        return substr(sanitize_textarea_field($detail), 0, 4000);
    }

    /** Read once, only by the administrator who initiated the request. */
    public static function take_error_detail($id, $code)
    {
        if (!current_user_can('manage_options') || !is_string($id) || !preg_match('/^[A-Za-z0-9]{32}$/', $id)) {
            return '';
        }
        $key = 'infosub_api_error_' . $id;
        $saved = get_transient($key);
        if (!is_array($saved) || $saved['user'] !== get_current_user_id() || $saved['code'] !== $code) {
            return '';
        }
        delete_transient($key);
        return $saved['detail'];
    }

    /** The API permission is tenant-specific; no offline_access because tokens are never kept. */
    private static function scope(array $cfg)
    {
        return 'openid profile ' . $cfg['scope'];
    }

    private static function done($code, array $extra = array(), $detail = '')
    {
        $args = array_merge(array('page' => Infosub_Settings::PAGE, 'infosub_api' => $code), $extra);
        if ($detail !== '') {
            $detail = self::redact_detail($detail, array());
            if (self::$diagnostic_user && user_can(self::$diagnostic_user, 'manage_options')) {
                $id = wp_generate_password(32, false, false);
                if (set_transient('infosub_api_error_' . $id, array(
                    'user' => self::$diagnostic_user,
                    'code' => $code,
                    'detail' => $detail,
                ), self::DETAIL_TTL)) {
                    $args['infosub_api_detail'] = $id;
                }
            }
            do_action('infosub_api_error', $code, $detail);
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log('[infosub api] ' . $code . ': ' . $detail);
            }
        }
        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }
}
