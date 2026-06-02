=== MCP Abilities - Cloudflare ===
Contributors: devenia
Tags: mcp, cloudflare, cache, ai, automation
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.0.7
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cloudflare cache management for WordPress via MCP.

== Description ==

This add-on plugin exposes Cloudflare cache management through MCP (Model Context Protocol). Tell your AI assistant "clear the Cloudflare cache" and it happens instantly.

Part of the MCP Expose Abilities ecosystem.

== Installation ==

1. Install the required plugins (Abilities API, MCP Adapter, Cloudflare)
2. Download the latest release
3. Upload via WordPress Admin → Plugins → Add New → Upload Plugin
4. Activate the plugin

== Changelog ==

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
