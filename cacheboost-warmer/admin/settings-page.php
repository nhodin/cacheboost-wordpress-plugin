<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_options_page(
        __('CacheBoost Warmer', 'cacheboost-warmer'),
        'CacheBoost',
        'manage_options',
        'cacheboost',
        'cacheboost_render_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('cacheboost_settings', 'cacheboost_options', [
        'sanitize_callback' => 'cacheboost_sanitize_options',
    ]);
});

add_action('wp_ajax_cacheboost_test_connection', 'cacheboost_ajax_test_connection');

function cacheboost_ajax_test_connection(): void {
    check_ajax_referer('cacheboost_test_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die();

    $options  = get_option('cacheboost_options', []);
    $api_key  = $options['api_key'] ?? '';
    $endpoint = 'https://api.cache-boost.com';

    if (!\CacheBoost\ApiClient::is_valid_api_key($api_key)) {
        wp_send_json_error(['message' => __('Invalid API key format.', 'cacheboost-warmer')]);
    }

    $result = \CacheBoost\ApiClient::ping($api_key, $endpoint);

    if ($result['success']) {
        $scopes   = $result['scopes'] ?? [];
        $required = ['sites:read', 'boosts:read', 'boosts:write', 'runs:read'];
        $missing  = array_diff($required, $scopes);

        if (!empty($missing)) {
            wp_send_json_error(['message' => sprintf(
                /* translators: %s: comma-separated list of missing API scopes */
                __('Connected, but missing required scopes: %s. Please regenerate your API key with the correct permissions.', 'cacheboost-warmer'),
                implode(', ', $missing)
            )]);
        }

        $home_domain = (string) preg_replace('#^https?://#', '', rtrim(home_url(), '/'));
        $matched     = null;
        foreach ($result['sites'] ?? [] as $site) {
            if (rtrim((string) ($site['domain'] ?? ''), '/') === $home_domain) {
                $matched = $site;
                break;
            }
        }

        if ($matched === null) {
            wp_send_json_error(['message' => sprintf(
                /* translators: %s: current site domain */
                __('Connected, but %s is not registered in your CacheBoost account. Please add your domain at app.cache-boost.com.', 'cacheboost-warmer'),
                $home_domain
            )]);
        }

        if (empty($matched['validated'])) {
            wp_send_json_error(['message' => sprintf(
                /* translators: %s: current site domain */
                __('Connected, but %s is not yet validated. Please complete domain validation in your CacheBoost account.', 'cacheboost-warmer'),
                $home_domain
            )]);
        }

        // Persist site_id and available regions so send() and the form work without re-testing
        $opts = get_option('cacheboost_options', []);
        $opts['available_regions'] = $result['regions'] ?? [];
        $opts['site_id'] = $matched['id'] ?? null;
        update_option('cacheboost_options', $opts);

        wp_send_json_success([
            'message' => __('Connection successful. Scopes and site access verified.', 'cacheboost-warmer'),
            'regions' => $result['regions'] ?? [],
        ]);
    } else {
        wp_send_json_error(['message' => sprintf(
            /* translators: %s: error detail */
            __('Connection failed: %s', 'cacheboost-warmer'),
            $result['message'] ?? ''
        )]);
    }
}

add_action('wp_ajax_cacheboost_clear_logs', 'cacheboost_ajax_clear_logs');

function cacheboost_ajax_clear_logs(): void {
    check_ajax_referer('cacheboost_clear_logs_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die();
    \CacheBoost\Logger::clear();
    wp_send_json_success();
}

function cacheboost_sanitize_options(array $input): array {
    $old    = get_option('cacheboost_options', []);
    $output = [];

    $output['enabled'] = !empty($input['enabled']);

    $key = sanitize_text_field($input['api_key'] ?? '');
    if (preg_match('/^cb_live_[a-zA-Z0-9]+$/', $key)) {
        $output['api_key'] = $key;
    } else {
        $output['api_key'] = '';
        add_settings_error(
            'cacheboost_options',
            'invalid_api_key',
            __('Invalid API key. Expected format: cb_live_...', 'cacheboost-warmer'),
            'error'
        );
    }

    $output['smart_enabled'] = !empty($input['smart_enabled']);
    $output['full_enabled']  = !empty($input['full_enabled']);

    $output['stock_warming'] = !empty($input['stock_warming']);

    $output['api_endpoint'] = 'https://api.cache-boost.com';

    // Log changed fields
    $changes = [];
    if (($old['enabled'] ?? false) !== $output['enabled']) {
        $changes[] = 'enabled: ' . ($output['enabled'] ? 'yes' : 'no');
    }
    if (($old['api_key'] ?? '') !== ($output['api_key'] ?? '')) {
        $key_preview = $output['api_key'] ? substr($output['api_key'], 0, 10) . '...' : '(cleared)';
        $changes[] = 'api_key: ' . $key_preview;
    }
    if (($old['smart_enabled'] ?? true) !== $output['smart_enabled']) {
        $changes[] = 'smart_enabled: ' . ($output['smart_enabled'] ? 'yes' : 'no');
    }
    if (($old['full_enabled'] ?? true) !== $output['full_enabled']) {
        $changes[] = 'full_enabled: ' . ($output['full_enabled'] ? 'yes' : 'no');
    }
    if (($old['stock_warming'] ?? false) !== $output['stock_warming']) {
        $changes[] = 'stock_warming: ' . ($output['stock_warming'] ? 'yes' : 'no');
    }

    $current    = get_option('cacheboost_options', []);
    $key_changed = ($old['api_key'] ?? '') !== ($output['api_key'] ?? '');

    if ($key_changed && !empty($output['api_key'])) {
        // Resolve site_id and regions immediately so send() works without clicking "Test Connection"
        $ping = \CacheBoost\ApiClient::ping($output['api_key'], $output['api_endpoint']);
        if ($ping['success']) {
            $home_domain = (string) preg_replace('#^https?://#', '', rtrim(home_url(), '/'));
            $matched_site = null;
            foreach ($ping['sites'] ?? [] as $s) {
                if (rtrim((string) ($s['domain'] ?? ''), '/') === $home_domain) {
                    $matched_site = $s;
                    break;
                }
            }
            $output['available_regions'] = $ping['regions'] ?? [];
            $output['site_id']           = $matched_site['id'] ?? null;
        }
    } else {
        // Preserve values written by the AJAX test (or a previous save)
        if (array_key_exists('available_regions', $current)) {
            $output['available_regions'] = $current['available_regions'];
        }
        if (array_key_exists('site_id', $current)) {
            $output['site_id'] = $current['site_id'];
        }
    }

    // Sanitize selected regions (format-validate slugs; intersect only when available list is known)
    $submitted = array_values(array_filter((array) ($input['regions'] ?? []), fn($v) => is_string($v) && preg_match('/^[a-z]{2,5}$/', $v)));
    $available = $output['available_regions'] ?? [];
    $output['regions'] = !empty($available)
        ? array_values(array_intersect($submitted, $available))
        : $submitted;

    if (($old['regions'] ?? []) !== $output['regions']) {
        $changes[] = 'regions: ' . (empty($output['regions']) ? '(all)' : implode(', ', $output['regions']));
    }

    if (!empty($changes)) {
        \CacheBoost\Logger::log('settings', 'Settings saved — ' . implode(', ', $changes));
    }

    return $output;
}

function cacheboost_render_settings_page(): void {
    if (!current_user_can('manage_options')) return;

    $options = get_option('cacheboost_options', []);
    $api_key = $options['api_key'] ?? '';

    if (\CacheBoost\ApiClient::is_valid_api_key($api_key)) {
        $me_resp = wp_remote_get('https://api.cache-boost.com/v1/me', [
            'timeout' => 5,
            'headers' => ['Authorization' => 'Bearer ' . $api_key],
        ]);
        if (!is_wp_error($me_resp) && wp_remote_retrieve_response_code($me_resp) === 200) {
            $me = json_decode(wp_remote_retrieve_body($me_resp), true);
            if (!empty($me['regions'])) {
                $options['available_regions'] = $me['regions'];
                update_option('cacheboost_options', $options);
            }
        }
    }

    $nonce             = wp_create_nonce('cacheboost_test_nonce');
    $clear_logs_nonce  = wp_create_nonce('cacheboost_clear_logs_nonce');
    $smart_enabled     = $options['smart_enabled'] ?? true;
    $full_enabled      = $options['full_enabled']  ?? true;
    $available_regions = $options['available_regions'] ?? [];
    $selected_regions  = $options['regions'] ?? [];

    if (empty($selected_regions) && in_array('us', $available_regions, true)) {
        $selected_regions = ['us'];
    }

    $region_labels = ['fr' => 'France', 'us' => 'USA', 'eu' => 'Europe', 'as' => 'Asia'];
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('CacheBoost Warmer', 'cacheboost-warmer'); ?></h1>
        <?php settings_errors('cacheboost_options'); ?>

        <form method="post" action="options.php">
            <?php settings_fields('cacheboost_settings'); ?>

            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row"><?php esc_html_e('Enable', 'cacheboost-warmer'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="cacheboost_options[enabled]" value="1"
                                <?php checked(!empty($options['enabled'])); ?> />
                            <?php esc_html_e('Activate cache warming after purge events', 'cacheboost-warmer'); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="cb_api_key"><?php esc_html_e('API Key', 'cacheboost-warmer'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="cb_api_key" name="cacheboost_options[api_key]"
                               value="<?php echo esc_attr($options['api_key'] ?? ''); ?>"
                               class="regular-text" placeholder="cb_live_..." autocomplete="off" />
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: link to CacheBoost profile page */
                                __('Required scopes: <code>sites:read</code>, <code>boosts:read</code>, <code>boosts:write</code>, <code>runs:read</code>. Generate your API key in your <a href="%s" target="_blank" rel="noopener">CacheBoost profile</a>.', 'cacheboost-warmer'),
                                'https://app.cache-boost.com/profile'
                            );
                            ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Warming triggers', 'cacheboost-warmer'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="cacheboost_options[smart_enabled]" value="1"
                                    <?php checked($smart_enabled); ?> />
                                <strong><?php esc_html_e('Smart', 'cacheboost-warmer'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('Warms only the affected URLs when a post or term is saved, or when a cache plugin purges a specific page.', 'cacheboost-warmer'); ?></p>
                            <br>
                            <label>
                                <input type="checkbox" name="cacheboost_options[full_enabled]" value="1"
                                    <?php checked($full_enabled); ?> />
                                <strong><?php esc_html_e('Full', 'cacheboost-warmer'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('Warms the entire site when a global cache flush occurs (theme switch, menu update, plugin upgrade, or full cache purge).', 'cacheboost-warmer'); ?></p>
                        </fieldset>
                    </td>
                </tr>

                <?php if (class_exists('WooCommerce')): ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Stock Warming', 'cacheboost-warmer'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="cacheboost_options[stock_warming]" value="1"
                                <?php checked(!empty($options['stock_warming'])); ?> />
                            <?php esc_html_e('Warm product pages when stock changes (e.g. after an order)', 'cacheboost-warmer'); ?>
                        </label>
                    </td>
                </tr>
                <?php endif; ?>

                <tr id="cb-regions-row">
                    <th scope="row"><?php esc_html_e('Warm Regions', 'cacheboost-warmer'); ?></th>
                    <td>
                        <?php if (empty($available_regions)): ?>
                            <p class="description">
                                <?php esc_html_e('Test the connection above to see available regions.', 'cacheboost-warmer'); ?>
                            </p>
                        <?php else: ?>
                            <fieldset>
                                <?php foreach ($available_regions as $slug):
                                    $label = $region_labels[$slug] ?? strtoupper($slug);
                                ?>
                                <label style="margin-right:16px">
                                    <input type="checkbox"
                                           name="cacheboost_options[regions][]"
                                           value="<?php echo esc_attr($slug); ?>"
                                           <?php checked(in_array($slug, $selected_regions, true)); ?> />
                                    <?php echo esc_html($label); ?>
                                </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="description">
                                <?php esc_html_e('Select which regions will warm your cache. Leave all unchecked to use every available region.', 'cacheboost-warmer'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Test Connection', 'cacheboost-warmer'); ?></th>
                    <td>
                        <button type="button" id="cb-test-connection" class="button">
                            <?php esc_html_e('Test Connection', 'cacheboost-warmer'); ?>
                        </button>
                        <span id="cb-test-result" style="margin-left:10px;vertical-align:middle;"></span>
                    </td>
                </tr>

            </table>

            <?php submit_button(); ?>
        </form>
    </div>

    <?php
    $logs = \CacheBoost\Logger::get_logs();
    $channel_colors = ['buffer' => '#0073aa', 'api' => '#46b450', 'settings' => '#826eb4'];
    ?>
    <hr style="margin-top:24px">
    <details id="cb-logs-accordion">
        <summary style="cursor:pointer;font-weight:600;margin:16px 0 8px;font-size:14px">
            <?php esc_html_e('Activity Log', 'cacheboost-warmer'); ?>
            <?php if (!empty($logs)): ?>
                <span style="font-weight:normal;color:#888;font-size:12px">
                    (<?php echo count($logs); ?>)
                </span>
            <?php endif; ?>
        </summary>

        <?php if (empty($logs)): ?>
            <p class="description"><?php esc_html_e('No activity logged yet.', 'cacheboost-warmer'); ?></p>
        <?php else: ?>
            <button type="button" id="cb-clear-logs" class="button button-small" style="margin-bottom:8px">
                <?php esc_html_e('Clear logs', 'cacheboost-warmer'); ?>
            </button>
            <table class="widefat striped" style="max-width:960px">
                <thead>
                    <tr>
                        <th style="width:150px"><?php esc_html_e('Time', 'cacheboost-warmer'); ?></th>
                        <th style="width:80px"><?php esc_html_e('Channel', 'cacheboost-warmer'); ?></th>
                        <th><?php esc_html_e('Message', 'cacheboost-warmer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $entry):
                    $ch    = $entry['ch'] ?? '';
                    $color = $channel_colors[$ch] ?? '#888';
                    $lvl   = $entry['lvl'] ?? 'info';
                ?>
                    <tr<?php echo $lvl === 'error' ? ' style="color:#cc0000"' : ''; ?>>
                        <td style="white-space:nowrap"><?php echo esc_html(wp_date('Y-m-d H:i:s', $entry['ts'])); ?></td>
                        <td>
                            <span style="background:<?php echo esc_attr($color); ?>;color:#fff;padding:1px 7px;border-radius:3px;font-size:11px">
                                <?php echo esc_html($ch); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($entry['msg'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </details>

    <script>
    (function () {
        var btn    = document.getElementById('cb-test-connection');
        var result = document.getElementById('cb-test-result');

        btn.addEventListener('click', function () {
            btn.disabled     = true;
            result.textContent = <?php echo wp_json_encode(__('Testing…', 'cacheboost-warmer')); ?>;
            result.style.color = '';

            var data = new FormData();
            data.append('action', 'cacheboost_test_connection');
            data.append('nonce',  <?php echo wp_json_encode($nonce); ?>);

            fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
            })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                if (json.success) {
                    result.textContent = json.data.message;
                    result.style.color = 'green';
                    if (json.data.regions && json.data.regions.length > 0) {
                        cbRenderRegions(json.data.regions);
                    }
                } else {
                    result.textContent = json.data.message;
                    result.style.color = '#cc0000';
                }
            })
            .catch(function () {
                result.textContent = <?php echo wp_json_encode(__('Request failed.', 'cacheboost-warmer')); ?>;
                result.style.color = '#cc0000';
            })
            .finally(function () {
                btn.disabled = false;
            });
        });

        var clearBtn = document.getElementById('cb-clear-logs');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                clearBtn.disabled = true;
                var data = new FormData();
                data.append('action', 'cacheboost_clear_logs');
                data.append('nonce',  <?php echo wp_json_encode($clear_logs_nonce); ?>);
                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                }).then(function () {
                    document.getElementById('cb-logs-accordion').querySelector('table')?.remove();
                    clearBtn.remove();
                    var acc = document.getElementById('cb-logs-accordion');
                    var p = document.createElement('p');
                    p.className = 'description';
                    p.textContent = <?php echo wp_json_encode(__('No activity logged yet.', 'cacheboost-warmer')); ?>;
                    acc.appendChild(p);
                });
            });
        }

        function cbRenderRegions(available) {
            var labels = <?php echo wp_json_encode($region_labels); ?>;
            // Read the current checked state from the live form (not the PHP-baked saved state)
            var currentChecked = Array.from(document.querySelectorAll('input[name="cacheboost_options[regions][]"]:checked')).map(function(el) { return el.value; });
            var saved = <?php echo wp_json_encode($selected_regions); ?>;
            var selected = currentChecked.length > 0 ? currentChecked : saved;
            var td = document.querySelector('#cb-regions-row td');
            var html = '<fieldset>';
            available.forEach(function (slug) {
                var label = labels[slug] || slug.toUpperCase();
                var checked = selected.indexOf(slug) !== -1 ? ' checked' : '';
                html += '<label style="margin-right:16px">'
                      + '<input type="checkbox" name="cacheboost_options[regions][]" value="' + slug + '"' + checked + '> '
                      + label + '</label>';
            });
            html += '</fieldset>';
            html += '<p class="description">'
                  + <?php echo wp_json_encode(__('Select which regions will warm your cache. Leave all unchecked to use every available region.', 'cacheboost-warmer')); ?>
                  + '</p>';
            td.innerHTML = html;
        }
    })();
    </script>
    <?php
}
