<?php
declare(strict_types=1);

namespace Rede\Tests;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

#[CoversFunction( 'Rede\rede_permission_callback' )]
#[CoversFunction( 'Rede\rede_get_totp_secret' )]
#[CoversFunction( 'Rede\rede_base32_encode' )]
#[CoversFunction( 'Rede\rede_base32_decode' )]
#[CoversFunction( 'Rede\rede_validate_totp' )]
class AuthTest extends TestCase {

	// A stable base32-encoded secret for use across tests.
	private const TEST_SECRET = 'JBSWY3DPEHPK3PXP';

	// A fixed Unix timestamp (falls in a clean TOTP window boundary for readability).
	private const FIXED_TIME = 1_700_000_000;

	protected function setUp(): void {
		rede_reset_state();
		$GLOBALS['rede_options']['rede_totp_secret'] = self::TEST_SECRET;
		$GLOBALS['rede_current_time']                = self::FIXED_TIME;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Independent TOTP implementation used to generate expected codes in tests.
	 * Duplicates the RFC 6238 algorithm intentionally — tests should not call
	 * the function under test to produce the value they then assert against.
	 */
	private function compute_totp( string $base32_secret, int $timestamp ): string {
		$key      = $this->b32decode( $base32_secret );
		$t        = (int) floor( $timestamp / 30 );
		$msg      = pack( 'J', $t );
		$hmac     = hash_hmac( 'sha1', $msg, $key, true );
		$offset   = ord( $hmac[19] ) & 0x0f;
		$otp      = (
			( ( ord( $hmac[ $offset ] ) & 0x7f ) << 24 ) |
			( ( ord( $hmac[ $offset + 1 ] ) & 0xff ) << 16 ) |
			( ( ord( $hmac[ $offset + 2 ] ) & 0xff ) << 8 ) |
			( ord( $hmac[ $offset + 3 ] ) & 0xff )
		) % 1_000_000;
		return str_pad( (string) $otp, 6, '0', STR_PAD_LEFT );
	}

	private function b32decode( string $data ): string {
		$alpha  = array_flip( str_split( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567' ) );
		$out    = '';
		$acc    = 0;
		$bits   = 0;
		foreach ( str_split( strtoupper( $data ) ) as $c ) {
			if ( '=' === $c || ! isset( $alpha[ $c ] ) ) {
				continue;
			}
			$acc   = ( $acc << 5 ) | $alpha[ $c ];
			$bits += 5;
			if ( $bits >= 8 ) {
				$bits -= 8;
				$out  .= chr( ( $acc >> $bits ) & 0xff );
			}
		}
		return $out;
	}

	private function totp_request( string $code ): WP_REST_Request {
		return new WP_REST_Request(
			array( 'url' => 'https://example.com/photo.jpg' ),
			array( 'authorization' => 'TOTP ' . $code )
		);
	}

	// -------------------------------------------------------------------------
	// rede_get_totp_secret()
	// -------------------------------------------------------------------------

	public function test_get_totp_secret_returns_stored_secret(): void {
		$this->assertSame( self::TEST_SECRET, \Rede\rede_get_totp_secret() );
	}

	public function test_get_totp_secret_generates_secret_when_none_exists(): void {
		unset( $GLOBALS['rede_options']['rede_totp_secret'] );

		$secret = \Rede\rede_get_totp_secret();

		$this->assertNotEmpty( $secret );
		$this->assertSame( $secret, $GLOBALS['rede_options']['rede_totp_secret'] );
	}

	public function test_get_totp_secret_is_stable_across_calls(): void {
		$first  = \Rede\rede_get_totp_secret();
		$second = \Rede\rede_get_totp_secret();

		$this->assertSame( $first, $second );
	}

	// -------------------------------------------------------------------------
	// rede_base32_encode() / rede_base32_decode()
	// -------------------------------------------------------------------------

	public function test_base32_round_trip(): void {
		$original = 'Hello, World!';
		$encoded  = \Rede\rede_base32_encode( $original );
		$decoded  = \Rede\rede_base32_decode( $encoded );

		$this->assertSame( $original, $decoded );
	}

	public function test_base32_decode_is_case_insensitive(): void {
		$upper = \Rede\rede_base32_decode( 'JBSWY3DP' );
		$lower = \Rede\rede_base32_decode( 'jbswy3dp' );

		$this->assertSame( $upper, $lower );
	}

	public function test_base32_decode_ignores_padding(): void {
		$with    = \Rede\rede_base32_decode( 'JBSWY3DP======' );
		$without = \Rede\rede_base32_decode( 'JBSWY3DP' );

		$this->assertSame( $with, $without );
	}

	// -------------------------------------------------------------------------
	// rede_validate_totp()
	// -------------------------------------------------------------------------

	public function test_validate_totp_accepts_current_window(): void {
		$code = $this->compute_totp( self::TEST_SECRET, self::FIXED_TIME );
		$this->assertTrue( \Rede\rede_validate_totp( $code ) );
	}

	public function test_validate_totp_accepts_previous_window(): void {
		$code = $this->compute_totp( self::TEST_SECRET, self::FIXED_TIME - 30 );
		$this->assertTrue( \Rede\rede_validate_totp( $code ) );
	}

	public function test_validate_totp_accepts_next_window(): void {
		$code = $this->compute_totp( self::TEST_SECRET, self::FIXED_TIME + 30 );
		$this->assertTrue( \Rede\rede_validate_totp( $code ) );
	}

	public function test_validate_totp_rejects_expired_code(): void {
		$code = $this->compute_totp( self::TEST_SECRET, self::FIXED_TIME - 90 );
		$this->assertFalse( \Rede\rede_validate_totp( $code ) );
	}

	public function test_validate_totp_rejects_wrong_code(): void {
		$this->assertFalse( \Rede\rede_validate_totp( '000000' ) );
	}

	// -------------------------------------------------------------------------
	// rede_permission_callback()
	// -------------------------------------------------------------------------

	public function test_permission_callback_allows_logged_in_user(): void {
		$GLOBALS['rede_user_logged_in'] = true;
		$request                        = new WP_REST_Request();

		$result = \Rede\rede_permission_callback( $request );

		$this->assertTrue( $result );
	}

	public function test_permission_callback_allows_valid_totp(): void {
		$code    = $this->compute_totp( self::TEST_SECRET, self::FIXED_TIME );
		$request = $this->totp_request( $code );

		$result = \Rede\rede_permission_callback( $request );

		$this->assertTrue( $result );
	}

	public function test_permission_callback_rejects_invalid_totp(): void {
		$request = $this->totp_request( '000000' );

		$result = \Rede\rede_permission_callback( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function test_permission_callback_rejects_unauthenticated_request(): void {
		$request = new WP_REST_Request();

		$result = \Rede\rede_permission_callback( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function test_permission_callback_rejects_malformed_totp_header(): void {
		$request = new WP_REST_Request(
			array(),
			array( 'authorization' => 'TOTP' ) // missing the code
		);

		$result = \Rede\rede_permission_callback( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_permission_callback_rejects_wrong_auth_scheme(): void {
		$request = new WP_REST_Request(
			array(),
			array( 'authorization' => 'Bearer sometoken' )
		);

		$result = \Rede\rede_permission_callback( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_permission_callback_returns_401_status(): void {
		$request = new WP_REST_Request();

		$result = \Rede\rede_permission_callback( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'status' => 401 ), $result->get_error_data() );
	}
}
