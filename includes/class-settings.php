<?php
/**
 * Settings page for the INFO-Subscription plugin (Settings -> INFO-Subscription).
 *
 * Every installation enters its own tenant name, policy and client ID here. Neither sign-in
 * uses a client secret: subscribers only need the id_token, and the API sign-in uses PKCE.
 *
 * wp-config.php constants override the saved values (useful for staging or
 * managed hosting): INFOSUB_TENANT, INFOSUB_POLICY, INFOSUB_CLIENT_ID,
 * INFOSUB_REDIRECT_URI, INFOSUB_API_TENANT_ID, INFOSUB_API_CLIENT_ID,
 * INFOSUB_API_REDIRECT_URI.
 *
 * Options written by this page:
 *   infosub_tenant, infosub_policy, infosub_client_id,
 *   infosub_gate_mode ('teaser' | 'redirect'), infosub_subscribe_url
 *
 * Other classes read the connection through Infosub_Settings::connection().
 */

if (!defined('ABSPATH')) {
    exit;
}

class Infosub_Settings
{
    const PAGE          = 'infosub-settings';
    const GROUP         = 'infosub_settings';
    const DEFAULT_POLICY = 'B2C_1_SignIn';

    /** @var array Field key => constant in wp-config.php that overrides it. */
    private static $constants = array(
        'tenant'        => 'INFOSUB_TENANT',
        'policy'        => 'INFOSUB_POLICY',
        'client_id'     => 'INFOSUB_CLIENT_ID',
        // API application (product list). A different identity provider and application.
        'api_tenant_id' => 'INFOSUB_API_TENANT_ID',
        'api_client_id' => 'INFOSUB_API_CLIENT_ID',
    );

    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'add_menu'));
        add_action('admin_init', array(__CLASS__, 'register'));
        add_action('admin_post_infosub_test', array(__CLASS__, 'handle_test'));
    }

    /* ------------------------------------------------------------------
     * Public API for the rest of the plugin
     * ---------------------------------------------------------------- */

    /**
     * The connection details in use: constants win over saved options.
     *
     * @return array tenant, policy, client_id, redirect_uri
     */
    public static function connection()
    {
        $out = array();
        foreach (array('tenant', 'policy', 'client_id') as $key) {
            $out[$key] = self::is_locked($key)
                ? (string) constant(self::$constants[$key])
                : (string) get_option('infosub_' . $key, '');
        }
        if ($out['policy'] === '') {
            $out['policy'] = self::DEFAULT_POLICY;
        }
        $out['redirect_uri']  = self::redirect_uri();
        return $out;
    }

    /** The URL that must be registered with INFO-Subscription as the redirect URI. */
    public static function redirect_uri()
    {
        if (defined('INFOSUB_REDIRECT_URI') && constant('INFOSUB_REDIRECT_URI') !== '') {
            return (string) constant('INFOSUB_REDIRECT_URI');
        }
        return rest_url('infosub/v1/callback');
    }

    /**
     * API client credentials and tenant-derived delegated permission used to load products.
     * The metadata document is filterable for other environments.
     *
     * @return array tenant_id, client_id, scope, redirect_uri,
     *               base_url, metadata_url
     */
    public static function api_connection()
    {
        $out = array();
        foreach (array('api_tenant_id' => 'tenant_id', 'api_client_id' => 'client_id') as $key => $name) {
            $out[$name] = self::is_locked($key)
                ? (string) constant(self::$constants[$key])
                : (string) get_option('infosub_' . $key, '');
        }
        $out['scope'] = 'https://prodlogins4.onmicrosoft.com/api/' . $out['tenant_id'] . '.ReadWriteAll';
        $out['redirect_uri'] = (string) apply_filters(
            'infosub_api_redirect_uri',
            defined('INFOSUB_API_REDIRECT_URI') && INFOSUB_API_REDIRECT_URI !== ''
                ? (string) INFOSUB_API_REDIRECT_URI
                : rest_url('infosub/v1/api-callback')
        );
        $out['base_url']      = (string) apply_filters('infosub_api_base_url', 'https://api.info-subscription.com');
        $out['metadata_url']  = (string) apply_filters(
            'infosub_api_metadata_url',
            'https://prodlogins4.b2clogin.com/prodlogins4.onmicrosoft.com/B2C_1A_V2SIGNIN/v2.0/.well-known/openid-configuration'
        );
        return $out;
    }

    /* ------------------------------------------------------------------
     * Registration
     * ---------------------------------------------------------------- */

    public static function add_menu()
    {
        add_options_page(
            __('INFO-Subscription', 'infosub'),
            __('INFO-Subscription', 'infosub'),
            'manage_options',
            self::PAGE,
            array(__CLASS__, 'render_page')
        );
    }

    public static function register()
    {
        // Neither sign-in uses a client secret any more: remove secrets and settings saved by older versions.
        foreach (array('infosub_client_secret', 'infosub_api_client_secret', 'infosub_api_scope') as $legacy) {
            if (get_option($legacy, null) !== null) {
                delete_option($legacy);
            }
        }

        register_setting(self::GROUP, 'infosub_tenant', array(
            'type' => 'string', 'default' => '', 'sanitize_callback' => array(__CLASS__, 'sanitize_tenant'),
        ));
        register_setting(self::GROUP, 'infosub_policy', array(
            'type' => 'string', 'default' => self::DEFAULT_POLICY, 'sanitize_callback' => array(__CLASS__, 'sanitize_policy'),
        ));
        register_setting(self::GROUP, 'infosub_client_id', array(
            'type' => 'string', 'default' => '', 'sanitize_callback' => array(__CLASS__, 'sanitize_client_id'),
        ));
        register_setting(self::GROUP, 'infosub_gate_mode', array(
            'type' => 'string', 'default' => 'teaser', 'sanitize_callback' => array(__CLASS__, 'sanitize_gate_mode'),
        ));
        register_setting(self::GROUP, 'infosub_subscribe_url', array(
            'type' => 'string', 'default' => '', 'sanitize_callback' => array(__CLASS__, 'sanitize_subscribe_url'),
        ));
        register_setting(self::GROUP, 'infosub_api_tenant_id', array(
            'type' => 'string', 'default' => '', 'sanitize_callback' => array(__CLASS__, 'sanitize_api_tenant_id'),
        ));
        register_setting(self::GROUP, 'infosub_api_client_id', array(
            'type' => 'string', 'default' => '', 'sanitize_callback' => array(__CLASS__, 'sanitize_api_client_id'),
        ));

        add_settings_section('infosub_connection', __('Subscriber sign-in (paywall)', 'infosub'), array(__CLASS__, 'section_connection'), self::PAGE);
        self::field('tenant', __('Tenant name', 'infosub'), 'text', 'infosub_connection', array(
            'placeholder' => 'experimentationS4prod',
            'desc'        => __('Usually your INFO-Subscription tenant name followed by S4Prod. Just the name, without .b2clogin.com.', 'infosub'),
        ));
        self::field('policy', __('Sign-in policy', 'infosub'), 'text', 'infosub_connection', array(
            'default' => self::DEFAULT_POLICY,
            'desc'    => __('Leave as B2C_1_SignIn unless INFO-Subscription told you otherwise.', 'infosub'),
        ));
        self::field('client_id', __('Client ID', 'infosub'), 'text', 'infosub_connection', array(
            'desc' => __('The application (client) ID issued when your application was registered. No client secret is needed: the application must allow ID tokens (implicit grant).', 'infosub'),
        ));

        add_settings_section('infosub_access', __('Access', 'infosub'), '__return_false', self::PAGE);
        self::field('gate_mode', __('Without access', 'infosub'), 'select', 'infosub_access', array(
            'default' => 'teaser',
            'options' => array(
                'teaser'   => __('Show a short teaser with sign-in and subscribe links', 'infosub'),
                'redirect' => __('Redirect to the subscribe page', 'infosub'),
            ),
        ));
        self::field('subscribe_url', __('Subscribe page URL', 'infosub'), 'text', 'infosub_access', array(
            'placeholder' => home_url('/subscribe/'),
            'desc'        => __('Where visitors without a subscription are sent. Can be your INFO-Subscription checkout address.', 'infosub'),
        ));

        add_settings_section('infosub_api', __('Product list (API credentials, separate)', 'infosub'), array(__CLASS__, 'section_api'), self::PAGE);
        self::field('api_tenant_id', __('INFO-Subscription tenant ID', 'infosub'), 'text', 'infosub_api', array(
            'placeholder' => '00000000-0000-0000-0000-000000000000',
            'desc'        => __('Sent as the S4-TenantId header on every API request (a GUID, not the sign-in tenant name above). Provided by Infosoft or by the tenant.', 'infosub'),
        ));
        self::field('api_client_id', __('API client ID', 'infosub'), 'text', 'infosub_api', array(
            'desc' => __('The application registered in the main INFO-Subscription identity provider. It must be a different registration than the subscriber sign-in application above, must have the tenant-specific ReadWriteAll delegated permission, and its redirect URI must be registered as a public client (Mobile and desktop applications), so no secret is needed.', 'infosub'),
        ));
    }

    private static function field($key, $label, $type, $section, array $args)
    {
        $args['key']       = $key;
        $args['type']      = $type;
        $args['label_for'] = 'infosub_' . $key;
        add_settings_field('infosub_' . $key, $label, array(__CLASS__, 'render_field'), self::PAGE, $section, $args);
    }

    /* ------------------------------------------------------------------
     * Sanitizing (these can run twice on the very first save, so they are idempotent)
     * ---------------------------------------------------------------- */

    public static function sanitize_tenant($value)
    {
        $value = is_string($value) ? trim($value) : '';
        $value = preg_replace('/\.(onmicrosoft\.com|b2clogin\.com)$/i', '', $value);
        if ($value !== '' && !preg_match('/^[A-Za-z0-9-]+$/', $value)) {
            add_settings_error('infosub_tenant', 'infosub_tenant', __('The tenant name may only contain letters, digits and hyphens.', 'infosub'));
            return (string) get_option('infosub_tenant', '');
        }
        return $value;
    }

    public static function sanitize_policy($value)
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return self::DEFAULT_POLICY;
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            add_settings_error('infosub_policy', 'infosub_policy', __('The policy name may only contain letters, digits and underscores.', 'infosub'));
            return (string) get_option('infosub_policy', self::DEFAULT_POLICY);
        }
        return $value;
    }

    public static function sanitize_client_id($value)
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            add_settings_error('infosub_client_id', 'infosub_client_id', __('The client ID contains unexpected characters.', 'infosub'));
            return (string) get_option('infosub_client_id', '');
        }
        return $value;
    }

    public static function sanitize_gate_mode($value)
    {
        return in_array($value, array('teaser', 'redirect'), true) ? $value : 'teaser';
    }

    public static function sanitize_subscribe_url($value)
    {
        return is_string($value) ? esc_url_raw(trim($value)) : '';
    }

    public static function sanitize_api_tenant_id($value)
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value !== '' && !preg_match('/^[0-9a-fA-F-]{36}$/', $value)) {
            add_settings_error('infosub_api_tenant_id', 'infosub_api_tenant_id', __('The tenant ID should look like a GUID, for example fe923cfe-2e67-4f7a-960a-d4c36fce22c4.', 'infosub'));
            return (string) get_option('infosub_api_tenant_id', '');
        }
        return $value;
    }

    public static function sanitize_api_client_id($value)
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            add_settings_error('infosub_api_client_id', 'infosub_api_client_id', __('The API client ID contains unexpected characters.', 'infosub'));
            return (string) get_option('infosub_api_client_id', '');
        }
        if ($value !== '' && strcasecmp($value, (string) get_option('infosub_client_id', '')) === 0) {
            add_settings_error(
                'infosub_api_client_id',
                'infosub_api_client_id_same',
                __('The API client ID is the same as the subscriber sign-in client ID. They belong to two different identity providers and need separate application registrations.', 'infosub'),
                'warning'
            );
        }
        return $value;
    }

    public static function section_api()
    {
        echo '<p>' . esc_html__('Optional. Lets editors pick products from a list instead of typing product IDs. An administrator signs in interactively with the API application. Its delegated scope is derived from the INFO-Subscription tenant ID. These credentials are separate from subscriber sign-in and are never used for the paywall. Access and refresh tokens are never stored.', 'infosub') . '</p>';
    }

    /* ------------------------------------------------------------------
     * Rendering
     * ---------------------------------------------------------------- */

    private static function is_locked($key)
    {
        if (!isset(self::$constants[$key])) {
            return false;
        }
        $const = self::$constants[$key];
        return defined($const) && constant($const) !== '';
    }

    public static function section_connection()
    {
        echo '<p>' . esc_html__('These details identify this website to the sign-in service your subscribers use (the tenant-specific instance, for example experimentationS4prod) and decide who can read protected articles. Each installation has its own. They are not used for the product list below.', 'infosub') . '</p>';
    }

    public static function render_field($args)
    {
        $key  = $args['key'];
        $name = 'infosub_' . $key;
        $desc = isset($args['desc']) ? $args['desc'] : '';

        if (self::is_locked($key)) {
            $shown = (string) constant(self::$constants[$key]);
            printf(
                '<input type="text" class="regular-text" value="%s" disabled="disabled" /><p class="description">%s</p>',
                esc_attr($shown),
                esc_html(sprintf(__('Set by %s in wp-config.php.', 'infosub'), self::$constants[$key]))
            );
            return;
        }

        if ($args['type'] === 'select') {
            $current = get_option($name, isset($args['default']) ? $args['default'] : '');
            printf('<select id="%1$s" name="%1$s">', esc_attr($name));
            foreach ($args['options'] as $value => $label) {
                printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html($label));
            }
            echo '</select>';
        } else {
            $value = (string) get_option($name, isset($args['default']) ? $args['default'] : '');
            printf(
                '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" placeholder="%3$s" autocomplete="off" />',
                esc_attr($name),
                esc_attr($value),
                esc_attr(isset($args['placeholder']) ? $args['placeholder'] : '')
            );
        }

        if ($desc !== '') {
            echo '<p class="description">' . esc_html($desc) . '</p>';
        }
    }

    public static function render_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $conn  = self::connection();
        $ready = $conn['tenant'] !== '' && $conn['client_id'] !== '';

        echo '<div class="wrap"><h1>' . esc_html__('INFO-Subscription', 'infosub') . '</h1>';
        settings_errors();
        self::render_test_notice();
        self::render_api_notice();

        if (!$ready) {
            echo '<div class="notice notice-warning inline"><p>'
                . esc_html__('Not configured yet: tenant name and client ID are both required before visitors can sign in.', 'infosub')
                . '</p></div>';
        }

        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        do_settings_sections(self::PAGE);
        submit_button();
        echo '</form>';

        echo '<h2>' . esc_html__('Redirect URI for the subscriber sign-in', 'infosub') . '</h2>';
        echo '<p>' . esc_html__('INFO-Subscription must have this exact address registered as the redirect URI for your subscriber sign-in application:', 'infosub') . '</p>';
        echo '<p><code>' . esc_html(self::redirect_uri()) . '</code></p>';

        $items = Infosub_Api::products();
        $when  = Infosub_Api::fetched_at();
        echo '<h2>' . esc_html__('Load products (API sign-in)', 'infosub') . '</h2>';
        echo '<p>' . esc_html__('Register this exact redirect URI on the API application under the "Mobile and desktop applications" platform (public client, no secret):', 'infosub') . ' <code>'
            . esc_html(self::api_connection()['redirect_uri']) . '</code></p>';
        echo '<p>' . esc_html__('Requested scope:', 'infosub') . ' <code>'
            . esc_html('openid profile ' . self::api_connection()['scope']) . '</code></p>';
        if ($items && $when) {
            echo '<p>' . esc_html(sprintf(
                _n('%1$d product loaded (%2$s).', '%1$d products loaded (%2$s).', count($items), 'infosub'),
                count($items),
                wp_date(get_option('date_format') . ' ' . get_option('time_format'), $when)
            )) . '</p>';
        } else {
            echo '<p>' . esc_html__('No products loaded yet.', 'infosub') . '</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="infosub_api_connect" />';
        wp_nonce_field('infosub_api_connect');
        submit_button(__('Sign in and load products', 'infosub'), 'secondary', 'infosub_api_submit', false);
        echo '</form>';

        echo '<h2>' . esc_html__('Test the connection', 'infosub') . '</h2>';
        echo '<p>' . esc_html__('Checks that the tenant name and policy resolve to a working sign-in service. The client ID is only verified at the first real sign-in.', 'infosub') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="infosub_test" />';
        wp_nonce_field('infosub_test');
        submit_button(__('Test connection', 'infosub'), 'secondary', 'submit', false);
        echo '</form></div>';
    }

    private static function render_test_notice()
    {
        if (empty($_GET['infosub_test']) || !is_string($_GET['infosub_test'])) {
            return;
        }
        $messages = array(
            'ok'          => array('success', __('Connection OK: the tenant and policy resolve to a working sign-in service.', 'infosub')),
            'unreachable' => array('error', __('Could not load the sign-in service configuration. Check the tenant name and policy.', 'infosub')),
            'incomplete'  => array('warning', __('Fill in and save the tenant name and client ID first.', 'infosub')),
        );
        $result = sanitize_key(wp_unslash($_GET['infosub_test']));
        if (!isset($messages[$result])) {
            return;
        }
        printf('<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr($messages[$result][0]), esc_html($messages[$result][1]));
    }

    private static function render_api_notice()
    {
        if (!current_user_can('manage_options') || empty($_GET['infosub_api']) || !is_string($_GET['infosub_api'])) {
            return;
        }
        $count    = isset($_GET['count']) ? absint($_GET['count']) : 0;
        $messages = array(
            'ok'             => array('success', sprintf(_n('%d product loaded from INFO-Subscription.', '%d products loaded from INFO-Subscription.', $count, 'infosub'), $count)),
            'empty'          => array('warning', __('The API answered, but returned no products for this tenant.', 'infosub')),
            'incomplete'     => array('warning', __('Fill in and save the tenant ID and API client ID first.', 'infosub')),
            'unreachable'    => array('error', __('Could not load the API authentication service configuration. Try again in a moment.', 'infosub')),
            'token_exchange' => array('error', __('Could not obtain an API access token. Check the API client, delegated permission and redirect URI.', 'infosub')),
            'provider'       => array('error', __('API sign-in was not completed. Check the application registration and try again.', 'infosub')),
            'state'          => array('error', __('API sign-in state expired or did not match. Start sign-in again.', 'infosub')),
            'unauthorized'   => array('error', __('The API rejected the access token (401). Check the API scope and that the application is allowed to use the API.', 'infosub')),
            'forbidden'      => array('error', __('The API denied access (403). Check that the API application is allowed to access the configured tenant ID.', 'infosub')),
            'api_error'      => array('error', __('The API request failed. Please try again.', 'infosub')),
        );
        $result = sanitize_key(wp_unslash($_GET['infosub_api']));
        if (!isset($messages[$result])) {
            return;
        }
        printf('<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr($messages[$result][0]), esc_html($messages[$result][1]));
        $id = isset($_GET['infosub_api_detail']) && is_string($_GET['infosub_api_detail'])
            ? wp_unslash($_GET['infosub_api_detail']) : '';
        $detail = Infosub_Api::take_error_detail($id, $result);
        if ($detail !== '') {
            echo '<div class="notice notice-error inline"><details><summary>'
                . esc_html__('Technical details', 'infosub')
                . '</summary><pre style="white-space: pre-wrap; overflow-wrap: anywhere;">'
                . esc_html($detail) . '</pre></details></div>';
        }
    }

    public static function handle_test()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'infosub'), '', array('response' => 403));
        }
        check_admin_referer('infosub_test');

        $result = Infosub_Auth::test_connection(); // 'ok' | 'unreachable' | 'incomplete'
        wp_safe_redirect(add_query_arg(
            array('page' => self::PAGE, 'infosub_test' => $result),
            admin_url('options-general.php')
        ));
        exit;
    }
}
