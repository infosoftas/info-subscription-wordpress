<?php

if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', __DIR__);

class Infosub_Api
{
    public static function products()
    {
        return array(
            array('id' => 'product-a', 'name' => 'Product A', 'part_no' => ''),
            array('id' => 'product-b', 'name' => 'Product B', 'part_no' => ''),
        );
    }

    public static function fetched_at()
    {
        return 0;
    }
}

function add_action()
{
}

function get_post_types()
{
    return array('post' => 'post');
}

function apply_filters($name, $value)
{
    return $value;
}

function wp_is_post_revision()
{
    return false;
}

function wp_is_post_autosave()
{
    return false;
}

function current_user_can()
{
    return true;
}

function wp_verify_nonce()
{
    return true;
}

function sanitize_text_field($value)
{
    return is_string($value) ? trim($value) : '';
}

function wp_unslash($value)
{
    return $value;
}

function get_current_user_id()
{
    return 1;
}

function set_transient($key, $value)
{
    $GLOBALS['transients'][$key] = $value;
}

function wp_nonce_field()
{
}

function esc_html__($value)
{
    return $value;
}

function __($value)
{
    return $value;
}

function esc_html($value)
{
    return $value;
}

function esc_attr($value)
{
    return $value;
}

function esc_textarea($value)
{
    return $value;
}

function selected($selected, $current = true, $echo = true)
{
    if (is_array($selected) || is_array($current)) {
        throw new Exception('Selected values must be scalar.');
    }
    return (string) $selected === (string) $current ? ' selected="selected"' : '';
}

function delete_post_meta($post_id, $key)
{
    unset($GLOBALS['post_meta'][$post_id][$key]);
}

function update_post_meta($post_id, $key, $value)
{
    $GLOBALS['post_meta'][$post_id][$key] = $value;
}

function get_post_meta($post_id, $key, $single)
{
    return isset($GLOBALS['post_meta'][$post_id][$key]) ? $GLOBALS['post_meta'][$post_id][$key] : '';
}

require_once __DIR__ . '/../includes/class-gate.php';
require_once __DIR__ . '/../includes/class-meta-box.php';

$_POST = array(
    Infosub_Meta_Box::NONCE_FIELD => 'valid',
    Infosub_Meta_Box::FIELD => array('product-a', 'product-b'),
    'infosub_access_mode' => 'selected_products',
);
Infosub_Meta_Box::save(17, (object) array('post_type' => 'post'));

$saved = isset($GLOBALS['post_meta'][17][Infosub_Gate::META_KEY])
    ? $GLOBALS['post_meta'][17][Infosub_Gate::META_KEY]
    : null;

if ($saved !== array('product-a', 'product-b')) {
    fwrite(STDERR, 'Expected both selected product IDs to be saved.' . PHP_EOL);
    exit(1);
}

ob_start();
Infosub_Meta_Box::render((object) array('ID' => 17));
$markup = ob_get_clean();
if (!preg_match('/<select[^>]+id="infosub_product"[^>]*>(.*?)<\/select>/s', $markup, $matches)
    || substr_count($matches[1], 'selected="selected"') !== 2) {
    fwrite(STDERR, 'Expected both required products to be selected in the editor.' . PHP_EOL);
    exit(1);
}

$_POST[Infosub_Meta_Box::FIELD] = "product-c, product-d\nproduct-c";
Infosub_Meta_Box::save(17, (object) array('post_type' => 'post'));
$saved = $GLOBALS['post_meta'][17][Infosub_Gate::META_KEY];
if ($saved !== array('product-c', 'product-d')) {
    fwrite(STDERR, 'Expected manually entered IDs to be split and deduplicated.' . PHP_EOL);
    exit(1);
}

