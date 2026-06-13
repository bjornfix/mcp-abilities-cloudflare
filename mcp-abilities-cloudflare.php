<?php
/**
 * Plugin Name: MCP Abilities - Cloudflare
 * Plugin URI: https://github.com/bjornfix/mcp-abilities-cloudflare
 * Description: Cloudflare abilities for MCP. Inspect and clear Cloudflare cache for WordPress sites.
 * Version: 1.0.9
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
					$hosts = array_slice( $hosts, 0, 100 );

				if ( ! empty( $files ) ) {
					$purge_data = array( 'files' => $files );
				} elseif ( ! empty( $tags ) ) {
					$purge_data = array( 'tags' => $tags );
				} elseif ( ! empty( $hosts ) ) {
					$purge_data = array( 'hosts' => $hosts );
				} else {
					$purge_data = array( 'purge_everything' => $purge_everything );
				}

				$purge_result = mcp_cloudflare_api_request(
					'POST',
					'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'] . '/purge_cache',
					$context,
					array(
						'body'    => wp_json_encode( $purge_data ),
						'timeout' => 30,
					)
				);

				if ( is_wp_error( $purge_result ) ) {
					return array(
						'success' => false,
						'message' => 'Failed to purge cache: ' . $purge_result->get_error_message(),
					);
				}

				$purge_body = $purge_result['body'];

				if ( empty( $purge_body['success'] ) ) {
					$error_msg = mcp_cloudflare_api_error_message( $purge_body );
					return array(
						'success' => false,
						'message' => 'Cache purge failed: ' . $error_msg,
					);
				}

				$domain  = $context['domain'] ?? '';
				$message = ! empty( $files )
					? 'Purged ' . count( $files ) . ' specific URL(s) from Cloudflare cache.'
					: 'Purged entire Cloudflare cache for ' . $domain . '.';

				return array(
					'success' => true,
					'message' => $message,
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
