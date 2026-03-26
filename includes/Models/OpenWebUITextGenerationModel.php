<?php
/**
 * OpenWebUI text generation model implementation.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

declare( strict_types=1 );

namespace OBenWeb\AiProviderForOpenWebUI\Models;

use OBenWeb\AiProviderForOpenWebUI\Provider\OpenWebUIProvider;
use OBenWeb\AiProviderForOpenWebUI\Util\OpenWebUIPath;
use OBenWeb\AiProviderForOpenWebUI\Util\OpenWebUIRequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Class for an OpenWebUI text generation model via its OpenAI-compatible endpoint.
 *
 * @since 1.0.0
 */
class OpenWebUITextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum $method  The HTTP method.
	 * @param string                                                  $path    The API path.
	 * @param array<string, string|list<string>>                      $headers The request headers.
	 * @param string|array<string, mixed>|null                        $data    The request payload.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Request The request instance.
	 */
	protected function createRequest(
		HttpMethodEnum $method,
		string $path,
		array $headers = array(),
		$data = null
	): Request {
		$path = OpenWebUIPath::normalize_for_chat_completions( $path );

		return new Request(
			$method,
			OpenWebUIProvider::url( $path ),
			$headers,
			$data,
			OpenWebUIRequestOptions::with_defaults( $this->getRequestOptions() )
		);
	}
}
