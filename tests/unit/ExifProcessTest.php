<?php
declare(strict_types=1);

namespace Rede\Tests;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

#[CoversFunction( 'Rede\rede_parse_rational' )]
#[CoversFunction( 'Rede\rede_gps_to_decimal' )]
#[CoversFunction( 'Rede\rede_process_exif_value' )]
#[CoversFunction( 'Rede\rede_process_exif' )]
class ExifProcessTest extends TestCase {

	protected function setUp(): void {
		rede_reset_state();
	}

	// -------------------------------------------------------------------------
	// rede_parse_rational()
	// -------------------------------------------------------------------------

	public function test_parse_rational_converts_simple_fraction(): void {
		$this->assertSame( 50.0, \Rede\rede_parse_rational( '50/1' ) );
	}

	public function test_parse_rational_converts_decimal_fraction(): void {
		$this->assertEqualsWithDelta( 39.3, \Rede\rede_parse_rational( '3930/100' ), 0.0001 );
	}

	public function test_parse_rational_returns_null_on_division_by_zero(): void {
		$this->assertNull( \Rede\rede_parse_rational( '5/0' ) );
	}

	public function test_parse_rational_handles_plain_numeric_string(): void {
		$this->assertSame( 42.0, \Rede\rede_parse_rational( '42' ) );
	}

	public function test_parse_rational_returns_null_for_non_numeric_string(): void {
		$this->assertNull( \Rede\rede_parse_rational( 'abc' ) );
	}

	public function test_parse_rational_handles_zero_numerator(): void {
		$this->assertSame( 0.0, \Rede\rede_parse_rational( '0/1' ) );
	}

	// -------------------------------------------------------------------------
	// rede_gps_to_decimal()
	// -------------------------------------------------------------------------

	public function test_gps_to_decimal_north_is_positive(): void {
		// 48° 51' 30" N → 48.858333...
		$dms    = array( '48/1', '51/1', '30/1' );
		$result = \Rede\rede_gps_to_decimal( $dms, 'N' );
		$this->assertNotNull( $result );
		$this->assertEqualsWithDelta( 48.858333, $result, 0.0001 );
	}

	public function test_gps_to_decimal_south_is_negative(): void {
		$dms    = array( '33/1', '52/1', '0/1' );
		$result = \Rede\rede_gps_to_decimal( $dms, 'S' );
		$this->assertNotNull( $result );
		$this->assertLessThan( 0.0, $result );
	}

	public function test_gps_to_decimal_west_is_negative(): void {
		$dms    = array( '122/1', '25/1', '9/1' );
		$result = \Rede\rede_gps_to_decimal( $dms, 'W' );
		$this->assertNotNull( $result );
		$this->assertLessThan( 0.0, $result );
	}

	public function test_gps_to_decimal_east_is_positive(): void {
		$dms    = array( '2/1', '21/1', '5/1' );
		$result = \Rede\rede_gps_to_decimal( $dms, 'E' );
		$this->assertNotNull( $result );
		$this->assertGreaterThan( 0.0, $result );
	}

	public function test_gps_to_decimal_returns_null_for_short_array(): void {
		$this->assertNull( \Rede\rede_gps_to_decimal( array( '48/1', '51/1' ), 'N' ) );
	}

	public function test_gps_to_decimal_returns_null_for_invalid_rational(): void {
		$this->assertNull( \Rede\rede_gps_to_decimal( array( 'abc', '51/1', '30/1' ), 'N' ) );
	}

	public function test_gps_to_decimal_result_is_rounded_to_six_decimal_places(): void {
		$dms    = array( '48/1', '51/1', '29/1' );
		$result = \Rede\rede_gps_to_decimal( $dms, 'N' );
		$this->assertNotNull( $result );
		// round() to 6 places means at most 6 decimal digits.
		$this->assertSame( $result, round( $result, 6 ) );
	}

	// -------------------------------------------------------------------------
	// rede_process_exif_value()
	// -------------------------------------------------------------------------

	public function test_process_exif_value_passes_integer_through(): void {
		$this->assertSame( 42, \Rede\rede_process_exif_value( 42 ) );
	}

	public function test_process_exif_value_converts_rational_string_to_float(): void {
		$this->assertSame( 50.0, \Rede\rede_process_exif_value( '50/1' ) );
	}

	public function test_process_exif_value_converts_exif_datetime_to_iso8601(): void {
		$result = \Rede\rede_process_exif_value( '2024:01:15 14:30:22' );
		$this->assertSame( '2024-01-15T14:30:22', $result );
	}

	public function test_process_exif_value_discards_control_characters(): void {
		$this->assertNull( \Rede\rede_process_exif_value( "valid\x00null" ) );
	}

	public function test_process_exif_value_discards_empty_string_after_trim(): void {
		$this->assertNull( \Rede\rede_process_exif_value( '   ' ) );
	}

