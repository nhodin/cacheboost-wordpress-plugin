<?php
if (!defined('ABSPATH')) exit;

function cacheboost_render_history_page(): void {
    if (!current_user_can('manage_options')) return;

    $options = get_option('cacheboost_options', []);
    $api_key = $options['api_key'] ?? '';

    if (!\CacheBoost\ApiClient::is_valid_api_key($api_key)) {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('CacheBoost — Run History', 'cacheboost-warmer'); ?></h1>
            <div class="notice notice-warning inline"><p>
                <?php echo wp_kses(
                    sprintf(
                        /* translators: %s: link to settings page */
                        __('Please <a href="%s">configure your API key</a> first.', 'cacheboost-warmer'),
                        esc_url(admin_url('admin.php?page=cacheboost'))
                    ),
                    ['a' => ['href' => []]]
                ); ?>
            </p></div>
        </div>
        <?php
        return;
    }

    $result = \CacheBoost\ApiClient::get_runs();
    $runs   = $result['success'] ? ($result['runs'] ?? []) : [];
    $error  = $result['success'] ? null : ($result['message'] ?? '');

    $status_colors = [
        'pending' => '#ffe082',
        'running' => '#90caf9',
        'done'    => '#a5d6a7',
        'failed'  => '#ef9a9a',
    ];
    $type_colors = [
        'inline' => '#9fa8da',
        'full'   => '#b39ddb',
    ];
    ?>
    <div class="wrap">
        <?php cacheboost_render_admin_header(__('CacheBoost — Run History', 'cacheboost-warmer')); ?>
        <div style="margin-bottom:16px;">
            <a href="<?php echo esc_url(add_query_arg([])); ?>" class="button button-secondary">
                ↻ <?php esc_html_e('Refresh', 'cacheboost-warmer'); ?>
            </a>
        </div>

        <?php if ($error !== null): ?>
            <div class="notice notice-error inline"><p><?php echo esc_html($error); ?></p></div>
        <?php elseif (empty($runs)): ?>
            <div class="notice notice-info inline"><p><?php esc_html_e('No runs found yet. Runs appear here after a cache purge triggers warming.', 'cacheboost-warmer'); ?></p></div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th style="width:70px"><?php esc_html_e('ID', 'cacheboost-warmer'); ?></th>
                        <th style="width:80px"><?php esc_html_e('Type', 'cacheboost-warmer'); ?></th>
                        <th style="width:90px"><?php esc_html_e('Status', 'cacheboost-warmer'); ?></th>
                        <th style="width:130px"><?php esc_html_e('Date', 'cacheboost-warmer'); ?></th>
                        <th style="width:60px"><?php esc_html_e('URLs', 'cacheboost-warmer'); ?></th>
                        <th style="width:80px"><?php esc_html_e('Region', 'cacheboost-warmer'); ?></th>
                        <th style="width:80px"><?php esc_html_e('Hit Rate', 'cacheboost-warmer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runs as $run):
                        $run_id    = (int) ($run['id'] ?? 0);
                        $status    = $run['status'] ?? 'unknown';
                        $type      = $run['_type'] ?? 'full';
                        $date      = !empty($run['created_at']) ? date_i18n('d/m/Y H:i', strtotime($run['created_at'])) : '—';
                        $url_count = isset($run['source_urls']) ? count((array) $run['source_urls']) : '—';
                        $regions   = !empty($run['run_region']) ? (array) $run['run_region'] : [];
                        $summary   = $run['summary'] ?? null;

                        $hit_rate = '—';
                        if (is_array($summary) && isset($summary['hit'], $summary['miss'])) {
                            $total_w = (int) $summary['hit'] + (int) $summary['miss'];
                            if ($total_w > 0) {
                                $hit_rate = round((int) $summary['hit'] / $total_w * 100) . '%';
                            }
                        }

                        $status_bg = $status_colors[$status] ?? '#e0e0e0';
                        $type_bg   = $type_colors[$type] ?? '#e0e0e0';
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url('https://app.cache-boost.com/boosts/run/' . $run_id); ?>" target="_blank" rel="noopener">
                                #<?php echo $run_id; ?>
                            </a>
                        </td>
                        <td>
                            <span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;background:<?php echo esc_attr($type_bg); ?>">
                                <?php echo esc_html(strtoupper($type)); ?>
                            </span>
                        </td>
                        <td>
                            <span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;background:<?php echo esc_attr($status_bg); ?>">
                                <?php echo esc_html($status); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($date); ?></td>
                        <td><?php echo esc_html($url_count); ?></td>
                        <td><?php echo esc_html(!empty($regions) ? implode(', ', $regions) : '—'); ?></td>
                        <td><?php echo esc_html($hit_rate); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p style="margin-top:20px;">
            <a href="https://app.cache-boost.com" target="_blank" rel="noopener">
                <?php esc_html_e('View full history in CacheBoost dashboard →', 'cacheboost-warmer'); ?>
            </a>
        </p>

        <?php cacheboost_render_last_flush(); ?>
    </div>
    <?php
}
