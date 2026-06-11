<?php
if (!defined('ABSPATH')) exit;

add_action('wp_dashboard_setup', function () {
    if (!current_user_can('manage_options')) return;
    if (!(new \CacheBoost\Config())->is_dashboard_widget_enabled()) return;
    wp_add_dashboard_widget(
        'cacheboost_dashboard_widget',
        __('CacheBoost — Last Warming Run', 'cacheboost-warmer'),
        'cacheboost_render_dashboard_widget'
    );
});

function cacheboost_render_dashboard_widget(): void {
    if (!\CacheBoost\ApiClient::can_notify()) {
        printf(
            '<p style="color:#646970">%s <a href="%s">%s</a></p>',
            esc_html__('Configure your API key to see warming stats.', 'cacheboost-warmer'),
            esc_url(admin_url('admin.php?page=cacheboost')),
            esc_html__('Go to settings →', 'cacheboost-warmer')
        );
        return;
    }

    // Blocking API calls are cached so the wp-admin dashboard never waits on
    // the API more than once per interval.
    $data = get_transient('cacheboost_widget_data');
    if (!is_array($data)) {
        $result = \CacheBoost\ApiClient::get_runs();
        $latest = (!empty($result['success']) && !empty($result['runs'])) ? $result['runs'][0] : null;

        // Fetch rich stats only for completed runs
        $stats = null;
        if ($latest && ($latest['status'] ?? '') === 'done' && (int) ($latest['id'] ?? 0) > 0) {
            $detail = \CacheBoost\ApiClient::get_run((int) $latest['id']);
            if ($detail['success']) {
                $stats = $detail['run']['stats'] ?? null;
            }
        }

        $data = ['latest' => $latest, 'stats' => $stats];
        set_transient('cacheboost_widget_data', $data, 2 * MINUTE_IN_SECONDS);
    }

    if (empty($data['latest'])) {
        echo '<p style="color:#646970">' . esc_html__('No warming runs yet.', 'cacheboost-warmer') . '</p>';
        return;
    }

    $latest = $data['latest'];
    $stats  = $data['stats'];
    $run_id = (int) ($latest['id'] ?? 0);
    $status = $latest['status'] ?? 'unknown';

    $type       = $latest['_type'] ?? 'full';
    $date       = !empty($latest['created_at']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($latest['created_at'])) : '—';
    $regions    = !empty($latest['run_region']) ? implode(', ', (array) $latest['run_region']) : null;

    $status_styles = [
        'done'    => 'background:#d1fae5;color:#065f46',
        'running' => 'background:#dbeafe;color:#1e40af',
        'failed'  => 'background:#fee2e2;color:#991b1b',
        'pending' => 'background:#fef9c3;color:#854d0e',
    ];
    $status_style = $status_styles[$status] ?? 'background:#f3f4f6;color:#374151';
    $type_style   = $type === 'full' ? 'background:#ede9fe;color:#5b21b6' : 'background:#e0e7ff;color:#3730a3';

    ?>
    <div style="font-size:13px">

        <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;flex-wrap:wrap">
            <span style="padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;<?php echo esc_attr($status_style); ?>">
                <?php echo esc_html(strtoupper($status)); ?>
            </span>
            <span style="padding:2px 9px;border-radius:12px;font-size:11px;font-weight:600;<?php echo esc_attr($type_style); ?>">
                <?php echo $type === 'full' ? esc_html__('FULL SITE', 'cacheboost-warmer') : esc_html__('SMART', 'cacheboost-warmer'); ?>
            </span>
            <span style="color:#6b7280;font-size:12px;margin-left:auto"><?php echo esc_html($date); ?></span>
        </div>

        <?php if ($stats !== null && ($stats['total'] ?? 0) > 0): ?>

            <?php
            $total     = (int)  $stats['total'];
            $hits      = (int)  $stats['hits'];
            $misses    = (int)  $stats['misses'];
            $hit_rate  = $stats['hit_rate'] !== null ? (float) $stats['hit_rate'] : null;
            $avg_hit   = $stats['avg_ms_hit']  !== null ? (int) $stats['avg_ms_hit']  : null;
            $avg_miss  = $stats['avg_ms_miss'] !== null ? (int) $stats['avg_ms_miss'] : null;
            ?>

            <!-- URLs warmed -->
            <div style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px">
                    <span style="color:#374151;font-weight:600"><?php esc_html_e('URLs warmed', 'cacheboost-warmer'); ?></span>
                    <span style="font-size:20px;font-weight:700;color:#111827"><?php echo esc_html(number_format_i18n($total)); ?></span>
                </div>
            </div>

            <?php if ($hit_rate !== null): ?>
            <!-- Cache hit ratio -->
            <div style="margin-bottom:14px">
                <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px">
                    <span style="color:#374151;font-weight:600"><?php esc_html_e('Cache hit ratio', 'cacheboost-warmer'); ?></span>
                    <span style="font-size:20px;font-weight:700;color:<?php echo $hit_rate >= 80 ? '#065f46' : ($hit_rate >= 50 ? '#854d0e' : '#991b1b'); ?>">
                        <?php echo esc_html($hit_rate); ?>%
                    </span>
                </div>
                <div style="background:#e5e7eb;border-radius:4px;height:8px;overflow:hidden">
                    <div style="height:8px;border-radius:4px;width:<?php echo esc_attr($hit_rate); ?>%;background:<?php echo $hit_rate >= 80 ? '#10b981' : ($hit_rate >= 50 ? '#f59e0b' : '#ef4444'); ?>;transition:width .3s"></div>
                </div>
                <div style="display:flex;justify-content:space-between;margin-top:4px;font-size:11px;color:#6b7280">
                    <?php // translators: %d: number of cache hits ?>
                    <span><?php echo esc_html(sprintf(__('%d HIT', 'cacheboost-warmer'), $hits)); ?></span>
                    <?php // translators: %d: number of cache misses ?>
                    <span><?php echo esc_html(sprintf(__('%d MISS', 'cacheboost-warmer'), $misses)); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($avg_hit !== null || $avg_miss !== null): ?>
            <!-- Response times -->
            <div style="margin-bottom:14px">
                <span style="color:#374151;font-weight:600;display:block;margin-bottom:8px"><?php esc_html_e('Avg response time', 'cacheboost-warmer'); ?></span>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 10px;text-align:center">
                        <div style="font-size:10px;font-weight:600;color:#15803d;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px">
                            <?php esc_html_e('HIT', 'cacheboost-warmer'); ?>
                        </div>
                        <div style="font-size:18px;font-weight:700;color:#14532d">
                            <?php echo $avg_hit !== null ? esc_html($avg_hit) . '<span style="font-size:11px;font-weight:400">ms</span>' : '—'; ?>
                        </div>
                    </div>
                    <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:8px 10px;text-align:center">
                        <div style="font-size:10px;font-weight:600;color:#c2410c;text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px">
                            <?php esc_html_e('MISS', 'cacheboost-warmer'); ?>
                        </div>
                        <div style="font-size:18px;font-weight:700;color:#7c2d12">
                            <?php echo $avg_miss !== null ? esc_html($avg_miss) . '<span style="font-size:11px;font-weight:400">ms</span>' : '—'; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        <?php elseif ($status === 'running'): ?>
            <p style="color:#1e40af"><?php esc_html_e('Warming in progress…', 'cacheboost-warmer'); ?></p>
        <?php elseif ($status === 'failed'): ?>
            <p style="color:#991b1b"><?php esc_html_e('Last run failed. Check the run history for details.', 'cacheboost-warmer'); ?></p>
        <?php else: ?>
            <p style="color:#6b7280"><?php esc_html_e('No stats available for this run.', 'cacheboost-warmer'); ?></p>
        <?php endif; ?>

        <?php if ($regions): ?>
        <div style="margin-bottom:12px;font-size:12px;color:#6b7280">
            <?php esc_html_e('Region:', 'cacheboost-warmer'); ?> <strong><?php echo esc_html($regions); ?></strong>
        </div>
        <?php endif; ?>

        <div style="border-top:1px solid #e5e7eb;padding-top:10px;display:flex;justify-content:space-between;align-items:center">
            <a href="<?php echo esc_url(admin_url('admin.php?page=cacheboost-history')); ?>" style="font-size:12px;color:#2271b1;text-decoration:none">
                <?php esc_html_e('Full history →', 'cacheboost-warmer'); ?>
            </a>
            <?php if ($run_id): ?>
            <a href="<?php echo esc_url('https://app.cache-boost.com/boosts/run/' . $run_id); ?>" target="_blank" rel="noopener" style="font-size:12px;color:#6b7280;text-decoration:none">
                #<?php echo absint($run_id); ?> ↗
            </a>
            <?php endif; ?>
        </div>

    </div>
    <?php
}
