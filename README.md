# Notran Torob Connect

A WooCommerce connector for Torob Product API v3. It builds a local product index, serves authenticated catalog requests, and creates links that preselect a product variation.

[راهنمای نصب و عیب‌یابی فارسی](README-fa.md)

## Requirements

| Component | Minimum |
| --- | --- |
| WordPress | 6.4 |
| WooCommerce | 8.2 |
| PHP | 7.4 with Sodium |
| Database | MySQL or MariaDB with InnoDB |

WP-Cron runs the catalog refresh and webhook queues. The release was tested with WordPress 7.1.4 and WooCommerce 11.3.

## Connect a store

1. Install the plugin ZIP in WordPress and activate it.
2. Open **WooCommerce → اتصال به ترب**. Verify the currency setting, preview a variable product, and test its variation links.
3. Copy the feed URL from the settings when the index is ready. Submit that URL to [Torob seller support](https://panel.torob.com/).
4. To enable change notices, enter a merchant webhook token obtained from Torob and enable the webhook setting. For server-managed credentials, set `NOTRAN_TOROB_WEBHOOK_TOKEN` in the site's `wp-config.php`.

Torob signs inbound feed requests. For failed requests, review the synchronization tab for authentication outcomes and timestamps. The full data exchange, terms, and privacy links are in [the WordPress.org readme](readme.txt).

## Publish a release

The repository root contains the plugin main file, `includes/`, and `assets/`. The WordPress installer package contains these files under one `notran-torob-connect/` directory.

1. Set the same version in the main plugin header, the version constant, and the stable tag in `readme.txt`.
2. Run [WordPress Plugin Check](https://wordpress.org/plugins/plugin-check/) and test activation, catalog generation, and the authenticated REST feed.
3. Create a matching Git tag, such as `v1.0.2`, and attach `notran-torob-connect-1.0.2.zip` as the install asset on the GitHub release.
4. Submit the install ZIP for [WordPress.org review](https://wordpress.org/plugins/developers/add/). After approval, use the SVN location assigned by WordPress.org. Copy the install package contents to `trunk/` and create a matching version directory under `tags/`.

## License

[GPL v2 or later](LICENSE.txt). Maintained by [Notran](https://notran.org/) and [Ali Shahroodi](https://shahroodi.com/).
