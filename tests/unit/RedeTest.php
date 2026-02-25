<?php
declare(strict_types=1);

namespace Rede\Tests;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

#[CoversFunction( 'Rede\rede_sanitize_url' )]
#[CoversFunction( 'Rede\rede_register_route' )]
#[CoversFunction( 'Rede\rede_fetch_image' )]
#[CoversFunction( 'Rede\rede_read_data' )]
class RedeTest extends TestCase {

	protected function setUp(): void {
		rede_reset_state();
	}

	// -------------------------------------------------------------------------
	// rede_sanitize_url()
	// -------------------------------------------------------------------------

	public function test_sanitize_url_returns_clean_url(): void {
		$result = \Rede\rede_sanitize_url( 'https://example.com/image.jpg' );
		$this->assertSame( 'https://example.com/image.jpg', $result );
	}

	public function test_sanitize_url_decodes_percent_encoding(): void {
		$result = \Rede\rede_sanitize_url( 'https%3A%2F%2Fexample.com%2Fimage.jpg' );
		$this->assertSame( 'https://example.com/image.jpg', $result );
	}

	// -------------------------------------------------------------------------
	// rede_register_route()
	// -------------------------------------------------------------------------

	public function test_register_route_stores_correct_namespace_and_path(): void {
		\Rede\rede_register_route();

		$this->assertCount( 1, $GLOBALS['rede_registered_routes'] );
		$route = $GLOBALS['rede_registered_routes'][0];
		$this->assertSame( 'exif-data/v1', $route['namespace'] );
		$this->assertSame( '/read/', $route['route'] );
	}

	public function test_register_route_uses_readable_method(): void {
		\Rede\rede_register_route();

		$args = $GLOBALS['rede_registered_routes'][0]['args'];
		$this->assertSame( WP_REST_Server::READABLE, $args['methods'] );
	}

	public function test_register_route_has_permission_callback(): void {
		\Rede\rede_register_route();

		$args = $GLOBALS['rede_registered_routes'][0]['args'];
		$this->assertArrayHasKey( 'permission_callback', $args );
		$this->assertNotEmpty( $args['permission_callback'] );
	}

	// -------------------------------------------------------------------------
	// rede_fetch_image() — error paths
	// -------------------------------------------------------------------------

