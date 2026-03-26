<?php
/**
 * OpenWebUI image generation model implementation.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

declare( strict_types=1 );

namespace OBenWeb\AiProviderForOpenWebUI\Models;

use OBenWeb\AiProviderForOpenWebUI\Provider\OpenWebUIProvider;
use OBenWeb\AiProviderForOpenWebUI\Settings\OpenWebUISettings;
use OBenWeb\AiProviderForOpenWebUI\Util\OpenWebUIPath;
use OBenWeb\AiProviderForOpenWebUI\Util\OpenWebUIRequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Class for an OpenWebUI image generation model.
 *
 * @since 1.1.0
 */
class OpenWebUIImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum $method The HTTP method.
	 * @param string                                                  $path The API path.
	 * @param array<string, string|list<string>>                      $headers The request headers.
	 * @param string|array<string, mixed>|null                        $data The request payload.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Request The request instance.
	 */
	protected function createRequest(
		HttpMethodEnum $method,
		string $path,
		array $headers = array(),
		$data = null
	): Request {
		$path = OpenWebUIPath::normalize_for_image_generations( $path );

		return new Request(
			$method,
			OpenWebUIProvider::url( $path ),
			$headers,
			$data,
			OpenWebUIRequestOptions::with_defaults( $this->getRequestOptions() )
		);
	}

	/**
	 * Parses Open WebUI image responses and normalizes non-standard shapes.
	 *
	 * Open WebUI image generations currently return a list of image objects with
	 * `url` values, not a strict OpenAI-compatible `data` object.
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response $response The HTTP response.
	 * @param string                                          $expected_mime_type Expected MIME type.
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult Parsed result.
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException If the response cannot be parsed.
	 */
	protected function parseResponseToGenerativeAiResult( Response $response, string $expected_mime_type = 'image/png' ): GenerativeAiResult {
		$response_data = $response->getData();

		if ( ! is_array( $response_data ) ) {
			throw ResponseException::fromMissingData( 'Open WebUI', 'data' );
		}

		$normalized_data = $this->normalize_image_response_data( $response_data );
		if ( null === $normalized_data ) {
			throw ResponseException::fromMissingData( 'Open WebUI', 'data' );
		}

		$normalized_body = wp_json_encode( $normalized_data );
		if ( false === $normalized_body ) {
			throw ResponseException::fromInvalidData( 'Open WebUI', 'data', 'Could not encode normalized image response data.' );
		}

		$normalized_response = new Response(
			$response->getStatusCode(),
			$response->getHeaders(),
			$normalized_body
		);

		return parent::parseResponseToGenerativeAiResult( $normalized_response, $expected_mime_type );
	}

	/**
	 * Normalizes Open WebUI image response data to OpenAI-compatible format.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $response_data Decoded response data.
	 * @return array<string, mixed>|null Normalized data or null.
	 */
	private function normalize_image_response_data( array $response_data ): ?array {
		$image_entries = array();
		if ( isset( $response_data['data'] ) && is_array( $response_data['data'] ) ) {
			$image_entries = $response_data['data'];
		} elseif ( isset( $response_data[0] ) && is_array( $response_data[0] ) ) {
			$image_entries = $response_data;
		}

		if ( empty( $image_entries ) ) {
			return null;
		}

		$normalized_entries = array();
		foreach ( $image_entries as $image_entry ) {
			if ( ! is_array( $image_entry ) ) {
				continue;
			}

			if ( isset( $image_entry['b64_json'] ) && is_string( $image_entry['b64_json'] ) && '' !== trim( $image_entry['b64_json'] ) ) {
				$normalized_entries[] = array(
					'b64_json' => trim( $image_entry['b64_json'] ),
				);
				continue;
			}

			if ( ! isset( $image_entry['url'] ) || ! is_string( $image_entry['url'] ) || '' === trim( $image_entry['url'] ) ) {
				continue;
			}

			$image_url    = $this->to_absolute_url( trim( $image_entry['url'] ) );
			$image_base64 = $this->download_image_as_base64( $image_url );
			if ( '' !== $image_base64 ) {
				$normalized_entries[] = array(
					'b64_json' => $image_base64,
				);
				continue;
			}

			$normalized_entries[] = array(
				'url' => $image_url,
			);
		}

		if ( empty( $normalized_entries ) ) {
			return null;
		}

		$id = isset( $response_data['id'] ) && is_string( $response_data['id'] )
			? $response_data['id']
			: '';

		return array(
			'id'    => $id,
			'data'  => $normalized_entries,
			'usage' => array(
				'input_tokens'  => 0,
				'output_tokens' => 0,
				'total_tokens'  => 0,
			),
		);
	}

	/**
	 * Converts relative URLs from Open WebUI to absolute URLs.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url URL from the API response.
	 * @return string Absolute URL.
	 */
	private function to_absolute_url( string $url ): string {
		if ( false !== filter_var( $url, \FILTER_VALIDATE_URL ) ) {
			return $url;
		}

		$host = getenv( 'OPENWEBUI_BASE_URL' );
		if ( ( false === $host || '' === $host ) && class_exists( OpenWebUISettings::class ) ) {
			$settings = OpenWebUISettings::get_settings();
			if ( isset( $settings['host'] ) && is_string( $settings['host'] ) && '' !== trim( $settings['host'] ) ) {
				$host = trim( $settings['host'] );
			}
		}

		if ( false === $host || '' === $host ) {
			$host = 'http://localhost:3000';
		}
		$host = rtrim( $host, '/' );

		if ( '/' === substr( $url, 0, 1 ) ) {
			return $host . $url;
		}

		return $host . '/' . $url;
	}

	/**
	 * Downloads a remote image and returns raw base64 data.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url Absolute image URL.
	 * @return string Base64 image data or empty string on failure.
	 */
	private function download_image_as_base64( string $url ): string {
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return '';
		}

		$request_args = array(
			'timeout' => 60,
		);

		$api_key = $this->get_openwebui_api_key();
		if ( '' !== $api_key ) {
			$request_args['headers'] = array(
				'Authorization' => 'Bearer ' . $api_key,
			);
		}

		$response = wp_remote_get( $url, $request_args );
		if ( is_wp_error( $response ) ) {
			return '';
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Image response bytes must be converted to base64 for the AI client File DTO.
		return base64_encode( $body );
	}

	/**
	 * Gets the Open WebUI API key from environment or settings.
	 *
	 * @since 1.1.0
	 *
	 * @return string API key or empty string.
	 */
	private function get_openwebui_api_key(): string {
		$api_key = getenv( 'OPENWEBUI_API_KEY' );
		if ( false !== $api_key && '' !== $api_key ) {
			return trim( $api_key );
		}

		if ( class_exists( OpenWebUISettings::class ) ) {
			$settings = OpenWebUISettings::get_settings();
			if ( isset( $settings['api_key'] ) && is_string( $settings['api_key'] ) && '' !== trim( $settings['api_key'] ) ) {
				return trim( $settings['api_key'] );
			}
		}

		if ( ! function_exists( 'get_option' ) ) {
			return '';
		}

		$connector_api_key = get_option( 'connectors_ai_openwebui_api_key', '' );
		if ( ! is_string( $connector_api_key ) ) {
			return '';
		}

		return trim( $connector_api_key );
	}
}
