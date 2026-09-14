<?php
/** Verify the proposed Cloudflare expression through the registered ability. */
declare( strict_types=1 );
require __DIR__ . '/ability-callbacks.php';
$options['cloudflare_api_email'] = 'admin@example.com';
$options['cloudflare_api_key'] = str_repeat( 'a', 40 );
$options['cloudflare_api_token'] = '';
$options['cloudflare_zone_id'] = 'zone-123';
$options['cloudflare_cached_domain_name'] = 'example.com';
$remote_requests = array();
$result = $ensure_cache_rule( array( 'exclude_paths' => array( '/members/' ) ) );
assert( true === $result['success'] && true === $result['dry_run'] );
$expression = $result['rule']['expression'];
assert( str_contains( $expression, '(http.request.uri.path ne "/members") and (not starts_with(http.request.uri.path, "/members/"))' ), 'Excluded path must cover both its root and descendants, not just the slash-terminated page.' );
assert( array( '/members/' ) === mcp_cloudflare_extract_custom_cache_exclude_paths( $expression ), 'A later update must retain the generated prefix exclusion.' );
assert( array( '/legacy/' ) === mcp_cloudflare_extract_custom_cache_exclude_paths( '(http.request.uri.path ne "/wp-login.php") and (http.request.uri.path ne "/legacy/")' ), 'An earlier exact custom exclusion must remain available for migration.' );
assert( ! array_filter( $remote_requests, static fn( $r ) => in_array( $r['args']['method'], array( 'POST', 'PUT', 'PATCH' ), true ) ), 'Preview must not change remote rules.' );
echo "cache rule exclusions passed\n";