	public function test_process_exif_value_trims_plain_strings(): void {
		$this->assertSame( 'Canon', \Rede\rede_process_exif_value( '  Canon  ' ) );
	}

	public function test_process_exif_value_recurses_into_arrays(): void {
		$result = \Rede\rede_process_exif_value( array( '50/1', '2024:01:15 14:30:22', 7 ) );
		$this->assertSame( array( 50.0, '2024-01-15T14:30:22', 7 ), $result );
	}

	public function test_process_exif_value_returns_plain_string_unchanged(): void {
		$this->assertSame( 'Canon EOS 5D', \Rede\rede_process_exif_value( 'Canon EOS 5D' ) );
	}

	// -------------------------------------------------------------------------
	// rede_process_exif()
	// -------------------------------------------------------------------------

	public function test_process_exif_transforms_simple_fields(): void {
		$raw = array(
			'Make'  => 'Canon',
			'Model' => 'EOS 5D Mark IV',
		);

		$result = \Rede\rede_process_exif( $raw );
		$this->assertSame( 'Canon', $result['Make'] );
		$this->assertSame( 'EOS 5D Mark IV', $result['Model'] );
	}

	public function test_process_exif_removes_null_values(): void {
		$raw    = array(
			'Make'    => 'Canon',
			'Comment' => "\x01\x02\x03", // control chars → null → filtered out
		);
		$result = \Rede\rede_process_exif( $raw );

		$this->assertArrayHasKey( 'Make', $result );
		$this->assertArrayNotHasKey( 'Comment', $result );
	}

	public function test_process_exif_adds_captured_at_from_datetime_original(): void {
		$raw    = array(
			'DateTimeOriginal' => '2024:01:15 14:30:22',
		);
		$result = \Rede\rede_process_exif( $raw );

		$this->assertSame( '2024-01-15T14:30:22', $result['CapturedAt'] );
	}

	public function test_process_exif_prefers_datetime_original_over_datetime(): void {
		$raw    = array(
			'DateTimeOriginal' => '2024:01:15 14:30:22',
			'DateTime'         => '2024:01:16 10:00:00',
		);
		$result = \Rede\rede_process_exif( $raw );

		$this->assertSame( '2024-01-15T14:30:22', $result['CapturedAt'] );
	}

	public function test_process_exif_falls_back_to_datetime_digitized(): void {
		$raw    = array(
			'DateTimeDigitized' => '2024:03:20 09:00:00',
		);
		$result = \Rede\rede_process_exif( $raw );

		$this->assertSame( '2024-03-20T09:00:00', $result['CapturedAt'] );
	}

	public function test_process_exif_falls_back_to_datetime(): void {
		$raw    = array(
			'DateTime' => '2024:06:01 08:00:00',
		);
		$result = \Rede\rede_process_exif( $raw );

		$this->assertSame( '2024-06-01T08:00:00', $result['CapturedAt'] );
	}

	public function test_process_exif_adds_decimal_gps_coordinates(): void {
		$raw = array(
			'GPSLatitude'     => array( '48/1', '51/1', '30/1' ),
			'GPSLatitudeRef'  => 'N',
			'GPSLongitude'    => array( '2/1', '21/1', '5/1' ),
			'GPSLongitudeRef' => 'E',
		);

		$result = \Rede\rede_process_exif( $raw );

		$this->assertArrayHasKey( 'GPSDecimalLatitude', $result );
		$this->assertArrayHasKey( 'GPSDecimalLongitude', $result );
		$this->assertGreaterThan( 0.0, $result['GPSDecimalLatitude'] );
		$this->assertGreaterThan( 0.0, $result['GPSDecimalLongitude'] );
	}

	public function test_process_exif_adds_decimal_altitude_above_sea_level(): void {
		$raw    = array(
			'GPSAltitude'    => '100/1',
			'GPSAltitudeRef' => 0,
		);
		$result = \Rede\rede_process_exif( $raw );

		$this->assertArrayHasKey( 'GPSDecimalAltitude', $result );
		$this->assertSame( 100.0, $result['GPSDecimalAltitude'] );
	}

	public function test_process_exif_negates_altitude_below_sea_level(): void {
		$raw    = array(
			'GPSAltitude'    => '50/1',
			'GPSAltitudeRef' => 1,
		);
		$result = \Rede\rede_process_exif( $raw );

		$this->assertArrayHasKey( 'GPSDecimalAltitude', $result );
		$this->assertSame( -50.0, $result['GPSDecimalAltitude'] );
	}

	public function test_process_exif_skips_decimal_gps_when_coords_missing(): void {
		$raw    = array( 'Make' => 'Canon' );
		$result = \Rede\rede_process_exif( $raw );

		$this->assertArrayNotHasKey( 'GPSDecimalLatitude', $result );
		$this->assertArrayNotHasKey( 'GPSDecimalLongitude', $result );
	}
}
