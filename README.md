# MCP Abilities – Cloudflare

Find which cached copy is out of date, inspect the connected Cloudflare zone, choose a purge scope and verify what the visitor receives. This WordPress add-on exposes Cloudflare cache diagnostics and changes through an authenticated MCP connection.

[![Stable download](https://img.shields.io/badge/stable-1.0.21-blue)](https://downloads.devenia.com/mcp-abilities-cloudflare.zip)
[![License](https://img.shields.io/badge/license-GPLv2%2B-blue)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple)](https://www.php.net/)

**Current stable download:** 1.0.21

**Source version / Stable tag:** 1.0.21

**Tested up to:** 7.1

**Tags:** mcp, cloudflare, cache, ai, automation

**License:** GPLv2 or later

## What It Does

The add-on reads Cloudflare zone details, cache settings, cache rules and Development Mode. It probes public URL response headers and can purge cached resources, change Development Mode or propose an anonymous WordPress HTML cache rule.

Cloudflare is one cache layer. A successful purge does not correct unsaved content, purge a visitor's browser or prove that the origin supplies the updated page.

## The Real Workflow

1. Confirm the saved WordPress change and the exact public URL.
2. Read the connected Cloudflare zone, then inspect the relevant response headers and rules.
3. Choose the smallest suitable purge scope. For targeted work, set `purge_everything: false` explicitly.
4. Read the purge response, including completed operations if a later operation failed.
5. Fetch the public page again and verify the actual changed content. Investigate local and browser caching separately.

## Why This Feels Different

An assistant can read cache evidence and issue a chosen cache action through one authenticated WordPress connection. The response identifies the purge operations and automatic HTML-to-prefix conversions, helping the operator understand what was requested.

## Before vs After

| Without the add-on | With the add-on |
| --- | --- |
| Guess which cache contains the old page. | Inspect Cloudflare headers, settings and the connected zone. |
| Treat every URL as a single cached file. | See when an HTML URL becomes a broader prefix purge. |
| Stop after the API says the purge succeeded. | Use the result to follow up with a real public-page check. |

## Who It Is For

WordPress operators and developers who use Cloudflare and an authenticated MCP client, and need to diagnose stale public responses or make deliberate cache changes.

## Requirements

- WordPress 6.9 or later, which includes the [Abilities API](https://developer.wordpress.org/apis/abilities-api/).
- PHP 8.0 or later as the declared minimum; use a maintained PHP version supported by your site.
- A [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter/) connection configured to expose the required abilities. Confirm discovery before making changes.
- A WordPress account with `manage_options`; every ability uses this permission.
- Cloudflare credentials for the intended zone and operations. The [Cache Purge API](https://developers.cloudflare.com/api/resources/cache/methods/purge/) requires Cache Purge permission; diagnostics and rule changes need the relevant read or edit permissions too.

The official [Cloudflare for WordPress plugin](https://wordpress.org/plugins/cloudflare/) can supply stored credentials. It is required for the dedicated credential-configuration action. Other actions can also use supported WordPress constants.

## Documentation

- [Product page](https://devenia.com/plugins/mcp-abilities-cloudflare/)
- [Cloudflare purge methods and limits](https://developers.cloudflare.com/cache/how-to/purge-cache/)
- [Prefix purge behaviour](https://developers.cloudflare.com/cache/how-to/purge-cache/purge_by_prefix/)
- [Development Mode](https://developers.cloudflare.com/cache/reference/development-mode/)
- [Cache-rule settings](https://developers.cloudflare.com/cache/how-to/cache-rules/settings/)

## Start Here

Ask your assistant: “Confirm the connected Cloudflare zone and inspect the headers for https://example.com/classes/. Explain whether the evidence points to edge caching and what a targeted purge would cover. After the chosen purge, check the actual page content.”

Do not supply a credential in a public prompt or report. Use your client's established secret-handling process or configure the official plugin directly.

## Abilities (9)

| Ability | Purpose |
| --- | --- |
| `cloudflare/configure-credentials` | Validate zone access, then store credentials through the official plugin after exact confirmation. |
| `cloudflare/clear-cache` | Purge files, prefixes, hosts, tags or the connected zone. |
| `cloudflare/get-zone` | Read the configured zone's details. |
| `cloudflare/get-development-mode` | Read Development Mode state. |
| `cloudflare/get-cache-settings` | Read selected zone settings; up to 25 setting names. |
| `cloudflare/get-cache-rulesets` | Read zone rulesets and the cache-settings entrypoint. |
| `cloudflare/test-url-cache-status` | Probe public URLs and report HTTP and cache-related headers. |
| `cloudflare/ensure-wordpress-html-cache-rule` | Preview or write the add-on's HTML cache rule in an existing cache-settings ruleset. |
| `cloudflare/set-development-mode` | Set Development Mode to `on` or `off`. |

## Usage Examples

### Inspect public responses

Call `cloudflare/test-url-cache-status`:

```json
{"urls":["https://example.com/classes/"],"repeat":2,"method":"GET"}
```

The probe handles at most 20 distinct URLs. `repeat` ranges from 1 to 3 and defaults to 2; `method` is `GET` or `HEAD`. It reports each attempt's HTTP status, `cf-cache-status`, `cache-control`, `age`, `cf-ray`, `server`, `content-type`, `vary` and whether `set-cookie` is present.

The response does not contain a content comparison. Its outer `success` means the probe finished; inspect individual attempts and HTTP status codes. The probe sends requests and can populate a cache.

### Purge selected resources

Call `cloudflare/clear-cache`:

```json
{
  "purge_everything": false,
  "files": ["https://example.com/classes/", "https://example.com/assets/styles.css"]
}
```

Extensionless and `.html` paths without query strings become prefix purges; asset URLs and URLs with query strings remain exact file purges. The response's `auto_prefixes` records the conversions. A slash is added to extensionless paths when absent, so verify the intended canonical path.

Each exposed input list accepts at most 100 items. In version 1.0.21, the shared implementation validates the complete list, retains combined explicit and automatic prefixes and sends requests of at most 100 targets. It stops on a failed request and preserves details of already completed requests. Cloudflare account rate limits still apply.

### Purge a branch

```json
{"purge_everything":false,"prefixes":["example.com/classes/"]}
```

A prefix also covers resources beneath that path. Accepted input can be `host/path` or a full URL; Cloudflare receives the scheme-less form. Query strings and fragments are not accepted in prefix purges. Use `hosts` or `tags` for their corresponding groups, or `purge_everything: true` for an intended zone-wide purge without specific targets.

The string-only file input cannot send custom cache-key headers. For those objects, choose an appropriate supported purge scope; see [Cloudflare's cache-key guidance](https://developers.cloudflare.com/cache/how-to/purge-cache/purge-cache-key/).

### Preview an HTML cache rule

Call `cloudflare/ensure-wordpress-html-cache-rule`:

```json
{"host":"example.com","dry_run":true,"edge_ttl_seconds":7200,"exclude_paths":["/members/"]}
```

The action requires a readable existing cache-settings entrypoint. It preserves unrelated rules, previews by default, and writes when `dry_run` is false. `enabled` defaults to true. The declared TTL range is 60–86400 seconds, default 3600; Cloudflare plan constraints may reject a value.

The rule selects GET/HEAD HTML paths without queries and excludes several standard WordPress paths and session cookies. It sets an edge TTL that overrides origin cache instructions. Do not treat the rule as proof that custom login, account, checkout or personalised routes are safe to cache.

In version 1.0.21, `/members/` excludes both `/members` and descendants under `/members/`, while a different path such as `/membership/` remains outside that exclusion. Existing custom exclusions are retained when `exclude_paths` is omitted. Inspect the proposed expression and test your actual routes and cookies before enabling the rule.

### Configure credentials

`cloudflare/configure-credentials` takes `api_credential`, `email` and the exact `confirm_dangerous_action` value `cloudflare/configure-credentials`. It requires the official Cloudflare plugin, refuses conflicting credential/domain constants and does not return the credential value.

The action looks up a zone using the WordPress hostname. A subdomain that is not itself a Cloudflare zone may require prior configuration of the correct zone through the official plugin or constants. Zone-read validation does not prove that every purge or rule permission is available.

Supported constants are `CLOUDFLARE_EMAIL`, `CLOUDFLARE_API_KEY`, `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ZONE_ID` and `CLOUDFLARE_DOMAIN_NAME`.

## Ownership and Automatic Behaviour

Cloudflare owns the edge cache and API permissions. WordPress owns the saved content and local cache; the visitor's browser has its own cache. Purging one does not prove the others are current.

Development Mode temporarily bypasses Cloudflare caching for up to three hours unless disabled earlier; it does not purge stored files.

The add-on attempts a public-host root-prefix purge after a completed plugin install or update and records the result. This can clear more than the specific page being investigated.

The shared `devenia_workflow_frontend_cache_invalidation_result` hook accepts frontend URL invalidation requests. It can combine a matching local-cache result with the Cloudflare purge. Without configured Cloudflare credentials, a valid local-cache receipt can be returned with the edge step explicitly skipped; that does not mean a configured Cloudflare cache was purged.

## Installation

1. Download the [stable ZIP](https://downloads.devenia.com/mcp-abilities-cloudflare.zip) and install it through WordPress.
2. Configure the correct Cloudflare credentials and zone, using the official plugin or supported constants.
3. Connect the MCP client, confirm the required abilities are discoverable and read the zone before requesting changes.
4. Review the purge scope and verify the returned page after each change.

## Changelog

### 1.0.21

- Update compatibility metadata after validation on WordPress 7.1 RC3.
- Reject invalid purge targets before cache changes.
- Process complete shared target lists in requests of at most 100 targets; retain combined prefixes and completed-operation details.
- Make custom HTML-cache path exclusions cover the root and descendants and retain them on later updates.
- Remove the outdated Enterprise-only description for tag purges.

### 1.0.19

- Add confirmed credential configuration through the official Cloudflare plugin, validating zone access without returning the secret.

### 1.0.17

- Report an unconfigured edge step as skipped when a valid local-cache result is available.

### 1.0.16

- Share purge behaviour between the ability and frontend invalidation hook.
- Observe completed plugin installs and updates to purge the public host prefix.

## Contributing

Keep changes in the owning ability or shared cache implementation. Test target coverage, invalid inputs, partial failures and the proposed rule expression through the public callbacks.

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

## Author

[basicus](https://profiles.wordpress.org/basicus/)

## Links

- [Product page](https://devenia.com/plugins/mcp-abilities-cloudflare/)
- [Stable download](https://downloads.devenia.com/mcp-abilities-cloudflare.zip)
- [Optional source mirror](https://github.com/bjornfix/mcp-abilities-cloudflare)
