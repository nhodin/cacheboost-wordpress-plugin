<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    $svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 160"><path fill="#14532d" d="m80 14 5 12H75z"/><path fill="#166534" d="m70 26 10 13 10-13v8L80 47 70 34Z"/><path fill="#15803d" d="m64 47 16 14 16-14v9L80 70 64 56Z"/><path fill="#16a34a" d="m57 70 23 15 23-15v9L80 94 57 79Z"/><path fill="#22c55e" d="m50 94 30 16 30-16v9l-30 16-30-16Z"/><path fill="#4ade80" d="m44 119 36 17 36-17-1 9-35 17-35-17Z"/><path stroke="#22c55e" stroke-linecap="round" stroke-width="3.5" d="M80 145v9"/></svg>';
    $icon = 'data:image/svg+xml;base64,' . base64_encode($svg);

    add_menu_page(
        'CacheBoost',
        'CacheBoost',
        'manage_options',
        'cacheboost',
        'cacheboost_render_settings_page',
        $icon,
        80
    );

    add_submenu_page(
        'cacheboost',
        __('CacheBoost — Settings', 'cacheboost-warmer'),
        __('Settings', 'cacheboost-warmer'),
        'manage_options',
        'cacheboost',
        'cacheboost_render_settings_page'
    );

    add_submenu_page(
        'cacheboost',
        __('CacheBoost — History', 'cacheboost-warmer'),
        __('History', 'cacheboost-warmer'),
        'manage_options',
        'cacheboost-history',
        'cacheboost_render_history_page'
    );
});
