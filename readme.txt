=== MCP Abilities - Cloudflare ===
Contributors: devenia
Tags: mcp, cloudflare, cache, ai, automation
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.0.11
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cloudflare cache diagnostics and cache management for WordPress via MCP.

== Description ==

This add-on plugin exposes Cloudflare cache diagnostics and cache management through MCP (Model Context Protocol).

Part of the MCP Expose Abilities ecosystem.

== Installation ==

1. Install the required plugins (Abilities API, MCP Adapter, Cloudflare)
2. Download the latest release
3. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
4. Activate the plugin

== Changelog ==

= 1.0.11 =
* Added: `cloudflare/clear-cache` now supports URL prefix purges for cached HTML cases where exact URL purges do not evict the edge object.
* Added: `cloudflare/clear-cache` responses now include structured purge metadata with purge type, payload keys, Cloudflare purge ID, and auth mode.

= 1.0.10 =
* Added: `cloudflare/ensure-wordpress-html-cache-rule` with dry-run by default.
* Added: the new cache-rule ability preserves existing rules and only targets anonymous public WordPress HTML requests.

= 1.0.9 =
* Added: read-only Cloudflare cache settings diagnostics.
* Added: read-only Cloudflare cache ruleset and cache-settings entrypoint inspection.
* Added: URL cache-status probes for `cf-cache-status`, `cache-control`, `age`, `set-cookie`, and related headers.

= 1.0.8 =
* Fixed: API Token installs now work when the official Cloudflare plugin stores the token in `cloudflare_api_key`.
* Fixed: Global API Key versus API Token detection now matches the official Cloudflare plugin.
* Fixed: cache purge now retries with alternate auth when Cloudflare returns `Authentication error`.
* Fixed: zero-parameter ability schemas now accept the empty/null representations MCP/WordPress paths can produce for `{}`.

= 1.0.7 =
* Fixed: zero-parameter schemas now stay object-shaped without using stdClass-backed `properties`.
* Fixed: validator path no longer throws `Cannot use object of type stdClass as array` for object-shaped inputs.

= 1.0.6 =
* Fixed: normalize stdClass-shaped MCP inputs and Cloudflare API response data before array access.
* Fixed: `cloudflare/get-zone` no longer throws `Cannot use object of type stdClass as array` when called with `{}`.

= 1.0.5 =
* Fixed: Cloudflare API auth now supports API token installs as well as email plus global API key installs.
* Fixed: Cloudflare API calls retry with the alternate auth header format when Cloudflare reports invalid request headers.
* Fixed: zero-parameter abilities now accept empty object inputs from MCP clients.

= 1.0.4 =
* Fixed: zero-parameter ability schemas now avoid empty `properties` objects so MCP Adapter 0.4.x clients do not receive invalid `properties: []` JSON

= 1.0.3 =
* Fixed: Removed hard plugin header dependency on abilities-api to avoid slug-mismatch activation blocking


= 1.0.2 =
* Improve zone ID lookup caching and API header reuse

= 1.0.1 =
* Added: Stored zone_id optimization

= 1.0.0 =
* Initial release
