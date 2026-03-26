<?php
/**
 * OpenWebUI multimodal model implementation.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

declare( strict_types=1 );

namespace OBenWeb\AiProviderForOpenWebUI\Models;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Class for a model that supports both text and image generation.
 *
 * @since 1.1.0
 */
class OpenWebUIMultimodalModel extends AbstractApiBasedModel implements TextGenerationModelInterface, ImageGenerationModelInterface {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 *
	 * @param array $prompt The prompt messages.
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult The text generation result.
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		$delegate = new OpenWebUITextGenerationModel( $this->metadata(), $this->providerMetadata() );
		$this->configure_delegate( $delegate );

		return $delegate->generateTextResult( $prompt );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 *
	 * @param array $prompt The prompt messages.
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult The image generation result.
	 */
	public function generateImageResult( array $prompt ): GenerativeAiResult {
		$delegate = new OpenWebUIImageGenerationModel( $this->metadata(), $this->providerMetadata() );
		$this->configure_delegate( $delegate );

		return $delegate->generateImageResult( $prompt );
	}

	/**
	 * Copies runtime dependencies/configuration to a delegated model instance.
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel $delegate Delegated model instance.
	 */
	private function configure_delegate( AbstractApiBasedModel $delegate ): void {
		$delegate->setConfig( $this->getConfig() );

		$request_options = $this->getRequestOptions();
		if ( null !== $request_options ) {
			$delegate->setRequestOptions( $request_options );
		}

		$delegate->setHttpTransporter( $this->getHttpTransporter() );
		$delegate->setRequestAuthentication( $this->getRequestAuthentication() );
	}
}
