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
    $endpoint = $options['api_endpoint'] ?? 'https://api.cacheboost.io';

    if (!\CacheBoost\ApiClient::is_valid_api_key($api_key)) {
        wp_send_json_error(['message' => __('Invalid API key format.', 'cacheboost-warmer')]);
    }

    $result = \CacheBoost\ApiClient::ping($api_key, $endpoint);

    if ($result['success']) {
        wp_send_json_success(['message' => __('Connection successful.', 'cacheboost-warmer')]);
    } else {
        wp_send_json_error(['message' => sprintf(
            /* translators: %s: error detail */
            __('Connection failed: %s', 'cacheboost-warmer'),
            $result['message'] ?? ''
        )]);
    }
}

function cacheboost_sanitize_options(array $input): array {
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

    $output['mode'] = in_array($input['mode'] ?? '', ['smart', 'full_only'], true)
        ? $input['mode']
        : 'smart';

    $output['stock_warming'] = !empty($input['stock_warming']);

    $output['api_endpoint'] = esc_url_raw(
        !empty($input['api_endpoint']) ? $input['api_endpoint'] : 'https://api.cacheboost.io'
    );

    return $output;
}

function cacheboost_render_settings_page(): void {
    if (!current_user_can('manage_options')) return;

    $options = get_option('cacheboost_options', []);
    $nonce   = wp_create_nonce('cacheboost_test_nonce');
    $mode    = $options['mode'] ?? 'smart';
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
                            <?php esc_html_e('Format: cb_live_... — never shared with third parties.', 'cacheboost-warmer'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Warming Mode', 'cacheboost-warmer'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="cacheboost_options[mode]" value="smart"
                                    <?php checked($mode, 'smart'); ?> />
                                <?php esc_html_e('Smart — targeted by URL (recommended)', 'cacheboost-warmer'); ?>
                            </label><br>
                            <label>
                                <input type="radio" name="cacheboost_options[mode]" value="full_only"
                                    <?php checked($mode, 'full_only'); ?> />
                                <?php esc_html_e('Full Only — always warm the entire site', 'cacheboost-warmer'); ?>
                            </label>
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

                <tr>
                    <th scope="row">
                        <label for="cb_api_endpoint"><?php esc_html_e('API Endpoint', 'cacheboost-warmer'); ?></label>
                    </th>
                    <td>
                        <input type="url" id="cb_api_endpoint" name="cacheboost_options[api_endpoint]"
                               value="<?php echo esc_attr($options['api_endpoint'] ?? 'https://api.cacheboost.io'); ?>"
                               class="regular-text" />
                        <p class="description">
                            <?php esc_html_e('Do not change unless instructed by CacheBoost support.', 'cacheboost-warmer'); ?>
                        </p>
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
    })();
    </script>
    <?php
}
