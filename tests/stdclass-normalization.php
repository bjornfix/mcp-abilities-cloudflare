<?php
/**
 * Regression test for stdClass-shaped MCP input and Cloudflare API responses.
 *
 * Run from the plugin directory:
 * php tests/stdclass-normalization.php
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

function add_action( $hook_name, $callback ): void {}

require dirname( __DIR__ ) . '/mcp-abilities-cloudflare.php';

$input           = new stdClass();
$input->files    = array( 'https://example.com/page/' );
$input->nested   = new stdClass();
$input->nested->a = 'b';

$normalized_input = mcp_cloudflare_normalize_input( $input );
assert( is_array( $normalized_input ) );
assert( $normalized_input['files'][0] === 'https://example.com/page/' );
assert( $normalized_input['nested']['a'] === 'b' );

$body              = new stdClass();
$body->success     = false;
$body->errors      = array();
$error             = new stdClass();
$error->code       = 6003;
$error->message    = 'Invalid request headers';
$body->errors[]    = $error;
$normalized_body   = mcp_cloudflare_normalize_data( $body );

assert( is_array( $normalized_body ) );
assert( mcp_cloudflare_is_invalid_header_error( $body ) );
assert( mcp_cloudflare_api_error_message( $body ) === 'Invalid request headers' );

$zone           = new stdClass();
$zone->success  = true;
$zone->result   = new stdClass();
$zone->result->id = 'example-zone-id';
$zone_array     = mcp_cloudflare_normalize_data( $zone );

assert( $zone_array['result']['id'] === 'example-zone-id' );

echo "stdClass normalization regression passed\n";
