<?php
/**
 * Shows a short, dismissible message when a sign-in attempt fails.
 *
 * Infosub_Auth redirects back to the site with ?infosub_error=<code>. This class
 * turns the code into a friendly message at the bottom of the page. It is added
 * through wp_footer, so it works with any theme. Only known codes are shown and
 * the query value is never printed.
 *
 * Messages can be changed with the 'infosub_error_messages' filter.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Infosub_Notices
{
    public static function init()
    {
        add_action('template_redirect', array(__CLASS__, 'maybe_nocache'));
        add_action('wp_footer', array(__CLASS__, 'render'));
    }

    private static function messages()
    {
        return (array) apply_filters('infosub_error_messages', array(
            'state'          => __('Your sign-in was interrupted or took too long. Please try again.', 'infosub'),
            'provider'       => __('The sign-in service reported a problem. Please try again.', 'infosub'),
            'token_invalid'  => __('We could not verify your sign-in. Please try again.', 'infosub'),
            'session'        => __('We could not start your session. Please make sure cookies are enabled and try again.', 'infosub'),
            'config'         => __('Sign-in is temporarily unavailable.', 'infosub'),
        ));
    }

    /** The error code from the URL, only if it is one we know. */
    private static function current_code()
    {
        if (empty($_GET['infosub_error']) || !is_string($_GET['infosub_error'])) {
            return '';
        }
        $code     = sanitize_key(wp_unslash($_GET['infosub_error']));
        $messages = self::messages();
        return isset($messages[$code]) ? $code : '';
    }

    public static function maybe_nocache()
    {
        if (self::current_code() === '') {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
    }

    public static function render()
    {
        $code = self::current_code();
        if ($code === '') {
            return;
        }
        $messages = self::messages();

        // "Try again" goes back to where the visitor was, except when sign-in is misconfigured.
        $retry = '';
        if ($code !== 'config') {
            $here  = is_singular() ? get_permalink() : home_url('/');
            $retry = Infosub_Auth::login_url($here);
        }
        ?>
        <div id="infosub-notice" class="infosub-notice" role="alert">
            <span class="infosub-notice__text"><?php echo esc_html($messages[$code]); ?></span>
            <?php if ($retry !== '') : ?>
                <a class="infosub-notice__retry" href="<?php echo esc_url($retry); ?>"><?php esc_html_e('Try again', 'infosub'); ?></a>
            <?php endif; ?>
            <button type="button" class="infosub-notice__close" aria-label="<?php esc_attr_e('Dismiss', 'infosub'); ?>">&times;</button>
        </div>
        <style>
            .infosub-notice{position:fixed;left:50%;bottom:1.25rem;transform:translateX(-50%);z-index:99999;display:flex;align-items:center;gap:.75rem;max-width:min(36rem,calc(100% - 2rem));padding:.75rem 1rem;background:#fff;color:#1d2327;border:1px solid #c3c4c7;border-left:4px solid #d63638;border-radius:4px;box-shadow:0 2px 12px rgba(0,0,0,.2);font:14px/1.4 system-ui,sans-serif}
            .infosub-notice a{color:inherit;font-weight:600;text-decoration:underline}
            .infosub-notice__close{margin-left:auto;background:none;border:0;color:inherit;font-size:1.25rem;line-height:1;cursor:pointer}
            @media (prefers-color-scheme:dark){.infosub-notice{background:#1d2327;color:#f0f0f1;border-color:#50575e;border-left-color:#d63638}}
        </style>
        <script>
            (function () {
                var box = document.getElementById('infosub-notice');
                if (!box) { return; }
                var close = box.querySelector('.infosub-notice__close');
                if (close) { close.addEventListener('click', function () { box.remove(); }); }
                // Remove the error from the address bar so a reload does not show it again.
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.delete('infosub_error');
                    window.history.replaceState(null, '', url.toString());
                } catch (e) {}
            })();
        </script>
        <?php
    }
}
