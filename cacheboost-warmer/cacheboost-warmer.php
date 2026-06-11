<?php
/**
 * Plugin Name: CacheBoost Warmer
 * Plugin URI:  https://www.cache-boost.com
 * Description: Notifies CacheBoost API after cache purge events to trigger targeted or full cache warming.
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author:      CacheBoost
 * Author URI:  https://www.cache-boost.com
 * License:     MIT
 * Text Domain: cacheboost-warmer
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('CACHEBOOST_VERSION', '1.0.0');
define('CACHEBOOST_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-logger.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-config.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-api-client.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-event-buffer.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-hooks-native.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-hooks-cache-plugins.php';
require_once CACHEBOOST_PLUGIN_DIR . 'includes/class-hooks-woocommerce.php';
require_once CACHEBOOST_PLUGIN_DIR . 'admin/menu.php';
require_once CACHEBOOST_PLUGIN_DIR . 'admin/settings-page.php';
require_once CACHEBOOST_PLUGIN_DIR . 'admin/history-page.php';
require_once CACHEBOOST_PLUGIN_DIR . 'admin/dashboard-widget.php';

register_activation_hook(__FILE__, function () {
    if (!get_option('cacheboost_notice_dismissed')) {
        update_option('cacheboost_notice_dismissed', '0');
    }
});

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (get_option('cacheboost_notice_dismissed') === '1') return;
    $options = get_option('cacheboost_options', []);
    if (!empty($options['api_key'])) return;

    $settings_url = admin_url('admin.php?page=cacheboost');
    $message = sprintf(
        /* translators: %s: link to CacheBoost settings page */
        __('CacheBoost Warmer is installed. <a href="%s">Configure your API key</a> to start warming your cache.', 'cacheboost-warmer'),
        esc_url($settings_url)
    );
    printf(
        '<div class="notice notice-info is-dismissible" id="cacheboost-setup-notice"><p>%s</p></div>',
        wp_kses($message, ['a' => ['href' => []]])
    );
});

add_action('wp_ajax_cacheboost_dismiss_notice', function () {
    check_ajax_referer('cacheboost_dismiss_notice', 'nonce');
    if (current_user_can('manage_options')) {
        update_option('cacheboost_notice_dismissed', '1');
    }
    wp_die();
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function (array $links): array {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('admin.php?page=cacheboost')),
        __('Settings', 'cacheboost-warmer')
    );
    array_unshift($links, $settings_link);
    return $links;
});

add_action('admin_footer', function () {
    if (!current_user_can('manage_options')) return;
    if (get_option('cacheboost_notice_dismissed') === '1') return;
    $options = get_option('cacheboost_options', []);
    if (!empty($options['api_key'])) return;
    ?>
    <script>
    (function($){
        $(document).on('click', '#cacheboost-setup-notice .notice-dismiss', function(){
            $.post(ajaxurl, {
                action: 'cacheboost_dismiss_notice',
                nonce: '<?php echo esc_js(wp_create_nonce('cacheboost_dismiss_notice')); ?>'
            });
        });
    })(jQuery);
    </script>
    <?php
});

add_action('init', function () {
    $config = new CacheBoost\Config();
    $buffer = new CacheBoost\EventBuffer();
    (new CacheBoost\HooksNative($buffer, $config))->register();
    (new CacheBoost\HooksCachePlugins($buffer, $config))->register();
    if (class_exists('WooCommerce')) {
        (new CacheBoost\HooksWooCommerce($buffer))->register();
    }
});

