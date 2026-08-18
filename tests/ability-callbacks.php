<?php
/**
 * Callback-level regression tests for MCP Abilities - Cloudflare.
 *
 * Run from the plugin directory:
 * php tests/ability-callbacks.php
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

class MCP_Cloudflare_Logger_Fixture {
	public function __construct( $debug = false ) {}
}

class MCP_Cloudflare_Data_Store_Fixture {
	public function __construct( $logger ) {}
	public function createUserDataStore( $credential, $email, $unique_id, $user_key ): bool {
		update_option( 'cloudflare_api_key', $credential );
		update_option( 'cloudflare_api_email', $email );
		return true;
	}
	public function setDomainNameCache( $domain ): bool {
		update_option( 'cloudflare_cached_domain_name', $domain );
		return true;
	}
	public function set( $name, $value ): bool {
		update_option( $name, $value );
		return true;
	}
}

class_alias( MCP_Cloudflare_Logger_Fixture::class, 'Cloudflare\\APO\\Integration\\DefaultLogger' );
class_alias( MCP_Cloudflare_Data_Store_Fixture::class, 'Cloudflare\\APO\\WordPress\\DataStore' );

$registered_abilities = array();
$remote_requests      = array();
$registered_actions   = array();
$registered_filters   = array();
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

function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ): void {
	global $registered_actions;
	$registered_actions[ $hook_name ][] = compact( 'callback', 'priority', 'accepted_args' );
}

function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ): void {
	global $registered_filters;
	$registered_filters[ $hook_name ][] = compact( 'callback', 'priority', 'accepted_args' );
}

function current_user_can( $capability ): bool {
	return true;
}

function get_option( string $name, $default = '' ) {
	global $options;
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

function update_option( string $name, $value, $autoload = null ): bool {
	global $options;
	$options[ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	global $options;
	unset( $options[ $name ] );
	return true;
}

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

function home_url( string $path = '' ): string {
	return 'https://example.com';
}

function is_email( $email ): bool {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
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

function sanitize_key( $value ): string {
	return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $value ) );
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
			'headers' => array(),
			'body'    => '{"success":true,"result":{"id":"development_mode","value":"off"}}',
		);
	}

	if ( str_contains( $url, '/settings/browser_cache_ttl' ) ) {
		return array(
			'headers' => array(),
			'body'    => '{"success":true,"result":{"id":"browser_cache_ttl","value":14400}}',
		);
	}

	if ( str_ends_with( $url, '/rulesets' ) ) {
		return array(
			'headers' => array(),
			'body'    => '{"success":true,"result":[{"id":"ruleset-1","phase":"http_request_cache_settings","kind":"zone","name":"Cache rules"}]}',
		);
	}

	if ( str_contains( $url, '/rulesets/phases/http_request_cache_settings/entrypoint' ) ) {
		return array(
			'headers' => array(),
			'body'    => '{"success":true,"result":{"id":"entrypoint-1","phase":"http_request_cache_settings","rules":[{"ref":"existing-rule","description":"Existing cache rule","expression":"(http.host eq \"static.example.com\")","action":"set_cache_settings","action_parameters":{"cache":true},"enabled":true}]}}',
		);
	}

	if ( str_ends_with( $url, '/rulesets/entrypoint-1' ) ) {
		$payload = json_decode( (string) ( $args['body'] ?? '{}' ), true, 512, JSON_THROW_ON_ERROR );
		return array(
			'headers' => array(),
			'body'    => json_encode(
				array(
					'success' => true,
					'result'  => array(
						'id'    => 'entrypoint-1',
						'rules' => $payload['rules'] ?? array(),
					),
				),
				JSON_THROW_ON_ERROR
			),
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

	if ( str_starts_with( $url, 'https://example.com/' ) ) {
		return array(
			'headers'  => array(
				'cf-cache-status' => 'DYNAMIC',
				'cache-control'   => 'no-cache',
				'cf-ray'          => 'test-ray',
				'server'          => 'cloudflare',
				'content-type'    => 'text/html; charset=UTF-8',
			),
			'response' => array( 'code' => 200 ),
			'body'     => '<html></html>',
		);
	}

	return array(
		'body' => '{"success":false,"errors":[{"code":1000,"message":"unexpected test URL"}]}',
	);
}

function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}

function wp_remote_retrieve_headers( array $response ): array {
	return $response['headers'] ?? array();
}

function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 200 );
}

require dirname( __DIR__ ) . '/mcp-abilities-cloudflare.php';

assert( 'mcp_cloudflare_frontend_cache_invalidation_result' === $registered_filters['devenia_workflow_frontend_cache_invalidation_result'][0]['callback'] );
assert( 3 === $registered_filters['devenia_workflow_frontend_cache_invalidation_result'][0]['accepted_args'] );
assert( 'mcp_cloudflare_observe_plugin_upgrade_complete' === $registered_actions['upgrader_process_complete'][0]['callback'] );
assert( 2 === $registered_actions['upgrader_process_complete'][0]['accepted_args'] );

mcp_register_cloudflare_abilities();

assert( isset( $registered_abilities['cloudflare/get-zone'] ) );
assert( isset( $registered_abilities['cloudflare/configure-credentials'] ) );
assert( isset( $registered_abilities['cloudflare/get-development-mode'] ) );
assert( isset( $registered_abilities['cloudflare/get-cache-settings'] ) );
assert( isset( $registered_abilities['cloudflare/get-cache-rulesets'] ) );
assert( isset( $registered_abilities['cloudflare/test-url-cache-status'] ) );
assert( isset( $registered_abilities['cloudflare/ensure-wordpress-html-cache-rule'] ) );
assert( isset( $registered_abilities['cloudflare/set-development-mode'] ) );
assert( isset( $registered_abilities['cloudflare/clear-cache'] ) );
assert( 'mcp_cloudflare_clear_cache_callback' === $registered_abilities['cloudflare/clear-cache']['execute_callback'] );

$configure = $registered_abilities['cloudflare/configure-credentials']['execute_callback'];
$before    = $options;
$blocked   = $configure(
	array(
		'api_credential' => str_repeat( 'b', 40 ),
		'email'          => 'owner@example.com',
	)
);
assert( false === $blocked['success'] );
assert( 'cloudflare_confirmation_required' === $blocked['code'] );
assert( $before === $options );

$configured = $configure(
	array(
		'api_credential'          => str_repeat( 'b', 40 ),
		'email'                   => 'owner@example.com',
		'confirm_dangerous_action' => 'cloudflare/configure-credentials',
	)
);
assert( true === $configured['success'] );
assert( true === $configured['configured'] );
assert( 'zone-123' === $configured['zone_id'] );
assert( str_repeat( 'b', 40 ) === $options['cloudflare_api_key'] );
assert( 'owner@example.com' === $options['cloudflare_api_email'] );
assert( 'zone-123' === $options['cloudflare_zone_id'] );
assert( 'example.com' === $options['cloudflare_cached_domain_name'] );
assert( ! isset( $options['cloudflare_api_token'] ) );
assert( ! str_contains( wp_json_encode( $configured ), str_repeat( 'b', 40 ) ) );
assert( 1 === count( $remote_requests ) );
assert( str_contains( $remote_requests[0]['url'], '/zones?name=example.com' ) );
assert( str_repeat( 'b', 40 ) === $remote_requests[0]['args']['headers']['X-Auth-Key'] );
$options         = $before;
$remote_requests = array();

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
assert( 'example.com/page/' === mcp_cloudflare_normalize_purge_prefix( 'https://example.com/page/' ) );
assert( 'example.com/page/' === mcp_cloudflare_normalize_purge_prefix( 'example.com/page/' ) );
assert( 'example.com/page/' === mcp_cloudflare_html_file_url_to_prefix( 'https://example.com/page/', array() ) );
assert( 'example.com/page/' === mcp_cloudflare_html_file_url_to_prefix( 'https://example.com/page', array() ) );
assert( '' === mcp_cloudflare_html_file_url_to_prefix( 'https://example.com/wp-content/app.css', array() ) );
assert( '' === mcp_cloudflare_html_file_url_to_prefix( 'https://example.com/page/?preview=1', array() ) );

$get_zone = $registered_abilities['cloudflare/get-zone']['execute_callback'];
$zone     = $get_zone( new stdClass() );
assert( true === $zone['success'] );
assert( 'zone-123' === $zone['zone']['id'] );

$get_development_mode = $registered_abilities['cloudflare/get-development-mode']['execute_callback'];
$development_mode     = $get_development_mode( new stdClass() );
assert( true === $development_mode['success'] );
assert( 'off' === $development_mode['value'] );

$get_cache_settings = $registered_abilities['cloudflare/get-cache-settings']['execute_callback'];
$cache_settings     = $get_cache_settings( array( 'settings' => array( 'browser_cache_ttl' ) ) );
assert( true === $cache_settings['success'] );
assert( 14400 === $cache_settings['settings'][0]['setting']['value'] );

$get_cache_rulesets = $registered_abilities['cloudflare/get-cache-rulesets']['execute_callback'];
$cache_rulesets     = $get_cache_rulesets( new stdClass() );
assert( true === $cache_rulesets['success'] );
assert( 'http_request_cache_settings' === $cache_rulesets['rulesets'][0]['phase'] );
assert( true === $cache_rulesets['entrypoint']['success'] );

$test_url_cache = $registered_abilities['cloudflare/test-url-cache-status']['execute_callback'];
$url_status     = $test_url_cache(
	array(
		'urls'   => array( 'https://example.com/' ),
		'repeat' => 1,
	)
);
assert( true === $url_status['success'] );
assert( 'DYNAMIC' === $url_status['results'][0]['attempts'][0]['cf_cache_status'] );

$ensure_cache_rule = $registered_abilities['cloudflare/ensure-wordpress-html-cache-rule']['execute_callback'];
$dry_rule          = $ensure_cache_rule( array() );
assert( true === $dry_rule['success'] );
assert( true === $dry_rule['dry_run'] );
assert( 'created' === $dry_rule['action'] );
assert( 2 === $dry_rule['rule_count'] );
assert( str_contains( $dry_rule['rule']['expression'], 'wordpress_logged_in_' ) );

$remote_requests = array();
$write_rule      = $ensure_cache_rule(
	array(
		'dry_run'          => false,
		'edge_ttl_seconds' => 7200,
	)
);
assert( true === $write_rule['success'] );
assert( false === $write_rule['dry_run'] );
assert( 2 === $write_rule['rule_count'] );
$put_request = end( $remote_requests );
assert( str_ends_with( $put_request['url'], '/rulesets/entrypoint-1' ) );
$put_body = json_decode( (string) $put_request['args']['body'], true, 512, JSON_THROW_ON_ERROR );
assert( 'existing-rule' === $put_body['rules'][0]['ref'] );
assert( 'mcp-wordpress-public-html-cache' === $put_body['rules'][1]['ref'] );
assert( 7200 === $put_body['rules'][1]['action_parameters']['edge_ttl']['default'] );

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
assert( 'everything' === $clear_result['purge']['type'] );
assert( 'purge-123' === $clear_result['purge']['cloudflare_id'] );
assert( 'mcp_cloudflare_deep_purge' === $clear_result['purge']['implementation'] );

$remote_requests = array();
$prefix_result   = $clear_cache(
	array(
		'purge_everything' => false,
		'prefixes'         => array( 'https://example.com/page/' ),
	)
);
$prefix_request  = end( $remote_requests );
$prefix_body     = json_decode( (string) $prefix_request['args']['body'], true, 512, JSON_THROW_ON_ERROR );
assert( true === $prefix_result['success'] );
assert( 'prefixes' === $prefix_result['purge']['type'] );
assert( array( 'prefixes' => array( 'example.com/page/' ) ) === $prefix_body );

$remote_requests = array();
$auto_prefix_result = $clear_cache(
	array(
		'purge_everything' => false,
		'files'            => array( 'https://example.com/page/' ),
	)
);
$auto_prefix_request = end( $remote_requests );
$auto_prefix_body    = json_decode( (string) $auto_prefix_request['args']['body'], true, 512, JSON_THROW_ON_ERROR );
assert( true === $auto_prefix_result['success'] );
assert( 'prefixes' === $auto_prefix_result['purge']['type'] );
assert( array( 'prefixes' => array( 'example.com/page/' ) ) === $auto_prefix_body );
assert( 'example.com/page/' === $auto_prefix_result['purge']['auto_prefixes']['https://example.com/page/'] );

$remote_requests = array();
$workflow_result = mcp_cloudflare_frontend_cache_invalidation_result(
	null,
	array( 'https://example.com/fr/plugins/example-workflow/' ),
	array( 'source' => 'translation-publication' )
);
$workflow_body   = json_decode( (string) $remote_requests[0]['args']['body'], true, 512, JSON_THROW_ON_ERROR );
assert( true === $workflow_result['success'] );
assert( 'mcp_cloudflare_deep_purge' === $workflow_result['purge']['implementation'] );
assert( array( 'prefixes' => array( 'example.com/fr/plugins/example-workflow/' ) ) === $workflow_body );

$valid_local_receipt = array(
	'success' => true,
	'adapter' => array( 'name' => 'cache-enabler' ),
	'purge' => array( 'implementation' => 'mcp_cache_enabler_frontend_cache_invalidation_result', 'count' => 1, 'urls' => array( 'https://example.com/fr/' ) ),
);
$remote_requests = array();
$composed_success = mcp_cloudflare_frontend_cache_invalidation_result( $valid_local_receipt, array( 'https://example.com/fr/' ), array() );
assert( true === $composed_success['success'] );
assert( $valid_local_receipt === $composed_success['local_cache'] );
assert( 1 === count( $remote_requests ) );

$local_failure_receipt = array( 'success' => false, 'error' => array( 'code' => 'local_cache_purge_failed' ) );
$remote_requests = array();
$composed_failure = mcp_cloudflare_frontend_cache_invalidation_result( $local_failure_receipt, array( 'https://example.com/fr/' ), array() );
assert( false === $composed_failure['success'] );
assert( 'local_cache_purge_failed' === $composed_failure['error']['code'] );
assert( array() === $remote_requests );

$remote_requests = array();
$bounded_urls    = array();
for ( $index = 0; $index < 105; $index++ ) {
	$bounded_urls[] = 'https://example.com/page-' . $index . '/';
}
$bounded_result = mcp_cloudflare_frontend_cache_invalidation_result( null, $bounded_urls, array() );
$bounded_body   = json_decode( (string) $remote_requests[0]['args']['body'], true, 512, JSON_THROW_ON_ERROR );
assert( true === $bounded_result['success'] );
assert( 100 === count( $bounded_body['prefixes'] ) );

$remote_requests = array();
$invalid_result  = mcp_cloudflare_frontend_cache_invalidation_result( null, array( 'javascript:alert(1)', 'https://outside.invalid/page/' ), array() );
assert( false === $invalid_result['success'] );
assert( 'invalid_purge_targets' === $invalid_result['error']['code'] );
assert( array() === $remote_requests );

$remote_requests = array();
mcp_cloudflare_observe_plugin_upgrade_complete( new stdClass(), array( 'type' => 'plugin', 'action' => 'update', 'plugins' => array( 'one/one.php', 'two/two.php' ) ) );
$upgrade_body = json_decode( (string) $remote_requests[0]['args']['body'], true, 512, JSON_THROW_ON_ERROR );
assert( 1 === count( $remote_requests ) );
assert( array( 'prefixes' => array( 'example.com/' ) ) === $upgrade_body );
assert( true === $options['mcp_cloudflare_last_plugin_upgrade_purge']['success'] );

$request_count = count( $remote_requests );
mcp_cloudflare_observe_plugin_upgrade_complete( new stdClass(), array( 'type' => 'theme', 'action' => 'update' ) );
mcp_cloudflare_observe_plugin_upgrade_complete( new stdClass(), array( 'type' => 'plugin', 'action' => 'delete' ) );
assert( $request_count === count( $remote_requests ) );

$remote_requests = array();
$mixed_result    = $clear_cache(
	array(
		'purge_everything' => false,
		'files'            => array(
			'https://example.com/wp-content/app.css',
			'https://example.com/page/',
		),
	)
);
$mixed_first_body  = json_decode( (string) $remote_requests[0]['args']['body'], true, 512, JSON_THROW_ON_ERROR );
$mixed_second_body = json_decode( (string) $remote_requests[1]['args']['body'], true, 512, JSON_THROW_ON_ERROR );
assert( true === $mixed_result['success'] );
assert( 'multi' === $mixed_result['purge']['type'] );
assert( array( 'files' => array( 'https://example.com/wp-content/app.css' ) ) === $mixed_first_body );
assert( array( 'prefixes' => array( 'example.com/page/' ) ) === $mixed_second_body );

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

$options['cloudflare_api_email'] = '';
$options['cloudflare_api_key']   = '';
$options['cloudflare_api_token'] = '';
$structured_error                = mcp_cloudflare_frontend_cache_invalidation_result( null, array( 'https://example.com/fr/' ), array() );
assert( false === $structured_error['success'] );
assert( 'cloudflare_context' === $structured_error['error']['code'] );
assert( 'mcp_cloudflare_deep_purge' === $structured_error['purge']['implementation'] );

$local_cache_receipt = array(
	'success' => true,
	'adapter' => array( 'name' => 'cache-enabler' ),
	'purge' => array( 'implementation' => 'mcp_cache_enabler_frontend_cache_invalidation_result', 'count' => 1, 'urls' => array( 'https://example.com/fr/' ) ),
);
$optional_edge_result = mcp_cloudflare_frontend_cache_invalidation_result( $local_cache_receipt, array( 'https://example.com/fr/' ), array() );
assert( true === $optional_edge_result['success'] );
assert( 'cache-enabler' === $optional_edge_result['adapter']['name'] );
assert( true === $optional_edge_result['edge_cache']['skipped'] );
assert( 'cloudflare_not_configured' === $optional_edge_result['edge_cache']['reason'] );

$no_local_cache_receipt = array(
	'success' => true,
	'adapter' => array( 'name' => 'cache-enabler' ),
	'purge' => array( 'implementation' => 'mcp_cache_enabler_frontend_cache_invalidation_result', 'count' => 0, 'urls' => array( 'https://example.com/fr/' ) ),
	'local_cache' => array( 'skipped' => true, 'reason' => 'no_local_page_cache_configured' ),
);
$no_local_edge_result = mcp_cloudflare_frontend_cache_invalidation_result( $no_local_cache_receipt, array( 'https://example.com/fr/' ), array() );
assert( true === $no_local_edge_result['success'] );
assert( true === $no_local_edge_result['edge_cache']['skipped'] );

$duplicate_edge_result = mcp_cloudflare_frontend_cache_invalidation_result( $local_cache_receipt, array( 'https://example.com/fr/', 'https://example.com/fr/' ), array() );
assert( true === $duplicate_edge_result['success'] );
assert( true === $duplicate_edge_result['edge_cache']['skipped'] );

$bounded_receipt_urls = array();
for ( $index = 0; $index < 100; $index++ ) {
	$bounded_receipt_urls[] = 'https://example.com/page-' . $index . '/';
}
$bounded_local_receipt = array(
	'success' => true,
	'adapter' => array( 'name' => 'cache-enabler' ),
	'purge' => array( 'implementation' => 'mcp_cache_enabler_frontend_cache_invalidation_result', 'count' => 100, 'urls' => $bounded_receipt_urls ),
);
$over_limit_urls = array_merge( $bounded_receipt_urls, array( 'https://example.com/page-100/', 'https://example.com/page-101/' ) );
$bounded_edge_result = mcp_cloudflare_frontend_cache_invalidation_result( $bounded_local_receipt, $over_limit_urls, array() );
assert( true === $bounded_edge_result['success'] );
assert( true === $bounded_edge_result['edge_cache']['skipped'] );

$malformed_local_receipt = array(
	'success' => true,
	'adapter' => array( 'name' => 'cache-enabler' ),
	'purge' => array( 'implementation' => 'mcp_cache_enabler_frontend_cache_invalidation_result' ),
);
$malformed_local_result = mcp_cloudflare_frontend_cache_invalidation_result( $malformed_local_receipt, array( 'https://example.com/fr/' ), array() );
assert( false === $malformed_local_result['success'] );
assert( 'invalid_local_cache_receipt' === $malformed_local_result['error']['code'] );

$mismatched_local_receipt = $local_cache_receipt;
$mismatched_local_receipt['purge']['urls'] = array( 'https://example.com/other/' );
$mismatched_local_result = mcp_cloudflare_frontend_cache_invalidation_result( $mismatched_local_receipt, array( 'https://example.com/fr/' ), array() );
assert( false === $mismatched_local_result['success'] );
assert( 'invalid_local_cache_receipt' === $mismatched_local_result['error']['code'] );

$unrelated_receipt = array( 'success' => true, 'adapter' => array( 'name' => 'unrelated' ), 'purge' => array( 'implementation' => 'unrelated' ) );
$unrelated_result = mcp_cloudflare_frontend_cache_invalidation_result( $unrelated_receipt, array( 'https://example.com/fr/' ), array() );
assert( false === $unrelated_result['success'] );
assert( 'invalid_local_cache_receipt' === $unrelated_result['error']['code'] );

$invalid_optional_result = mcp_cloudflare_frontend_cache_invalidation_result( $local_cache_receipt, array( 'javascript:alert(1)', 'https://outside.invalid/page/' ), array() );
assert( false === $invalid_optional_result['success'] );
assert( 'invalid_purge_targets' === $invalid_optional_result['error']['code'] );

mcp_cloudflare_observe_plugin_upgrade_complete( new stdClass(), array( 'type' => 'plugin', 'action' => 'install', 'plugin' => 'sample/sample.php' ) );
assert( false === $options['mcp_cloudflare_last_plugin_upgrade_purge']['success'] );
assert( 'cloudflare_context' === $options['mcp_cloudflare_last_plugin_upgrade_purge']['error'] );
assert( strlen( $options['mcp_cloudflare_last_plugin_upgrade_purge']['message'] ) <= 500 );

echo "ability callback regression passed\n";
