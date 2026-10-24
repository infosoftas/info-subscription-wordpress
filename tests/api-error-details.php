<?php
/**
 * Run with wp eval-file while the plugin is active. Uses synthetic HTTP responses
 * and option overrides; never reads or changes saved API credentials.
 */
if (PHP_SAPI !== 'cli' || !defined('ABSPATH') || !class_exists('Infosub_Api')) {
    exit(1);
}
final class Infosub_Test_Api_Redirect extends Exception
{
}

$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID'));
if (!$admins) {
    exit("An administrator is required for this test.\n");
}
$admin = (int) $admins[0];
$fixture = array(
    'api_tenant_id'     => '00000000-0000-0000-0000-000000000001',
    'api_client_id'     => 'test-api-client',
);
foreach ($fixture as $key => $value) {
    add_filter('pre_option_infosub_' . $key, function () use ($value) {
        return $value;
    });
}

$metadata_url = 'https://example.test/metadata';
$redirect_uri = 'https://example.test/wp-json/infosub/v1/api-callback';
add_filter('infosub_api_metadata_url', function () use ($metadata_url) {
    return $metadata_url;
});
add_filter('infosub_api_redirect_uri', function () use ($redirect_uri) {
    return $redirect_uri;
});
$meta = array(
    'issuer' => 'https://example.test/',
    'authorization_endpoint' => 'https://example.test/authorize',
    'token_endpoint' => 'https://example.test/token',
    'jwks_uri' => 'https://example.test/keys',
);
set_transient('infosub_oidc_meta_' . md5($metadata_url), $meta, 300);
$token_response = null;
$product_status = 200;
$requests = array();
add_filter('pre_http_request', function ($pre, $args, $url) use (&$token_response, &$product_status, &$requests) {
    $requests[] = array('url' => $url, 'args' => $args);
    if ($url === 'https://example.test/token') {
        return $token_response;
    }
    if ($url === 'https://api.info-subscription.com/product') {
        return array('response' => array('code' => $product_status), 'body' => wp_json_encode(array(
            array('id' => 'test-product', 'name' => 'Test product', 'partNo' => 'TEST', 'externalPartNo' => ''),
        )));
    }
    return new WP_Error('unexpected_http', 'Unexpected test HTTP request');
}, 10, 3);
remove_all_filters('wp_redirect');
add_filter('wp_redirect', function ($url) {
    throw new Infosub_Test_Api_Redirect($url);
});
$failed = false;
$results = array();
$check = function ($ok, $name) use (&$failed, &$results) {
    $failed = $failed || !$ok;
    $results[] = ($ok ? 'PASS: ' : 'FAIL: ') . $name;
};
$notice = new ReflectionMethod(Infosub_Settings::class, 'render_api_notice');
$render = function () use ($notice) {
    ob_start();
    $notice->invoke(null);
    return ob_get_clean();
};
$start = function () use ($admin) {
    wp_set_current_user($admin);
    $_REQUEST['_wpnonce'] = wp_create_nonce('infosub_api_connect');
    try {
        Infosub_Api::handle_connect();
    } catch (Infosub_Test_Api_Redirect $redirect) {
        return $redirect->getMessage();
    }
    throw new RuntimeException('Expected an authorization redirect.');
};
$callback = function ($state, $code = 'test-authorization-code', $logged_in = true) use ($admin) {
    $_COOKIE[Infosub_Api::STATE_COOKIE] = $state;
    // Like a real browser redirect: login cookie present, but REST has no nonce, so no current user.
    $_COOKIE[LOGGED_IN_COOKIE] = $logged_in ? wp_generate_auth_cookie($admin, time() + 3600, 'logged_in') : '';
    $request = new WP_REST_Request('GET', '/infosub/v1/api-callback');
    $request->set_query_params(array('state' => $state, 'code' => $code));
    wp_set_current_user(0);
    try {
        Infosub_Api::handle_callback($request);
    } catch (Infosub_Test_Api_Redirect $redirect) {
        parse_str((string) parse_url($redirect->getMessage(), PHP_URL_QUERY), $_GET);
        return $redirect->getMessage();
    }
    throw new RuntimeException('Expected a callback redirect.');
};

