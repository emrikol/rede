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

namespace Rede;

/**
 * Registers the REST API route for reading EXIF data.
 *
 * @return void
 */
function rede_register_route(): void {
	register_rest_route(
		'exif-data/v1',
		'/read/',
		array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\rede_read_data',
			'permission_callback' => __NAMESPACE__ . '\rede_permission_callback',
			'args'                => array(
				'url' => array(
					'sanitize_callback' => __NAMESPACE__ . '\rede_sanitize_url',
					'default'           => false,
				),
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\rede_register_route' );

/**
 * Checks whether the request is authenticated via Application Password or TOTP.
 *
 * Accepts two authentication paths:
 *  - WordPress Application Password (HTTP Basic auth) — any logged-in WP user.
 *  - TOTP — a valid Authorization: TOTP <code> header against the stored secret.
 *
 * @param \WP_REST_Request $request The REST request object.
 * @return true|\WP_Error True if authenticated, WP_Error on failure.
 */
function rede_permission_callback( \WP_REST_Request $request ): true|\WP_Error {
	if ( is_user_logged_in() ) {
		return true;
	}

	$auth = $request->get_header( 'Authorization' );
	if ( is_string( $auth ) && str_starts_with( $auth, 'TOTP ' ) ) {
		$code = trim( substr( $auth, 5 ) );
		if ( rede_validate_totp( $code ) ) {
			return true;
		}
	}

	return new \WP_Error( 'rest_forbidden', 'Authentication required.', array( 'status' => 401 ) );
}

/**
 * Returns the stored TOTP secret, generating and persisting one on first call.
 *
 * @return string Base32-encoded TOTP secret.
 */
function rede_get_totp_secret(): string {
	$secret = get_option( 'rede_totp_secret' );
	if ( false === $secret || '' === $secret ) {
		$secret = rede_base32_encode( random_bytes( 20 ) );
		update_option( 'rede_totp_secret', $secret, false );
	}
	return (string) $secret;
}

/**
 * Encodes binary data as a base32 string (RFC 4648, no padding).
 *
 * @param string $data Binary data to encode.
 * @return string Base32-encoded string.
 */
function rede_base32_encode( string $data ): string {
	$alphabet    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$output      = '';
	$accumulator = 0;
	$bits        = 0;

	foreach ( str_split( $data ) as $char ) {
		$accumulator = ( $accumulator << 8 ) | ord( $char );
		$bits       += 8;
		while ( $bits >= 5 ) {
			$bits   -= 5;
			$output .= $alphabet[ ( $accumulator >> $bits ) & 0x1f ];
		}
	}

	if ( $bits > 0 ) {
		$output .= $alphabet[ ( $accumulator << ( 5 - $bits ) ) & 0x1f ];
	}

	return $output;
}

/**
 * Decodes a base32 string to binary data (RFC 4648).
 *
 * @param string $data Base32-encoded string (padding optional).
 * @return string Decoded binary string.
 */
function rede_base32_decode( string $data ): string {
	$alphabet    = array_flip( str_split( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567' ) );
	$output      = '';
	$accumulator = 0;
	$bits        = 0;

	foreach ( str_split( strtoupper( $data ) ) as $char ) {
		if ( '=' === $char || ! isset( $alphabet[ $char ] ) ) {
			continue;
		}
		$accumulator = ( $accumulator << 5 ) | $alphabet[ $char ];
		$bits       += 5;
		if ( $bits >= 8 ) {
			$bits   -= 8;
			$output .= chr( ( $accumulator >> $bits ) & 0xff );
		}
	}

	return $output;
}

/**
 * Validates a TOTP code against the stored secret with a ±1 time-step window.
 *
 * Implements RFC 6238 (TOTP) using HMAC-SHA1 and 30-second time steps.
 *
 * @param string $code 6-digit TOTP code to validate.
 * @return bool True if the code is valid for the current or adjacent time steps.
 */
function rede_validate_totp( string $code ): bool {
	$key       = rede_base32_decode( rede_get_totp_secret() );
	$time_step = (int) floor( time() / 30 );

	for ( $offset = -1; $offset <= 1; $offset++ ) {
		$msg      = pack( 'J', $time_step + $offset );
		$hmac     = hash_hmac( 'sha1', $msg, $key, true );
		$offset_b = ord( $hmac[19] ) & 0x0f;
		$otp      = (
			( ( ord( $hmac[ $offset_b ] ) & 0x7f ) << 24 ) |
			( ( ord( $hmac[ $offset_b + 1 ] ) & 0xff ) << 16 ) |
			( ( ord( $hmac[ $offset_b + 2 ] ) & 0xff ) << 8 ) |
			( ord( $hmac[ $offset_b + 3 ] ) & 0xff )
		) % 1_000_000;

		if ( hash_equals( str_pad( (string) $otp, 6, '0', STR_PAD_LEFT ), $code ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Sanitizes the URL parameter from the REST request.
 *
 * @param string $url The URL to sanitize.
 * @return string Sanitized URL.
 */
function rede_sanitize_url( string $url ): string {
	return esc_url_raw( rawurldecode( $url ) );
}

/**
 * Fetches a remote image via the WordPress HTTP API and writes it to a temp file.
 *
 * Uses wp_remote_get() rather than passing a URL directly to exif_read_data(),
 * giving WordPress control over timeouts, proxies, and SSL verification.
 *
 * @param string $url Remote image URL.
 * @return string|\WP_Error Absolute path to the temp file on success, WP_Error on failure.
 */
function rede_fetch_image( string $url ): string|\WP_Error {
	$response = wp_remote_get( $url, array( 'timeout' => 15 ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get,WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- not a VIP Go plugin; 15 s is a reasonable timeout for remote image fetching.

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return new \WP_Error(
			'rede_fetch_failed',
			sprintf( 'Remote image returned HTTP %d.', $code ),
			array( 'status' => 502 )
		);
	}

	$body = wp_remote_retrieve_body( $response );
	if ( '' === $body ) {
		return new \WP_Error(
			'rede_empty_response',
			'Remote image returned an empty body.',
			array( 'status' => 502 )
		);
	}

	$temp = wp_tempnam();
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents -- temp file for local exif_read_data(); WP_Filesystem not suitable here.
	if ( false === file_put_contents( $temp, $body ) ) {
		return new \WP_Error(
			'rede_temp_write_failed',
			'Unable to write image to a temporary file.',
			array( 'status' => 500 )
		);
	}

	if ( ! exif_imagetype( $temp ) ) {
		wp_delete_file( $temp );
		return new \WP_Error(
			'rede_not_image',
			'URL does not point to a valid image.',
			array( 'status' => 422 )
		);
	}

	return $temp;
}

/**
 * Converts an EXIF rational string ("numerator/denominator") to a float.
 *
 * @param string $value Rational string, e.g. "50/1" or "3930/100".
 * @return float|null Converted value, or null on division by zero or invalid input.
 */
function rede_parse_rational( string $value ): ?float {
	if ( ! str_contains( $value, '/' ) ) {
		return is_numeric( $value ) ? (float) $value : null;
	}

	[ $num, $den ] = explode( '/', $value, 2 );
	$den           = (float) $den;

	if ( 0.0 === $den ) {
		return null;
	}

	return (float) $num / $den;
}

/**
 * Converts a GPS DMS (degrees/minutes/seconds) array to decimal degrees.
 *
 * Each element of $dms is a rational string (e.g. "32/1", "54/1", "35.56/100").
 * The $ref cardinal ("N", "S", "E", "W") determines the sign.
 *
 * @param array  $dms Array of three rational strings: degrees, minutes, seconds.
 * @param string $ref Cardinal direction: N, S, E, or W.
 * @return float|null Decimal degrees, or null if the input is invalid.
 */
function rede_gps_to_decimal( array $dms, string $ref ): ?float {
	if ( count( $dms ) < 3 ) {
		return null;
	}

	$degrees = rede_parse_rational( (string) $dms[0] );
	$minutes = rede_parse_rational( (string) $dms[1] );
	$seconds = rede_parse_rational( (string) $dms[2] );

	if ( null === $degrees || null === $minutes || null === $seconds ) {
		return null;
	}

	$decimal = $degrees + ( $minutes / 60.0 ) + ( $seconds / 3600.0 );

	if ( 'S' === strtoupper( $ref ) || 'W' === strtoupper( $ref ) ) {
		$decimal = -$decimal;
	}

	return round( $decimal, 6 );
}

/**
 * Transforms a single raw EXIF value into a clean, JSON-safe representation.
 *
 * - Arrays are processed recursively.
 * - Integers pass through unchanged.
 * - Strings containing control characters are discarded (returned as null).
 * - Rational strings ("n/d") are converted to floats.
 * - EXIF datetime strings are normalized to ISO 8601.
 * - All other strings are trimmed; empty results become null.
 *
 * @param int|string|array $value Raw value from exif_read_data().
 * @return int|float|string|array|null Transformed value.
 */
function rede_process_exif_value( int|string|array $value ): int|float|string|array|null {
	if ( is_array( $value ) ) {
		return array_map( static fn( $v ) => rede_process_exif_value( $v ), $value );
	}

	if ( is_int( $value ) ) {
		return $value;
	}

	/*
	 * Discard any value containing control characters — they are binary data
	 * that cannot be safely serialized to JSON.
	 */
	foreach ( str_split( $value ) as $char ) {
		if ( ord( $char ) < 0x20 ) {
			return null;
		}
	}

	$value = trim( $value );

	if ( '' === $value ) {
		return null;
	}

	// Rational number → float (e.g. "50/1" → 50.0, "3930/100" → 39.3).
	if ( preg_match( '/^\d+(?:\.\d+)?\/\d+(?:\.\d+)?$/', $value ) ) {
		return rede_parse_rational( $value );
	}

	// EXIF datetime → ISO 8601 (e.g. "2024:01:15 14:30:22" → "2024-01-15T14:30:22").
	if ( preg_match( '/^\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
		return (string) preg_replace( '/^(\d{4}):(\d{2}):(\d{2}) /', '$1-$2-$3T', $value );
	}

	return $value;
}

/**
 * Transforms a raw exif_read_data() array into a clean, API-friendly structure.
 *
 * In addition to per-value transformations via rede_process_exif_value(), this
 * function adds convenience fields:
 *  - GPSDecimalLatitude / GPSDecimalLongitude / GPSDecimalAltitude
 *  - CapturedAt — the best available EXIF datetime in ISO 8601
 *
 * @param array $raw Raw array returned by exif_read_data().
 * @return array Processed EXIF data with null values removed.
 */
function rede_process_exif( array $raw ): array {
	$out = array_map( static fn( $v ) => rede_process_exif_value( $v ), $raw );

	// Decimal GPS — computed from the raw rational arrays before transformation.
	if (
		isset( $raw['GPSLatitude'], $raw['GPSLatitudeRef'], $raw['GPSLongitude'], $raw['GPSLongitudeRef'] )
		&& is_array( $raw['GPSLatitude'] )
		&& is_array( $raw['GPSLongitude'] )
	) {
		$lat = rede_gps_to_decimal( $raw['GPSLatitude'], (string) $raw['GPSLatitudeRef'] );
		$lon = rede_gps_to_decimal( $raw['GPSLongitude'], (string) $raw['GPSLongitudeRef'] );

		if ( null !== $lat && null !== $lon ) {
			$out['GPSDecimalLatitude']  = $lat;
			$out['GPSDecimalLongitude'] = $lon;
		}
	}

	if ( isset( $raw['GPSAltitude'] ) && is_string( $raw['GPSAltitude'] ) ) {
		$alt = rede_parse_rational( $raw['GPSAltitude'] );
		if ( null !== $alt ) {
			$ref                       = isset( $raw['GPSAltitudeRef'] ) ? (int) $raw['GPSAltitudeRef'] : 0;
			$out['GPSDecimalAltitude'] = 1 === $ref ? -$alt : $alt;
		}
	}

	// CapturedAt — first available datetime field, already ISO 8601 from rede_process_exif_value().
	foreach ( array( 'DateTimeOriginal', 'DateTimeDigitized', 'DateTime' ) as $key ) {
		if ( isset( $out[ $key ] ) && is_string( $out[ $key ] ) ) {
			$out['CapturedAt'] = $out[ $key ];
			break;
		}
	}

	// Strip null values (binary/control-char fields that were discarded).
	return array_filter( $out, static fn( $v ): bool => null !== $v );
}

/**
 * Returns processed EXIF data for the provided remote image URL.
 *
 * @param \WP_REST_Request $request The REST request object.
 * @return \WP_REST_Response
 */
function rede_read_data( \WP_REST_Request $request ): \WP_REST_Response {
	$url       = (string) $request['url'];
	$cache_key = md5( 'exif-json:' . $url );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $cached,
			)
		);
	}

	if ( ! is_callable( 'exif_read_data' ) ) {
		return rest_ensure_response(
			array(
				'success' => false,
				'data'    => 'exif_read_data function not found!',
			)
		);
	}

	$temp = rede_fetch_image( $url );
	if ( is_wp_error( $temp ) ) {
		return rest_ensure_response(
			array(
				'success' => false,
				'data'    => $temp->get_error_message(),
			)
		);
	}

	set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- legitimate use for catching exif_read_data() warnings.
		static function ( int $errno, string $errstr ): never {
			throw new \ErrorException( $errstr, 0, $errno ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception is caught immediately, never output.
		}
	);
	try {
		$raw_exif = exif_read_data( $temp );
	} catch ( \ErrorException ) {
		$raw_exif = false;
	} finally {
		restore_error_handler();
		wp_delete_file( $temp );
	}

	if ( false === $raw_exif ) {
		return rest_ensure_response(
			array(
				'success' => false,
				'data'    => 'EXIF Not Found for image',
			)
		);
	}

	$exif = rede_process_exif( $raw_exif );
	set_transient( $cache_key, $exif );

	return rest_ensure_response(
		array(
			'success' => true,
			'data'    => $exif,
		)
	);
}
