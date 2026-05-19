# CacheBoost Warmer — WordPress Plugin

Automatically triggers a CacheBoost cache warm-up whenever a flush or invalidation occurs in WordPress.

- **Smart mode**: resolves purged post and term URLs and triggers a targeted warm via `POST /v1/warm`.
- **Full Only mode**: always triggers a full site warming.
- **Deduplication**: multiple purge events within the same HTTP request produce a single API call.
- **Non-blocking**: `wp_remote_post()` with `blocking => false`; never delays a WordPress request.
- **WooCommerce**: warms product and category pages on save; opt-in stock-change warming.
- **Cache plugin integrations**: WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache.

---

## Requirements

| Item | Version |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| WooCommerce (optional) | 7.0+ |
| CacheBoost account | Active (Free plan or higher) |

---

## Installation

### Option A — Manual

1. Copy `cacheboost-warmer/` into `wp-content/plugins/` on your WordPress installation.
2. Activate via **Plugins → Installed Plugins**.
3. Go to **Settings → CacheBoost** and enter your API key.

### Option B — Upload zip

1. Zip the `cacheboost-warmer/` folder.
2. Go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate and configure.

---

## Setup in CacheBoost

### Step 1 — Get your API key

1. Log in to [app.cacheboost.io](https://app.cacheboost.io).
2. Go to **API Keys** → **New key**.
3. Select the `boosts:write` scope (required to trigger warm-ups).
4. Copy the generated key (format `cb_live_…`). It is shown only once.

---

## Configuration

Go to **Settings → CacheBoost**.

| Field | Description |
|---|---|
| Enable | Master on/off switch |
| API Key | Your `cb_live_…` key |
| Warming Mode | `Smart` (targeted by URL) or `Full Only` (always warm everything) |
| Stock Warming | *(WooCommerce only)* Warm product pages when stock changes |
| API Endpoint | Pre-filled — do not change unless instructed |
| Test Connection | Validates your API key live |

---

## Observed events

### Native WordPress

| Event | Action |
|---|---|
| `save_post` / `deleted_post` | Targeted warm (permalink + taxonomy URLs) |
| `edit_term` | Targeted warm (term URL) |
| `switch_theme` | Full warming |
| `wp_update_nav_menu` | Full warming |
| `customize_save_after` | Full warming |
| `upgrader_process_complete` | Full warming |

### Cache plugin hooks

| Plugin | Detection | Full purge hook | Targeted hook |
|---|---|---|---|
| WP Rocket | `function_exists('rocket_clean_domain')` | `after_rocket_clean_domain` | `after_rocket_clean_post` |
| W3 Total Cache | `function_exists('w3tc_pgcache_flush')` | `w3tc_flush_all` | `w3tc_flush_post` |
| LiteSpeed Cache | `class_exists('LiteSpeed_Cache_API')` | `litespeed_purge_all` | `litespeed_purge_post` |
| WP Super Cache | `function_exists('wp_cache_clear_cache')` | `wp_cache_cleared` | — |

### WooCommerce

| Event | Action |
|---|---|
| `woocommerce_update_product` / `woocommerce_new_product` | Product URL + category URLs |
| `woocommerce_update_product_variation` | Parent product URL |
| `woocommerce_product_set_stock` *(opt-in)* | Product URL |

---

## Translations

The admin interface is fully translated into:

| Locale | Language |
|---|---|
| `en_US` | English (default) |
| `fr_FR` | French |
| `de_DE` | German |
| `es_ES` | Spanish |
| `nl_NL` | Dutch |

Translation files are in `cacheboost-warmer/languages/`. To add a new language, copy `cacheboost-warmer.pot` to `cacheboost-warmer-{locale}.po`, translate the `msgstr` entries, then compile with:

```bash
msgfmt cacheboost-warmer-{locale}.po -o cacheboost-warmer-{locale}.mo
```

Or use [Poedit](https://poedit.net/) to edit and compile in one step.

---

## File structure

```
cacheboost-warmer/
├── cacheboost-warmer.php              # Plugin bootstrap and declaration
├── uninstall.php                      # Cleanup on plugin deletion
├── includes/
│   ├── class-config.php               # Reads WP options
│   ├── class-api-client.php           # HTTP calls to CacheBoost API
│   ├── class-event-buffer.php         # Deduplication + shutdown flush
│   ├── class-hooks-native.php         # Native WordPress hooks
│   ├── class-hooks-cache-plugins.php  # WP Rocket, W3TC, LiteSpeed, WP Super Cache
│   └── class-hooks-woocommerce.php    # WooCommerce hooks
├── admin/
│   └── settings-page.php              # Settings > CacheBoost admin page
├── languages/
│   ├── cacheboost-warmer.pot
│   ├── cacheboost-warmer-fr_FR.po
│   ├── cacheboost-warmer-de_DE.po
│   ├── cacheboost-warmer-es_ES.po
│   └── cacheboost-warmer-nl_NL.po
└── readme.txt                         # WordPress.org listing
```

---

## Troubleshooting

**The plugin sends nothing after I save a post.**
Check that **Enable** is checked and the API key is valid (format `cb_live_...`). Use **Test Connection** to validate.

**Full purge events have no effect.**
Make sure the warming mode is not accidentally set to Smart while you expect a full warm. Check that the API key has the `boosts:write` scope.

**WooCommerce stock changes trigger too many calls.**
The EventBuffer deduplicates URLs automatically — only one API call is sent per request regardless of how many line items an order contains. If you still want to disable it, uncheck **Stock Warming**.

**The plugin conflicts with my cache plugin.**
Cache plugin hooks are registered only if the corresponding plugin is active. If you see double calls, check which hooks are firing with a plugin like Query Monitor.

---

## License

MIT
