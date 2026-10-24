<?php
/**
 * "Subscription access" box on the post edit screen.
 *
 * Stores an access mode and optional required product IDs in protected post meta.
 * SelectedProducts requires at least one product; matching any one grants access.
 *
 * By default only users who can edit other people's posts (editors and
 * administrators) may change the requirement, so an author cannot make their own
 * article free. Change that with the 'infosub_access_capability' filter.
 * Post types: 'infosub_gated_post_types' filter (default: all public types
 * except attachments).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Infosub_Meta_Box
{
    const NONCE_ACTION = 'infosub_save_product';
    const NONCE_FIELD  = 'infosub_product_nonce';
    const FIELD        = 'infosub_product';
    const MODE_FIELD   = 'infosub_access_mode';

    public static function init()
    {
        add_action('add_meta_boxes', array(__CLASS__, 'add_box'));
        add_action('save_post', array(__CLASS__, 'save'), 10, 2);
        add_action('admin_notices', array(__CLASS__, 'maybe_show_error'));
    }

    public static function capability()
    {
        return (string) apply_filters('infosub_access_capability', 'edit_others_posts');
    }

    public static function post_types()
    {
        $types = get_post_types(array('public' => true));
        unset($types['attachment']);
        return array_values((array) apply_filters('infosub_gated_post_types', array_values($types)));
    }

    public static function add_box()
    {
        if (!current_user_can(self::capability())) {
            return;
        }
        foreach (self::post_types() as $type) {
            add_meta_box(
                'infosub_access',
                __('Subscription access', 'infosub'),
                array(__CLASS__, 'render'),
                $type,
                'side',
                'default'
            );
        }
    }

    public static function render($post)
    {
        $values = Infosub_Gate::stored_products($post->ID);
        $mode = Infosub_Gate::access_mode($post->ID);

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        echo '<p><label for="infosub_access_mode"><strong>' . esc_html__('Access', 'infosub') . '</strong></label></p>';
        printf('<select id="infosub_access_mode" name="%s" class="widefat" aria-controls="infosub-products-controls">', esc_attr(self::MODE_FIELD));
        foreach (array(
            Infosub_Gate::ALL_ACCESS => __('AllAccess', 'infosub'),
            Infosub_Gate::USER_LOGGED_IN => __('UserLoggedIn', 'infosub'),
            Infosub_Gate::SELECTED_PRODUCTS => __('SelectedProducts', 'infosub'),
        ) as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($mode, $value, false), esc_html($label));
        }
        echo '</select>';
        echo '<p class="description">'
            . esc_html__('AllAccess: everyone. UserLoggedIn: anyone signed in through INFO-Subscription, with or without products. SelectedProducts: at least one selected product is required.', 'infosub')
            . '</p>';
        printf('<div id="infosub-products-controls"%s>', $mode === Infosub_Gate::SELECTED_PRODUCTS ? '' : ' hidden');
        echo '<p><label for="infosub_product"><strong>' . esc_html__('Required products', 'infosub') . '</strong></label></p>';
        $products = Infosub_Api::products();
        if ($products) {
            // Product list loaded from the INFO-Subscription API (Settings -> INFO-Subscription).
            self::render_select($values, $products);
        } else {
            $input = implode(PHP_EOL, $values);
            printf(
                '<textarea id="infosub_product" name="%1$s" class="widefat" rows="4" autocomplete="off">%2$s</textarea>',
                esc_attr(self::FIELD),
                esc_textarea($input)
            );
        }

        echo '<p class="description">'
            . esc_html__('Select at least one product. Visitors need any one selected product to read the full article. When entering IDs manually, use one ID per line or separate them with commas.', 'infosub')
            . '</p>';
        echo '</div>';
        ?>
        <script>
            (function () {
                var mode = document.getElementById('infosub_access_mode');
                var products = document.getElementById('infosub-products-controls');
                if (!mode || !products) { return; }
                mode.addEventListener('change', function () {
                    products.hidden = mode.value !== 'selected_products';
                });
            }());
        </script>
        <?php
    }

    public static function save($post_id, $post)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!in_array($post->post_type, self::post_types(), true)) {
            return;
        }
        if (!isset($_POST[self::NONCE_FIELD]) || !is_string($_POST[self::NONCE_FIELD])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_ACTION)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id) || !current_user_can(self::capability())) {
            return;
        }
        if (!isset($_POST[self::MODE_FIELD])) {
            return;
        }
        $mode = wp_unslash($_POST[self::MODE_FIELD]);
        if (!in_array($mode, array(Infosub_Gate::ALL_ACCESS, Infosub_Gate::USER_LOGGED_IN, Infosub_Gate::SELECTED_PRODUCTS), true)) {
            self::save_error('mode');
            return;
        }
        if ($mode !== Infosub_Gate::SELECTED_PRODUCTS) {
            // Remember product choices when switching modes, but do not enforce them.
            update_post_meta($post_id, Infosub_Gate::MODE_META_KEY, $mode);
            return;
        }

        $raw = isset($_POST[self::FIELD]) ? wp_unslash($_POST[self::FIELD]) : '';
        if (is_string($raw)) {
            $raw = trim($raw) === '' ? array() : preg_split('/[\r\n,]+/', trim($raw));
        } elseif (!is_array($raw)) {
            self::save_error('products');
            return;
        }

        $values = array();
        foreach ($raw as $value) {
            if (!is_string($value)) {
                self::save_error('products');
                return;
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $value)) {
                self::save_error('products');
                return;
            }
            if (!in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        if (!$values) {
            self::save_error('empty_products');
            return;
        }
        update_post_meta($post_id, Infosub_Gate::META_KEY, $values);
        update_post_meta($post_id, Infosub_Gate::MODE_META_KEY, $mode);
    }

    private static function save_error($code)
    {
        // Never turn a typo into "free": keep the old value and tell the editor.
        set_transient('infosub_meta_error_' . get_current_user_id(), $code, 120);
    }

    public static function maybe_show_error()
    {
        $key = 'infosub_meta_error_' . get_current_user_id();
        $code = get_transient($key);
        if (!$code) {
            return;
        }
        delete_transient($key);
        $messages = array(
            'mode' => __('The access mode was not saved because it is invalid. The previous access rule was kept.', 'infosub'),
            'empty_products' => __('SelectedProducts requires at least one product. The previous access rule was kept.', 'infosub'),
            'products' => __('The product IDs were not saved because at least one contains unexpected characters. The previous values were kept.', 'infosub'),
        );
        echo '<div class="notice notice-error is-dismissible"><p>'
            . esc_html(isset($messages[$code]) ? $messages[$code] : $messages['products'])
            . '</p></div>';
    }

    /**
     * Dropdown of the products loaded from the API. A product ID saved earlier that is
     * not in the list (for example a product removed since) stays selectable as "Custom".
     */
    private static function render_select(array $values, array $products)
    {
        printf('<select id="infosub_product" name="%s[]" class="widefat" multiple="multiple" size="6">', esc_attr(self::FIELD));

        $found = array();
        foreach ($products as $product) {
            $label = $product['name'] !== '' ? $product['name'] : $product['id'];
            if ($product['part_no'] !== '') {
                $label .= ' (' . $product['part_no'] . ')';
            }
            $is_current = self::contains_id($values, $product['id']);
            if ($is_current) {
                $found[] = $product['id'];
            }
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($product['id']),
                selected($is_current, true, false),
                esc_html($label)
            );
        }
        foreach ($values as $value) {
            if (self::contains_id($found, $value)) {
                continue;
            }
            printf(
                '<option value="%s" selected="selected">%s</option>',
                esc_attr($value),
                esc_html(sprintf(__('Custom ID: %s', 'infosub'), $value))
            );
        }
        echo '</select>';

        $when = Infosub_Api::fetched_at();
        if ($when) {
            echo '<p class="description">' . esc_html(sprintf(
                __('Product list loaded %s. Refresh it under Settings, INFO-Subscription.', 'infosub'),
                wp_date(get_option('date_format'), $when)
            )) . '</p>';
        }
    }

    private static function contains_id(array $values, $id)
    {
        foreach ($values as $value) {
            if (strcasecmp($value, $id) === 0) {
                return true;
            }
        }
        return false;
    }
}
