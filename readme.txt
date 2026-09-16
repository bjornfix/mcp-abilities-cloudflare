=== MCP Abilities - Cloudflare ===
Contributors: basicus
Tags: mcp, cloudflare, cache, ai, automation
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 1.0.22
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cloudflare cache diagnostics and cache management for WordPress via MCP.

== Description ==

Inspect Cloudflare's connected zone, cache settings, rulesets and public response headers from an authenticated WordPress MCP client. Choose a cache purge scope, then verify the actual page content separately.

The nine abilities are `cloudflare/configure-credentials`, `cloudflare/clear-cache`, `cloudflare/get-zone`, `cloudflare/get-development-mode`, `cloudflare/get-cache-settings`, `cloudflare/get-cache-rulesets`, `cloudflare/test-url-cache-status`, `cloudflare/ensure-wordpress-html-cache-rule` and `cloudflare/set-development-mode`. All require the WordPress permission to manage site options.

For targeted purges, explicitly set `purge_everything: false`. The default empty purge call clears the connected zone. HTML paths without query strings become broader prefix purges; assets and query-string URLs remain exact file purges. Inspect automatic conversions and the scope before making changes. Version 1.0.21 rejects invalid targets before purging and processes the complete shared target list in requests of at most 100 items. A failed later request retains the completed-operation details. Cloudflare rate limits still apply.

The URL probe handles at most 20 URLs, returning response headers rather than a content comparison. Read each attempt's HTTP status and result. A successful purge response does not prove that the origin supplied the corrected page or that the visitor's browser cache is current.

The HTML cache-rule action previews by default and requires an existing cache-settings entrypoint. Its edge TTL can override origin cache instructions. Review custom login, account, checkout and personalised routes before enabling it. Custom excluded paths cover the path root and descendants in version 1.0.21 and remain preserved when omitted from later updates.

Development Mode temporarily bypasses Cloudflare caching; it does not purge stored files. The add-on also attempts a public-host root-prefix purge after completed plugin installs or updates.

Cloudflare credentials can come from the official Cloudflare plugin or supported WordPress constants. The dedicated configuration action requires the official plugin and exact confirmation. It validates zone access before storing settings without returning the credential; this does not validate every possible API permission.

See the [product page](https://devenia.com/plugins/mcp-abilities-cloudflare/) and [Cloudflare purge documentation](https://developers.cloudflare.com/cache/how-to/purge-cache/) for the workflow and provider limits.

== Installation ==

For update notifications in WordPress, install [Devenia MCP Updater](https://downloads.devenia.com/devenia-mcp-updater.zip). The updater is optional. You choose which plugins update automatically through WordPress.

1. Use WordPress 6.9 or newer and PHP 8.0 or newer.
2. Install and activate WordPress MCP Adapter.
3. Configure Cloudflare for WordPress, or use supported WordPress constants for credentials and zone context.
4. Download and install the stable package from https://downloads.devenia.com/mcp-abilities-cloudflare.zip.
5. Configure and validate the Cloudflare credentials through the dedicated ability or the official plugin settings.

== Changelog ==

= 1.0.22 =
* Add one dismissible Plugins-screen reminder when Devenia MCP Updater is missing or inactive, with persistent install or activate links. Automatic updates remain your choice in WordPress.

= 1.0.21 =
* Updated compatibility metadata after validation on WordPress 7.1 RC3.
* Excluded HTML-cache paths now cover the path root and its descendants and remain preserved on later rule updates.
* Reject invalid purge targets before issuing cache changes.
* Process the complete target list in bounded requests without silently dropping targets.
* Preserve completed-request details when a later purge request fails.

= 1.0.19 =
* Added: a confirmed configuration ability validates Cloudflare credentials before storing them through the official Cloudflare plugin and never returns the secret.
* Fixed: calls without confirmation return a structured refusal before any credential validation or storage.

= 1.0.17 =
* Changed the shared cache Adapter to treat an unconfigured Cloudflare edge as optional when a prior local cache Adapter has already returned a successful purge receipt.

= 1.0.16 =
* Added: one reusable, bounded deep-purge implementation is shared by the MCP ability and cache-coherence hooks.
* Added: generic frontend URL invalidation purges public HTML prefixes and returns structured success or failure details.
* Added: completed plugin installs and updates purge the public HTML root prefix and store bounded status without interrupting the upgrader.

= 1.0.15 =
* Removed organization-specific runtime identity from cache probes and managed WordPress HTML cache rules.
* Existing equivalent rules are now recognized by policy behavior rather than a branded label or reference.

= 1.0.14 =
* Added: `cloudflare/ensure-wordpress-html-cache-rule` now supports `exclude_paths` for proven cache-rule bypass cases and preserves existing custom excludes on later updates.

= 1.0.13 =
* Changed: extensionless/html URLs passed to `cloudflare/clear-cache` as `files` now automatically use Cloudflare prefix purge.
* Changed: mixed HTML page and asset inputs are split into the correct purge operations instead of forcing one purge type.

= 1.0.12 =
* Fixed: prefix purges now normalize full URLs and scheme-less prefixes into Cloudflare's required `host/path` format.

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
