=== CacheBoost Warmer – Cache Preload after Purge ===
Contributors: nhodin
Tags: cache, preload, cache warming, woocommerce, performance
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Re-warms your pages automatically after every cache purge, from the regions your visitors are in. Works with WP Rocket, LiteSpeed and any CDN.

== Description ==

Every cache purge leaves your pages "cold": the first visitor after a purge pays the full origin render time. CacheBoost Warmer closes that gap. Whenever a purge or invalidation occurs in WordPress, it tells the CacheBoost API to re-warm the affected pages, so your cache stays hot and every visitor hits a fast, cached response.

**Free plan:** 500 warmed URLs per month, no credit card. The plugin requires a CacheBoost account ([sign up](https://app.cache-boost.com/register)); warming requests are sent from CacheBoost's servers in 12 regions, not from your hosting.

**Features:**

* **Smart warming** — resolves purged post/term URLs and triggers a targeted warm (only the affected pages).
* **Full warming** — triggers a full site warming on global events (theme switch, plugin upgrade, full cache purge).
* **Deduplication** — multiple purge events within the same HTTP request produce a single API call.
* **Non-blocking** — uses `wp_remote_post()` with `blocking => false`; never delays a WordPress request.
* **WooCommerce** — warms product and category pages on save; optional stock-change warming.
* **Cache plugin integrations** — WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache.
* **Multisite** — each sub-site has its own settings and API key.

== Installation ==

1. Upload the `cacheboost-warmer` folder to `wp-content/plugins/`.
2. Activate the plugin via **Plugins → Installed Plugins**.
3. Go to the **CacheBoost** menu in the admin sidebar and click **Connect to CacheBoost**.

== Configuration ==

= Step 1 — Connect =

In the **CacheBoost** menu, click **Connect to CacheBoost**, then sign in or create a free account and click **Authorize**. Back in WordPress, everything is set up: your site is added to your CacheBoost account and validated, its sitemap is registered, and a Boost is created for full-site warming.

Prefer an API key? Generate one in your [profile](https://app.cache-boost.com/profile) with the scopes `sites:read`, `sites:write`, `boosts:read`, `boosts:write`, `runs:read`, and paste it in the **API Key** field.

= Step 2 — Adjust the settings (optional) =

* **Enable** — master on/off switch.
* **Warming triggers** — Smart (targeted URLs) and/or Full (entire site), each can be toggled independently.
* **Stock Warming** — (WooCommerce only) warm product pages after stock changes.
* **Test Connection** — validate your API key without leaving the admin. If your domain is not yet validated in CacheBoost, the plugin validates it automatically (requires the `sites:write` scope).

== Cache plugin support ==

WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache

== Frequently Asked Questions ==

= Does this plugin slow down my WordPress site? =

No. Warming notifications are fire-and-forget (`blocking => false`) and never delay a request. The dashboard widget and history page fetch stats from the API; widget results are cached for a couple of minutes.

= Does it work on multisite? =

Yes, but do not network-activate. Each sub-site should have its own settings and API key.

= What is sent to the CacheBoost API? =

Only the site URL, the list of page URLs to warm (Smart mode), and a timestamp. No credentials and no personal data are ever transmitted.

= What happens if my API key is invalid or the API is unreachable? =

Nothing breaks on the front end. Because notifications are non-blocking, a failed or rejected request is logged silently and your WordPress pages keep serving as usual. Use **Test Connection** in the admin to validate the key.

= Does CacheBoost work with my CDN? =

CacheBoost works with any CDN that serves content via HTTP — Cloudflare, Fastly, BunnyCDN, KeyCDN, and more. If your CDN caches HTTP responses, CacheBoost can warm it.

= Does CacheBoost work without a CDN? =

Absolutely. CacheBoost works with any caching layer — not just CDNs. If your site uses Varnish, Redis, a file cache, a database cache, or any HTTP-based caching system, CacheBoost can warm it. As long as your pages are served faster on the second request, CacheBoost is useful.

= Will the warming requests affect my server load? =

Warming requests are sent at a controlled rate to avoid overloading your origin server. You can configure the concurrency and request rate per boost from your CacheBoost dashboard.

= What if my site has thousands of URLs? =

No problem. On a full warm, CacheBoost reads your sitemap (including multi-level and sitemap index files) with no limit on the number of URLs. Every page gets warmed, regardless of the size of your site.

= Is my data safe? =

CacheBoost only sends HTTP requests to your public URLs — the same requests any visitor would make. No credentials and no private data are ever accessed.

== Changelog ==

= 1.1.1 =
* Warns in the WordPress admin when the CacheBoost account email must be verified, and when warming is paused because it was not.

= 1.1.0 =
* New **Connect to CacheBoost** button: sign in or sign up, authorize, done. No more copying an API key, adding the domain or creating a Boost by hand.

= 1.0.3 =
* Region labels follow the CacheBoost catalogue (ISO country codes: France, Germany, United Kingdom, United States, Spain, Italy…). Click Test Connection to refresh the list of available regions.

= 1.0.2 =
* Domain ownership is now validated automatically when saving a new API key or clicking Test Connection, without uploading a file or editing the theme. Requires the `sites:write` API scope.
* Settings page warns when the domain is not validated, with a link to the manual validation methods.

= 1.0.1 =
* Improvement: clearer admin notices on Test Connection failures.

= 1.0.0 =
* Initial release.

== External Services ==

This plugin sends data to the CacheBoost API (https://api.cache-boost.com) to trigger cache warming jobs after a purge event. Data transmitted includes the site URL, the list of page URLs to warm and a timestamp. No personal user data is sent.

Requests are only made when:
- The plugin is enabled in the CacheBoost settings
- A valid API key (format: cb_live_...) has been configured

CacheBoost Terms of Service: https://www.cache-boost.com/terms
