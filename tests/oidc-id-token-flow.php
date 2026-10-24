<?php
/**
 * Integration test for the subscriber sign-in (id_token + form_post, no client secret).
 * Needs a WordPress install with the plugin active:
 *
 *     wp eval-file wp-content/plugins/infosub/tests/oidc-id-token-flow.php
 */

if (!defined('ABSPATH') || !class_exists('Infosub_Auth')) {
    exit("Run with: wp eval-file tests/oidc-id-token-flow.php (plugin active)\n");
}

use Infosub\Vendor\Firebase\JWT\JWT;

final class Infosub_Test_Redirect extends Exception
{
}

$results = array();
$failed  = false;
$check   = function ($ok, $name) use (&$results, &$failed) {
    $results[] = ($ok ? 'PASS: ' : 'FAIL: ') . $name;
    $failed    = $failed || !$ok;
};

$errors = array();
add_action('infosub_auth_error', function ($code, $detail) use (&$errors) {
    $errors[] = $code . ($detail !== '' ? ': ' . $detail : '');
}, 10, 2);
remove_all_filters('wp_redirect'); // WP-CLI's own handler only prints warnings
add_filter('wp_redirect', function ($location) {
    throw new Infosub_Test_Redirect($location);
}, PHP_INT_MAX);

$follow = function (callable $fn) {
    try {
        $fn();
    } catch (Infosub_Test_Redirect $redirect) {
        return $redirect->getMessage();
    }
    return null;
};

$saved = array();
foreach (array('infosub_tenant', 'infosub_policy', 'infosub_client_id', 'infosub_client_secret') as $name) {
    $saved[$name] = get_option($name, null);
}

$tenant    = 'infosubtest';
$policy    = 'B2C_1_SignIn';
$client_id = 'test-client-id';
update_option('infosub_tenant', $tenant);
update_option('infosub_policy', $policy);
update_option('infosub_client_id', $client_id);
delete_option('infosub_client_secret');

$metadata_url = sprintf(
    'https://%1$s.b2clogin.com/%1$s.onmicrosoft.com/%2$s/v2.0/.well-known/openid-configuration',
    $tenant,
    $policy
);
$meta = array(
    'issuer'                 => 'https://issuer.test/v2.0/',
    'authorization_endpoint' => 'https://login.test/authorize?p=' . $policy,
    'token_endpoint'         => 'https://login.test/token?p=' . $policy,
    'jwks_uri'               => 'https://login.test/keys',
);
set_transient('infosub_oidc_meta_' . md5($metadata_url), $meta, 300);

$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
openssl_pkey_export($key, $private_pem);
$rsa = openssl_pkey_get_details($key)['rsa'];
$b64 = function ($value) {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
};
set_transient('infosub_jwks_' . md5($meta['jwks_uri']), array('keys' => array(array(
    'kty' => 'RSA',
    'kid' => 'k1',
    'use' => 'sig',
    'n'   => $b64($rsa['n']),
    'e'   => $b64($rsa['e']),
))), 300);

$return = home_url('/protected-article/');

try {
    // 1. Sign-in starts without a client secret and asks for an id_token by form post.
    $login = new WP_REST_Request('GET', '/infosub/v1/login');
    $login->set_param('return', $return);
    $location = $follow(function () use ($login) {
        Infosub_Auth::handle_login($login);
    });
    $query = array();
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
    $check(strpos((string) $location, $meta['authorization_endpoint']) === 0, 'login redirects to the authorization endpoint without a client secret');
    $check(isset($query['response_type']) && $query['response_type'] === 'id_token', 'login requests response_type=id_token');
    $check(isset($query['response_mode']) && $query['response_mode'] === 'form_post', 'login requests response_mode=form_post');

    // 2. The identity provider posts back to the callback.
    $routes  = rest_get_server()->get_routes();
    $methods = array();
    foreach (isset($routes['/infosub/v1/callback']) ? $routes['/infosub/v1/callback'] : array() as $handler) {
        $methods = array_merge($methods, array_keys(array_filter((array) $handler['methods'])));
    }
    $check(in_array('POST', $methods, true), 'callback route accepts POST');

    // 3. A posted, valid id_token starts a subscriber session.
    $state  = isset($query['state']) ? (string) $query['state'] : '';
    $record = get_transient('infosub_oidc_state_' . $state);
    $nonce  = is_array($record) ? $record['nonce'] : '';
    $token  = JWT::encode(array(
        'iss'                    => $meta['issuer'],
        'aud'                    => $client_id,
        'nonce'                  => $nonce,
        'iat'                    => time(),
        'exp'                    => time() + 300,
        'extension_SubscriberId' => 'subscriber-1',
        'extension_Products'     => wp_json_encode(array(array(
            'Id'        => 'PRODUCT-A',
            'ValidFrom' => gmdate('c', time() - 3600),
            'ValidTo'   => gmdate('c', time() + 86400),
        ))),
    ), $private_pem, 'RS256', 'k1');

    $_COOKIE[Infosub_Auth::STATE_COOKIE] = $state;
    $callback = new WP_REST_Request('POST', '/infosub/v1/callback');
    $callback->set_body_params(array('state' => $state, 'id_token' => $token));
    $errors   = array();
    $location = $follow(function () use ($callback) {
        Infosub_Auth::handle_callback($callback);
    });
    $check($location === $return && !$errors, 'posted id_token signs the subscriber in' . ($errors ? ' (' . implode('; ', $errors) . ')' : ''));
    $check(Infosub_Session::has_product('PRODUCT-A'), 'session carries products from the id_token');

    // 4. A tampered id_token is rejected.
    Infosub_Session::destroy();
    $location = $follow(function () use ($login) {
        Infosub_Auth::handle_login($login);
    });
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
    $state = isset($query['state']) ? (string) $query['state'] : '';
    $_COOKIE[Infosub_Auth::STATE_COOKIE] = $state;
    $callback = new WP_REST_Request('POST', '/infosub/v1/callback');
    $callback->set_body_params(array('state' => $state, 'id_token' => substr($token, 0, -4) . 'AAAA'));
    $errors = array();
    $follow(function () use ($callback) {
        Infosub_Auth::handle_callback($callback);
    });
    $check(isset($errors[0]) && strpos($errors[0], 'token_invalid') === 0, 'tampered id_token is rejected');
    $check(!Infosub_Session::has_product('PRODUCT-A'), 'tampered id_token gives no access');
} finally {
    Infosub_Session::destroy();
    foreach ($saved as $name => $value) {
        if ($value === null) {
            delete_option($name);
        } else {
            update_option($name, $value);
        }
    }
    delete_transient('infosub_oidc_meta_' . md5($metadata_url));
    delete_transient('infosub_jwks_' . md5($meta['jwks_uri']));
}

echo implode("\n", $results), "\n";
if ($failed) {
    exit(1);
}