function cacheboost_render_admin_header(string $title): void {
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0 14px;border-bottom:1px solid #dcdcde;margin-bottom:20px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 160" width="24" height="24" aria-hidden="true"><path fill="#14532d" d="m80 14 5 12H75z"/><path fill="#166534" d="m70 26 10 13 10-13v8L80 47 70 34Z"/><path fill="#15803d" d="m64 47 16 14 16-14v9L80 70 64 56Z"/><path fill="#16a34a" d="m57 70 23 15 23-15v9L80 94 57 79Z"/><path fill="#22c55e" d="m50 94 30 16 30-16v9l-30 16-30-16Z"/><path fill="#4ade80" d="m44 119 36 17 36-17-1 9-35 17-35-17Z"/><path stroke="#22c55e" stroke-linecap="round" stroke-width="3.5" d="M80 145v9"/></svg>
            <span style="font-size:18px;font-weight:600;color:#1d2327"><?php echo esc_html($title); ?></span>
            <span style="font-size:11px;color:#646970;background:#f6f7f7;border:1px solid #dcdcde;padding:1px 8px;border-radius:10px">
                v<?php echo esc_html(CACHEBOOST_VERSION); ?>
            </span>
        </div>
        <nav style="display:flex;align-items:center;gap:4px;font-size:13px;">
            <a href="https://app.cache-boost.com" target="_blank" rel="noopener"
               style="padding:4px 10px;border-radius:4px;text-decoration:none;color:#2271b1;font-weight:500">
                <?php esc_html_e('Dashboard', 'cacheboost-warmer'); ?>
            </a>
            <span style="color:#dcdcde">|</span>
            <a href="https://cache-boost.com/support" target="_blank" rel="noopener"
               style="padding:4px 10px;border-radius:4px;text-decoration:none;color:#2271b1;font-weight:500">
                <?php esc_html_e('Support', 'cacheboost-warmer'); ?>
            </a>
            <span style="color:#dcdcde">|</span>
            <a href="mailto:contact@cache-boost.com"
               style="padding:4px 10px;border-radius:4px;text-decoration:none;color:#2271b1;font-weight:500">
                <?php esc_html_e('Contact', 'cacheboost-warmer'); ?>
            </a>
        </nav>
    </div>
    <?php
}

function cacheboost_render_last_flush(): void {
    $flush = get_option('cacheboost_last_flush');
    ?>
    <div style="margin-top:32px;border-top:1px solid #dcdcde;padding-top:16px;">
        <h3 style="font-size:13px;font-weight:600;color:#1d2327;margin:0 0 10px">
            <?php esc_html_e('Last warming event', 'cacheboost-warmer'); ?>
        </h3>
        <?php if (empty($flush)): ?>
            <p class="description"><?php esc_html_e('No warming event recorded yet.', 'cacheboost-warmer'); ?></p>
        <?php else:
            $mode  = $flush['mode'] ?? 'smart';
            $ts    = $flush['ts']   ?? 0;
            $urls  = $flush['urls'] ?? [];
            $label = $mode === 'full'
                ? __('Full site warm', 'cacheboost-warmer')
                : __('Smart warm', 'cacheboost-warmer');
            $badge_bg = $mode === 'full' ? '#b39ddb' : '#9fa8da';
        ?>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                <span style="background:<?php echo esc_attr($badge_bg); ?>;color:#fff;padding:2px 9px;border-radius:3px;font-size:11px;font-weight:600">
                    <?php echo esc_html($label); ?>
                </span>
                <span style="color:#646970;font-size:12px">
                    <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts)); ?>
                </span>
            </div>
            <?php if ($mode === 'full'): ?>
                <p class="description"><?php esc_html_e('Entire site was queued for warming.', 'cacheboost-warmer'); ?></p>
            <?php elseif (empty($urls)): ?>
                <p class="description"><?php esc_html_e('No URLs recorded.', 'cacheboost-warmer'); ?></p>
            <?php else: ?>
                <ul style="margin:0;padding:0;list-style:none;max-height:160px;overflow-y:auto;border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7;">
                    <?php foreach ($urls as $url): ?>
                        <li style="padding:4px 10px;border-bottom:1px solid #dcdcde;font-size:12px;font-family:monospace;word-break:break-all">
                            <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener" style="color:#2271b1;text-decoration:none">
                                <?php echo esc_html($url); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="description" style="margin-top:6px">
                    <?php
                    /* translators: %d: number of URLs */
                    echo esc_html(sprintf(_n('%d URL warmed', '%d URLs warmed', count($urls), 'cacheboost-warmer'), count($urls)));
                    ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}
