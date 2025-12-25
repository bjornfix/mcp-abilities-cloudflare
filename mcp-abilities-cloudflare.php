<?php
/**
 * Plugin Name: MCP Abilities - Cloudflare
 * Plugin URI: https://github.com/bjornfix/mcp-abilities-cloudflare
 * Description: Cloudflare abilities for MCP. Clear cache for entire site or specific URLs.
 * Version: 1.0.1
 * Author: Devenia
 * Author URI: https://devenia.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Requires Plugins: abilities-api
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

				// Get Cloudflare credentials from plugin options.
				$api_email = get_option( 'cloudflare_api_email', '' );
				$api_key   = get_option( 'cloudflare_api_key', '' );
				$zone_id   = get_option( 'cloudflare_zone_id', '' );
				$domain    = get_option( 'cloudflare_cached_domain_name', '' );

				if ( empty( $api_email ) || empty( $api_key ) ) {
					return array(
						'success' => false,
						'message' => 'Cloudflare API credentials not configured. Install and configure the Cloudflare plugin first.',
					);
				}

				if ( empty( $domain ) ) {
					$domain = wp_parse_url( home_url(), PHP_URL_HOST );
				}

				// Step 1: Get zone ID - use stored value or look it up via API.
				if ( empty( $zone_id ) ) {
					$zones_response = wp_remote_get(
						'https://api.cloudflare.com/client/v4/zones?name=' . rawurlencode( $domain ),
						array(
							'headers' => array(
								'X-Auth-Email' => $api_email,
								'X-Auth-Key'   => $api_key,
								'Content-Type' => 'application/json',
							),
							'timeout' => 30,
						)
					);

					if ( is_wp_error( $zones_response ) ) {
						return array(
							'success' => false,
							'message' => 'Failed to connect to Cloudflare API: ' . $zones_response->get_error_message(),
						);
					}

					$zones_body = json_decode( wp_remote_retrieve_body( $zones_response ), true );

					if ( empty( $zones_body['success'] ) || empty( $zones_body['result'][0]['id'] ) ) {
						$error_msg = isset( $zones_body['errors'][0]['message'] ) ? $zones_body['errors'][0]['message'] : 'Zone not found';
						return array(
							'success' => false,
							'message' => 'Cloudflare API error: ' . $error_msg,
						);
					}

					$zone_id = $zones_body['result'][0]['id'];
				}

				// Step 2: Purge cache.
				$purge_everything = isset( $input['purge_everything'] ) ? (bool) $input['purge_everything'] : true;
				$files            = isset( $input['files'] ) && is_array( $input['files'] ) ? $input['files'] : array();

				if ( ! empty( $files ) ) {
					$purge_data = array( 'files' => $files );
				} else {
					$purge_data = array( 'purge_everything' => $purge_everything );
				}

				$purge_response = wp_remote_post(
					'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache',
					array(
						'headers' => array(
							'X-Auth-Email' => $api_email,
							'X-Auth-Key'   => $api_key,
							'Content-Type' => 'application/json',
						),
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

				$message = ! empty( $files )
					? 'Purged ' . count( $files ) . ' specific URL(s) from Cloudflare cache.'
					: 'Purged entire Cloudflare cache for ' . $domain . '.';

				return array(
					'success' => true,
					'message' => $message,
				);
			},
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_options' );
			},
			'meta'                => array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		)
	);
}
add_action( 'wp_abilities_api_init', 'mcp_register_cloudflare_abilities' );
