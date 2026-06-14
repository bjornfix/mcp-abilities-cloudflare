<?php
/**
 * Plugin Name: MCP Abilities - Cloudflare
 * Plugin URI: https://github.com/bjornfix/mcp-abilities-cloudflare
 * Description: Cloudflare abilities for MCP. Inspect and clear Cloudflare cache for WordPress sites.
 * Version: 1.0.13
 * Author: Devenia
 * Author URI: https://devenia.com
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

/**
 * Check if Abilities API is available.
 */
function mcp_cloudflare_check_dependencies(): bool {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>MCP Abilities - Cloudflare</strong> requires the <a href="https://github.com/WordPress/abilities-api">Abilities API</a> plugin to be installed and activated.</p></div>';
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
 * The Devenia public HTML cache rule caches extensionless/html paths at the
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
		return '';
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

/**
 * Build the default public WordPress HTML cache rule.
 *
 * @param string $host             Hostname to cache.
 * @param int    $edge_ttl_seconds Edge TTL for successful responses.
 * @param bool   $enabled          Whether the rule should be enabled.
 * @param string $description      Rule description.
 * @param string $ref              Stable rule reference.
 * @return array
 */
function mcp_cloudflare_build_wordpress_html_cache_rule( string $host, int $edge_ttl_seconds, bool $enabled, string $description, string $ref ): array {
	$edge_ttl_seconds = max( 60, min( 86400, $edge_ttl_seconds ) );
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
						'description' => 'Optional: Specific URLs to purge instead of everything.',
					),
					'tags'             => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Optional: Cache tags to purge (Enterprise plans only).',
					),
					'prefixes'         => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Optional: URL prefixes to purge when exact URL purges do not match cached HTML variants.',
					),
					'hosts'            => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
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
			'execute_callback'    => function ( $input = array() ): array {
				$input = mcp_cloudflare_normalize_input( $input );

				$context = mcp_cloudflare_get_context();
				if ( is_wp_error( $context ) ) {
					return array(
						'success' => false,
						'message' => $context->get_error_message(),
					);
				}

				// Step 2: Purge cache.
				$purge_everything = isset( $input['purge_everything'] ) ? (bool) $input['purge_everything'] : true;
					$files = isset( $input['files'] ) && is_array( $input['files'] )
						? array_values(
							array_filter(
								array_map(
									static function ( $item ) {
										return is_string( $item ) ? esc_url_raw( $item ) : '';
									},
									$input['files']
								)
							)
						)
						: array();
					$tags = isset( $input['tags'] ) && is_array( $input['tags'] )
						? array_values(
							array_filter(
								array_map(
									static function ( $item ) {
										return is_string( $item ) ? sanitize_text_field( $item ) : '';
									},
									$input['tags']
								)
							)
						)
						: array();
					$prefixes = isset( $input['prefixes'] ) && is_array( $input['prefixes'] )
						? array_values(
							array_filter(
								array_map(
									static function ( $item ) {
										return mcp_cloudflare_normalize_purge_prefix( $item );
									},
									$input['prefixes']
								)
							)
						)
						: array();
					$hosts = isset( $input['hosts'] ) && is_array( $input['hosts'] )
						? array_values(
							array_filter(
								array_map(
									static function ( $item ) {
										return is_string( $item ) ? sanitize_text_field( $item ) : '';
									},
									$input['hosts']
								)
							)
						)
						: array();
					$files = array_slice( $files, 0, 100 );
					$tags  = array_slice( $tags, 0, 100 );
					$prefixes = array_slice( $prefixes, 0, 100 );
					$hosts = array_slice( $hosts, 0, 100 );

				$exact_files      = array();
				$auto_prefixes    = array();
				$auto_prefix_from = array();
				foreach ( $files as $file_url ) {
					$prefix = mcp_cloudflare_html_file_url_to_prefix( $file_url, $context );
					if ( '' !== $prefix ) {
						$auto_prefixes[]               = $prefix;
						$auto_prefix_from[ $file_url ] = $prefix;
						continue;
					}

					$exact_files[] = $file_url;
				}

				$prefixes = array_values( array_unique( array_merge( $prefixes, $auto_prefixes ) ) );
				$purge_operations = array();

				if ( ! empty( $exact_files ) ) {
					$purge_operations[] = array(
						'type'  => 'files',
						'data'  => array( 'files' => $exact_files ),
						'count' => count( $exact_files ),
					);
				}
				if ( ! empty( $tags ) ) {
					$purge_operations[] = array(
						'type'  => 'tags',
						'data'  => array( 'tags' => $tags ),
						'count' => count( $tags ),
					);
				}
				if ( ! empty( $prefixes ) ) {
					$purge_operations[] = array(
						'type'  => 'prefixes',
						'data'  => array( 'prefixes' => $prefixes ),
						'count' => count( $prefixes ),
					);
				}
				if ( ! empty( $hosts ) ) {
					$purge_operations[] = array(
						'type'  => 'hosts',
						'data'  => array( 'hosts' => $hosts ),
						'count' => count( $hosts ),
					);
				}
				if ( empty( $purge_operations ) ) {
					$purge_operations[] = array(
						'type'  => 'everything',
						'data'  => array( 'purge_everything' => $purge_everything ),
						'count' => 1,
					);
				}

				$operation_results = array();
				foreach ( $purge_operations as $operation ) {
					$purge_result = mcp_cloudflare_api_request(
						'POST',
						'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'] . '/purge_cache',
						$context,
						array(
							'body'    => wp_json_encode( $operation['data'] ),
							'timeout' => 30,
						)
					);

					if ( is_wp_error( $purge_result ) ) {
						return array(
							'success' => false,
							'message' => 'Failed to purge cache: ' . $purge_result->get_error_message(),
							'purge'   => array(
								'completed' => $operation_results,
							),
						);
					}

					$purge_body = $purge_result['body'];

					if ( empty( $purge_body['success'] ) ) {
						$error_msg = mcp_cloudflare_api_error_message( $purge_body );
						return array(
							'success' => false,
							'message' => 'Cache purge failed: ' . $error_msg,
							'purge'   => array(
								'failed_type' => $operation['type'],
								'completed'   => $operation_results,
							),
						);
					}

					$operation_results[] = array(
						'type'          => $operation['type'],
						'count'         => $operation['count'],
						'payload_keys'  => array_keys( $operation['data'] ),
						'cloudflare_id' => (string) ( $purge_body['result']['id'] ?? '' ),
						'auth_mode'     => (string) ( $purge_result['auth_mode'] ?? '' ),
					);
				}

				$domain = $context['domain'] ?? '';
				$counts = array_fill_keys( array( 'files', 'tags', 'prefixes', 'hosts', 'everything' ), 0 );
				foreach ( $purge_operations as $operation ) {
					$counts[ $operation['type'] ] += (int) $operation['count'];
				}
				$message_parts = array();
				if ( $counts['files'] > 0 ) {
					$message_parts[] = $counts['files'] . ' exact URL(s)';
				}
				if ( $counts['tags'] > 0 ) {
					$message_parts[] = $counts['tags'] . ' cache tag(s)';
				}
				if ( $counts['prefixes'] > 0 ) {
					$message_parts[] = $counts['prefixes'] . ' URL prefix(es)';
				}
				if ( $counts['hosts'] > 0 ) {
					$message_parts[] = $counts['hosts'] . ' host(s)';
				}
				$message = $counts['everything'] > 0
					? 'Purged entire Cloudflare cache for ' . $domain . '.'
					: 'Purged ' . implode( ', ', $message_parts ) . ' from Cloudflare cache.';
				$purge_type = count( $operation_results ) > 1 ? 'multi' : ( $operation_results[0]['type'] ?? 'unknown' );
				$total_count = array_sum( $counts );

				return array(
					'success' => true,
					'message' => $message,
					'purge'   => array(
						'type'          => $purge_type,
						'count'         => $total_count,
						'payload_keys'  => array_values( array_unique( array_merge( ...array_map( static function ( $operation ) {
							return array_keys( $operation['data'] );
						}, $purge_operations ) ) ) ),
						'cloudflare_id' => $operation_results[0]['cloudflare_id'] ?? '',
						'auth_mode'     => $operation_results[0]['auth_mode'] ?? '',
						'operations'    => $operation_results,
						'auto_prefixes' => $auto_prefix_from,
					),
				);
			},
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
									'User-Agent' => 'Devenia MCP Cloudflare Cache Probe/1.0',
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
					: 'Devenia public WordPress HTML cache';
				$ref              = isset( $input['ref'] ) && is_string( $input['ref'] )
					? sanitize_key( $input['ref'] )
					: 'devenia-public-wordpress-html-cache';

				$ruleset = mcp_cloudflare_get_cache_entrypoint_ruleset( $context );
				if ( is_wp_error( $ruleset ) ) {
					return array(
						'success' => false,
						'message' => $ruleset->get_error_message(),
					);
				}

				$existing_rules = isset( $ruleset['rules'] ) && is_array( $ruleset['rules'] ) ? $ruleset['rules'] : array();
				$new_rule       = mcp_cloudflare_build_wordpress_html_cache_rule( $host, $edge_ttl_seconds, $enabled, $description, $ref );
				$rules          = array();
				$existing_rule  = null;
				$action         = 'created';

				foreach ( $existing_rules as $rule ) {
					if ( ! is_array( $rule ) ) {
						continue;
					}

					$matches_ref         = isset( $rule['ref'] ) && $ref === (string) $rule['ref'];
					$matches_description = isset( $rule['description'] ) && $description === (string) $rule['description'];
					if ( $matches_ref || $matches_description ) {
						$existing_rule = $rule;
						if ( isset( $rule['id'] ) ) {
							$new_rule['id'] = $rule['id'];
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
