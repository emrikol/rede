<?php
declare(strict_types=1);

namespace {
	require_once __DIR__ . '/../vendor/autoload.php';

	// ---------------------------------------------------------------------------
	// In-memory state — reset before every test via rede_reset_state().
	// ---------------------------------------------------------------------------
	$GLOBALS['rede_transients']            = array();
	$GLOBALS['rede_registered_routes']     = array();
	$GLOBALS['rede_actions']               = array();
	$GLOBALS['rede_options']               = array();
	$GLOBALS['rede_user_logged_in']        = false;
	$GLOBALS['rede_current_time']          = null;   // null = use real time().
	$GLOBALS['rede_remote_response']       = array( // Default: successful 200 response.
		'response' => array( 'code' => 200 ),
		'body'     => 'fake-image-data',
	);
	$GLOBALS['rede_exif_imagetype']        = true;  // true = valid image, false = invalid.
	$GLOBALS['rede_exif_data']             = array(); // array = return this, false = not found.
	$GLOBALS['rede_exif_callable']         = true;
	$GLOBALS['rede_exif_triggers_warning']   = false; // true = emit E_USER_WARNING to exercise error-handler path.
	$GLOBALS['rede_file_put_contents_fails'] = false; // true = simulate file_put_contents() returning false.

	/**
	 * Reset the in-memory WordPress stubs to a clean baseline before each test.
	 *
	 * Every test's setUp() should call this so tests are fully independent.
	 */
	function rede_reset_state(): void {
		$GLOBALS['rede_transients']            = array();
		$GLOBALS['rede_registered_routes']     = array();
		$GLOBALS['rede_actions']               = array();
		$GLOBALS['rede_options']               = array();
		$GLOBALS['rede_user_logged_in']        = false;
		$GLOBALS['rede_current_time']          = null;
		$GLOBALS['rede_remote_response']       = array(
			'response' => array( 'code' => 200 ),
			'body'     => 'fake-image-data',
		);
		$GLOBALS['rede_exif_imagetype']        = true;
		$GLOBALS['rede_exif_data']             = array();
		$GLOBALS['rede_exif_callable']         = true;
		$GLOBALS['rede_exif_triggers_warning']   = false;
		$GLOBALS['rede_file_put_contents_fails'] = false;
	}

	// ---------------------------------------------------------------------------
	// WordPress stub classes.
	// ---------------------------------------------------------------------------

	class WP_REST_Server {
		public const READABLE = 'GET';
	}

	class WP_REST_Request implements ArrayAccess {
		/**
		 * @param array<string, mixed>  $params
		 * @param array<string, string> $headers
		 */
		public function __construct(
			private array $params = array(),
			private array $headers = array()
		) {}

		public function get_header( string $name ): ?string {
			return $this->headers[ strtolower( $name ) ] ?? null;
		}

		public function offsetExists( mixed $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		public function offsetGet( mixed $offset ): mixed {
			return $this->params[ $offset ] ?? false;
		}

		public function offsetSet( mixed $offset, mixed $value ): void {
			$this->params[ $offset ] = $value;
		}

		public function offsetUnset( mixed $offset ): void {
			unset( $this->params[ $offset ] );
		}
	}

	class WP_REST_Response {
		public function __construct( private readonly mixed $data = null ) {}

		public function get_data(): mixed {
			return $this->data;
		}
	}

	class WP_Error {
		public function __construct(
			private readonly string $code = '',
			private readonly string $message = '',
			private readonly mixed $data = ''
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}

	// ---------------------------------------------------------------------------
	// WordPress stub functions — routing / hooks.
	// ---------------------------------------------------------------------------

	function register_rest_route( string $namespace, string $route, array $args ): bool {
		$GLOBALS['rede_registered_routes'][] = compact( 'namespace', 'route', 'args' );
		return true;
	}

	function add_action( string $hook, callable|string $callback ): true {
		$GLOBALS['rede_actions'][ $hook ][] = $callback;
		return true;
	}

	// ---------------------------------------------------------------------------
	// WordPress stub functions — options.
	// ---------------------------------------------------------------------------

	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['rede_options'][ $key ] ?? $default;
	}

	function update_option( string $key, mixed $value, bool|string $autoload = true ): bool {
		$GLOBALS['rede_options'][ $key ] = $value;
		return true;
	}

	// ---------------------------------------------------------------------------
	// WordPress stub functions — authentication.
	// ---------------------------------------------------------------------------

	function is_user_logged_in(): bool {
		return (bool) $GLOBALS['rede_user_logged_in'];
	}