	public function test_fetch_image_returns_wp_error_on_network_failure(): void {
		$GLOBALS['rede_remote_response'] = new WP_Error( 'http_request_failed', 'Connection refused.' );

		$result = \Rede\rede_fetch_image( 'https://example.com/photo.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	public function test_fetch_image_returns_wp_error_on_non_200_status(): void {
		$GLOBALS['rede_remote_response'] = array(
			'response' => array( 'code' => 404 ),
			'body'     => '',
		);

		$result = \Rede\rede_fetch_image( 'https://example.com/photo.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rede_fetch_failed', $result->get_error_code() );
	}

	public function test_fetch_image_returns_wp_error_on_empty_body(): void {
		$GLOBALS['rede_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '',
		);

		$result = \Rede\rede_fetch_image( 'https://example.com/photo.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rede_empty_response', $result->get_error_code() );
	}

	public function test_fetch_image_returns_wp_error_when_temp_write_fails(): void {
		$GLOBALS['rede_file_put_contents_fails'] = true;

		$result = \Rede\rede_fetch_image( 'https://example.com/photo.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rede_temp_write_failed', $result->get_error_code() );
	}

	public function test_fetch_image_returns_wp_error_when_not_an_image(): void {
		$GLOBALS['rede_exif_imagetype'] = false;

		$result = \Rede\rede_fetch_image( 'https://example.com/not-an-image.txt' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rede_not_image', $result->get_error_code() );
	}

	public function test_fetch_image_returns_temp_file_path_on_success(): void {
		$result = \Rede\rede_fetch_image( 'https://example.com/photo.jpg' );

		$this->assertIsString( $result );
		$this->assertFileExists( $result );

		// Clean up the real temp file created during the test.
		if ( is_string( $result ) && file_exists( $result ) ) {
			unlink( $result );
		}
	}

	// -------------------------------------------------------------------------
	// rede_read_data() — cache hit
	// -------------------------------------------------------------------------

	public function test_read_data_returns_cached_exif_without_fetching(): void {
		$cached    = array( 'Make' => 'Apple', 'Model' => 'iPhone 15' );
		$cache_key = md5( 'exif-json:https://example.com/photo.jpg' );

		$GLOBALS['rede_transients'][ $cache_key ] = $cached;
		$GLOBALS['rede_exif_callable']            = false; // Ensure exif is not reached.

		$request  = new WP_REST_Request( array( 'url' => 'https://example.com/photo.jpg' ) );
		$response = \Rede\rede_read_data( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( $cached, $data['data'] );
	}

	// -------------------------------------------------------------------------
	// rede_read_data() — exif extension unavailable
	// -------------------------------------------------------------------------

	public function test_read_data_returns_error_when_exif_not_callable(): void {
		$GLOBALS['rede_exif_callable'] = false;

		$request  = new WP_REST_Request( array( 'url' => 'https://example.com/photo.jpg' ) );
		$response = \Rede\rede_read_data( $request );
		$data     = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertSame( 'exif_read_data function not found!', $data['data'] );
	}

	// -------------------------------------------------------------------------
	// rede_read_data() — fetch failures
	// -------------------------------------------------------------------------

	public function test_read_data_returns_error_when_fetch_fails(): void {
		$GLOBALS['rede_remote_response'] = new WP_Error( 'http_request_failed', 'Connection refused.' );

		$request  = new WP_REST_Request( array( 'url' => 'https://example.com/photo.jpg' ) );
		$response = \Rede\rede_read_data( $request );
		$data     = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertSame( 'Connection refused.', $data['data'] );
	}

	public function test_read_data_returns_error_when_not_an_image(): void {
		$GLOBALS['rede_exif_imagetype'] = false;

		$request  = new WP_REST_Request( array( 'url' => 'https://example.com/not-an-image.txt' ) );
		$response = \Rede\rede_read_data( $request );
		$data     = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertSame( 'URL does not point to a valid image.', $data['data'] );
	}

	// -------------------------------------------------------------------------
	// rede_read_data() — exif returns false (bad/missing EXIF)
	// -------------------------------------------------------------------------

	public function test_read_data_returns_error_when_exif_not_found(): void {
		$GLOBALS['rede_exif_data'] = false;

		$request  = new WP_REST_Request( array( 'url' => 'https://example.com/no-exif.jpg' ) );
		$response = \Rede\rede_read_data( $request );
		$data     = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertSame( 'EXIF Not Found for image', $data['data'] );
	}

	public function test_read_data_returns_error_when_exif_triggers_warning(): void {
		$GLOBALS['rede_exif_triggers_warning'] = true;

		$request  = new WP_REST_Request( array( 'url' => 'https://example.com/corrupt.jpg' ) );
		$response = \Rede\rede_read_data( $request );
		$data     = $response->get_data();

		$this->assertFalse( $data['success'] );
		$this->assertSame( 'EXIF Not Found for image', $data['data'] );
	}

	// -------------------------------------------------------------------------
	// rede_read_data() — success + caching
	// -------------------------------------------------------------------------

	public function test_read_data_returns_exif_on_success(): void {
		$GLOBALS['rede_exif_data'] = array( 'Make' => 'Canon', 'Model' => 'EOS 5D Mark IV' );

		$request  = new WP_REST_Request( array( 'url' => 'https://example.com/photo.jpg' ) );
		$response = \Rede\rede_read_data( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'Canon', $data['data']['Make'] );
		$this->assertSame( 'EOS 5D Mark IV', $data['data']['Model'] );
	}

	public function test_read_data_caches_processed_exif_after_successful_fetch(): void {
		$GLOBALS['rede_exif_data'] = array( 'Make' => 'Nikon', 'Model' => 'Z8' );

		$url       = 'https://example.com/photo.jpg';
		$cache_key = md5( 'exif-json:' . $url );

		$request = new WP_REST_Request( array( 'url' => $url ) );
		\Rede\rede_read_data( $request );

		$this->assertArrayHasKey( $cache_key, $GLOBALS['rede_transients'] );
		$this->assertSame( 'Nikon', $GLOBALS['rede_transients'][ $cache_key ]['Make'] );
	}
}
