<?php
/** Verify purge target coverage and rejection through the public callbacks. */
declare( strict_types=1 );
require __DIR__ . '/ability-callbacks.php';
$options['cloudflare_api_email'] = 'admin@example.com';
$options['cloudflare_api_key'] = str_repeat( 'a', 40 );
$options['cloudflare_api_token'] = '';
$options['cloudflare_zone_id'] = 'zone-123';
$options['cloudflare_cached_domain_name'] = 'example.com';
$failures = array();
$check = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) { $failures[] = $message; }
};
foreach ( array(
	array( 'files' => array( 'not-a-url' ) ),
	array( 'files' => array( 'https://outside.invalid/page/' ) ),
	array( 'purge_everything' => false, 'files' => array( 'https://example.com/valid/', 'not-a-url' ) ),
) as $input ) {
	$remote_requests = array();
	$result = $clear_cache( $input );
	$check( false === $result['success'] && array() === $remote_requests, 'Invalid targets must fail without any purge: ' . json_encode( $input ) );
}
$urls = array_map( static fn( $i ) => 'https://example.com/page-' . $i . '/', range( 0, 104 ) );
$remote_requests = array();
$result = mcp_cloudflare_frontend_cache_invalidation_result( null, $urls, array() );
$sent = array();
foreach ( $remote_requests as $request ) {
	$body = json_decode( $request['args']['body'], true );
	$sent = array_merge( $sent, $body['prefixes'] ?? array() );
	$check( count( $body['prefixes'] ?? array() ) <= 100, 'Every prefix request must stay within the Cloudflare limit.' );
}
$check( true === $result['success'] && 105 === count( array_unique( $sent ) ), 'Shared invalidation must purge all 105 targets, not the first 100.' );
$remote_requests = array();
$urls[104] = 'https://outside.invalid/page/';
$result = mcp_cloudflare_frontend_cache_invalidation_result( null, $urls, array() );
$check( false === $result['success'] && array() === $remote_requests, 'Invalid target after index 100 must fail before any purge.' );
$remote_requests = array();
$result = $clear_cache( array( 'purge_everything' => false, 'files' => array_map( static fn( $i ) => 'https://example.com/page-' . $i . '/', range( 0, 99 ) ), 'prefixes' => array( 'example.com/other/' ) ) );
$check( true === $result['success'] && 101 === $result['purge']['count'], 'Explicit and automatic prefixes must both be retained.' );

$urls = array_map( static fn( $i ) => 'https://example.com/page-' . $i . '/', range( 0, 104 ) );
$receipt = array( 'success' => true, 'adapter' => array( 'name' => 'cache-enabler' ), 'purge' => array( 'implementation' => 'mcp_cache_enabler_frontend_cache_invalidation_result', 'count' => 105, 'urls' => $urls ) );
$remote_requests = array();
$result = mcp_cloudflare_frontend_cache_invalidation_result( $receipt, $urls, array() );
$check( true === $result['success'] && $receipt === $result['local_cache'] && 105 === $result['purge']['count'], 'Complete local receipt must compose with the complete edge purge.' );
$remote_requests = array();
$GLOBALS['purge_failure_after'] = 1;
$result = mcp_cloudflare_frontend_cache_invalidation_result( null, $urls, array() );
unset( $GLOBALS['purge_failure_after'] );
$check( false === $result['success'] && 100 === ( $result['purge']['completed'][0]['count'] ?? 0 ) && 2 === count( $remote_requests ), 'A later failed batch must report failure and retain the completed batch.' );
foreach ( array( array( 'prefixes' => array( 'outside.invalid/path/' ) ), array( 'hosts' => array( 'outside.invalid' ) ), array( 'prefixes' => array( 'example.com/path/?query=1' ) ) ) as $input ) {
	$remote_requests = array();
	$result = $clear_cache( $input );
	$check( false === $result['success'] && array() === $remote_requests, 'Invalid host or prefix must fail without a purge.' );
}
if ( $failures ) { fwrite( STDERR, implode( "\n", $failures ) . "\n" ); exit( 1 ); }
echo "purge target coverage passed\n";
