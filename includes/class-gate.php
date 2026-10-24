<?php
/**
 * Access enforcement for the INFO-Subscription plugin.
 *
 * Per-post access: public, signed in through INFO-Subscription, or holding any
 * selected product. No WordPress user accounts are involved, except that
 * editors and admins can always preview.
 *
 * Two modes (option 'infosub_gate_mode'):
 *   'teaser'   (default) show a short excerpt plus a sign-in / subscribe box
 *   'redirect' send visitors without access to the subscribe page
 *
 * Settings read here (all optional):
 *   infosub_gate_mode      'teaser' | 'redirect'
 *   infosub_subscribe_url  where "Subscribe" and redirect mode point
 *
 * Extension points (filters):
 *   infosub_required_products ($product_ids, $post_id) map categories, post types or a
 *                                                      site-wide rule to products
 *   infosub_teaser_words      ($words, $post_id)       default 60
 *   infosub_paywall_html      ($html, $post_id)
 *   infosub_login_url         ($url, $return_url)      default: /wp-json/infosub/v1/login
 */

if (!defined('ABSPATH')) {
    exit;
}

class Infosub_Gate
{
    const META_KEY = '_infosub_product';
    const MODE_META_KEY = '_infosub_access_mode';
    const ALL_ACCESS = 'all_access';
    const USER_LOGGED_IN = 'user_logged_in';
    const SELECTED_PRODUCTS = 'selected_products';

    public static function init()
    {
        // Hard block / cache headers: runs before any template output.
        add_action('template_redirect', array(__CLASS__, 'maybe_block'), 1);

        // Late priority so the content is fully rendered before we decide.
        add_filter('the_content', array(__CLASS__, 'filter_content'), 999);
        add_filter('get_the_excerpt', array(__CLASS__, 'filter_excerpt'), 999, 2);

        // Feeds.
        add_filter('the_content_feed', array(__CLASS__, 'filter_feed'), 999);
        add_filter('the_excerpt_rss', array(__CLASS__, 'filter_feed'), 999);

        // REST API.
        add_action('rest_api_init', array(__CLASS__, 'register_rest_filters'));
    }

    /* ------------------------------------------------------------------
     * Rules
     * ---------------------------------------------------------------- */

    /**
     * The products that give access in SelectedProducts mode (any one is enough).
     * Older posts store a single ID as a string.
     */
    public static function required_products($post_id)
    {
        $products = apply_filters('infosub_required_products', self::stored_products($post_id), $post_id);
        return self::clean_ids($products);
    }

    /**
     * The product IDs saved on the post itself (before filters).
     */
    public static function stored_products($post_id)
    {
        return self::clean_ids(get_post_meta($post_id, self::META_KEY, true));
    }

    public static function is_gated($post_id)
    {
        return self::access_mode($post_id) !== self::ALL_ACCESS;
    }

    public static function access_mode($post_id)
    {
        $mode = get_post_meta($post_id, self::MODE_META_KEY, true);
        if ($mode === '') {
            // Preserve legacy posts and existing product filters until an explicit mode is saved.
            return self::required_products($post_id) ? self::SELECTED_PRODUCTS : self::ALL_ACCESS;
        }
        return in_array($mode, array(self::ALL_ACCESS, self::USER_LOGGED_IN, self::SELECTED_PRODUCTS), true)
            ? $mode : self::SELECTED_PRODUCTS;
    }

    /**
     * May the current visitor see this post?
     */
    public static function is_allowed($post_id)
    {
        $mode = self::access_mode($post_id);
        if ($mode === self::ALL_ACCESS) {
            return true; // free content
        }
        if (current_user_can('edit_post', $post_id)) {
            return true; // editors and admins can always preview
        }
        if ($mode === self::USER_LOGGED_IN) {
            return Infosub_Session::is_active();
        }
        foreach (self::required_products($post_id) as $product_id) {
            if (Infosub_Session::has_product($product_id)) {
                return true;
            }
        }
        return false;
    }

