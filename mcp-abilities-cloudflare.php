<?php
/**
 * Plugin Name: MCP Abilities - Cloudflare
 * Plugin URI: https://devenia.com/plugins/mcp-abilities-cloudflare/
 * Description: Cloudflare abilities for MCP. Inspect and clear Cloudflare cache for WordPress sites.
 * Version: 1.0.22
 * Author: basicus
 * Author URI: https://profiles.wordpress.org/basicus/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 *
 * @package MCP_Abilities_Cloudflare
 */

declare( strict_types=1 );

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_init', static function () {
	require_once __DIR__ . '/includes/devenia-updater-notice.php';
	mcp_abilities_cloudflare_Updater_Notice::register( __FILE__ );
} );

/**
 * Check if Abilities API is available.
 */
function mcp_cloudflare_check_dependencies(): bool {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>MCP Abilities - Cloudflare</strong> requires the <a href="https://developer.wordpress.org/apis/abilities-api/">WordPress Abilities API</a> available in WordPress 6.9 or newer.</p></div>';
		} );
		return false;
	}
	return true;
}

/**
 * Permission callback for Cloudflare abilities.
 */
function mcp_cloudflare_permission_callback(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Read a Cloudflare-related option or constant as a trimmed string.
 *
 * @param string $option_name   WordPress option name.
 * @param string $constant_name Optional constant name.
 * @return string
 */
function mcp_cloudflare_get_config_value( string $option_name, string $constant_name = '' ): string {
	if ( '' !== $constant_name && defined( $constant_name ) && constant( $constant_name ) !== '' ) {
		return trim( (string) constant( $constant_name ) );
	}

	$value = get_option( $option_name, '' );

	return is_string( $value ) ? trim( $value ) : '';
}

/**
 * Recursively normalize decoded JSON, MCP input, and option payloads to arrays.
 *
 * Some MCP clients and WordPress internals may pass empty object-shaped values as stdClass.
 * The ability callbacks use array access internally, so normalize once at the boundary.
 *
 * @param mixed $value Value to normalize.
 * @return mixed
 */
function mcp_cloudflare_normalize_data( $value ) {
	if ( $value instanceof stdClass ) {
		$value = get_object_vars( $value );
	}

	if ( is_array( $value ) ) {
		foreach ( $value as $key => $item ) {
			$value[ $key ] = mcp_cloudflare_normalize_data( $item );
		}
	}

	return $value;
}

/**
 * Normalize ability callback input to an array.
 *
 * @param mixed $input Raw ability input.
 * @return array
 */
function mcp_cloudflare_normalize_input( $input ): array {
	$input = mcp_cloudflare_normalize_data( $input );

	return is_array( $input ) ? $input : array();
}

/**
 * Return a validator-safe schema for abilities with no meaningful input.
 *
 * JSON `{}` can arrive at PHP as an empty array or null before WP_Ability
 * validation, depending on the MCP Adapter/Abilities API version. Accept those
 * no-parameter representations without requiring callers to send a placeholder.
 *
 * @return array
 */
function mcp_cloudflare_empty_input_schema(): array {
	return array(
		'type'                 => array( 'object', 'array', 'null' ),
		'properties'           => array(
			'_ignored' => array(
				'type'        => 'boolean',
				'description' => 'Optional placeholder ignored by this ability.',
			),
		),
		'additionalProperties' => false,
		'maxItems'             => 0,
	);
}

/**
 * Determine whether a credential is a Cloudflare Global API Key.
 *
 * Mirrors the official Cloudflare WordPress plugin's v4.14.3 credential
 * classification: global keys use X-Auth-Email + X-Auth-Key, API tokens use
 * Authorization: Bearer. The Cloudflare plugin stores both in cloudflare_api_key.
 *
 * @param string $credential Cloudflare API credential.
 * @return bool
 */
function mcp_cloudflare_is_global_api_key( string $credential ): bool {
	if ( '' === $credential ) {
		return false;
	}

	if ( str_starts_with( $credential, 'cfk_' ) ) {
		return true;
	}

	if ( str_starts_with( $credential, 'cfut_' ) || str_starts_with( $credential, 'cfat_' ) ) {
		return false;
	}

	$length = strlen( $credential );
	return $length >= 37 && $length <= 45 && 1 === preg_match( '/^[0-9a-f]+$/', $credential );
}

/**
 * Build Cloudflare API headers for the selected auth mode.
 *
 * @param array  $context   Cloudflare context.
 * @param string $auth_mode Auth mode: token or key.
 * @return array
 */
function mcp_cloudflare_api_headers( array $context, string $auth_mode ): array {
	$headers = array(
		'Content-Type' => 'application/json',
	);

	if ( 'token' === $auth_mode ) {
		$headers['Authorization'] = 'Bearer ' . $context['api_token'];
		return $headers;
	}

	$headers['X-Auth-Email'] = $context['api_email'];
	$headers['X-Auth-Key']   = $context['api_key'];

	return $headers;
}

/**
 * Extract a readable Cloudflare API error message.
 *
 * @param mixed $body Decoded response body.
 * @return string
 */
function mcp_cloudflare_api_error_message( $body ): string {
	$body = mcp_cloudflare_normalize_data( $body );
	if ( empty( $body['errors'] ) || ! is_array( $body['errors'] ) ) {
		return 'Unknown error';
	}

	$error = reset( $body['errors'] );
	if ( is_array( $error ) && ! empty( $error['message'] ) ) {
		return (string) $error['message'];
	}

	return 'Unknown error';
}

/**
 * Determine whether a Cloudflare response failed because auth headers were wrong.
 *
 * @param mixed $body Decoded response body.
 * @return bool
 */
function mcp_cloudflare_is_invalid_header_error( $body ): bool {
	$body = mcp_cloudflare_normalize_data( $body );
	if ( empty( $body['errors'] ) || ! is_array( $body['errors'] ) ) {
		return false;
	}

	foreach ( $body['errors'] as $error ) {
		if ( ! is_array( $error ) ) {
			continue;
		}

		$code    = isset( $error['code'] ) ? (int) $error['code'] : 0;
		$message = isset( $error['message'] ) ? strtolower( (string) $error['message'] ) : '';

		if ( 6003 === $code || str_contains( $message, 'invalid request headers' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Determine whether a Cloudflare response should retry with alternate auth.
 *
 * @param mixed $body Decoded response body.
 * @return bool
 */
function mcp_cloudflare_is_auth_retry_error( $body ): bool {
	if ( mcp_cloudflare_is_invalid_header_error( $body ) ) {
		return true;
	}

	$body = mcp_cloudflare_normalize_data( $body );
	if ( empty( $body['errors'] ) || ! is_array( $body['errors'] ) ) {
		return false;
	}

	foreach ( $body['errors'] as $error ) {
		if ( ! is_array( $error ) ) {
			continue;
		}

		$code    = isset( $error['code'] ) ? (int) $error['code'] : 0;
		$message = isset( $error['message'] ) ? strtolower( (string) $error['message'] ) : '';

		if ( 10000 === $code || str_contains( $message, 'authentication error' ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Return auth modes to try, in order.
 *
 * The official Cloudflare plugin stores both Global API Keys and API Tokens in
 * cloudflare_api_key. Match its credential-format check so token installs use
 * Bearer auth immediately instead of trying X-Auth-Key first.
 *
 * @param array $context Cloudflare context.
 * @return array
 */
function mcp_cloudflare_auth_modes( array $context ): array {
	$modes = array();

	if ( ! empty( $context['api_token'] ) ) {
		$modes[] = 'token';
	}

	if ( ! empty( $context['api_key'] ) && ! mcp_cloudflare_is_global_api_key( $context['api_key'] ) ) {
		$modes[] = 'token';
	}

	if ( ! empty( $context['api_email'] ) && ! empty( $context['api_key'] ) && mcp_cloudflare_is_global_api_key( $context['api_key'] ) ) {
		$modes[] = 'key';
	}

	if ( ! empty( $context['api_email'] ) && ! empty( $context['api_key'] ) ) {
		$modes[] = mcp_cloudflare_is_global_api_key( $context['api_key'] ) ? 'token' : 'key';
	}

	return array_values( array_unique( $modes ) );
}

/**
 * Make a Cloudflare API request with auth-header fallback.
 *
 * @param string $method  HTTP method.
 * @param string $url     Cloudflare API URL.
 * @param array  $context Cloudflare context.
 * @param array  $args    Extra request args.
 * @return array|WP_Error
 */
function mcp_cloudflare_api_request( string $method, string $url, array $context, array $args = array() ) {
	$modes = mcp_cloudflare_auth_modes( $context );
	if ( empty( $modes ) ) {
		return new WP_Error( 'cloudflare_missing_credentials', 'Cloudflare API credentials not configured. Install and configure the Cloudflare plugin first.' );
	}

	foreach ( $modes as $index => $mode ) {
		if ( 'token' === $mode && empty( $context['api_token'] ) && ! empty( $context['api_key'] ) ) {
			$context['api_token'] = $context['api_key'];
		}

		$request_args            = $args;
		$request_args['method']  = $method;
		$request_args['headers'] = mcp_cloudflare_api_headers( $context, $mode );
		$request_args['timeout'] = isset( $request_args['timeout'] ) ? $request_args['timeout'] : 30;

		$response = wp_remote_request( $url, $request_args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		$body = mcp_cloudflare_normalize_data( $body );
		$body = is_array( $body ) ? $body : null;

		if ( empty( $body['success'] ) && mcp_cloudflare_is_auth_retry_error( $body ) && isset( $modes[ $index + 1 ] ) ) {
			continue;
		}

		return array(
			'response'  => $response,
			'body'      => $body,
			'auth_mode' => $mode,
		);
	}

	return new WP_Error( 'cloudflare_auth', 'Cloudflare API error: Invalid request headers' );
}

/**
 * Resolve and cache the Cloudflare zone ID for a domain.
 *
 * @param array  $context Cloudflare context.
 * @param string $domain    Domain name.
 * @return string|WP_Error
 */
function mcp_cloudflare_get_zone_id( array $context, string $domain ) {
	$zone_id = get_option( 'cloudflare_zone_id', '' );
	if ( ! empty( $zone_id ) ) {
		return $zone_id;
	}

	$zones_result = mcp_cloudflare_api_request(
		'GET',
		add_query_arg( 'name', $domain, 'https://api.cloudflare.com/client/v4/zones' ),
		$context
	);

	if ( is_wp_error( $zones_result ) ) {
		return $zones_result;
	}

	$zones_body = $zones_result['body'];
	if ( empty( $zones_body['success'] ) || empty( $zones_body['result'][0]['id'] ) ) {
		$error_msg = mcp_cloudflare_api_error_message( $zones_body );
		if ( 'Unknown error' === $error_msg ) {
			$error_msg = 'Zone not found';
		}
		return new WP_Error( 'cloudflare_zone', 'Cloudflare API error: ' . $error_msg );
	}

	$zone_id = $zones_body['result'][0]['id'];
	update_option( 'cloudflare_zone_id', $zone_id );

	return $zone_id;
}

/**
 * Resolve Cloudflare credentials and zone context.
 *
 * @return array|WP_Error
 */
function mcp_cloudflare_get_context() {
	$api_email = mcp_cloudflare_get_config_value( 'cloudflare_api_email', 'CLOUDFLARE_EMAIL' );
	$api_key   = mcp_cloudflare_get_config_value( 'cloudflare_api_key', 'CLOUDFLARE_API_KEY' );
	$api_token = mcp_cloudflare_get_config_value( 'cloudflare_api_token', 'CLOUDFLARE_API_TOKEN' );
	$zone_id   = mcp_cloudflare_get_config_value( 'cloudflare_zone_id', 'CLOUDFLARE_ZONE_ID' );
	$domain    = mcp_cloudflare_get_config_value( 'cloudflare_cached_domain_name', 'CLOUDFLARE_DOMAIN_NAME' );

	if (
		empty( $api_token ) &&
		(
			empty( $api_key ) ||
			( mcp_cloudflare_is_global_api_key( $api_key ) && empty( $api_email ) )
		)
	) {
		return new WP_Error( 'cloudflare_missing_credentials', 'Cloudflare API credentials not configured. Install and configure the Cloudflare plugin first.' );
	}

	if ( empty( $domain ) ) {
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
	}

	$context = array(
		'api_email' => $api_email,
		'api_key'   => $api_key,
		'api_token' => $api_token,
		'zone_id'   => $zone_id,
		'domain'    => $domain,
	);

	if ( empty( $context['zone_id'] ) ) {
		$zone_id = mcp_cloudflare_get_zone_id( $context, $domain );
		if ( is_wp_error( $zone_id ) ) {
			return $zone_id;
		}
		$context['zone_id'] = $zone_id;
	}

	return $context;
}

/**
 * Restore Cloudflare options after a failed configuration write.
 *
 * @param array<string,array{exists:bool,value:mixed}> $previous Previous option state.
 * @return void
 */
function mcp_cloudflare_restore_configuration_options( array $previous ): void {
	foreach ( $previous as $name => $state ) {
		if ( $state['exists'] ) {
			update_option( $name, $state['value'] );
		} else {
			delete_option( $name );
		}
	}
}

/**
 * Configure the official Cloudflare plugin after validating the credential.
 *
 * @param mixed $input Ability input.
 * @return array
 */
function mcp_cloudflare_configure_credentials_callback( $input = array() ): array {
	$input        = mcp_cloudflare_normalize_input( $input );
	$confirmation = isset( $input['confirm_dangerous_action'] ) ? (string) $input['confirm_dangerous_action'] : '';
	if ( ! hash_equals( 'cloudflare/configure-credentials', $confirmation ) ) {
		return array(
			'success' => false,
			'code'    => 'cloudflare_confirmation_required',
			'message' => 'This action requires exact confirmation: cloudflare/configure-credentials',
		);
	}

	$credential = isset( $input['api_credential'] ) ? trim( (string) $input['api_credential'] ) : '';
	$email      = isset( $input['email'] ) ? trim( (string) $input['email'] ) : '';
	if ( '' === $credential || '' === $email || ! is_email( $email ) ) {
		return array(
			'success' => false,
			'code'    => 'cloudflare_invalid_credentials',
			'message' => 'A Cloudflare API credential and valid account email are required.',
		);
	}

	foreach ( array( 'CLOUDFLARE_API_KEY', 'CLOUDFLARE_API_TOKEN', 'CLOUDFLARE_EMAIL', 'CLOUDFLARE_DOMAIN_NAME' ) as $constant_name ) {
		if ( defined( $constant_name ) && '' !== trim( (string) constant( $constant_name ) ) ) {
			return array(
				'success' => false,
				'code'    => 'cloudflare_constants_override',
				'message' => 'Cloudflare constants override the official plugin settings and must be removed before configuration.',
			);
		}
	}

	$store_class  = '\\Cloudflare\\APO\\WordPress\\DataStore';
	$logger_class = '\\Cloudflare\\APO\\Integration\\DefaultLogger';
	if ( ! class_exists( $store_class ) || ! class_exists( $logger_class ) ) {
		return array(
			'success' => false,
			'code'    => 'cloudflare_plugin_required',
			'message' => 'The official Cloudflare plugin must be installed and active before configuration.',
		);
	}

	$domain = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( '' === $domain ) {
		return array(
			'success' => false,
			'code'    => 'cloudflare_domain_missing',
			'message' => 'The WordPress site domain could not be determined.',
		);
	}

	$context = array(
		'api_email' => $email,
		'api_key'   => $credential,
		'api_token' => mcp_cloudflare_is_global_api_key( $credential ) ? '' : $credential,
		'zone_id'   => '',
		'domain'    => $domain,
	);
	$zones   = mcp_cloudflare_api_request(
		'GET',
		add_query_arg( 'name', $domain, 'https://api.cloudflare.com/client/v4/zones' ),
		$context
	);
	if ( is_wp_error( $zones ) ) {
		return array(
			'success' => false,
			'code'    => 'cloudflare_validation_failed',
			'message' => $zones->get_error_message(),
		);
	}

	$body = $zones['body'];
	if ( empty( $body['success'] ) || empty( $body['result'][0]['id'] ) || empty( $body['result'][0]['name'] ) ) {
		$error_message = mcp_cloudflare_api_error_message( $body );
		return array(
			'success' => false,
			'code'    => 'cloudflare_validation_failed',
			'message' => 'Cloudflare API error: ' . ( 'Unknown error' === $error_message ? 'Zone not found' : $error_message ),
		);
	}

	$zone_id   = (string) $body['result'][0]['id'];
	$zone_name = strtolower( (string) $body['result'][0]['name'] );
	$names     = array(
		'cloudflare_api_key',
		'cloudflare_api_email',
		'cloudflare_api_token',
		'cloudflare_zone_id',
		'cloudflare_cached_domain_name',
	);
	$missing   = new stdClass();
	$previous  = array();
	foreach ( $names as $name ) {
		$value             = get_option( $name, $missing );
		$previous[ $name ] = array(
			'exists' => $missing !== $value,
			'value'  => $value,
		);
	}

	$logger = new $logger_class( false );
	$store  = new $store_class( $logger );
	$store->createUserDataStore( $credential, $email, null, null );
	$store->setDomainNameCache( $zone_name );
	$store->set( 'cloudflare_zone_id', $zone_id );
	delete_option( 'cloudflare_api_token' );

	if (
		! hash_equals( $credential, (string) get_option( 'cloudflare_api_key', '' ) ) ||
		! hash_equals( $email, (string) get_option( 'cloudflare_api_email', '' ) ) ||
		! hash_equals( $zone_id, (string) get_option( 'cloudflare_zone_id', '' ) ) ||
		! hash_equals( $zone_name, (string) get_option( 'cloudflare_cached_domain_name', '' ) )
	) {
		mcp_cloudflare_restore_configuration_options( $previous );
		return array(
			'success' => false,
			'code'    => 'cloudflare_configuration_write_failed',
			'message' => 'Cloudflare credentials were valid, but the official plugin settings could not be stored.',
		);
	}

	return array(
		'success'   => true,
		'configured' => true,
		'message'   => 'The official Cloudflare plugin is configured.',
		'domain'    => $zone_name,
		'zone_id'   => $zone_id,
		'auth_mode' => (string) $zones['auth_mode'],
	);
}

/**
 * Normalize an HTTP response header value to a short string.
 *
 * @param mixed $value Header value.
 * @return string
 */
function mcp_cloudflare_header_value( $value ): string {
	if ( is_array( $value ) ) {
		$value = implode( ', ', array_map( 'strval', $value ) );
	}

	return sanitize_text_field( (string) $value );
}

/**
 * Normalize a Cloudflare purge prefix.
 *
 * Cloudflare's purge_cache `prefixes` values use `host/path` without a URI
 * scheme. Do not pass these through esc_url_raw(); WordPress will prepend
 * `http://` to scheme-less host/path values, and Cloudflare rejects that.
 *
 * @param mixed $value Raw prefix.
 * @return string
 */
function mcp_cloudflare_normalize_purge_prefix( $value ): string {
	if ( ! is_string( $value ) ) {
		return '';
	}

	$prefix = trim( sanitize_text_field( $value ) );
	$prefix = preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', $prefix );
	$prefix = ltrim( (string) $prefix, '/' );

	return $prefix;
}

/**
 * Convert a public HTML file purge URL to Cloudflare's prefix format.
 *
 * The managed public HTML cache rule caches extensionless/html paths at the
 * edge. Exact URL purge can report success while leaving those HTML objects
 * in cache, so extensionless/html page URLs are better purged by prefix.
 *
 * @param string $url     Public URL from the `files` input.
 * @param array  $context Cloudflare context.
 * @return string Prefix in `host/path` format, or empty string when the URL should remain an exact file purge.
 */
function mcp_cloudflare_html_file_url_to_prefix( string $url, array $context ): string {
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return '';
	}

	if ( ! empty( $parts['query'] ) ) {
		return '';
	}

	$host = strtolower( (string) $parts['host'] );
	$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
	if ( '' === $path || '/' === $path ) {
		return mcp_cloudflare_normalize_purge_prefix( $host . '/' );
	}

	$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( '' !== $extension && 'html' !== $extension ) {
		return '';
	}

	if ( '' === $extension && ! str_ends_with( $path, '/' ) ) {
		$path .= '/';
	}

	return mcp_cloudflare_normalize_purge_prefix( $host . $path );
}

/**
 * Execute a bounded Cloudflare purge through the plugin's canonical path.
 *
 * @param mixed $raw_input Ability or Adapter input.
 * @return array<string,mixed>
 */
function mcp_cloudflare_deep_purge( $raw_input = array() ): array {
	$input   = mcp_cloudflare_normalize_input( $raw_input );
	$context = mcp_cloudflare_get_context();
	if ( is_wp_error( $context ) ) {
		return array(
			'success' => false,
			'message' => $context->get_error_message(),
			'error'   => array( 'code' => 'cloudflare_context', 'message' => $context->get_error_message() ),
			'purge'   => array( 'implementation' => __FUNCTION__, 'completed' => array() ),
		);
	}

	$purge_everything = isset( $input['purge_everything'] ) ? (bool) $input['purge_everything'] : true;
	$normalizers      = array(
		'files'    => static function ( $item ): string {
			if ( ! is_string( $item ) ) {
				return '';
			}
			$url   = esc_url_raw( $item );
			$parts = wp_parse_url( $url );
			return is_array( $parts ) && in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) && ! empty( $parts['host'] ) ? $url : '';
		},
		'tags'     => static function ( $item ): string { return is_string( $item ) ? sanitize_text_field( $item ) : ''; },
		'prefixes' => static function ( $item ): string { return mcp_cloudflare_normalize_purge_prefix( $item ); },
		'hosts'    => static function ( $item ): string { return is_string( $item ) ? sanitize_text_field( $item ) : ''; },
	);
	$invalid_targets = static function (): array {
		$message = 'Every cache purge target must be valid and belong to the configured domain.';
		return array(
			'success' => false,
			'message' => $message,
			'error'   => array( 'code' => 'invalid_purge_targets', 'message' => $message ),
			'purge'   => array( 'implementation' => 'mcp_cloudflare_deep_purge', 'completed' => array() ),
		);
	};
	$values = array();
	$zone_host = strtolower( (string) ( $context['domain'] ?? '' ) );
	foreach ( $normalizers as $key => $normalizer ) {
		if ( array_key_exists( $key, $input ) && ! is_array( $input[ $key ] ) ) {
			return $invalid_targets();
		}
		$values[ $key ] = array();
		foreach ( $input[ $key ] ?? array() as $raw ) {
			$value = $normalizer( $raw );
			if ( '' === $value ) {
				return $invalid_targets();
			}
			if ( 'tags' !== $key ) {
				$parts = wp_parse_url( 'files' === $key ? $value : 'https://' . $value );
				$host = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
				if (
					'' === $zone_host || '' === $host
					|| ( $host !== $zone_host && ! str_ends_with( $host, '.' . $zone_host ) )
					|| isset( $parts['user'] ) || isset( $parts['pass'] )
					|| ( 'files' !== $key && ( isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['port'] ) ) )
					|| ( 'hosts' === $key && isset( $parts['path'] ) )
				) {
					return $invalid_targets();
				}
			}
			$values[ $key ][] = $value;
		}
		$values[ $key ] = array_values( array_unique( $values[ $key ] ) );
	}

	$exact_files      = array();
	$auto_prefixes    = array();
	$auto_prefix_from = array();
	foreach ( $values['files'] as $file_url ) {
		$prefix = mcp_cloudflare_html_file_url_to_prefix( $file_url, $context );
		if ( '' !== $prefix ) {
			$auto_prefixes[]               = $prefix;
			$auto_prefix_from[ $file_url ] = $prefix;
		} else {
			$exact_files[] = $file_url;
		}
	}
	$values['prefixes'] = array_values( array_unique( array_merge( $values['prefixes'], $auto_prefixes ) ) );

	$operations = array();
	foreach ( array( 'files' => $exact_files, 'tags' => $values['tags'], 'prefixes' => $values['prefixes'], 'hosts' => $values['hosts'] ) as $type => $items ) {
		foreach ( array_chunk( $items, 100 ) as $batch ) {
			$operations[] = array( 'type' => $type, 'data' => array( $type => $batch ), 'count' => count( $batch ) );
		}
	}
	if ( ! $operations && false === $purge_everything ) {
		$message = 'No valid cache purge targets were provided.';
		return array(
			'success' => false,
			'message' => $message,
			'error'   => array( 'code' => 'invalid_purge_targets', 'message' => $message ),
			'purge'   => array( 'implementation' => __FUNCTION__, 'completed' => array() ),
		);
	}
	if ( ! $operations ) {
		$operations[] = array( 'type' => 'everything', 'data' => array( 'purge_everything' => $purge_everything ), 'count' => 1 );
	}

	$completed = array();
	foreach ( $operations as $operation ) {
		$result = mcp_cloudflare_api_request(
			'POST',
			'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'] . '/purge_cache',
			$context,
			array( 'body' => wp_json_encode( $operation['data'] ), 'timeout' => 30 )
		);
		if ( is_wp_error( $result ) ) {
			$message = 'Failed to purge cache: ' . $result->get_error_message();
			return array(
				'success' => false,
				'message' => $message,
				'error'   => array( 'code' => 'cloudflare_transport', 'message' => $message ),
				'purge'   => array( 'implementation' => __FUNCTION__, 'failed_type' => $operation['type'], 'completed' => $completed ),
			);
		}
		if ( empty( $result['body']['success'] ) ) {
			$message = 'Cache purge failed: ' . mcp_cloudflare_api_error_message( $result['body'] );
			return array(
				'success' => false,
				'message' => $message,
				'error'   => array( 'code' => 'cloudflare_api', 'message' => $message ),
				'purge'   => array( 'implementation' => __FUNCTION__, 'failed_type' => $operation['type'], 'completed' => $completed ),
			);
		}
		$completed[] = array(
			'type'          => $operation['type'],
			'count'         => $operation['count'],
			'payload_keys'  => array_keys( $operation['data'] ),
			'cloudflare_id' => (string) ( $result['body']['result']['id'] ?? '' ),
			'auth_mode'     => (string) ( $result['auth_mode'] ?? '' ),
		);
	}

	$counts = array_fill_keys( array( 'files', 'tags', 'prefixes', 'hosts', 'everything' ), 0 );
	foreach ( $operations as $operation ) {
		$counts[ $operation['type'] ] += (int) $operation['count'];
	}
	$parts = array();
	foreach ( array( 'files' => 'exact URL(s)', 'tags' => 'cache tag(s)', 'prefixes' => 'URL prefix(es)', 'hosts' => 'host(s)' ) as $type => $label ) {
		if ( $counts[ $type ] ) {
			$parts[] = $counts[ $type ] . ' ' . $label;
		}
	}
	$message = $counts['everything'] ? 'Purged entire Cloudflare cache for ' . ( $context['domain'] ?? '' ) . '.' : 'Purged ' . implode( ', ', $parts ) . ' from Cloudflare cache.';
	return array(
		'success' => true,
		'message' => $message,
		'purge'   => array(
			'implementation' => __FUNCTION__,
			'type'           => count( $completed ) > 1 ? 'multi' : ( $completed[0]['type'] ?? 'unknown' ),
			'count'          => array_sum( $counts ),
			'payload_keys'   => array_values( array_unique( array_merge( ...array_map( static function ( $operation ) { return array_keys( $operation['data'] ); }, $operations ) ) ) ),
			'cloudflare_id'  => $completed[0]['cloudflare_id'] ?? '',
			'auth_mode'      => $completed[0]['auth_mode'] ?? '',
			'operations'     => $completed,
			'auto_prefixes'  => $auto_prefix_from,
		),
	);
}

/**
 * Ability callback for the shared deep purge implementation.
 *
 * @param mixed $input Ability input.
 * @return array<string,mixed>
 */
function mcp_cloudflare_clear_cache_callback( $input = array() ): array {
	return mcp_cloudflare_deep_purge( $input );
}

/**
 * Consume a generic frontend URL invalidation request.
 *
 * @param mixed $current Previous Adapter result.
 * @param mixed $urls    Public URLs to invalidate.
 * @param mixed $context Producer context.
 * @return array<string,mixed>
 */
function mcp_cloudflare_frontend_cache_invalidation_result( $current, $urls, $context ): array {
	if ( ! is_array( $urls ) ) {
		return array(
			'success' => false,
			'message' => 'Frontend cache invalidation requires a URL list.',
			'error'   => array( 'code' => 'invalid_urls', 'message' => 'Frontend cache invalidation requires a URL list.' ),
			'purge'   => array( 'implementation' => 'mcp_cloudflare_deep_purge', 'completed' => array() ),
		);
	}

	$site_host       = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	$normalized_urls = array();
	foreach ( $urls as $url ) {
		$normalized = is_string( $url ) ? esc_url_raw( $url ) : '';
		$parts      = '' !== $normalized ? wp_parse_url( $normalized ) : false;
		$scheme     = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';
		$host       = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		if (
			'' === $site_host
			|| ! in_array( $scheme, array( 'http', 'https' ), true )
			|| ( $host !== $site_host && ! str_ends_with( $host, '.' . $site_host ) )
		) {
			$message = 'No valid cache purge targets were provided.';
			return array(
				'success' => false,
				'message' => $message,
				'error'   => array( 'code' => 'invalid_purge_targets', 'message' => $message ),
				'purge'   => array( 'implementation' => 'mcp_cloudflare_deep_purge', 'completed' => array() ),
			);
		}
		$normalized_urls[] = $normalized;
	}
	$normalized_urls = array_values( array_unique( $normalized_urls ) );

	$local_cache_receipt = null;
	if ( null !== $current ) {
		if ( ! is_array( $current ) ) {
			return array(
				'success' => false,
				'message' => 'The local cache Adapter did not return a valid receipt.',
				'error'   => array( 'code' => 'invalid_local_cache_receipt', 'message' => 'The local cache Adapter did not return a valid receipt.' ),
			);
		}
		if ( empty( $current['success'] ) ) {
			return $current;
		}
		$receipt_urls         = $current['purge']['urls'] ?? null;
		$receipt_count        = $current['purge']['count'] ?? null;
		$active_local_receipt =
			is_array( $receipt_urls )
			&& $normalized_urls === $receipt_urls
			&& is_int( $receipt_count )
			&& count( $receipt_urls ) === $receipt_count;
		$absent_local_receipt =
			is_array( $receipt_urls )
			&& $normalized_urls === $receipt_urls
			&& 0 === $receipt_count
			&& true === ( $current['local_cache']['skipped'] ?? null )
			&& 'no_local_page_cache_configured' === (string) ( $current['local_cache']['reason'] ?? '' );
		if (
			'cache-enabler' !== (string) ( $current['adapter']['name'] ?? '' )
			|| 'mcp_cache_enabler_frontend_cache_invalidation_result' !== (string) ( $current['purge']['implementation'] ?? '' )
			|| ( ! $active_local_receipt && ! $absent_local_receipt )
		) {
			return array(
				'success' => false,
				'message' => 'The local cache Adapter did not return a valid receipt.',
				'error'   => array( 'code' => 'invalid_local_cache_receipt', 'message' => 'The local cache Adapter did not return a valid receipt.' ),
			);
		}
		$local_cache_receipt = $current;
	}

	$result = mcp_cloudflare_deep_purge( array( 'purge_everything' => false, 'files' => $normalized_urls ) );
	if (
		null !== $local_cache_receipt
		&& empty( $result['success'] )
		&& 'cloudflare_context' === (string) ( $result['error']['code'] ?? '' )
		&& 'Cloudflare API credentials not configured. Install and configure the Cloudflare plugin first.' === (string) ( $result['message'] ?? '' )
	) {
		$local_cache_receipt['edge_cache'] = array(
			'name' => 'cloudflare',
			'skipped' => true,
			'reason' => 'cloudflare_not_configured',
			'context' => is_array( $context ) ? array_keys( $context ) : array(),
		);
		return $local_cache_receipt;
	}
	if ( null !== $local_cache_receipt && ! empty( $result['success'] ) ) {
		$result['local_cache'] = $local_cache_receipt;
	}
	$result['adapter'] = array( 'name' => 'cloudflare', 'context' => is_array( $context ) ? array_keys( $context ) : array() );
	return $result;
}

/**
 * Purge public HTML after a completed plugin install/update.
 *
 * @param mixed $upgrader   Upgrader instance.
 * @param mixed $hook_extra Completion context.
 */
function mcp_cloudflare_observe_plugin_upgrade_complete( $upgrader, $hook_extra ): void {
	if ( ! is_array( $hook_extra ) || 'plugin' !== ( $hook_extra['type'] ?? '' ) || ! in_array( $hook_extra['action'] ?? '', array( 'install', 'update' ), true ) ) {
		return;
	}

	try {
		$parts  = wp_parse_url( home_url( '/' ) );
		$prefix = is_array( $parts ) && ! empty( $parts['host'] ) ? mcp_cloudflare_normalize_purge_prefix( strtolower( (string) $parts['host'] ) . '/' ) : '';
		$result = '' !== $prefix
			? mcp_cloudflare_deep_purge( array( 'purge_everything' => false, 'prefixes' => array( $prefix ) ) )
			: array( 'success' => false, 'message' => 'Could not determine the public HTML host.', 'error' => array( 'code' => 'invalid_home_url', 'message' => 'Could not determine the public HTML host.' ) );
	} catch ( Throwable $throwable ) {
		$result = array( 'success' => false, 'message' => $throwable->getMessage(), 'error' => array( 'code' => 'upgrader_purge_exception', 'message' => $throwable->getMessage() ) );
	}

	$status = array(
		'timestamp' => time(),
		'success'   => ! empty( $result['success'] ),
		'action'    => sanitize_key( (string) $hook_extra['action'] ),
		'type'      => 'plugin',
		'message'   => substr( sanitize_text_field( (string) ( $result['message'] ?? '' ) ), 0, 500 ),
		'error'     => sanitize_key( (string) ( $result['error']['code'] ?? '' ) ),
		'prefixes'  => isset( $prefix ) && '' !== $prefix ? array( $prefix ) : array(),
	);
	try {
		update_option( 'mcp_cloudflare_last_plugin_upgrade_purge', $status, false );
	} catch ( Throwable $throwable ) {
		// Cache coherence must never replace or corrupt the upgrader response.
	}
}

/**
 * Whether a Cloudflare rule already implements this WordPress HTML policy.
 *
 * Match the behavior instead of a vendor- or site-branded display label. This
 * lets older equivalent rules migrate to the generic identity without keeping
 * customer names in the reusable Module.
 *
 * @param array<string,mixed> $rule Cloudflare rule.
 * @param string              $host WordPress hostname.
 */
function mcp_cloudflare_is_wordpress_html_cache_policy_rule( array $rule, string $host ): bool {
	if ( 'set_cache_settings' !== (string) ( $rule['action'] ?? '' ) ) {
		return false;
	}

	$expression = (string) ( $rule['expression'] ?? '' );
	return str_contains( $expression, 'http.host eq "' . $host . '"' )
		&& str_contains( $expression, 'wordpress_logged_in_' )
		&& str_contains( $expression, '/wp-admin' );
}

/**
 * Fetch a single Cloudflare zone setting.
 *
 * @param array  $context Cloudflare context.
 * @param string $setting Setting ID.
 * @return array
 */
function mcp_cloudflare_get_zone_setting( array $context, string $setting ): array {
	$result = mcp_cloudflare_api_request(
		'GET',
		'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'] . '/settings/' . rawurlencode( $setting ),
		$context
	);

	if ( is_wp_error( $result ) ) {
		return array(
			'success' => false,
			'id'      => $setting,
			'message' => $result->get_error_message(),
		);
	}

	$body = $result['body'];
	if ( empty( $body['success'] ) || empty( $body['result'] ) ) {
		return array(
			'success' => false,
			'id'      => $setting,
			'message' => 'Cloudflare API error: ' . mcp_cloudflare_api_error_message( $body ),
		);
	}

	return array(
		'success' => true,
		'id'      => $setting,
		'setting' => $body['result'],
	);
}

function mcp_cloudflare_normalize_cache_exclude_paths( array $paths ): array {
	$normalized = array();
	foreach ( $paths as $path ) {
		if ( ! is_string( $path ) ) {
			continue;
		}

		$parsed = wp_parse_url( $path );
		if ( is_array( $parsed ) && ! empty( $parsed['path'] ) ) {
			$path = (string) $parsed['path'];
		}

		$path = '/' . trim( sanitize_text_field( $path ), '/' );
		if ( '/' === $path ) {
			continue;
		}

		$normalized[] = $path . '/';
	}

	return array_values( array_unique( $normalized ) );
}

function mcp_cloudflare_extract_custom_cache_exclude_paths( string $expression ): array {
	if ( '' === $expression ) {
		return array();
	}

	$base_exclusions = array(
		'/wp-login.php',
		'/xmlrpc.php',
		'/wp-cron.php',
		'/feed',
	);
	preg_match_all( '/http\\.request\\.uri\\.path ne "([^"]+)"/', $expression, $matches );
	$paths = isset( $matches[1] ) && is_array( $matches[1] ) ? $matches[1] : array();
	$paths = array_values(
		array_filter(
			$paths,
			static function ( string $path ) use ( $base_exclusions ): bool {
				return ! in_array( $path, $base_exclusions, true );
			}
		)
	);

	return mcp_cloudflare_normalize_cache_exclude_paths( $paths );
}

/**
 * Build the default public WordPress HTML cache rule.
 *
 * @param string $host             Hostname to cache.
 * @param int    $edge_ttl_seconds Edge TTL for successful responses.
 * @param bool   $enabled          Whether the rule should be enabled.
 * @param string $description      Rule description.
 * @param string $ref              Stable rule reference.
 * @param array  $exclude_paths    Public paths excluded from anonymous HTML cache.
 * @return array
 */
function mcp_cloudflare_build_wordpress_html_cache_rule( string $host, int $edge_ttl_seconds, bool $enabled, string $description, string $ref, array $exclude_paths = array() ): array {
	$edge_ttl_seconds = max( 60, min( 86400, $edge_ttl_seconds ) );
	$exclude_paths    = mcp_cloudflare_normalize_cache_exclude_paths( $exclude_paths );
	$expression_parts = array(
		'(http.host eq "' . addcslashes( $host, "\\\"" ) . '")',
		'((http.request.method eq "GET") or (http.request.method eq "HEAD"))',
		'((http.request.uri.path.extension eq "") or (http.request.uri.path.extension eq "html"))',
		'(http.request.uri.query eq "")',
		'(not starts_with(http.request.uri.path, "/wp-admin"))',
		'(http.request.uri.path ne "/wp-login.php")',
		'(not starts_with(http.request.uri.path, "/wp-json"))',
		'(http.request.uri.path ne "/xmlrpc.php")',
		'(http.request.uri.path ne "/wp-cron.php")',
		'(http.request.uri.path ne "/feed")',
		'(not ends_with(http.request.uri.path, "/feed/"))',
		'(not http.cookie contains "wordpress_logged_in_")',
		'(not http.cookie contains "wordpress_sec_")',
		'(not http.cookie contains "wp-postpass_")',
		'(not http.cookie contains "comment_author_")',
		'(not http.cookie contains "woocommerce_")',
		'(not http.cookie contains "wp_woocommerce_session_")',
	);
	foreach ( $exclude_paths as $path ) {
		$expression_parts[] = '(http.request.uri.path ne "' . addcslashes( rtrim( $path, '/' ), "\\\"" ) . '")';
		$expression_parts[] = '(not starts_with(http.request.uri.path, "' . addcslashes( $path, "\\\"" ) . '"))';
	}

	return array(
		'ref'               => $ref,
		'description'       => $description,
		'expression'        => implode( ' and ', $expression_parts ),
		'action'            => 'set_cache_settings',
		'action_parameters' => array(
			'cache'       => true,
			'edge_ttl'    => array(
				'mode'            => 'override_origin',
				'default'         => $edge_ttl_seconds,
				'status_code_ttl' => array(
					array(
						'status_code_range' => array(
							'from' => 200,
							'to'   => 299,
						),
						'value'             => $edge_ttl_seconds,
					),
					array(
						'status_code_range' => array(
							'from' => 300,
							'to'   => 399,
						),
						'value'             => 300,
					),
					array(
						'status_code_range' => array(
							'from' => 400,
							'to'   => 499,
						),
						'value'             => 0,
					),
					array(
						'status_code_range' => array(
							'from' => 500,
						),
						'value'             => -1,
					),
				),
			),
			'browser_ttl' => array(
				'mode' => 'respect_origin',
			),
		),
		'enabled'           => $enabled,
	);
}

/**
 * Fetch the cache-settings entrypoint ruleset.
 *
 * @param array $context Cloudflare context.
 * @return array|WP_Error
 */
function mcp_cloudflare_get_cache_entrypoint_ruleset( array $context ) {
	$result = mcp_cloudflare_api_request(
		'GET',
		'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'] . '/rulesets/phases/http_request_cache_settings/entrypoint',
		$context
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( empty( $result['body']['success'] ) || empty( $result['body']['result'] ) || ! is_array( $result['body']['result'] ) ) {
		return new WP_Error( 'cloudflare_cache_ruleset', 'Cloudflare API error: ' . mcp_cloudflare_api_error_message( $result['body'] ?? null ) );
	}

	return $result['body']['result'];
}

/**
 * Register Cloudflare abilities.
 */
function mcp_register_cloudflare_abilities(): void {
	if ( ! mcp_cloudflare_check_dependencies() ) {
		return;
	}

	// =========================================================================
	// CLOUDFLARE - Configure Credentials
	// =========================================================================
	wp_register_ability(
		'cloudflare/configure-credentials',
		array(
			'label'               => 'Configure Cloudflare Credentials',
			'description'         => 'Validate and store Cloudflare credentials through the official Cloudflare plugin.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'api_credential', 'email' ),
				'properties'           => array(
					'api_credential' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Cloudflare API token or Global API Key. The value is never returned.',
					),
					'email' => array(
						'type'        => 'string',
						'format'      => 'email',
						'description' => 'Cloudflare account email used by the official plugin.',
					),
					'confirm_dangerous_action' => array(
						'type'        => 'string',
						'const'       => 'cloudflare/configure-credentials',
						'description' => 'Exact confirmation required to replace stored Cloudflare credentials.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'configured' => array( 'type' => 'boolean' ),
					'message'    => array( 'type' => 'string' ),
					'code'       => array( 'type' => 'string' ),
					'domain'     => array( 'type' => 'string' ),
					'zone_id'    => array( 'type' => 'string' ),
					'auth_mode'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => 'mcp_cloudflare_configure_credentials_callback',
			'permission_callback' => 'mcp_cloudflare_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// CLOUDFLARE - Clear Cache
	// =========================================================================
	wp_register_ability(
		'cloudflare/clear-cache',
		array(
			'label'               => 'Clear Cloudflare Cache',
			'description'         => 'Purges the Cloudflare cache for the site. Requires Cloudflare plugin to be configured with API credentials.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'purge_everything' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Purge all cached files (default: true).',
					),
					'files'            => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'maxItems'    => 100,
						'description' => 'Optional: Specific URLs to purge instead of everything.',
					),
					'tags'             => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'maxItems'    => 100,
						'description' => 'Optional: Cache tags to purge, subject to the Cloudflare account permissions and rate limits.',
					),
					'prefixes'         => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'maxItems'    => 100,
						'description' => 'Optional: URL prefixes to purge when exact URL purges do not match cached HTML variants.',
					),
					'hosts'            => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'maxItems'    => 100,
						'description' => 'Optional: Hostnames to purge.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'message' => array( 'type' => 'string' ),
					'purge'   => array( 'type' => 'object' ),
				),
			),
			'execute_callback'    => 'mcp_cloudflare_clear_cache_callback',
			'permission_callback' => 'mcp_cloudflare_permission_callback',
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
				),
		)
	);

	// =========================================================================
	// CLOUDFLARE - Get Zone
	// =========================================================================
	wp_register_ability(
		'cloudflare/get-zone',
		array(
			'label'               => 'Get Cloudflare Zone',
			'description'         => 'Fetch Cloudflare zone details for the site domain.',
			'category'            => 'site',
			'input_schema'        => mcp_cloudflare_empty_input_schema(),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'zone'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = mcp_cloudflare_normalize_input( $input );

				$context = mcp_cloudflare_get_context();
				if ( is_wp_error( $context ) ) {
					return array(
						'success' => false,
						'message' => $context->get_error_message(),
					);
				}

				$result = mcp_cloudflare_api_request(
					'GET',
					'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'],
					$context
				);

				if ( is_wp_error( $result ) ) {
					return array(
						'success' => false,
						'message' => $result->get_error_message(),
					);
				}

				$body = $result['body'];
				if ( empty( $body['success'] ) || empty( $body['result'] ) ) {
					$error_msg = mcp_cloudflare_api_error_message( $body );
					return array(
						'success' => false,
						'message' => 'Cloudflare API error: ' . $error_msg,
					);
				}

				return array(
					'success' => true,
					'zone'    => $body['result'],
					'message' => 'Zone retrieved successfully.',
				);
			},
			'permission_callback' => 'mcp_cloudflare_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// CLOUDFLARE - Get Development Mode
	// =========================================================================
	wp_register_ability(
		'cloudflare/get-development-mode',
		array(
			'label'               => 'Get Cloudflare Development Mode',
			'description'         => 'Get Cloudflare development mode status.',
			'category'            => 'site',
			'input_schema'        => mcp_cloudflare_empty_input_schema(),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'value'   => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = mcp_cloudflare_normalize_input( $input );

				$context = mcp_cloudflare_get_context();
				if ( is_wp_error( $context ) ) {
					return array(
						'success' => false,
						'message' => $context->get_error_message(),
					);
				}

				$result = mcp_cloudflare_api_request(
					'GET',
					'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'] . '/settings/development_mode',
					$context
				);

				if ( is_wp_error( $result ) ) {
					return array(
						'success' => false,
						'message' => $result->get_error_message(),
					);
				}

				$body = $result['body'];
				if ( empty( $body['success'] ) || empty( $body['result']['value'] ) ) {
					$error_msg = mcp_cloudflare_api_error_message( $body );
					return array(
						'success' => false,
						'message' => 'Cloudflare API error: ' . $error_msg,
					);
				}

				return array(
					'success' => true,
					'value'   => $body['result']['value'],
					'message' => 'Development mode status retrieved.',
				);
			},
			'permission_callback' => 'mcp_cloudflare_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// CLOUDFLARE - Get Cache Settings
	// =========================================================================
	wp_register_ability(
		'cloudflare/get-cache-settings',
		array(
			'label'               => 'Get Cloudflare Cache Settings',
			'description'         => 'Fetch relevant Cloudflare zone cache settings for diagnosis.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => array( 'object', 'array', 'null' ),
				'properties'           => array(
					'settings' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Optional setting IDs to fetch. Defaults to common cache/performance settings.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'settings' => array( 'type' => 'array' ),
					'message'  => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = mcp_cloudflare_normalize_input( $input );

				$context = mcp_cloudflare_get_context();
				if ( is_wp_error( $context ) ) {
					return array(
						'success' => false,
						'message' => $context->get_error_message(),
					);
				}

				$default_settings = array(
					'cache_level',
					'browser_cache_ttl',
					'development_mode',
					'always_online',
					'brotli',
					'early_hints',
					'automatic_platform_optimization',
				);
				$settings         = isset( $input['settings'] ) && is_array( $input['settings'] )
					? array_values(
						array_filter(
							array_map(
								static function ( $setting ) {
									return is_string( $setting ) ? sanitize_key( $setting ) : '';
								},
								$input['settings']
							)
						)
					)
					: $default_settings;
				$settings         = array_slice( array_values( array_unique( $settings ) ), 0, 25 );
				$results          = array();
				$successful       = 0;

				foreach ( $settings as $setting ) {
					$result = mcp_cloudflare_get_zone_setting( $context, $setting );
					if ( ! empty( $result['success'] ) ) {
						++$successful;
					}
					$results[] = $result;
				}

				return array(
					'success'  => $successful > 0,
					'settings' => $results,
					'message'  => sprintf(
						'Retrieved %1$d of %2$d requested Cloudflare setting(s).',
						$successful,
						count( $settings )
					),
				);
			},
			'permission_callback' => 'mcp_cloudflare_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// CLOUDFLARE - Get Cache Rulesets
	// =========================================================================
	wp_register_ability(
		'cloudflare/get-cache-rulesets',
		array(
			'label'               => 'Get Cloudflare Cache Rulesets',
			'description'         => 'Fetch Cloudflare rulesets and the cache-settings entrypoint ruleset for the zone.',
			'category'            => 'site',
			'input_schema'        => mcp_cloudflare_empty_input_schema(),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'    => array( 'type' => 'boolean' ),
					'rulesets'   => array( 'type' => 'array' ),
					'entrypoint' => array( 'type' => 'object' ),
					'message'    => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = mcp_cloudflare_normalize_input( $input );

				$context = mcp_cloudflare_get_context();
				if ( is_wp_error( $context ) ) {
					return array(
						'success' => false,
						'message' => $context->get_error_message(),
					);
				}

				$list_result = mcp_cloudflare_api_request(
					'GET',
					'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'] . '/rulesets',
					$context
				);

				if ( is_wp_error( $list_result ) ) {
					return array(
						'success' => false,
						'message' => $list_result->get_error_message(),
					);
				}

				$list_body = $list_result['body'];
				if ( empty( $list_body['success'] ) ) {
					return array(
						'success' => false,
						'message' => 'Cloudflare API error: ' . mcp_cloudflare_api_error_message( $list_body ),
					);
				}

				$entrypoint_result = mcp_cloudflare_api_request(
					'GET',
					'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'] . '/rulesets/phases/http_request_cache_settings/entrypoint',
					$context
				);
				$entrypoint        = array(
					'success' => false,
					'message' => 'Cache-settings entrypoint ruleset not found or not readable.',
				);

				if ( is_wp_error( $entrypoint_result ) ) {
					$entrypoint['message'] = $entrypoint_result->get_error_message();
				} elseif ( ! empty( $entrypoint_result['body']['success'] ) && ! empty( $entrypoint_result['body']['result'] ) ) {
					$entrypoint = array(
						'success' => true,
						'ruleset' => $entrypoint_result['body']['result'],
					);
				} else {
					$entrypoint['message'] = 'Cloudflare API error: ' . mcp_cloudflare_api_error_message( $entrypoint_result['body'] ?? null );
				}

				return array(
					'success'    => true,
					'rulesets'   => $list_body['result'] ?? array(),
					'entrypoint' => $entrypoint,
					'message'    => 'Cloudflare rulesets retrieved.',
				);
			},
			'permission_callback' => 'mcp_cloudflare_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// CLOUDFLARE - Test URL Cache Status
	// =========================================================================
	wp_register_ability(
		'cloudflare/test-url-cache-status',
		array(
			'label'               => 'Test Cloudflare URL Cache Status',
			'description'         => 'Fetch public URLs and report Cloudflare cache/status headers without purging or changing settings.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'urls' ),
				'properties'           => array(
					'urls'   => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Public URLs to fetch. Maximum 20.',
					),
					'repeat' => array(
						'type'        => 'integer',
						'default'     => 2,
						'minimum'     => 1,
						'maximum'     => 3,
						'description' => 'Fetch count per URL, useful for MISS-to-HIT checks.',
					),
					'method' => array(
						'type'        => 'string',
						'enum'        => array( 'GET', 'HEAD' ),
						'default'     => 'GET',
						'description' => 'HTTP method to use.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'results' => array( 'type' => 'array' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input  = mcp_cloudflare_normalize_input( $input );
				$urls   = isset( $input['urls'] ) && is_array( $input['urls'] )
					? array_values(
						array_filter(
							array_map(
								static function ( $url ) {
									return is_string( $url ) ? esc_url_raw( $url ) : '';
								},
								$input['urls']
							)
						)
					)
					: array();
				$urls   = array_slice( array_values( array_unique( $urls ) ), 0, 20 );
				$repeat = isset( $input['repeat'] ) ? max( 1, min( 3, (int) $input['repeat'] ) ) : 2;
				$method = isset( $input['method'] ) && 'HEAD' === strtoupper( (string) $input['method'] ) ? 'HEAD' : 'GET';

				if ( empty( $urls ) ) {
					return array(
						'success' => false,
						'message' => 'At least one URL is required.',
					);
				}

				$results = array();
				foreach ( $urls as $url ) {
					$attempts = array();
					for ( $i = 1; $i <= $repeat; $i++ ) {
						$response = wp_remote_request(
							$url,
							array(
								'method'      => $method,
								'timeout'     => 30,
								'redirection' => 5,
								'headers'     => array(
									'User-Agent' => 'MCP Abilities Cloudflare Cache Probe/1.0; ' . home_url( '/' ),
									'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
								),
							)
						);

						if ( is_wp_error( $response ) ) {
							$attempts[] = array(
								'attempt' => $i,
								'success' => false,
								'message' => $response->get_error_message(),
							);
							continue;
						}

						$headers    = function_exists( 'wp_remote_retrieve_headers' ) ? wp_remote_retrieve_headers( $response ) : array();
						$header_map = array();
						if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
							$header_map = $headers->getAll();
						} elseif ( is_array( $headers ) ) {
							$header_map = $headers;
						}
						$normalized_headers = array();
						foreach ( $header_map as $name => $value ) {
							$normalized_headers[ strtolower( (string) $name ) ] = mcp_cloudflare_header_value( $value );
						}

						$attempts[] = array(
							'attempt'         => $i,
							'success'         => true,
							'status_code'     => function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0,
							'cf_cache_status' => $normalized_headers['cf-cache-status'] ?? '',
							'cache_control'   => $normalized_headers['cache-control'] ?? '',
							'age'             => $normalized_headers['age'] ?? '',
							'cf_ray'          => $normalized_headers['cf-ray'] ?? '',
							'server'          => $normalized_headers['server'] ?? '',
							'content_type'    => $normalized_headers['content-type'] ?? '',
							'vary'            => $normalized_headers['vary'] ?? '',
							'set_cookie'      => array_key_exists( 'set-cookie', $normalized_headers ),
						);
					}

					$results[] = array(
						'url'      => $url,
						'attempts' => $attempts,
					);
				}

				return array(
					'success' => true,
					'results' => $results,
					'message' => 'URL cache status probe complete.',
				);
			},
			'permission_callback' => 'mcp_cloudflare_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);

	// =========================================================================
	// CLOUDFLARE - Ensure WordPress HTML Cache Rule
	// =========================================================================
	wp_register_ability(
		'cloudflare/ensure-wordpress-html-cache-rule',
		array(
			'label'               => 'Ensure WordPress HTML Cache Rule',
			'description'         => 'Create or update a conservative Cloudflare cache rule for public anonymous WordPress HTML pages.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'host'             => array(
						'type'        => 'string',
						'description' => 'Hostname to cache. Defaults to the configured Cloudflare domain.',
					),
					'edge_ttl_seconds' => array(
						'type'        => 'integer',
						'default'     => 3600,
						'minimum'     => 60,
						'maximum'     => 86400,
						'description' => 'Edge TTL for successful anonymous HTML responses.',
					),
					'enabled'          => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Whether the rule should be enabled.',
					),
					'dry_run'          => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'When true, return the proposed ruleset without writing to Cloudflare.',
					),
					'description'      => array(
						'type'        => 'string',
						'description' => 'Cloudflare rule description.',
					),
					'ref'              => array(
						'type'        => 'string',
						'description' => 'Stable Cloudflare rule reference used for idempotent updates.',
					),
					'exclude_paths'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Optional public path prefixes to exclude from the anonymous HTML cache rule, such as /vi/example/. Existing custom excludes are preserved when omitted.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'       => array( 'type' => 'boolean' ),
					'message'       => array( 'type' => 'string' ),
					'dry_run'       => array( 'type' => 'boolean' ),
					'host'          => array( 'type' => 'string' ),
					'ruleset_id'    => array( 'type' => 'string' ),
					'action'        => array( 'type' => 'string' ),
					'rule'          => array( 'type' => 'object' ),
					'existing_rule' => array( 'type' => 'object' ),
					'rule_count'    => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = mcp_cloudflare_normalize_input( $input );

				$context = mcp_cloudflare_get_context();
				if ( is_wp_error( $context ) ) {
					return array(
						'success' => false,
						'message' => $context->get_error_message(),
					);
				}

				$host = isset( $input['host'] ) && is_string( $input['host'] )
					? strtolower( sanitize_text_field( $input['host'] ) )
					: strtolower( (string) ( $context['domain'] ?? '' ) );
				if ( '' === $host || ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
					return array(
						'success' => false,
						'message' => 'A valid hostname is required.',
					);
				}

				$edge_ttl_seconds = isset( $input['edge_ttl_seconds'] ) ? (int) $input['edge_ttl_seconds'] : 3600;
				$enabled          = isset( $input['enabled'] ) ? (bool) $input['enabled'] : true;
				$dry_run          = ! array_key_exists( 'dry_run', $input ) || (bool) $input['dry_run'];
				$description      = isset( $input['description'] ) && is_string( $input['description'] )
					? sanitize_text_field( $input['description'] )
					: 'WordPress public HTML cache';
				$ref              = isset( $input['ref'] ) && is_string( $input['ref'] )
					? sanitize_key( $input['ref'] )
					: 'mcp-wordpress-public-html-cache';
				$exclude_paths    = isset( $input['exclude_paths'] ) && is_array( $input['exclude_paths'] )
					? mcp_cloudflare_normalize_cache_exclude_paths( $input['exclude_paths'] )
					: array();

				$ruleset = mcp_cloudflare_get_cache_entrypoint_ruleset( $context );
				if ( is_wp_error( $ruleset ) ) {
					return array(
						'success' => false,
						'message' => $ruleset->get_error_message(),
					);
				}

				$existing_rules = isset( $ruleset['rules'] ) && is_array( $ruleset['rules'] ) ? $ruleset['rules'] : array();
				$new_rule       = mcp_cloudflare_build_wordpress_html_cache_rule( $host, $edge_ttl_seconds, $enabled, $description, $ref, $exclude_paths );
				$rules          = array();
				$existing_rule  = null;
				$action         = 'created';

				foreach ( $existing_rules as $rule ) {
					if ( ! is_array( $rule ) ) {
						continue;
					}

					$matches_ref         = isset( $rule['ref'] ) && $ref === (string) $rule['ref'];
					$matches_description = isset( $rule['description'] ) && $description === (string) $rule['description'];
					$matches_policy      = mcp_cloudflare_is_wordpress_html_cache_policy_rule( $rule, $host );
					if ( $matches_ref || $matches_description || $matches_policy ) {
						$existing_rule = $rule;
						if ( isset( $rule['id'] ) ) {
							$new_rule['id'] = $rule['id'];
						}
						if ( ! isset( $input['exclude_paths'] ) && isset( $rule['expression'] ) && is_string( $rule['expression'] ) ) {
							$preserved_paths = mcp_cloudflare_extract_custom_cache_exclude_paths( $rule['expression'] );
							if ( ! empty( $preserved_paths ) ) {
								$new_rule       = mcp_cloudflare_build_wordpress_html_cache_rule( $host, $edge_ttl_seconds, $enabled, $description, $ref, $preserved_paths );
								$new_rule['id'] = $rule['id'];
							}
						}
						$rules[] = $new_rule;
						$action  = 'updated';
						continue;
					}

					$rules[] = $rule;
				}

				if ( null === $existing_rule ) {
					$rules[] = $new_rule;
				}

				if ( $dry_run ) {
					return array(
						'success'       => true,
						'message'       => 'Dry run complete. No Cloudflare changes were made.',
						'dry_run'       => true,
						'host'          => $host,
						'ruleset_id'    => (string) ( $ruleset['id'] ?? '' ),
						'action'        => $action,
						'rule'          => $new_rule,
						'existing_rule' => $existing_rule ?? array(),
						'rule_count'    => count( $rules ),
					);
				}

				$update_result = mcp_cloudflare_api_request(
					'PUT',
					'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'] . '/rulesets/' . rawurlencode( (string) $ruleset['id'] ),
					$context,
					array(
						'body'    => wp_json_encode( array( 'rules' => $rules ) ),
						'timeout' => 30,
					)
				);

				if ( is_wp_error( $update_result ) ) {
					return array(
						'success' => false,
						'message' => $update_result->get_error_message(),
					);
				}

				$body = $update_result['body'];
				if ( empty( $body['success'] ) || empty( $body['result'] ) ) {
					return array(
						'success' => false,
						'message' => 'Cloudflare API error: ' . mcp_cloudflare_api_error_message( $body ),
					);
				}

				return array(
					'success'       => true,
					'message'       => 'Cloudflare WordPress HTML cache rule ' . $action . '.',
					'dry_run'       => false,
					'host'          => $host,
					'ruleset_id'    => (string) ( $body['result']['id'] ?? $ruleset['id'] ?? '' ),
					'action'        => $action,
					'rule'          => $new_rule,
					'existing_rule' => $existing_rule ?? array(),
					'rule_count'    => isset( $body['result']['rules'] ) && is_array( $body['result']['rules'] ) ? count( $body['result']['rules'] ) : count( $rules ),
				);
			},
			'permission_callback' => 'mcp_cloudflare_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);

	// =========================================================================
	// CLOUDFLARE - Set Development Mode
	// =========================================================================
	wp_register_ability(
		'cloudflare/set-development-mode',
		array(
			'label'               => 'Set Cloudflare Development Mode',
			'description'         => 'Enable or disable Cloudflare development mode.',
			'category'            => 'site',
			'input_schema'        => array(
				'type'                 => 'object',
				'required'             => array( 'value' ),
				'properties'           => array(
					'value' => array(
						'type'        => 'string',
						'enum'        => array( 'on', 'off' ),
						'description' => 'Turn development mode on or off.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'value'   => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function ( $input = array() ): array {
				$input = mcp_cloudflare_normalize_input( $input );
				$value = sanitize_text_field( $input['value'] ?? '' );
				if ( empty( $value ) ) {
					return array(
						'success' => false,
						'message' => 'value is required.',
					);
				}

				$context = mcp_cloudflare_get_context();
				if ( is_wp_error( $context ) ) {
					return array(
						'success' => false,
						'message' => $context->get_error_message(),
					);
				}

				$result = mcp_cloudflare_api_request(
					'PATCH',
					'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'] . '/settings/development_mode',
					$context,
					array(
						'body'    => wp_json_encode( array( 'value' => $value ) ),
						'timeout' => 30,
					)
				);

				if ( is_wp_error( $result ) ) {
					return array(
						'success' => false,
						'message' => $result->get_error_message(),
					);
				}

				$body = $result['body'];
				if ( empty( $body['success'] ) || empty( $body['result']['value'] ) ) {
					$error_msg = mcp_cloudflare_api_error_message( $body );
					return array(
						'success' => false,
						'message' => 'Cloudflare API error: ' . $error_msg,
					);
				}

				return array(
					'success' => true,
					'value'   => $body['result']['value'],
					'message' => 'Development mode updated.',
				);
			},
			'permission_callback' => 'mcp_cloudflare_permission_callback',
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				),
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'mcp_register_cloudflare_abilities' );
add_filter( 'devenia_workflow_frontend_cache_invalidation_result', 'mcp_cloudflare_frontend_cache_invalidation_result', 10, 3 );
add_action( 'upgrader_process_complete', 'mcp_cloudflare_observe_plugin_upgrade_complete', 10, 2 );
