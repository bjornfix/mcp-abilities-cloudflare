<?php
/**
 * Plugin Name: MCP Abilities - Cloudflare
 * Plugin URI: https://github.com/bjornfix/mcp-abilities-cloudflare
 * Description: Cloudflare abilities for MCP. Clear cache for entire site or specific URLs.
 * Version: 1.0.3
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
 * Build Cloudflare API headers.
 *
 * @param string $api_email API email.
 * @param string $api_key   API key.
 * @return array
 */
function mcp_cloudflare_api_headers( string $api_email, string $api_key ): array {
	return array(
		'X-Auth-Email' => $api_email,
		'X-Auth-Key'   => $api_key,
		'Content-Type' => 'application/json',
	);
}

/**
 * Resolve and cache the Cloudflare zone ID for a domain.
 *
 * @param string $api_email API email.
 * @param string $api_key   API key.
 * @param string $domain    Domain name.
 * @return string|WP_Error
 */
function mcp_cloudflare_get_zone_id( string $api_email, string $api_key, string $domain ) {
	$zone_id = get_option( 'cloudflare_zone_id', '' );
	if ( ! empty( $zone_id ) ) {
		return $zone_id;
	}

	$zones_response = wp_remote_get(
		add_query_arg( 'name', $domain, 'https://api.cloudflare.com/client/v4/zones' ),
		array(
			'headers' => mcp_cloudflare_api_headers( $api_email, $api_key ),
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $zones_response ) ) {
		return $zones_response;
	}

	$zones_body = json_decode( wp_remote_retrieve_body( $zones_response ), true );
	if ( empty( $zones_body['success'] ) || empty( $zones_body['result'][0]['id'] ) ) {
		$error_msg = isset( $zones_body['errors'][0]['message'] ) ? $zones_body['errors'][0]['message'] : 'Zone not found';
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
	$api_email = get_option( 'cloudflare_api_email', '' );
	$api_key   = get_option( 'cloudflare_api_key', '' );
	$zone_id   = get_option( 'cloudflare_zone_id', '' );
	$domain    = get_option( 'cloudflare_cached_domain_name', '' );

	if ( empty( $api_email ) || empty( $api_key ) ) {
		return new WP_Error( 'cloudflare_missing_credentials', 'Cloudflare API credentials not configured. Install and configure the Cloudflare plugin first.' );
	}

	if ( empty( $domain ) ) {
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );
	}

	if ( empty( $zone_id ) ) {
		$zone_id = mcp_cloudflare_get_zone_id( $api_email, $api_key, $domain );
		if ( is_wp_error( $zone_id ) ) {
			return $zone_id;
		}
	}

	return array(
		'api_email' => $api_email,
		'api_key'   => $api_key,
		'zone_id'   => $zone_id,
		'domain'    => $domain,
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
				$input = is_array( $input ) ? $input : array();

				$context = mcp_cloudflare_get_context();
				if ( is_wp_error( $context ) ) {
					return array(
						'success' => false,
						'message' => $context->get_error_message(),
					);
				}

				// Step 2: Purge cache.
				$purge_everything = isset( $input['purge_everything'] ) ? (bool) $input['purge_everything'] : true;
				$files            = isset( $input['files'] ) && is_array( $input['files'] )
					? array_values( array_filter( $input['files'], 'is_string' ) )
					: array();
				$tags             = isset( $input['tags'] ) && is_array( $input['tags'] )
					? array_values( array_filter( $input['tags'], 'is_string' ) )
					: array();
				$hosts            = isset( $input['hosts'] ) && is_array( $input['hosts'] )
					? array_values( array_filter( $input['hosts'], 'is_string' ) )
					: array();

				if ( ! empty( $files ) ) {
					$purge_data = array( 'files' => $files );
				} elseif ( ! empty( $tags ) ) {
					$purge_data = array( 'tags' => $tags );
				} elseif ( ! empty( $hosts ) ) {
					$purge_data = array( 'hosts' => $hosts );
				} else {
					$purge_data = array( 'purge_everything' => $purge_everything );
				}

				$purge_response = wp_remote_post(
					'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'] . '/purge_cache',
					array(
						'headers' => mcp_cloudflare_api_headers( $context['api_email'], $context['api_key'] ),
						'body'    => wp_json_encode( $purge_data ),
						'timeout' => 30,
					)
				);

				if ( is_wp_error( $purge_response ) ) {
					return array(
						'success' => false,
						'message' => 'Failed to purge cache: ' . $purge_response->get_error_message(),
					);
				}

				$purge_body = json_decode( wp_remote_retrieve_body( $purge_response ), true );

				if ( empty( $purge_body['success'] ) ) {
					$error_msg = isset( $purge_body['errors'][0]['message'] ) ? $purge_body['errors'][0]['message'] : 'Unknown error';
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
					'destructive' => false,
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
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success' => array( 'type' => 'boolean' ),
					'zone'    => array( 'type' => 'object' ),
					'message' => array( 'type' => 'string' ),
				),
			),
			'execute_callback'    => function (): array {
				$context = mcp_cloudflare_get_context();
				if ( is_wp_error( $context ) ) {
					return array(
						'success' => false,
						'message' => $context->get_error_message(),
					);
				}

				$response = wp_remote_get(
					'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'],
					array(
						'headers' => mcp_cloudflare_api_headers( $context['api_email'], $context['api_key'] ),
						'timeout' => 30,
					)
				);

				if ( is_wp_error( $response ) ) {
					return array(
						'success' => false,
						'message' => $response->get_error_message(),
					);
				}

				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( empty( $body['success'] ) || empty( $body['result'] ) ) {
					$error_msg = isset( $body['errors'][0]['message'] ) ? $body['errors'][0]['message'] : 'Unknown error';
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
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(),
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
			'execute_callback'    => function (): array {
				$context = mcp_cloudflare_get_context();
				if ( is_wp_error( $context ) ) {
					return array(
						'success' => false,
						'message' => $context->get_error_message(),
					);
				}

				$response = wp_remote_get(
					'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'] . '/settings/development_mode',
					array(
						'headers' => mcp_cloudflare_api_headers( $context['api_email'], $context['api_key'] ),
						'timeout' => 30,
					)
				);

				if ( is_wp_error( $response ) ) {
					return array(
						'success' => false,
						'message' => $response->get_error_message(),
					);
				}

				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( empty( $body['success'] ) || empty( $body['result']['value'] ) ) {
					$error_msg = isset( $body['errors'][0]['message'] ) ? $body['errors'][0]['message'] : 'Unknown error';
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

				$response = wp_remote_request(
					'https://api.cloudflare.com/client/v4/zones/' . $context['zone_id'] . '/settings/development_mode',
					array(
						'method'  => 'PATCH',
						'headers' => mcp_cloudflare_api_headers( $context['api_email'], $context['api_key'] ),
						'body'    => wp_json_encode( array( 'value' => $value ) ),
						'timeout' => 30,
					)
				);

				if ( is_wp_error( $response ) ) {
					return array(
						'success' => false,
						'message' => $response->get_error_message(),
					);
				}

				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( empty( $body['success'] ) || empty( $body['result']['value'] ) ) {
					$error_msg = isset( $body['errors'][0]['message'] ) ? $body['errors'][0]['message'] : 'Unknown error';
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