$_POST[Infosub_Meta_Box::FIELD] = array('product-e', 'invalid id');
Infosub_Meta_Box::save(17, (object) array('post_type' => 'post'));
if ($GLOBALS['post_meta'][17][Infosub_Gate::META_KEY] !== array('product-c', 'product-d')) {
    fwrite(STDERR, 'Invalid product ID must not clear or replace the saved requirements.' . PHP_EOL);
    exit(1);
}

$_POST[Infosub_Meta_Box::FIELD] = '';
Infosub_Meta_Box::save(17, (object) array('post_type' => 'post'));
if (!isset($GLOBALS['post_meta'][17][Infosub_Gate::META_KEY])
    || $GLOBALS['post_meta'][17][Infosub_Gate::META_KEY] !== array('product-c', 'product-d')
    || empty($GLOBALS['transients']['infosub_meta_error_1'])) {
    fwrite(STDERR, 'An empty SelectedProducts selection must keep the previous rule and report an error.' . PHP_EOL);
    exit(1);
}

foreach (array('all_access' => 'AllAccess', 'user_logged_in' => 'UserLoggedIn') as $mode => $label) {
    $_POST['infosub_access_mode'] = $mode;
    // Hidden, irrelevant product fields must not prevent changing access mode.
    $_POST[Infosub_Meta_Box::FIELD] = array('invalid id');
    Infosub_Meta_Box::save(17, (object) array('post_type' => 'post'));
    ob_start();
    Infosub_Meta_Box::render((object) array('ID' => 17));
    $markup = ob_get_clean();
    if (!preg_match('/<option value="' . $mode . '" selected="selected">' . $label . '<\/option>/', $markup)
        || !preg_match('/<div[^>]+id="infosub-products-controls"[^>]*\bhidden\b/', $markup)) {
        fwrite(STDERR, "$label must be saved, selected and hide the product controls." . PHP_EOL);
        exit(1);
    }
}

$_POST['infosub_access_mode'] = 'selected_products';
$_POST[Infosub_Meta_Box::FIELD] = array('product-b');
Infosub_Meta_Box::save(17, (object) array('post_type' => 'post'));
ob_start();
Infosub_Meta_Box::render((object) array('ID' => 17));
$markup = ob_get_clean();
if (!preg_match('/<div[^>]+id="infosub-products-controls"[^>]*>/', $markup, $matches)
    || strpos($matches[0], 'hidden') !== false
    || strpos($markup, 'Free (no product required)') !== false) {
    fwrite(STDERR, 'SelectedProducts must show product controls without a Free option.' . PHP_EOL);
    exit(1);
}

$_POST['infosub_access_mode'] = 'invalid-mode';
Infosub_Meta_Box::save(17, (object) array('post_type' => 'post'));
if (Infosub_Gate::access_mode(17) !== 'selected_products') {
    fwrite(STDERR, 'An invalid access mode must preserve the previous rule.' . PHP_EOL);
    exit(1);
}
unset($_POST['infosub_access_mode']);
$_POST[Infosub_Meta_Box::FIELD] = array('product-a');
Infosub_Meta_Box::save(17, (object) array('post_type' => 'post'));
if ($GLOBALS['post_meta'][17][Infosub_Gate::META_KEY] !== array('product-b')) {
    fwrite(STDERR, 'A missing access-mode field must not change the access rule.' . PHP_EOL);
    exit(1);
}

if (in_array('--render', $argv, true)) {
    echo $markup;
    exit;
}

echo 'PASS: saves selected and manually entered product IDs' . PHP_EOL;
echo 'PASS: selects all saved products in the editor' . PHP_EOL;
echo 'PASS: invalid product ID preserves existing requirements' . PHP_EOL;
echo 'PASS: empty SelectedProducts selection preserves requirements and reports an error' . PHP_EOL;
echo 'PASS: AllAccess and UserLoggedIn save while hiding irrelevant product controls' . PHP_EOL;
echo 'PASS: SelectedProducts shows products without a Free option' . PHP_EOL;
echo 'PASS: invalid and missing access modes preserve the previous rule' . PHP_EOL;
