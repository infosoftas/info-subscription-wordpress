<?php

if (PHP_SAPI !== 'cli') {
    exit;
}
/**
 * Run: php tests/gate-multiple-products.php
 */

define('ABSPATH', __DIR__);

$GLOBALS['meta']       = array();
$GLOBALS['transients'] = array();
$GLOBALS['can_edit']   = false;
$GLOBALS['modes']      = array();
$GLOBALS['gate_mode']  = 'teaser';
$GLOBALS['post_id']    = 6;

function add_action() {}
function add_filter() {}
function apply_filters($name, $value) { return $value; }
function current_user_can() { return $GLOBALS['can_edit']; }
function get_post_meta($post_id, $key, $single) {
    $values = $key === '_infosub_access_mode' ? $GLOBALS['modes'] : $GLOBALS['meta'];
    return isset($values[$post_id]) ? $values[$post_id] : '';
}
function get_transient($key) { return isset($GLOBALS['transients'][$key]) ? $GLOBALS['transients'][$key] : false; }
function is_admin() { return false; }
function get_the_ID() { return 6; }
function wp_trim_words($value) { return $value; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function esc_html__($value) { return $value; }
function __($value) { return $value; }
function esc_html($value) { return $value; }
function get_permalink() { return 'https://example.test/article'; }
function strip_shortcodes($value) { return $value; }
function esc_url($value) { return $value; }
function esc_url_raw($value) { return $value; }
function rest_url($path) { return 'https://example.test/wp-json/' . $path; }
function add_query_arg($key, $value, $url) { return $url . '?' . http_build_query(array($key => $value)); }
function get_option($key, $default = '') {
    return $key === 'infosub_gate_mode' ? $GLOBALS['gate_mode'] : 'https://example.test/subscribe';
}
function is_singular() { return true; }
function get_queried_object_id() { return $GLOBALS['post_id']; }
function wp_redirect($url) { throw new RuntimeException($url); }

require_once __DIR__ . '/../includes/class-session.php';
require_once __DIR__ . '/../includes/class-gate.php';

$failures = 0;
function check($name, $expected, $actual)
{
    global $failures;
    if ($expected === $actual) {
        echo "PASS: $name" . PHP_EOL;
    } else {
        echo "FAIL: $name (expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . ')' . PHP_EOL;
        $failures++;
    }
}

// Subscriber owns product-b (valid) and product-x (expired). Session is loaded once per request.
$sid = str_repeat('a', 64);
$_COOKIE[Infosub_Session::COOKIE] = $sid;
$GLOBALS['transients']['infosub_sess_' . hash('sha256', $sid)] = array(
    'expires'  => time() + 3600,
    'products' => array(
        array('Id' => 'PRODUCT-B', 'ValidFrom' => '', 'ValidTo' => gmdate('c', time() + 86400)),
        array('Id' => 'product-x', 'ValidFrom' => '', 'ValidTo' => gmdate('c', time() - 86400)),
    ),
);

$GLOBALS['meta'][1] = array('product-a', 'product-b');
check('any one of several products grants access', true, Infosub_Gate::is_allowed(1));

$GLOBALS['meta'][2] = array('product-a', 'product-c');
check('no matching product denies access', false, Infosub_Gate::is_allowed(2));

$GLOBALS['meta'][3] = array('product-a', 'product-x');
check('expired matching product denies access', false, Infosub_Gate::is_allowed(3));

$GLOBALS['meta'][4] = 'product-b';
check('legacy single-product string still grants access', true, Infosub_Gate::is_allowed(4));

$GLOBALS['meta'][5] = array();
check('post without products is free', true, Infosub_Gate::is_allowed(5));
check('post without products is not gated', false, Infosub_Gate::is_gated(5));

$GLOBALS['modes'][6] = 'user_logged_in';
check('login-only article is gated without product requirements', true, Infosub_Gate::is_gated(6));
check('active INFO session grants login-only access', true, Infosub_Gate::is_allowed(6));

$GLOBALS['modes'][7] = 'all_access';
$GLOBALS['meta'][7] = array('not-owned');
check('AllAccess ignores remembered product requirements', true, Infosub_Gate::is_allowed(7));
check('AllAccess is not gated even with remembered products', false, Infosub_Gate::is_gated(7));

$GLOBALS['modes'][8] = 'selected_products';
check('empty explicit product requirements do not make an article free', false, Infosub_Gate::is_allowed(8));
check('empty explicit product requirements remain gated', true, Infosub_Gate::is_gated(8));

function reset_session_cache()
{
    foreach (array('loaded' => false, 'cache' => null) as $property => $value) {
        $reflection = new ReflectionProperty(Infosub_Session::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue(null, $value);
    }
}

// A signed-in visitor with no product entitlements still qualifies for UserLoggedIn.
$GLOBALS['transients']['infosub_sess_' . hash('sha256', $sid)]['products'] = array();
reset_session_cache();
check('subscriber with no products can read login-only articles', true, Infosub_Gate::is_allowed(6));
$GLOBALS['transients']['infosub_sess_' . hash('sha256', $sid)]['expires'] = time() - 1;
reset_session_cache();
check('expired session does not qualify as logged in', false, Infosub_Gate::is_allowed(6));

// A separate visitor request, with no subscriber session (WordPress login alone is irrelevant).
reset_session_cache();
unset($_COOKIE[Infosub_Session::COOKIE]);
check('anonymous visitor cannot read a login-only article', false, Infosub_Gate::is_allowed(6));
check('public article stays accessible anonymously', true, Infosub_Gate::is_allowed(7));

$response = new class {
    private $data = array('content' => array('rendered' => 'Secret body', 'raw' => 'Secret body'));
    public function get_data() { return $this->data; }
    public function set_data($data) { $this->data = $data; }
};
$result = Infosub_Gate::filter_rest($response, (object) array('ID' => 6, 'post_excerpt' => ''), null)->get_data();
check('REST hides login-only article body from anonymous visitors', '', $result['content']['rendered']);
check('REST removes raw login-only content', false, isset($result['content']['raw']));
check('feeds label login-only articles as requiring sign-in', true,
    strpos(Infosub_Gate::filter_feed('Article excerpt'), '[Sign-in required]') !== false);
check('login-only teaser offers sign-in instead of a subscription', true,
    strpos(Infosub_Gate::filter_content('Article excerpt'), 'Sign in to read this article.') !== false
    && strpos(Infosub_Gate::filter_content('Article excerpt'), 'infosub-subscribe') === false);

$GLOBALS['gate_mode'] = 'redirect';
try {
    Infosub_Gate::maybe_block();
    check('login-only article redirects anonymous visitors to sign-in', true, false);
} catch (RuntimeException $redirect) {
    check('login-only article redirects anonymous visitors to sign-in', true,
        strpos($redirect->getMessage(), '/wp-json/infosub/v1/login?') !== false);
}
$GLOBALS['post_id'] = 8;
try {
    Infosub_Gate::maybe_block();
    check('product-only article still redirects to checkout', true, false);
} catch (RuntimeException $redirect) {
    check('product-only article still redirects to checkout', true,
        strpos($redirect->getMessage(), 'https://example.test/subscribe?') === 0);
}

$GLOBALS['can_edit'] = true;
check('editors retain login-only preview access', true, Infosub_Gate::is_allowed(6));
check('editors retain product-gated preview access', true, Infosub_Gate::is_allowed(8));

exit($failures ? 1 : 0);
