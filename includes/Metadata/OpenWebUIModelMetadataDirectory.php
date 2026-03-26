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
 *     name?: string
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

			$options = array(
				new SupportedOption( OptionEnum::systemInstruction() ),
				new SupportedOption( OptionEnum::candidateCount() ),
				new SupportedOption( OptionEnum::maxTokens() ),
				new SupportedOption( OptionEnum::temperature() ),
				new SupportedOption( OptionEnum::topP() ),
				new SupportedOption( OptionEnum::stopSequences() ),
				new SupportedOption( OptionEnum::frequencyPenalty() ),
				new SupportedOption( OptionEnum::presencePenalty() ),
				new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
				new SupportedOption( OptionEnum::outputSchema() ),
				new SupportedOption( OptionEnum::functionDeclarations() ),
				new SupportedOption( OptionEnum::customOptions() ),
				new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
				new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
			);

			$models_map[ $model_id ] = new ModelMetadata(
				$model_id,
				$model_name,
				array(
					CapabilityEnum::textGeneration(),
					CapabilityEnum::chatHistory(),
				),
				$options
			);
		}

		ksort( $models_map );

		return $models_map;
	}

	/**
	 * Creates a request object for the OpenWebUI API.
	 *
	 * @since 1.0.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum $method  The HTTP method.
	 * @param string                                                  $path    The API endpoint path, relative to the base URI.
	 * @param array<string, string|list<string>>                      $headers The request headers.
	 * @param string|array<string, mixed>|null                        $data    The request data.
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