    private static function clean_ids($value)
    {
        $ids = array();
        foreach ((array) $value as $id) {
            $id = is_string($id) ? trim($id) : '';
            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /* ------------------------------------------------------------------
     * Hooks
     * ---------------------------------------------------------------- */

    /**
     * Runs on every front-end request before the template loads.
     */
    public static function maybe_block()
    {
        if (is_admin() || !is_singular()) {
            return;
        }
        $post_id = get_queried_object_id();
        if (!$post_id || !self::is_gated($post_id)) {
            return;
        }

        // The output for this URL differs per visitor, so it must never be cached publicly.
        self::mark_uncacheable();

        if (self::is_allowed($post_id)) {
            return;
        }

        if (get_option('infosub_gate_mode', 'teaser') === 'redirect') {
            $url = self::access_mode($post_id) === self::USER_LOGGED_IN
                ? self::login_url($post_id)
                : add_query_arg('return', rawurlencode(get_permalink($post_id)), self::subscribe_url());
            // wp_redirect (not wp_safe_redirect): the target comes from admin settings and
            // may be the external INFO-Subscription checkout domain.
            wp_redirect(esc_url_raw($url), 302);
            exit;
        }
        // Teaser mode: the_content filter below replaces the body.
    }

    public static function filter_content($content)
    {
        if (is_admin()) {
            return $content;
        }
        $post_id = get_the_ID();
        if (!$post_id || self::is_allowed($post_id)) {
            return $content;
        }
        self::mark_uncacheable();
        return self::teaser_html($content, $post_id);
    }

    public static function filter_excerpt($excerpt, $post = null)
    {
        $post = get_post($post);
        if (!$post || is_admin() || self::is_allowed($post->ID)) {
            return $excerpt;
        }
        if ($post->post_excerpt !== '') {
            return $excerpt; // a hand-written excerpt is meant to be public
        }
        self::mark_uncacheable();
        return wp_trim_words($excerpt, self::teaser_words($post->ID), '…');
    }

    /**
     * Feed readers send no subscriber cookie, so gated items show a short note.
     */
    public static function filter_feed($text)
    {
        $post_id = get_the_ID();
        if (!$post_id || self::is_allowed($post_id)) {
            return $text;
        }
        $teaser = wp_trim_words(wp_strip_all_tags($text), self::teaser_words($post_id), '…');
        $label = self::access_mode($post_id) === self::USER_LOGGED_IN
            ? __('[Sign-in required]', 'infosub') : __('[Subscribers only]', 'infosub');
        return $teaser . ' ' . esc_html($label) . ' ' . get_permalink($post_id);
    }

    public static function register_rest_filters()
    {
        foreach (get_post_types(array('show_in_rest' => true)) as $type) {
            add_filter("rest_prepare_{$type}", array(__CLASS__, 'filter_rest'), 10, 3);
        }
    }

    /**
     * Never expose protected bodies through /wp-json/.
     */
    public static function filter_rest($response, $post, $request)
    {
        if (self::is_allowed($post->ID)) {
            return $response;
        }
        $data  = $response->get_data();
        $words = self::teaser_words($post->ID);

        if (isset($data['content'])) {
            $data['content']['rendered']  = '';
            $data['content']['protected'] = true;
            unset($data['content']['raw']);
        }
        if (isset($data['excerpt']) && $post->post_excerpt === '') {
            $data['excerpt']['rendered'] = wp_trim_words(
                wp_strip_all_tags(isset($data['excerpt']['rendered']) ? $data['excerpt']['rendered'] : ''),
                $words,
                '…'
            );
        }
        $response->set_data($data);
        return $response;
    }

    /* ------------------------------------------------------------------
     * Output
     * ---------------------------------------------------------------- */

    private static function teaser_words($post_id)
    {
        return max(0, (int) apply_filters('infosub_teaser_words', 60, $post_id));
    }

    private static function teaser_html($content, $post_id)
    {
        $words = self::teaser_words($post_id);
        $html  = '';
        if ($words > 0) {
            $text = wp_trim_words(wp_strip_all_tags(strip_shortcodes($content)), $words, '…');
            if ($text !== '') {
                $html = '<p>' . esc_html($text) . '</p>';
            }
        }
        return $html . self::paywall_html($post_id);
    }

    private static function paywall_html($post_id)
    {
        $login = self::login_url($post_id);
        $login_only = self::access_mode($post_id) === self::USER_LOGGED_IN;

        $html  = '<div class="infosub-paywall">';
        $html .= '<p>' . esc_html($login_only
            ? __('Sign in to read this article.', 'infosub')
            : __('This article is for subscribers.', 'infosub')) . '</p>';
        $html .= '<p><a class="infosub-signin" href="' . esc_url($login) . '">' . esc_html__('Sign in', 'infosub') . '</a>';
        if (!$login_only) {
            $html .= ' <a class="infosub-subscribe" href="' . esc_url(self::subscribe_url()) . '">' . esc_html__('Subscribe', 'infosub') . '</a>';
        }
        $html .= '</p>';
        $html .= '</div>';

        return apply_filters('infosub_paywall_html', $html, $post_id);
    }

    private static function login_url($post_id)
    {
        $permalink = get_permalink($post_id);
        return apply_filters(
            'infosub_login_url',
            add_query_arg('return', rawurlencode($permalink), rest_url('infosub/v1/login')),
            $permalink
        );
    }

    private static function subscribe_url()
    {
        $url = get_option('infosub_subscribe_url', '');
        return $url ? $url : home_url('/subscribe/');
    }

    /**
     * Ask page caches not to store this response.
     * Headers can only be sent before output starts, which is why maybe_block()
     * calls this early for singular views. Elsewhere only the constant can help,
     * and only for cache plugins that honour it.
     */
    private static function mark_uncacheable()
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!headers_sent()) {
            nocache_headers();
            header('Vary: Cookie', false);
        }
    }
}
