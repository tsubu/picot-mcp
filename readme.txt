=== Picot MCP ===
Contributors: tsubu
Tags: mcp, ai, abilities, rest-api, api
Requires at least: 6.9
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 0.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose WordPress as an MCP server with API key auth and scoped permissions.

== Description ==

Picot MCP turns your WordPress site into an MCP (Model Context Protocol) server so AI clients such as Cursor or Claude Code can work with content safely.

Architecture: the official WordPress MCP Adapter is the protocol runtime; Picot adds the product layer (API keys, scopes, admin UI, audit log).

* One MCP URL and API keys to connect (plaintext shown only once at issue)
* Multiple API keys with per-key scopes, optional expiry, and rate limits
* Seven feature toggles (posts/pages, taxonomies, media, settings, plugins, themes, users)
* Plugin/theme install and update from wordpress.org slugs only
* Plugin/theme activation, deactivation, and ZIP package transfer are not available via MCP (use WordPress admin)
* Audit log with user, key id, IP, and failure codes
* Built on the WordPress Abilities API and official MCP Adapter

== Installation ==

1. Upload the `picot-mcp` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins screen
3. Open Settings → MCP, enable the server, review the site ceiling, issue an API key, and copy the key immediately (it cannot be shown again)

== Frequently Asked Questions ==

= Does this plugin need Composer on the site? =

No. Release packages already include the required MCP Adapter under `vendor/`.

= Who can manage API keys? =

Only administrators (`manage_options`).

= What is the default MCP endpoint? =

New installs use `/wp-json/picot-mcp/mcp-server` with MCP disabled until you enable it. Existing sites keep their previously saved route until changed in Settings.

= Can I install custom plugin or theme ZIP packages via MCP? =

No. Install and update are limited to packages from wordpress.org. There is no ZIP install or ZIP export via MCP. Activate or deactivate plugins and themes in WordPress admin.

= Can I copy an API key again later? =

No. Keys are stored as irreversible hashes. Copy the plaintext when it is issued, or create a new key.

== Changelog ==

= 0.2.3 =
* Audit: purge legacy zip_install from stored settings and API keys
* Audit: block self-deletion of Picot MCP via the plugins ability
* Audit: limit dependency/admin error notices to plugin-related admin screens

= 0.2.2 =
* Remove Plugin/theme ZIP export and the zip_install operation entirely

= 0.2.1 =
* WordPress.org review: remove arbitrary Base64 ZIP install for plugins/themes
* WordPress.org review: remove MCP activate/deactivate for plugins and theme switch
* Install/update remain wordpress.org-only; delete requires the plugin to already be inactive in admin
* Contributors list uses WordPress.org username; translation files omitted from directory package

= 0.2.0 =
* Product hardening: safe defaults (MCP off; critical/plugins/themes/users off)
* API keys are issue-once (hash-only; no reversible secret in the database)
* Key expiry and per-key rate limiting
* Stronger audit log (token id, IP, failure code/message; configurable retention)
* Adapter runtime status, optional observability handler, admin warnings
* Smoke tests for sanitize helpers and safe defaults (kept outside the plugin package for Plugin Check)

= 0.1.12 =
* Add “Copy all” for MCP connection snippet (site name, label, URL, key)

= 0.1.11 =
* Add Logs tab with recent MCP usage (who / feature / action, last 50)

= 0.1.10 =
* Add plugin/theme `export_zip` (ZIP → Base64) for MCP package transfer

= 0.1.9 =
* Add dedicated Plugin/theme ZIP install operation checkbox (site + per-key)

= 0.1.8 =
* Add plugin/theme ZIP install via Base64 (`install_zip`, optional overwrite)

= 0.1.7 =
* Security: enforce per-key critical for sensitive user operations
* Security: object-level caps on media get/list; safer defaults (MCP off; picot-mcp route)
* Fix: preserve key scopes when site features are temporarily disabled; flash key ID case handling
* Fix: revoke/update save error handling; normalize legacy token permission maps

= 0.1.6 =
* WordPress.org packaging guardrails (distribution zip, i18n, Plugin Check cleanup)
* Admin UI and security hardening for API key management

= 0.1.0 =
* Initial release
