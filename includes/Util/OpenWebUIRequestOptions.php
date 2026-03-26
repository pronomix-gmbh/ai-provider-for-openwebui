<?php
/**
 * Open WebUI request options utility.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

declare( strict_types=1 );

namespace OBenWeb\AiProviderForOpenWebUI\Util;

use WordPress\AiClient\Providers\Http\DTO\RequestOptions;

/**
 * Utility methods for Open WebUI request options.
 *
 * @since 1.0.0
 */
class OpenWebUIRequestOptions {

	/**
	 * Environment variable for overriding request timeout.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const TIMEOUT_ENV = 'OPENWEBUI_REQUEST_TIMEOUT';

	/**
	 * Default request timeout in seconds.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const DEFAULT_TIMEOUT = 120.0;

	/**
	 * Default connect timeout in seconds.
	 *
	 * @since 1.0.0
	 * @var float
	 */
	private const DEFAULT_CONNECT_TIMEOUT = 10.0;

	/**
	 * Returns request options with sensible defaults applied.
	 *
	 * @since 1.0.0
	 *
	 * @param RequestOptions|null $request_options Existing request options.
	 * @return RequestOptions Request options with defaults.
	 */
	public static function with_defaults( ?RequestOptions $request_options = null ): RequestOptions {
		$options = $request_options instanceof RequestOptions
			? $request_options
			: new RequestOptions();

		if ( null === $options->getConnectTimeout() ) {
			$options->setConnectTimeout( self::DEFAULT_CONNECT_TIMEOUT );
		}

		if ( null === $options->getTimeout() ) {
			$options->setTimeout( self::get_timeout_from_env_or_default() );
		}

		return $options;
	}

	/**
	 * Gets timeout from environment variable or the default.
	 *
	 * @since 1.0.0
	 *
	 * @return float Timeout in seconds.
	 */
	private static function get_timeout_from_env_or_default(): float {
		$timeout = getenv( self::TIMEOUT_ENV );
		if ( false === $timeout || '' === $timeout || ! is_numeric( $timeout ) ) {
			return self::DEFAULT_TIMEOUT;
		}

		$timeout_value = (float) $timeout;
		if ( $timeout_value <= 0 ) {
			return self::DEFAULT_TIMEOUT;
		}

		return $timeout_value;
	}
}