	// ---------------------------------------------------------------------------
	// WordPress stub functions — transients.
	// ---------------------------------------------------------------------------

	function get_transient( string $key ): mixed {
		return $GLOBALS['rede_transients'][ $key ] ?? false;
	}

	function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['rede_transients'][ $key ] = $value;
		return true;
	}

	// ---------------------------------------------------------------------------
	// WordPress stub functions — HTTP.
	// ---------------------------------------------------------------------------

	function wp_remote_get( string $url, array $args = array() ): array|\WP_Error {
		return $GLOBALS['rede_remote_response'];
	}

	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof \WP_Error;
	}

	function wp_remote_retrieve_response_code( array|\WP_Error $response ): int|string {
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return (int) ( $response['response']['code'] ?? 0 );
	}

	function wp_remote_retrieve_body( array|\WP_Error $response ): string {
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return (string) ( $response['body'] ?? '' );
	}

	// ---------------------------------------------------------------------------
	// WordPress stub functions — temp files.
	// ---------------------------------------------------------------------------

	function wp_tempnam( string $filename = '', string $dir = '' ): string {
		return (string) tempnam( sys_get_temp_dir(), 'rede_test_' );
	}

	function wp_delete_file( string $file ): void {
		if ( file_exists( $file ) ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	// ---------------------------------------------------------------------------
	// WordPress stub functions — REST / escaping.
	// ---------------------------------------------------------------------------

	function esc_url_raw( string $url, array $protocols = array() ): string {
		return $url;
	}

	function rest_ensure_response( mixed $data ): WP_REST_Response {
		return new WP_REST_Response( $data );
	}
}

// ---------------------------------------------------------------------------
// Rede-namespace overrides — shadow PHP built-ins / extension functions so
// plugin code running in namespace Rede uses these stubs at test time.
// ---------------------------------------------------------------------------

namespace Rede {
	/**
	 * Shadows PHP's time() for plugin code in namespace Rede.
	 *
	 * Returns $GLOBALS['rede_current_time'] when set, so tests can pin the
	 * clock to a fixed timestamp for deterministic TOTP validation.
	 */
	function time(): int {
		return $GLOBALS['rede_current_time'] ?? \time();
	}

	/**
	 * Shadows PHP's is_callable() for plugin code in namespace Rede.
	 *
	 * Returns $GLOBALS['rede_exif_callable'] when checking 'exif_read_data'.
	 */
	function is_callable( mixed $value, bool $syntax_only = false ): bool {
		if ( is_string( $value ) && 'exif_read_data' === $value ) {
			return (bool) $GLOBALS['rede_exif_callable'];
		}
		return \is_callable( $value, $syntax_only );
	}

	/**
	 * Shadows file_put_contents() for plugin code in namespace Rede.
	 *
	 * Returns false when $GLOBALS['rede_file_put_contents_fails'] is true,
	 * otherwise delegates to the real file_put_contents().
	 */
	function file_put_contents( string $filename, mixed $data, int $flags = 0, mixed $context = null ): int|false {
		if ( true === ( $GLOBALS['rede_file_put_contents_fails'] ?? false ) ) {
			return false;
		}
		return \file_put_contents( $filename, $data, $flags );
	}

	/**
	 * Shadows exif_imagetype() for plugin code in namespace Rede.
	 *
	 * Returns a truthy int (JPEG type) when $GLOBALS['rede_exif_imagetype'] is true,
	 * false otherwise.
	 */
	function exif_imagetype( string $filename ): int|false {
		return $GLOBALS['rede_exif_imagetype'] ? 2 /* IMAGETYPE_JPEG */ : false;
	}

	/**
	 * Shadows exif_read_data() for plugin code in namespace Rede.
	 *
	 * Returns $GLOBALS['rede_exif_data']: false simulates a bad/missing image;
	 * an array simulates successfully parsed EXIF data.
	 */
	function exif_read_data( string $filename, string $required_sections = '', bool $as_arrays = false, bool $read_thumbnail = false ): array|false {
		if ( true === $GLOBALS['rede_exif_triggers_warning'] ) {
			trigger_error( 'Malformed EXIF data', E_USER_WARNING );
		}

		$data = $GLOBALS['rede_exif_data'];
		if ( false === $data ) {
			return false;
		}
		return is_array( $data ) ? $data : array();
	}
}

// ---------------------------------------------------------------------------
// Load plugin code under test.
// ---------------------------------------------------------------------------

namespace {
	require_once __DIR__ . '/../rede.php';
}
