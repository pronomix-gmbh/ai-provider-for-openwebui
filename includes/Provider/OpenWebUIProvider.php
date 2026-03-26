<?php
/**
 * OpenWebUI provider implementation.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

declare( strict_types=1 );

namespace OBenWeb\AiProviderForOpenWebUI\Provider;

use OBenWeb\AiProviderForOpenWebUI\Metadata\OpenWebUIModelMetadataDirectory;
use OBenWeb\AiProviderForOpenWebUI\Models\OpenWebUIImageGenerationModel;
use OBenWeb\AiProviderForOpenWebUI\Models\OpenWebUIMultimodalModel;
use OBenWeb\AiProviderForOpenWebUI\Models\OpenWebUITextGenerationModel;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Class for the OpenWebUI provider.
 *
 * @since 1.0.0
 */
class OpenWebUIProvider extends AbstractApiProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function baseUrl(): string {
		$host = getenv( 'OPENWEBUI_BASE_URL' );
		if ( false !== $host && '' !== $host ) {
			return rtrim( $host, '/' );
		}

		return 'http://localhost:3000';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @param \WordPress\AiClient\Providers\Models\DTO\ModelMetadata $model_metadata The model metadata.
	 * @param \WordPress\AiClient\Providers\DTO\ProviderMetadata     $provider_metadata The provider metadata.
	 * @return \WordPress\AiClient\Providers\Models\Contracts\ModelInterface The model instance.
	 * @throws \WordPress\AiClient\Common\Exception\RuntimeException If the model capability is not supported.
	 */
	protected static function createModel(
		ModelMetadata $model_metadata,
		ProviderMetadata $provider_metadata
	): ModelInterface {
		$supports_text_generation  = false;
		$supports_image_generation = false;

		$capabilities = $model_metadata->getSupportedCapabilities();
		foreach ( $capabilities as $capability ) {
			if ( $capability->isTextGeneration() ) {
				$supports_text_generation = true;
			}

			if ( $capability->isImageGeneration() ) {
				$supports_image_generation = true;
			}
		}

		if ( $supports_text_generation && $supports_image_generation ) {
			return new OpenWebUIMultimodalModel( $model_metadata, $provider_metadata );
		}

		if ( $supports_image_generation ) {
			return new OpenWebUIImageGenerationModel( $model_metadata, $provider_metadata );
		}

		if ( $supports_text_generation ) {
			return new OpenWebUITextGenerationModel( $model_metadata, $provider_metadata );
		}

		throw new RuntimeException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
			'Unsupported model capabilities: ' . implode( ', ', $capabilities )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		$provider_meta = array(
			'openwebui',
			'Open WebUI',
			ProviderTypeEnum::cloud(),
			'https://docs.openwebui.com/reference/api-endpoints/',
			RequestAuthenticationMethod::apiKey(),
		);

		if ( version_compare( AiClient::VERSION, '1.2.0', '>=' ) ) {
			if ( function_exists( '__' ) ) {
				$provider_meta[] = __( 'Text generation and image generation through Open WebUI API endpoints.', 'ai-provider-for-open-webui' );
			} else {
				$provider_meta[] = 'Text generation and image generation through Open WebUI API endpoints.';
			}
		}

		if ( version_compare( AiClient::VERSION, '1.3.0', '>=' ) ) {
			$provider_meta[] = __DIR__ . '/logo-openwebui.png';
		}

		return new ProviderMetadata( ...$provider_meta );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new ListModelsApiBasedProviderAvailability(
			static::modelMetadataDirectory()
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new OpenWebUIModelMetadataDirectory();
	}
}
