# MCP Abilities - Cloudflare

Cloudflare cache management for WordPress via MCP.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-cloudflare)](https://github.com/bjornfix/mcp-abilities-cloudflare/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

**Tested up to:** 6.9
**Stable tag:** 1.0.3
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

This add-on plugin exposes Cloudflare cache management through MCP (Model Context Protocol). Tell your AI assistant "clear the Cloudflare cache" and it happens instantly.

**Part of the [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/) ecosystem.**

## Requirements

- WordPress 6.9+
- PHP 8.0+
- [Abilities API](https://github.com/WordPress/abilities-api) plugin
- [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin
- [Cloudflare](https://wordpress.org/plugins/cloudflare/) plugin (configured with API credentials)

## Installation

1. Install the required plugins (Abilities API, MCP Adapter, Cloudflare)
2. Download the latest release from [Releases](https://github.com/bjornfix/mcp-abilities-cloudflare/releases)
3. Upload via WordPress Admin > Plugins > Add New > Upload Plugin
4. Activate the plugin

## Abilities (4)

| Ability | Description |
|---------|-------------|
| `cloudflare/clear-cache` | Purge entire Cloudflare cache or specific URLs |
| `cloudflare/get-zone` | Get active Cloudflare zone details |
| `cloudflare/get-development-mode` | Read current Cloudflare Development Mode status |
| `cloudflare/set-development-mode` | Enable or disable Cloudflare Development Mode |

## Usage Examples

### Clear entire cache

```json
{
  "ability_name": "cloudflare/clear-cache",
  "parameters": {
    "purge_everything": true
  }
}
```

### Clear specific URLs

```json
{
  "ability_name": "cloudflare/clear-cache",
  "parameters": {
    "purge_everything": false,
    "files": [
      "https://example.com/page-1/",
      "https://example.com/page-2/"
    ]
  }
}
```

## Changelog

### 1.0.3
- Fixed: Removed hard plugin header dependency on abilities-api to avoid slug-mismatch activation blocking

### 1.0.2
- Improve zone ID lookup caching and API header reuse

### 1.0.1
- Added: Stored zone_id optimization

### 1.0.0
- Initial release

## License

GPL-2.0+

## Author

[Devenia](https://devenia.com) - We've been doing SEO and web development since 1993.

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/)
- [Core Plugin (MCP Expose Abilities)](https://github.com/bjornfix/mcp-expose-abilities)
- [All Add-on Plugins](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
