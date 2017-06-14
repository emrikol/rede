<?php
/**
 * Plugin Name: Remote EXIF Data Endpoint
 * Plugin URI: https://github.com/emrikol/rede
 * Description: Creates an endpoint to return EXIF data of remote images.
 * Version: 1.0.0
 * Author: Derrick Tennant
 * Author URI: https://emrikol.com/
 * License: GPL3
 * GitHub Plugin URI: https://github.com/emrikol/rede/
 */

function rede_register_route() {
	register_rest_route( 'exif-data/v1', '/read/', array(
		'methods' => WP_REST_Server::READABLE,
		'callback' => 'rede_read_data',
		'args' => array(
			'url' => array(
				'sanitize_callback' => 'rede_sanitize_url',
				'default' => false,
			),
		),
	) );
}
add_action( 'rest_api_init', 'rede_register_route' );

function rede_sanitize_url( $url ) {
	return esc_url_raw( rawurldecode( $url ) );
}

function rede_read_data( $request ) {
	$url = $request['url'];

	$cache_key = md5( 'exif-json:' . $url );
	$exif = get_transient( $cache_key );

	if ( false === $exif ) {
		if ( is_callable( 'exif_read_data' ) ) {
			$raw_exif = @exif_read_data( $url ); // On bad data, exif_read_data() will throw a warning, suppress.  @codingStandardsIgnoreLine.
			if ( false === $raw_exif ) {
				return rest_ensure_response( array( 'success' => false, 'data' => 'EXIF Not Found for image' ) );
			}
			$exif = $raw_exif;
			set_transient( $cache_key, $exif );
		} else {
			return rest_ensure_response( array( 'success' => false, 'data' => 'exif_read_data function not found!' ) );
		}
	}

	return rest_ensure_response( array( 'success' => true, 'data' => $exif ) );
}
