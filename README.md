# MCP Abilities - Cloudflare

Cloudflare abilities for MCP. Inspect and clear Cloudflare cache for WordPress sites.

[![GitHub release](https://img.shields.io/github/v/release/bjornfix/mcp-abilities-cloudflare)](https://github.com/bjornfix/mcp-abilities-cloudflare/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple.svg)](https://php.net)

**Tested up to:** 7.0
**Stable tag:** 1.0.9
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

## What It Does

Cloudflare abilities for MCP. Inspect and clear Cloudflare cache for WordPress sites.

This plugin is part of the Devenia MCP abilities ecosystem. It gives an MCP-capable agent a focused, authenticated way to work with Cloudflare work inside WordPress through MCP.

**Example:** "Handle this WordPress maintenance task directly." - The agent can inspect the site, call the relevant ability, and return the result without making the human click through wp-admin for every step.

## The Real Workflow

In practice, the human should not have to memorize every ability name.

The normal pattern is:

1. install the base MCP stack
2. install only the add-ons the site actually needs
3. let the agent discover the available abilities
4. give the agent a clear task with boundaries
5. verify the result in WordPress

The human's job is mostly to describe the goal.
The agent's job is to figure out the mechanics.

## Why This Feels Different

Most WordPress automation still leaves the repetitive part to the human.

This plugin is different because the agent can act inside the site through a narrow, authenticated ability surface:

- inspect current site state before changing anything
- run the specific action needed for the task
- return structured results that are easy to verify
- keep the workflow inside WordPress instead of a separate checklist

That changes the experience from:

- `Here is what you should do in wp-admin`

to:

- `Tell the agent what needs doing, and let it carry out the work`

## Before vs After

### Before

- ask the AI what to do
- copy the answer into WordPress by hand
- click through wp-admin for the repetitive bits
- postpone maintenance because the task is tedious

### After

- tell the agent what needs doing
- let it inspect the relevant WordPress state
- let it run the targeted ability
- verify the result and move on

## Who It Is For

This is a good fit for:

- agencies managing WordPress sites with AI-assisted maintenance
- operators who want agents to do real WordPress work instead of producing instructions
- teams already using MCP Expose Abilities
- sites where this WordPress area is updated often enough to deserve automation

It is especially useful when the manual version is repetitive enough that important maintenance gets delayed.

## Documentation

Start with the main plugin page and base stack documentation:

- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
- [Getting Started](https://github.com/bjornfix/mcp-expose-abilities/wiki/Getting-Started)
- [Install Order and Dependencies](https://github.com/bjornfix/mcp-expose-abilities/wiki/Install-Order-and-Dependencies)

If you are using an AI agent, the simplest instruction is often just:

- `Read https://github.com/bjornfix/mcp-expose-abilities and figure out the stack before making changes.`

## Start Here

If you are new to the stack, use this order:

1. Install **Abilities API**.
2. Install **MCP Adapter**.
3. Install **MCP Expose Abilities**.
4. Install **MCP Abilities - Cloudflare**.
5. Confirm the new abilities appear in discovery.
6. Give the agent a clear task that uses this add-on.

If you skip base-stack verification and start with add-ons immediately, troubleshooting gets harder than it needs to be.

## Abilities (7)

| Ability | Description |
|---------|-------------|
| `cloudflare/clear-cache` | Purge entire Cloudflare cache or specific URLs |
| `cloudflare/get-zone` | Get active Cloudflare zone details |
| `cloudflare/get-development-mode` | Read current Cloudflare Development Mode status |
| `cloudflare/get-cache-settings` | Read relevant Cloudflare cache/performance zone settings |
| `cloudflare/get-cache-rulesets` | Read Cloudflare rulesets and the cache-settings entrypoint |
| `cloudflare/test-url-cache-status` | Probe public URLs and report Cloudflare cache headers |
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

### 1.0.9
- Added read-only cache diagnostics for Cloudflare zone cache settings.
- Added read-only ruleset inspection for the `http_request_cache_settings` entrypoint.
- Added URL cache-status probes that report `cf-cache-status`, `cache-control`, `age`, `set-cookie`, and related headers.

### 1.0.8
- Fixed Cloudflare API Token installs where the official Cloudflare plugin stores the token in `cloudflare_api_key`.
- Matched the official Cloudflare plugin's Global API Key vs API Token credential-format detection.
- Retried cache purge requests with alternate auth when Cloudflare returns `Authentication error`.
- Updated zero-parameter ability schemas to accept the empty/null representations that MCP/WordPress paths can produce for `{}`.

### 1.0.7
- Fixed zero-parameter schemas so they stay object-shaped without using stdClass-backed `properties`.
- Fixed validator-path `Cannot use object of type stdClass as array` failures for object-shaped inputs.

### 1.0.6
- Fixed stdClass-shaped MCP inputs and Cloudflare API response data normalization before array access.
- Fixed `cloudflare/get-zone` so `{}` calls do not throw `Cannot use object of type stdClass as array`.

### 1.0.5
- Fixed Cloudflare API auth handling for installs using API tokens instead of only email + global API key.
- Added auth-header fallback for Cloudflare responses that report invalid request headers.
- Fixed zero-parameter abilities so `{}` inputs are accepted by MCP clients.

### 1.0.4
- Fixed zero-parameter ability schemas so MCP Adapter 0.4.x clients do not receive invalid `properties: []` JSON

### 1.0.3
- Fixed: Removed hard plugin header dependency on abilities-api to avoid slug-mismatch activation blocking

### 1.0.2
- Improve zone ID lookup caching and API header reuse

### 1.0.1
- Added: Stored zone_id optimization

### 1.0.0
- Initial release

## Contributing

PRs welcome. Keep changes focused on the plugin's WordPress ability surface and preserve authenticated, explicit workflows.

## License

GPL-2.0+

## Author

[Devenia](https://devenia.com) - We've been doing SEO and web development since 1993.

## Links

- [Plugin Page](https://devenia.com/plugins/mcp-expose-abilities/#add-ons)
- [MCP Expose Abilities](https://devenia.com/plugins/mcp-expose-abilities/)
- [GitHub Releases](https://github.com/bjornfix/mcp-abilities-cloudflare/releases)

## Star and Share

If this plugin saves you time or makes WordPress maintenance easier to verify, please:

- star the repo
- share it with people running WordPress sites
- point them to the main plugin page so they can see what the ecosystem can actually do

Why do it?

Because agent-friendly open WordPress tooling helps more of the boring but important work get done.
