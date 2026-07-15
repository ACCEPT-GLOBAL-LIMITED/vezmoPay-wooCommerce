<?php
/**
 * WC_Logger wrapper with secret redaction.
 *
 * @package VezmoPay
 */

namespace VezmoPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Debug logging (toggle in gateway settings). Never logs secrets.
 */
class Logger {

	/**
	 * Log source shown in WooCommerce → Status → Logs.
	 */
	const SOURCE = 'vezmopay';

	/**
	 * Whether debug logging is enabled.
	 *
	 * @var bool
	 */
	private $enabled;

	/**
	 * Constructor.
	 *
	 * @param bool $enabled Enable debug logging.
	 */
	public function __construct( $enabled = false ) {
		$this->enabled = (bool) $enabled;
	}

	/**
	 * Write a debug line.
	 *
	 * @param string $message Message (will be redacted).
	 * @param array  $context Optional structured context (will be redacted).
	 */
	public function debug( $message, array $context = array() ) {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * Write an error line. Errors are logged even when debug logging is off.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional structured context.
	 */
	public function error( $message, array $context = array() ) {
		$this->log( 'error', $message, $context, true );
	}

	/**
	 * Internal log writer.
	 *
	 * @param string $level   WC log level.
	 * @param string $message Message.
	 * @param array  $context Structured context.
	 * @param bool   $force   Log even when debug is disabled.
	 */
	private function log( $level, $message, array $context, $force = false ) {
		if ( ! $this->enabled && ! $force ) {
			return;
		}
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		if ( $context ) {
			$message .= ' ' . wp_json_encode( self::redact( $context ) );
		}
		wc_get_logger()->log( $level, self::redact_string( $message ), array( 'source' => self::SOURCE ) );
	}

	/**
	 * Recursively redact sensitive keys from a context array.
	 *
	 * @param array $data Context data.
	 * @return array
	 */
	public static function redact( array $data ) {
		// Compared with separators stripped, so e.g. api_key, api-key and apiKey all match.
		$sensitive = array( 'xapikey', 'xapisecret', 'authorization', 'token', 'accesstoken', 'refreshtoken', 'secret', 'apisecret', 'apikey', 'clienttoken', 'webhooksecret' );
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::redact( $value );
			} elseif ( in_array( self::normalize_key( (string) $key ), $sensitive, true ) ) {
				$data[ $key ] = '[redacted]';
			}
		}
		return $data;
	}

	/**
	 * Normalize a key name for comparison (strip separators).
	 *
	 * @param string $key Key name.
	 * @return string
	 */
	private static function normalize_key( $key ) {
		return strtolower( str_replace( array( '_', '-' ), '', $key ) );
	}

	/**
	 * Redact obvious secret material embedded in free-form strings.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function redact_string( $message ) {
		// vzm_ API keys, whsec_ webhook secrets, and Bearer tokens.
		$message = preg_replace( '/vzm_[A-Za-z0-9_\-]+/', 'vzm_[redacted]', $message );
		$message = preg_replace( '/whsec_[A-Za-z0-9]+/', 'whsec_[redacted]', $message );
		$message = preg_replace( '/Bearer\s+[A-Za-z0-9._\-]+/', 'Bearer [redacted]', $message );
		return $message;
	}
}
