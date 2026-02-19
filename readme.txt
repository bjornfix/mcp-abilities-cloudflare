=== MCP Abilities - Cloudflare ===
Contributors: devenia
Tags: mcp, cloudflare, cache, ai, automation
Requires at least: 6.9
Tested up to: 6.9
Stable tag: 1.0.3
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

= 1.0.3 =
* Fixed: Removed hard plugin header dependency on abilities-api to avoid slug-mismatch activation blocking


= 1.0.2 =
* Improve zone ID lookup caching and API header reuse

= 1.0.1 =
* Added: Stored zone_id optimization

= 1.0.0 =
* Initial release