try {
    $url = $start();
    $query = array();
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $check(parse_url($url, PHP_URL_HOST) === 'example.test'
        && ($query['response_type'] ?? '') === 'code'
        && ($query['redirect_uri'] ?? '') === $redirect_uri,
        'product loading starts interactive authorization-code sign-in with API callback');
    $expected_scope = 'openid profile https://prodlogins4.onmicrosoft.com/api/00000000-0000-0000-0000-000000000001.ReadWriteAll';
    $check(($query['scope'] ?? '') === $expected_scope,
        'authorization requests OIDC scopes plus tenant-derived ReadWriteAll permission, without offline_access');
    $state = $query['state'] ?? '';
    $record = get_transient(Infosub_Api::STATE_PREFIX . $state);
    $check(preg_match('/^[a-f0-9]{32}$/', $state) === 1 && is_array($record),
        'authorization state is random and stored server-side');
    $verifier = is_array($record) ? (string) ($record['verifier'] ?? '') : '';
    $s256 = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $check(preg_match('/^[A-Za-z0-9_-]{43,128}$/', $verifier) === 1
        && ($query['code_challenge_method'] ?? '') === 'S256'
        && ($query['code_challenge'] ?? '') === $s256
        && strpos($url, $verifier) === false,
        'authorization uses a PKCE S256 challenge and keeps the verifier server-side');

    $token_response = array('response' => array('code' => 200), 'body' => wp_json_encode(array(
        'access_token' => 'synthetic-access-token',
        'refresh_token' => 'synthetic-refresh-token',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
    )));
    $previous = get_option(Infosub_Api::OPTION, null);
    try {
        $callback($state);
        $check($_GET['infosub_api'] === 'ok' && Infosub_Api::products() === array(
            array('id' => 'test-product', 'name' => 'Test product', 'part_no' => 'TEST', 'external_part_no' => ''),
        ), 'access token alone loads and caches products without requiring an ID token');
        $check(get_transient(Infosub_Api::STATE_PREFIX . $state) === false,
            'authorization state is consumed after the callback');
        $check(count($requests) === 2
            && $requests[0]['url'] === 'https://example.test/token'
            && $requests[0]['args']['body'] === array(
                'grant_type' => 'authorization_code',
                'client_id' => 'test-api-client',
                'code' => 'test-authorization-code',
                'redirect_uri' => $redirect_uri,
                'scope' => $expected_scope,
                'code_verifier' => $verifier,
            ), 'callback exchanges code with the PKCE verifier and no client secret');
        $check($requests[1]['args']['headers']['Authorization'] === 'Bearer synthetic-access-token'
            && $requests[1]['args']['headers']['S4-TenantId'] === '00000000-0000-0000-0000-000000000001',
            'product request carries the access token and tenant header');
        $check(strpos(wp_json_encode(get_option(Infosub_Api::OPTION)), 'synthetic-access-token') === false
            && strpos(wp_json_encode(get_option(Infosub_Api::OPTION)), 'synthetic-refresh-token') === false,
            'neither API access nor refresh token is stored');
    } finally {
        if ($previous === null) {
            delete_option(Infosub_Api::OPTION);
        } else {
            update_option(Infosub_Api::OPTION, $previous, false);
        }
    }

    $failed_start = $start();
    parse_str((string) parse_url($failed_start, PHP_URL_QUERY), $failed_query);
    $failed_state = $failed_query['state'];
    $failed_verifier = get_transient(Infosub_Api::STATE_PREFIX . $failed_state)['verifier'];
    $token_response = array('response' => array('code' => 400), 'body' => wp_json_encode(array(
        'error' => 'invalid_grant',
        'error_description' => 'Bad verifier ' . $failed_verifier . ' for test-authorization-code',
        'correlation_id' => 'test-correlation',
    )));
    $callback($failed_state);
    wp_set_current_user($admin);
    $html = $render();
    $check(strpos($html, 'HTTP 400') !== false && strpos($html, 'invalid_grant') !== false
        && strpos($html, 'test-correlation') !== false
        && strpos($html, $failed_verifier) === false
        && strpos($html, 'test-authorization-code') === false,
        'admin sees redacted token exchange diagnostics');
    $check(strpos($render(), 'invalid_grant') === false,
        'API diagnostics are consumed after display');

    $bad_state = $start();
    parse_str((string) parse_url($bad_state, PHP_URL_QUERY), $bad_query);
    $callback(str_repeat('0', 32));
    $check($_GET['infosub_api'] === 'state' && get_transient(Infosub_Api::STATE_PREFIX . $bad_query['state']) !== false,
        'mismatched state cannot redeem the authorization code');

    $requests = array();
    $anon_start = $start();
    parse_str((string) parse_url($anon_start, PHP_URL_QUERY), $anon_query);
    $callback($anon_query['state'], 'test-authorization-code', false);
    $check($_GET['infosub_api'] === 'state' && !$requests,
        'callback without the initiating administrator login cannot redeem the code');

    wp_set_current_user($admin);
    ob_start();
    Infosub_Settings::render_page();
    $page = ob_get_clean();
    $check(strpos($page, 'Sign in and load products') !== false
        && strpos($page, esc_html($redirect_uri)) !== false
        && strpos($page, esc_html($expected_scope)) !== false
        && strpos($page, 'infosub_api_client_secret') === false,
        'settings page displays interactive sign-in, callback and derived scope, without a secret field');
} finally {
    delete_transient('infosub_oidc_meta_' . md5($metadata_url));
}
echo implode("\n", $results), "\n";
exit($failed ? 1 : 0);
