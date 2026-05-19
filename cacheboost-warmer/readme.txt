=== CacheBoost Warmer ===
Contributors: cacheboost
Tags: cache, cache warming, performance, WooCommerce, WP Rocket
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: MIT

Notifies the CacheBoost API after cache purge events to trigger targeted or full cache warming.

== Description ==

CacheBoost Warmer automatically triggers a cache warm-up whenever a purge or invalidation occurs in WordPress, keeping your pages served from cache at all times.

**Features:**

* **Smart mode** — resolves purged post/term URLs and triggers a targeted warm (only the affected pages).
* **Full Only mode** — always triggers a full site warming.
* **Deduplication** — multiple purge events within the same HTTP request produce a single API call.
* **Non-blocking** — uses `wp_remote_post()` with `blocking => false`; never delays a WordPress request.
* **WooCommerce** — warms product and category pages on save; optional stock-change warming.
* **Cache plugin integrations** — WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache.
* **Multisite** — each sub-site has its own settings and API key.

== Installation ==

1. Upload the `cacheboost-warmer` folder to `wp-content/plugins/`.
2. Activate the plugin via **Plugins → Installed Plugins**.
3. Go to **Settings → CacheBoost** and enter your API key.

== Configuration ==

= Step 1 — Get your API key =

1. Log in to [app.cacheboost.io](https://app.cacheboost.io).
2. Go to **API Keys** and click **New key**.
3. Select the `boosts:write` scope (minimum required).
4. Copy the generated key (format `cb_live_…`).

= Step 2 — Configure the plugin =

Go to **Settings → CacheBoost**:

* **Enable** — master on/off switch.
* **API Key** — paste your `cb_live_...` key.
* **Warming Mode** — Smart (recommended) or Full Only.
* **Stock Warming** — (WooCommerce only) warm product pages after stock changes.
* **Test Connection** — validate your API key without leaving the admin.

== Cache plugin support ==

| Plugin | Full purge | Targeted purge |
|---|---|---|
| WP Rocket | ✓ | ✓ |
| W3 Total Cache | ✓ | ✓ |
| LiteSpeed Cache | ✓ | ✓ |
| WP Super Cache | ✓ | — (falls back to native WP hooks) |

== Frequently Asked Questions ==

= Is any data sent before I configure the plugin? =

No. The plugin is completely silent until both **Enable** is checked and a valid API key has been entered.

= Does this plugin slow down my WordPress admin? =

No. All API calls are fire-and-forget (`blocking => false`). The CacheBoost API never blocks a WordPress request.

= Does it work on multisite? =

Yes, but do not network-activate. Each sub-site should have its own settings and API key.

= What is sent to the CacheBoost API? =

Only the site URL, the list of page URLs to warm (Smart mode), and a timestamp. No personal user data is ever transmitted.

== Changelog ==

= 1.0.0 =
* Initial release.

== External Services ==

This plugin sends data to the CacheBoost API (https://api.cacheboost.io) to trigger cache warming jobs after a purge event. Data transmitted includes the site URL and the list of page URLs to warm. No personal user data is sent.

Requests are only made when:
- The plugin is enabled in Settings > CacheBoost
- A valid API key (format: cb_live_...) has been configured

CacheBoost Terms of Service: https://cacheboost.io/terms
CacheBoost Privacy Policy:   https://cacheboost.io/privacy
