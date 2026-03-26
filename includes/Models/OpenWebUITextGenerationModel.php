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
use OBenWeb\AiProviderForOpenWebUI\Util\OpenWebUIPromptConstraints;
use OBenWeb\AiProviderForOpenWebUI\Util\OpenWebUIRequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Class for an OpenWebUI text generation model via its OpenAI-compatible endpoint.
 *
 * @since 1.0.0
 */
class OpenWebUITextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {

	/**
	 * Whether the current request is an alt-text task.
	 *
	 * @since 1.1.0
	 *
	 * @var bool
	 */
	private bool $enforce_alt_text_constraints = false;

	/**
	 * Maximum allowed alt-text length for the current request.
	 *
	 * @since 1.1.0
	 *
	 * @var int
	 */
	private int $alt_text_max_length = 0;

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

	/**
	 * Adds format and language constraints to the request prompt.
	 *
	 * Open WebUI deployments may ignore some structured output hints depending on
	 * model/backend. We therefore send the same constraints as system prompt text.
	 *
	 * @since 1.1.0
	 *
	 * @param array $prompt Prompt messages.
	 * @return array<string, mixed> Prepared request params.
	 */
	protected function prepareGenerateTextParams( array $prompt ): array {
		$params = parent::prepareGenerateTextParams( $prompt );

		$this->enforce_alt_text_constraints = false;
		$this->alt_text_max_length          = 0;

		if ( ! isset( $params['messages'] ) || ! is_array( $params['messages'] ) ) {
			return $params;
		}

		$messages = $params['messages'];

		$locale = $this->get_current_locale();
		if ( function_exists( 'apply_filters' ) ) {
			$locale = (string) apply_filters( 'ai_provider_for_openwebui_response_locale', $locale );
		}

		$config      = $this->getConfig();
		$instruction = OpenWebUIPromptConstraints::build_constraints_instruction(
			$locale,
			$config->getOutputMimeType(),
			null !== $config->getOutputSchema()
		);

		if ( $this->is_alt_text_request( $messages ) ) {
			$this->enforce_alt_text_constraints = true;
			$this->alt_text_max_length          = $this->get_alt_text_max_length();

			$alt_text_instruction = OpenWebUIPromptConstraints::build_alt_text_instruction(
				$locale,
				$this->alt_text_max_length
			);

			if ( '' !== trim( $alt_text_instruction ) ) {
				$instruction = '' === trim( $instruction )
					? $alt_text_instruction
					: trim( $instruction . "\n" . $alt_text_instruction );
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$instruction = (string) apply_filters(
				'ai_provider_for_openwebui_prompt_constraints',
				$instruction,
				$locale,
				$config->getOutputMimeType(),
				null !== $config->getOutputSchema(),
				$this->metadata()->getId()
			);
		}

		if ( '' === trim( $instruction ) ) {
			return $params;
		}

		$params['messages'] = $this->prepend_system_instruction(
			$params['messages'],
			$instruction
		);

		return $params;
	}

	/**
	 * Returns the current WordPress locale in BCP-47-like format.
	 *
	 * @since 1.1.0
	 *
	 * @return string Locale, for example "de-DE", or empty string.
	 */
	private function get_current_locale(): string {
		$locale = '';

		if ( function_exists( 'determine_locale' ) ) {
			$locale = (string) determine_locale();
		} elseif ( function_exists( 'get_locale' ) ) {
			$locale = (string) get_locale();
		}

		$locale = str_replace( '_', '-', trim( $locale ) );
		$locale = preg_replace( '/[^A-Za-z0-9-]/', '', $locale );

		if ( ! is_string( $locale ) ) {
			return '';
		}

		return $locale;
	}

	/**
	 * Prepends a system message with additional constraints.
	 *
	 * If the first message is already a system message, the instruction is added
	 * to it instead of creating a second system message.
	 *
	 * @since 1.1.0
	 *
	 * @param list<array<string, mixed>> $messages Prepared request messages.
	 * @param string                     $instruction Instruction to add.
	 * @return list<array<string, mixed>> Updated messages.
	 */
	private function prepend_system_instruction( array $messages, string $instruction ): array {
		if ( empty( $messages ) ) {
			return array(
				array(
					'role'    => 'system',
					'content' => array(
						array(
							'type' => 'text',
							'text' => $instruction,
						),
					),
				),
			);
		}

		if ( isset( $messages[0]['role'] ) && 'system' === (string) $messages[0]['role'] ) {
			if ( isset( $messages[0]['content'] ) && is_array( $messages[0]['content'] ) ) {
				$messages[0]['content'][] = array(
					'type' => 'text',
					'text' => $instruction,
				);

				return array_values( $messages );
			}

			if ( isset( $messages[0]['content'] ) && is_string( $messages[0]['content'] ) ) {
				$messages[0]['content'] = trim( $messages[0]['content'] . "\n" . $instruction );

				return array_values( $messages );
			}
		}

		array_unshift(
			$messages,
			array(
				'role'    => 'system',
				'content' => array(
					array(
						'type' => 'text',
						'text' => $instruction,
					),
				),
			)
		);

		return array_values( $messages );
	}

	/**
	 * Parses OpenWebUI responses and normalizes non-standard successful shapes.
	 *
	 * Open WebUI may return non-OpenAI response shapes in some configurations,
	 * for example an object with `message`/`response` fields instead of `choices`.
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response $response The HTTP response.
	 * @return \WordPress\AiClient\Results\DTO\GenerativeAiResult Parsed result.
	 * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException If the response payload cannot be parsed.
	 */
	protected function parseResponseToGenerativeAiResult( Response $response ): GenerativeAiResult {
		$response_data = $response->getData();

		if ( is_array( $response_data ) ) {
			if (
				isset( $response_data['choices'] )
				&& is_array( $response_data['choices'] )
				&& ! empty( $response_data['choices'] )
			) {
				$prepared_response_data = $this->maybe_apply_alt_text_length_limit( $response_data );
				$prepared_response      = $this->response_from_data( $response, $prepared_response_data );

				return parent::parseResponseToGenerativeAiResult( $prepared_response );
			}

			$error_message = $this->get_error_message_from_response_data( $response_data );
			if ( '' !== $error_message ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
				throw ResponseException::fromInvalidData( 'Open WebUI', 'choices', $error_message );
			}

			$normalized_response_data = $this->normalize_non_standard_response_data( $response_data );
			if ( is_array( $normalized_response_data ) ) {
				$normalized_response_data = $this->maybe_apply_alt_text_length_limit( $normalized_response_data );
				$normalized_response      = $this->response_from_data( $response, $normalized_response_data );

				return parent::parseResponseToGenerativeAiResult( $normalized_response );
			}

			throw ResponseException::fromInvalidData(
				'Open WebUI',
				'choices',
				'Missing "choices" key in successful response.'
			);
		}

		$body = $response->getBody();
		if ( is_string( $body ) && '' !== trim( $body ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output.
			throw ResponseException::fromInvalidData(
				'Open WebUI',
				'choices',
				'Response body is not valid JSON.'
			);
		}

		throw ResponseException::fromMissingData( 'Open WebUI', 'choices' );
	}

	/**
	 * Extracts a provider error message from a decoded response.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $response_data Decoded response data.
	 * @return string Error message or empty string.
	 */
	private function get_error_message_from_response_data( array $response_data ): string {
		if ( isset( $response_data['detail'] ) && is_string( $response_data['detail'] ) ) {
			return trim( $response_data['detail'] );
		}

		if ( isset( $response_data['error'] ) && is_string( $response_data['error'] ) ) {
			return trim( $response_data['error'] );
		}

		if ( isset( $response_data['error'] ) && is_array( $response_data['error'] ) ) {
			$error = $response_data['error'];

			if ( isset( $error['message'] ) && is_string( $error['message'] ) ) {
				return trim( $error['message'] );
			}

			if ( isset( $error['detail'] ) && is_string( $error['detail'] ) ) {
				return trim( $error['detail'] );
			}
		}

		return '';
	}

	/**
	 * Normalizes known non-OpenAI response shapes to OpenAI-compatible format.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $response_data Decoded response data.
	 * @return array<string, mixed>|null Normalized response data or null.
	 */
	private function normalize_non_standard_response_data( array $response_data ): ?array {
		$content = '';
		$role    = 'assistant';

		if ( isset( $response_data['message'] ) && is_array( $response_data['message'] ) ) {
			$message = $response_data['message'];

			if ( isset( $message['content'] ) && is_string( $message['content'] ) ) {
				$content = $message['content'];
			}

			if ( isset( $message['role'] ) && is_string( $message['role'] ) && '' !== trim( $message['role'] ) ) {
				$role = $message['role'];
			}
		}

		if ( '' === $content && isset( $response_data['response'] ) && is_string( $response_data['response'] ) ) {
			$content = $response_data['response'];
		}

		if ( '' === $content ) {
			return null;
		}

		$prompt_tokens     = isset( $response_data['prompt_eval_count'] ) && is_numeric( $response_data['prompt_eval_count'] )
			? (int) $response_data['prompt_eval_count']
			: 0;
		$completion_tokens = isset( $response_data['eval_count'] ) && is_numeric( $response_data['eval_count'] )
			? (int) $response_data['eval_count']
			: 0;
		$total_tokens      = isset( $response_data['total_tokens'] ) && is_numeric( $response_data['total_tokens'] )
			? (int) $response_data['total_tokens']
			: $prompt_tokens + $completion_tokens;

		$finish_reason_raw = '';
		if ( isset( $response_data['done_reason'] ) && is_string( $response_data['done_reason'] ) ) {
			$finish_reason_raw = $response_data['done_reason'];
		} elseif ( isset( $response_data['finish_reason'] ) && is_string( $response_data['finish_reason'] ) ) {
			$finish_reason_raw = $response_data['finish_reason'];
		}

		$id = isset( $response_data['id'] ) && is_string( $response_data['id'] )
			? $response_data['id']
			: '';

		return array(
			'id'      => $id,
			'choices' => array(
				array(
					'message'       => array(
						'role'    => $role,
						'content' => $content,
					),
					'finish_reason' => $this->normalize_finish_reason( $finish_reason_raw ),
				),
			),
			'usage'   => array(
				'prompt_tokens'     => $prompt_tokens,
				'completion_tokens' => $completion_tokens,
				'total_tokens'      => $total_tokens,
			),
		);
	}

	/**
	 * Creates a new response object from decoded response data.
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AiClient\Providers\Http\DTO\Response $response Original response.
	 * @param array<string, mixed>                            $response_data Decoded response data.
	 * @return \WordPress\AiClient\Providers\Http\DTO\Response Response object.
	 */
	private function response_from_data( Response $response, array $response_data ): Response {
		$encoded = wp_json_encode( $response_data );
		if ( false === $encoded ) {
			return $response;
		}

		return new Response(
			$response->getStatusCode(),
			$response->getHeaders(),
			$encoded
		);
	}

	/**
	 * Returns the alt-text length constraint.
	 *
	 * @since 1.1.0
	 *
	 * @return int Maximum length in characters.
	 */
	private function get_alt_text_max_length(): int {
		$max_length = 125;

		if ( function_exists( 'apply_filters' ) ) {
			$max_length = (int) apply_filters(
				'ai_provider_for_openwebui_alt_text_max_length',
				$max_length,
				$this->metadata()->getId()
			);
		}

		if ( $max_length < 1 ) {
			return 125;
		}

		return $max_length;
	}

	/**
	 * Detects whether the request is for alt-text generation.
	 *
	 * @since 1.1.0
	 *
	 * @param array<int, mixed> $messages Prepared request messages.
	 * @return bool True if this appears to be an alt-text request.
	 */
	private function is_alt_text_request( array $messages ): bool {
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$text = $this->extract_message_text( $message );
			if ( '' === $text ) {
				continue;
			}

			$normalized_text = strtolower( $text );
			if (
				false !== strpos( $normalized_text, 'alt text' )
				|| false !== strpos( $normalized_text, 'alt-text' )
				|| false !== strpos( $normalized_text, 'alttext' )
				|| false !== strpos( $normalized_text, 'alternativtext' )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extracts textual content from a prepared chat message payload.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $message Message payload.
	 * @return string Concatenated text.
	 */
	private function extract_message_text( array $message ): string {
		if ( ! isset( $message['content'] ) ) {
			return '';
		}

		if ( is_string( $message['content'] ) ) {
			return trim( $message['content'] );
		}

		if ( ! is_array( $message['content'] ) ) {
			return '';
		}

		$chunks = array();
		foreach ( $message['content'] as $part ) {
			if ( is_string( $part ) ) {
				$chunks[] = $part;
				continue;
			}

			if ( ! is_array( $part ) ) {
				continue;
			}

			if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
				$chunks[] = $part['text'];
				continue;
			}

			if ( isset( $part['content'] ) && is_string( $part['content'] ) ) {
				$chunks[] = $part['content'];
			}
		}

		if ( empty( $chunks ) ) {
			return '';
		}

		return trim( implode( "\n", $chunks ) );
	}

	/**
	 * Applies alt-text max-length constraint to text candidates.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $response_data Response payload.
	 * @return array<string, mixed> Updated response payload.
	 */
	private function maybe_apply_alt_text_length_limit( array $response_data ): array {
		if ( ! $this->enforce_alt_text_constraints || $this->alt_text_max_length < 1 ) {
			return $response_data;
		}

		if ( ! isset( $response_data['choices'] ) || ! is_array( $response_data['choices'] ) ) {
			return $response_data;
		}

		foreach ( $response_data['choices'] as $index => $choice ) {
			if ( ! is_array( $choice ) ) {
				continue;
			}

			if ( isset( $choice['message'] ) && is_array( $choice['message'] ) ) {
				$message = $choice['message'];

				if ( isset( $message['content'] ) && is_string( $message['content'] ) ) {
					$message['content'] = $this->truncate_alt_text( $message['content'] );
				}

				if ( isset( $message['content'] ) && is_array( $message['content'] ) ) {
					foreach ( $message['content'] as $part_index => $part ) {
						if ( ! is_array( $part ) || ! isset( $part['text'] ) || ! is_string( $part['text'] ) ) {
							continue;
						}

						$part['text']                      = $this->truncate_alt_text( $part['text'] );
						$message['content'][ $part_index ] = $part;
					}
				}

				$choice['message'] = $message;
			}

			if ( isset( $choice['text'] ) && is_string( $choice['text'] ) ) {
				$choice['text'] = $this->truncate_alt_text( $choice['text'] );
			}

			$response_data['choices'][ $index ] = $choice;
		}

		return $response_data;
	}

	/**
	 * Truncates alt text to the configured maximum length.
	 *
	 * @since 1.1.0
	 *
	 * @param string $text Alt text candidate.
	 * @return string Truncated text.
	 */
	private function truncate_alt_text( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return $text;
		}

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			if ( mb_strlen( $text, 'UTF-8' ) > $this->alt_text_max_length ) {
				return (string) mb_substr( $text, 0, $this->alt_text_max_length, 'UTF-8' );
			}

			return $text;
		}

		if ( strlen( $text ) > $this->alt_text_max_length ) {
			return substr( $text, 0, $this->alt_text_max_length );
		}

		return $text;
	}

	/**
	 * Normalizes provider-specific finish reasons to OpenAI-compatible values.
	 *
	 * @since 1.1.0
	 *
	 * @param string $finish_reason Raw finish reason.
	 * @return string Normalized finish reason.
	 */
	private function normalize_finish_reason( string $finish_reason ): string {
		$finish_reason = strtolower( trim( $finish_reason ) );

		if ( in_array( $finish_reason, array( 'stop', 'length', 'content_filter', 'tool_calls' ), true ) ) {
			return $finish_reason;
		}

		if ( in_array( $finish_reason, array( 'max_tokens', 'token_limit', 'timeout' ), true ) ) {
			return 'length';
		}

		if ( false !== strpos( $finish_reason, 'tool' ) ) {
			return 'tool_calls';
		}

		return 'stop';
	}
}
