=== Notran Torob Connect ===
Tags: woocommerce, torob, product feed, variations
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Connect WooCommerce to Torob Product API v3 with product variation links, a catalog preview, and optional product update notifications.

== Description ==

Notran Torob Connect helps WooCommerce merchants list products and their eligible variations in Torob, an Iranian shopping search service.

* Build a local catalog for simple products and eligible variations with prices in Iranian toman.
* Give each variation its own identifier and a product link with its attributes preselected.
* Configure variation titles and preview exported price, availability, attributes, and images in the dashboard.
* Authenticate Torob requests with Ed25519-signed JWTs.
* Optionally notify Torob when a catalog entry changes.

The administration interface is in Persian. This independent connector is maintained by [Notran](https://notran.org/). Torob controls listing visibility and grouping in its seller panel.

== Installation ==

1. Install and activate WooCommerce 8.2 or later. Enable PHP Sodium and use MySQL or MariaDB with InnoDB for the catalog tables.
2. Install and activate Notran Torob Connect in the WordPress Plugins screen.
3. Open **WooCommerce > اتصال به ترب** and verify the store's price unit. Preview a variable product and open its variation links in a private browser window.
4. Wait for the catalog to finish building. Copy the exact feed URL shown in the settings and give it to [Torob seller support](https://panel.torob.com/) to register the store's Product API v3 feed.
5. If you want product update notices, request a webhook token from Torob, enter it in the plugin settings, and enable the webhook option.

Keep WP-Cron running for catalog refreshes and webhook delivery. Ensure that the server clock is synchronized and that the REST endpoint receives the Torob request headers.

== Frequently Asked Questions ==

= What do I need to preview the catalog? =

Activate WooCommerce and add a published simple or variable product. The WordPress dashboard preview is available before registering a feed with Torob.

= Which credential is used for the feed? =

Torob sends its signed JWT with each Product API v3 request. The plugin verifies the signature using Torob's public key and checks the audience and validity times. The optional webhook uses a separate token obtained from Torob.

= Why do I receive HTTP 401 when testing a POST? =

The feed requires a valid Torob signature. For a Torob request that fails, open the authentication report under the synchronization status tab. The report stores a result and timestamp for each outcome. A result of `expired` or `not_yet_valid` is a reason to compare the web server's system time and the token validity times.

= How do I replace an existing Torob feed? =

Coordinate the URL change with Torob support. The plugin uses WooCommerce product and variation IDs as stable identifiers. Compare existing IDs and links with the preview before switching sources.

= What happens when I remove the plugin? =

Deactivation stops its scheduled work. Uninstall deletes the plugin's local catalog, queues, settings, and Torob-specific product fields.

== External services ==

This plugin connects to [Torob](https://torob.com/) to provide catalog listings. Merchant registration and feed configuration are handled through the [Torob seller panel](https://panel.torob.com/).

* **Product API v3.** Torob initiates authenticated requests to the merchant's WordPress REST endpoint after the merchant registers the feed URL with Torob. The response contains selected store catalog data, including identifiers, titles, product and image URLs, prices, stock status, categories, public product details, and update dates.
* **Product webhook.** When a merchant enables the optional webhook and supplies a token, the plugin sends the identifiers and URLs of changed entries to `https://api.torob.com/update/webhook/v1/`. The token is included in the request's Bearer authorization header. Torob uses the notice to queue a new fetch through the Product API.

The WordPress installation stores the indexed catalog, processing queues, settings, authentication outcomes and timestamps, and, when entered in the settings, the webhook token. Torob's inbound JWT is used to authenticate the request. Outbound requests from the plugin are limited to the optional product webhook.

* [Torob Terms of Use](https://torob.com/pages/terms/)
* [Torob Privacy Policy](https://torob.com/pages/privacy/)
* [Torob Product API documentation](https://github.com/torob/Torob-Sync/blob/main/product_api_v3.md)
* [Torob webhook documentation](https://github.com/torob/Torob-Sync/blob/main/product_webhook.md)

== Changelog ==

= 1.0.2 =
* Add the directory readme and describe the Torob data exchange and webhook opt-in.
* Clarify request handling and secure the formatting of database errors and uninstall table identifiers.

= 1.0.1 =
* Improve JWT validation for supported www aliases and forwarded headers.
* Record authentication outcomes and prevent caching of feed responses.

= 1.0.0 =
* Add the WooCommerce catalog, variation preview, authenticated Torob feed, and optional product webhook.
