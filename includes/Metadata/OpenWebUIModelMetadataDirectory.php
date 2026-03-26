<?php
/**
 * OpenWebUI model metadata directory implementation.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

declare( strict_types=1 );

namespace OBenWeb\AiProviderForOpenWebUI\Metadata;

use OBenWeb\AiProviderForOpenWebUI\Provider\OpenWebUIProvider;
use OBenWeb\AiProviderForOpenWebUI\Util\OpenWebUIRequestOptions;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Class for the OpenWebUI model metadata directory.
 *
 * @since 1.0.0
 *
 * @phpstan-type ModelEntry array{
 *     id?: string,
 *     name?: string,
 *     capabilities?: array<mixed>,
 *     info?: array{
 *         meta?: array{
 *             capabilities?: array<mixed>
 *         }
 *     }
 * }
 * @phpstan-type ModelsResponseData array{
 *     data?: list<ModelEntry>
 * }
 */
class OpenWebUIModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, \WordPress\AiClient\Providers\Models\DTO\ModelMetadata> List of models keyed by model id.
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException If the response data is missing.
	 */
	protected function sendListModelsRequest(): array {
		$request  = $this->createRequest( HttpMethodEnum::GET(), 'api/models' );
		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = $this->getHttpTransporter()->send( $request );

		ResponseUtil::throwIfNotSuccessful( $response );

		$response_data = $response->getData();

		$model_entries    = array();
		$found_data_field = false;
		if ( isset( $response_data['data'] ) && is_array( $response_data['data'] ) ) {
			$found_data_field = true;
			$model_entries    = $response_data['data'];
		} elseif ( isset( $response_data[0] ) && is_array( $response_data[0] ) ) {
			$model_entries = $response_data;
		}

		if ( ! $found_data_field && empty( $model_entries ) ) {
			throw ResponseException::fromMissingData( 'OpenWebUI', 'data' );
		}

		$models_map = array();
		foreach ( $model_entries as $model_entry ) {
			if ( ! is_array( $model_entry ) || ! isset( $model_entry['id'] ) || '' === (string) $model_entry['id'] ) {
				continue;
			}

			$model_id   = (string) $model_entry['id'];
			$model_name = isset( $model_entry['name'] ) && '' !== (string) $model_entry['name']
				? (string) $model_entry['name']
				: $model_id;

				$supports_vision           = $this->supports_vision( $model_entry );
				$supports_image_generation = $this->supports_image_generation( $model_entry );

			$capabilities = array(
				CapabilityEnum::textGeneration(),
				CapabilityEnum::chatHistory(),
			);
			if ( $supports_image_generation ) {
				$capabilities[] = CapabilityEnum::imageGeneration();
			}

			$models_map[ $model_id ] = new ModelMetadata(
				$model_id,
				$model_name,
				$capabilities,
				$this->build_supported_options( $supports_vision, $supports_image_generation )
			);
		}

		ksort( $models_map );

		return $models_map;
	}

	/**
	 * Builds supported options for a model.
	 *
	 * @since 1.1.0
	 *
	 * @param bool $supports_vision Whether the model supports image input for text generation.
	 * @param bool $supports_image_generation Whether the model supports image generation.
	 * @return array<int, \WordPress\AiClient\Providers\Models\DTO\SupportedOption> Supported options.
	 */
	private function build_supported_options( bool $supports_vision, bool $supports_image_generation ): array {
		$input_modalities = array( array( ModalityEnum::text() ) );
		if ( $supports_vision ) {
			$input_modalities[] = array( ModalityEnum::text(), ModalityEnum::image() );
		}

		$output_modalities = array( array( ModalityEnum::text() ) );
		if ( $supports_image_generation ) {
			$output_modalities[] = array( ModalityEnum::image() );
		}

		$output_mime_types = array( 'text/plain', 'application/json' );
		if ( $supports_image_generation ) {
			$output_mime_types = array_merge(
				$output_mime_types,
				array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif' )
			);
		}

		$options = array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::frequencyPenalty() ),
			new SupportedOption( OptionEnum::presencePenalty() ),
			new SupportedOption( OptionEnum::outputMimeType(), $output_mime_types ),
			new SupportedOption( OptionEnum::outputSchema() ),
			new SupportedOption( OptionEnum::functionDeclarations() ),
			new SupportedOption( OptionEnum::customOptions() ),
			new SupportedOption( OptionEnum::outputModalities(), $output_modalities ),
			new SupportedOption( OptionEnum::inputModalities(), $input_modalities ),
		);

		if ( $supports_image_generation ) {
			$options[] = new SupportedOption( OptionEnum::outputFileType() );
			$options[] = new SupportedOption( OptionEnum::outputMediaOrientation() );
			$options[] = new SupportedOption( OptionEnum::outputMediaAspectRatio() );
		}

		return $options;
	}

	/**
	 * Determines whether a model supports vision input.
	 *
	 * @since 1.1.0
	 *
	 * @param array $model_entry Model entry data.
	 * @return bool True if vision is supported.
	 */
	private function supports_vision( array $model_entry ): bool {
		$capabilities = $this->get_capabilities_map( $model_entry );
		$supported    = $this->capability_is_enabled( $capabilities, 'vision' );

		if ( function_exists( 'apply_filters' ) ) {
			$supported = (bool) apply_filters(
				'ai_provider_for_openwebui_model_supports_vision',
				$supported,
				$model_entry,
				$capabilities
			);
		}

		return $supported;
	}

	/**
	 * Determines whether a model supports image generation.
	 *
	 * @since 1.1.0
	 *
	 * @param array $model_entry Model entry data.
	 * @return bool True if image generation is supported.
	 */
	private function supports_image_generation( array $model_entry ): bool {
		$capabilities = $this->get_capabilities_map( $model_entry );
		$supported    = $this->capability_is_enabled( $capabilities, 'image_generation' )
			|| $this->capability_is_enabled( $capabilities, 'image' );
		$model_id     = isset( $model_entry['id'] ) && is_string( $model_entry['id'] )
			? (string) $model_entry['id']
			: '';

		if ( function_exists( 'apply_filters' ) ) {
			$supported = (bool) apply_filters(
				'ai_provider_for_openwebui_model_supports_image_generation',
				$supported,
				$model_id,
				$model_entry,
				$capabilities
			);
		}

		return $supported;
	}

	/**
	 * Returns capabilities from an Open WebUI model entry.
	 *
	 * @since 1.1.0
	 *
	 * @param array $model_entry Model entry.
	 * @return array<mixed> Capability data map/list.
	 */
	private function get_capabilities_map( array $model_entry ): array {
		if ( isset( $model_entry['info'] ) && is_array( $model_entry['info'] ) ) {
			$info = $model_entry['info'];
			if ( isset( $info['meta'] ) && is_array( $info['meta'] ) ) {
				$meta = $info['meta'];
				if ( isset( $meta['capabilities'] ) && is_array( $meta['capabilities'] ) ) {
					return $meta['capabilities'];
				}
			}
		}

		if ( isset( $model_entry['capabilities'] ) && is_array( $model_entry['capabilities'] ) ) {
			return $model_entry['capabilities'];
		}

		return array();
	}

	/**
	 * Checks whether a capability flag is enabled in a capabilities payload.
	 *
	 * @since 1.1.0
	 *
	 * @param array<mixed> $capabilities Capabilities payload.
	 * @param string       $capability_key Capability key.
	 * @return bool True if enabled.
	 */
	private function capability_is_enabled( array $capabilities, string $capability_key ): bool {
		if ( isset( $capabilities[ $capability_key ] ) ) {
			return $this->value_is_truthy( $capabilities[ $capability_key ] );
		}

		foreach ( $capabilities as $key => $value ) {
			if ( is_int( $key ) && is_string( $value ) && $capability_key === $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether a value should be considered truthy.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $value Input value.
	 * @return bool True if truthy.
	 */
	private function value_is_truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value > 0;
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			return in_array( $value, array( '1', 'true', 'yes', 'on', 'enabled' ), true );
		}

		return false;
	}

	/**
	 * Creates a request object for the OpenWebUI API.
	 *
	 * @since 1.0.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum $method The HTTP method.
	 * @param string                                                  $path The API endpoint path, relative to the base URI.
	 * @param array<string, string|list<string>>                      $headers The request headers.
	 * @param string|array<string, mixed>|null                        $data The request data.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Request The request object.
	 */
	private function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request(
			$method,
			OpenWebUIProvider::url( $path ),
			$headers,
			$data,
			OpenWebUIRequestOptions::with_defaults()
		);
	}
}
