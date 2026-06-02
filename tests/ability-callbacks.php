<?php
/**
 * Callback-level regression tests for MCP Abilities - Cloudflare.
 *
 * Run from the plugin directory:
 * php tests/ability-callbacks.php
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$registered_abilities = array();
$remote_requests      = array();
$options              = array(
	'cloudflare_api_email'          => 'admin@example.com',
	'cloudflare_api_key'            => str_repeat( 'a', 40 ),
	'cloudflare_api_token'          => '',
	'cloudflare_zone_id'            => '',
	'cloudflare_cached_domain_name' => 'example.com',
);

class WP_Error {
	private string $message;

	public function __construct( string $code = '', string $message = '' ) {
		$this->message = $message;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

function wp_register_ability( string $name, array $args ): void {
	global $registered_abilities;
	$registered_abilities[ $name ] = $args;
}

function add_action( $hook_name, $callback ): void {}

function current_user_can( $capability ): bool {
	return true;
}

function get_option( string $name, $default = '' ) {
	global $options;
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

function update_option( string $name, $value ): void {
	global $options;
	$options[ $name ] = $value;
}

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

function home_url(): string {
	return 'https://example.com';
}

function add_query_arg( string $key, string $value, string $url ): string {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . rawurlencode( $key ) . '=' . rawurlencode( $value );
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function wp_json_encode( $value ): string {
	return json_encode( $value, JSON_THROW_ON_ERROR );
}

function sanitize_text_field( $value ): string {
	return trim( strip_tags( (string) $value ) );
}

function esc_url_raw( $value ): string {
	return trim( (string) $value );
}

function wp_remote_request( string $url, array $args ) {
	global $remote_requests;
	$remote_requests[] = array( 'url' => $url, 'args' => $args );

	if ( str_contains( $url, '/zones?name=example.com' ) ) {
		return array(
			'body' => json_encode(
				array(
					'success' => true,
					'result'  => array(
						array(
							'id'   => 'zone-123',
							'name' => 'example.com',
						),
					),
				),
				JSON_THROW_ON_ERROR
			),
		);
	}

	if ( str_ends_with( $url, '/zones/zone-123' ) ) {
		return array(
			'body' => '{"success":true,"result":{"id":"zone-123","name":"example.com","status":"active"}}',
		);
	}

	if ( str_ends_with( $url, '/settings/development_mode' ) ) {
		return array(
			'body' => '{"success":true,"result":{"id":"development_mode","value":"off"}}',
		);
	}

	if ( str_ends_with( $url, '/purge_cache' ) ) {
		$headers = $args['headers'] ?? array();
		if ( str_repeat( 'b', 40 ) === ( $headers['X-Auth-Key'] ?? '' ) ) {
			return array(
				'body' => '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}',
			);
		}

		return array(
			'body' => '{"success":true,"result":{"id":"purge-123"}}',
		);
	}

	return array(
		'body' => '{"success":false,"errors":[{"code":1000,"message":"unexpected test URL"}]}',
	);
}

function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}

require dirname( __DIR__ ) . '/mcp-abilities-cloudflare.php';

mcp_register_cloudflare_abilities();

assert( isset( $registered_abilities['cloudflare/get-zone'] ) );
assert( isset( $registered_abilities['cloudflare/get-development-mode'] ) );
assert( isset( $registered_abilities['cloudflare/set-development-mode'] ) );
assert( isset( $registered_abilities['cloudflare/clear-cache'] ) );

foreach ( array( 'cloudflare/get-zone', 'cloudflare/get-development-mode' ) as $ability_name ) {
	$schema = $registered_abilities[ $ability_name ]['input_schema'];
	assert( array( 'object', 'array', 'null' ) === $schema['type'] );
	assert( is_array( $schema['properties'] ) );
	assert( ! empty( $schema['properties']['_ignored'] ) );
	assert( false === $schema['additionalProperties'] );
	assert( 0 === $schema['maxItems'] );
}

assert( mcp_cloudflare_is_global_api_key( str_repeat( 'a', 40 ) ) );
assert( ! mcp_cloudflare_is_global_api_key( 'cfut_' . str_repeat( 'A', 40 ) ) );
assert( ! mcp_cloudflare_is_global_api_key( str_repeat( 'A', 40 ) ) );

$get_zone = $registered_abilities['cloudflare/get-zone']['execute_callback'];
$zone     = $get_zone( new stdClass() );
assert( true === $zone['success'] );
assert( 'zone-123' === $zone['zone']['id'] );

$get_development_mode = $registered_abilities['cloudflare/get-development-mode']['execute_callback'];
$development_mode     = $get_development_mode( new stdClass() );
assert( true === $development_mode['success'] );
assert( 'off' === $development_mode['value'] );

$set_input        = new stdClass();
$set_input->value = 'on';
$set_development_mode = $registered_abilities['cloudflare/set-development-mode']['execute_callback'];
$set_result            = $set_development_mode( $set_input );
assert( true === $set_result['success'] );
assert( 'off' === $set_result['value'] );

$clear_input                   = new stdClass();
$clear_input->purge_everything = true;
$clear_cache                   = $registered_abilities['cloudflare/clear-cache']['execute_callback'];
$clear_result                  = $clear_cache( $clear_input );
assert( true === $clear_result['success'] );

$remote_requests = array();
$options['cloudflare_api_key']   = 'cfut_' . str_repeat( 'A', 40 );
$options['cloudflare_api_token'] = '';
$options['cloudflare_zone_id']   = 'zone-123';
$token_result                    = $clear_cache( array( 'purge_everything' => true ) );
$token_headers                   = $remote_requests[0]['args']['headers'] ?? array();
assert( true === $token_result['success'] );
assert( isset( $token_headers['Authorization'] ) );
assert( 'Bearer ' . $options['cloudflare_api_key'] === $token_headers['Authorization'] );
assert( ! isset( $token_headers['X-Auth-Key'] ) );

$remote_requests = array();
$options['cloudflare_api_email'] = 'admin@example.com';
$options['cloudflare_api_key']   = str_repeat( 'b', 40 );
$options['cloudflare_api_token'] = '';
$options['cloudflare_zone_id']   = 'zone-123';
$fallback_result                 = mcp_cloudflare_api_request(
	'POST',
	'https://api.cloudflare.com/client/v4/zones/zone-123/purge_cache',
	array(
		'api_email' => $options['cloudflare_api_email'],
		'api_key'   => $options['cloudflare_api_key'],
		'api_token' => '',
	),
	array( 'body' => '{"purge_everything":true}' )
);
$first_headers                   = $remote_requests[0]['args']['headers'] ?? array();
$second_headers                  = $remote_requests[1]['args']['headers'] ?? array();
assert( is_array( $fallback_result ) );
assert( 'token' === $fallback_result['auth_mode'] );
assert( $options['cloudflare_api_key'] === ( $first_headers['X-Auth-Key'] ?? '' ) );
assert( 'Bearer ' . $options['cloudflare_api_key'] === ( $second_headers['Authorization'] ?? '' ) );

echo "ability callback regression passed\n";
