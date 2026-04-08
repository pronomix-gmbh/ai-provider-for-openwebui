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
	 * Whether excerpt constraints should be enforced for the current request.
	 *
	 * @since 1.2.2
	 *
	 * @var bool
	 */
	private bool $enforce_excerpt_constraints = false;

	/**
	 * Maximum number of words allowed for excerpt output.
	 *
	 * @since 1.2.2
	 *
	 * @var int
	 */
	private int $excerpt_max_words = 30;

	/**
	 * Requested candidate count for the current request.
	 *
	 * @since 1.2.2
	 *
	 * @var int
	 */
	private int $requested_candidate_count = 1;

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
		$this->enforce_excerpt_constraints  = false;
		$this->excerpt_max_words            = 30;
		$this->requested_candidate_count    = 1;

		if ( ! isset( $params['messages'] ) || ! is_array( $params['messages'] ) ) {
			return $params;
		}

		$messages            = $params['messages'];
		$config              = $this->getConfig();
		$is_alt_text_request = $this->is_alt_text_request( $messages );

		$candidate_count = $config->getCandidateCount();
		if ( is_int( $candidate_count ) && $candidate_count > 1 ) {
			$this->requested_candidate_count = $candidate_count;
		}

		$locale      = $this->get_effective_locale( $messages );
		$instruction = OpenWebUIPromptConstraints::build_constraints_instruction(
			$locale,
			$config->getOutputMimeType(),
			null !== $config->getOutputSchema()
		);

		$multi_candidate_instruction = OpenWebUIPromptConstraints::build_multi_candidate_instruction(
			$this->requested_candidate_count
		);
		if (
			'' !== trim( $multi_candidate_instruction )
			&& ! $is_alt_text_request
			&& ! $this->expects_structured_json_output( $config->getOutputMimeType() )
		) {
			$instruction = '' === trim( $instruction )
				? $multi_candidate_instruction
				: trim( $instruction . "\n" . $multi_candidate_instruction );
		}

		if ( $is_alt_text_request ) {
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

		if ( $this->is_excerpt_request( $messages ) ) {
			$this->enforce_excerpt_constraints = true;
			$this->excerpt_max_words           = $this->get_excerpt_max_words();

			$excerpt_instruction = OpenWebUIPromptConstraints::build_excerpt_instruction(
				$locale,
				$this->excerpt_max_words
			);

			if ( '' !== trim( $excerpt_instruction ) ) {
				$instruction = '' === trim( $instruction )
					? $excerpt_instruction
					: trim( $instruction . "\n" . $excerpt_instruction );
			}
		}

		if ( function_exists( 'apply_filters' ) ) {
			$instruction = (string) apply_filters(
				'obenweb_openwebui_provider_prompt_constraints',
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
	 * Resolves the locale for response constraints.
	 *
	 * @since 1.2.2
	 *
	 * @param array<int, mixed> $messages Prepared request messages.
	 * @return string Locale, for example "de-DE", or empty string.
	 */
	private function get_effective_locale( array $messages ): string {
		$locale          = $this->get_current_locale();
		$detected_locale = $this->detect_locale_from_messages( $messages );

		if ( '' !== $detected_locale ) {
			$locale = $detected_locale;
		}

		if ( function_exists( 'apply_filters' ) ) {
			$locale = (string) apply_filters(
				'obenweb_openwebui_provider_response_locale',
				$locale,
				$messages,
				$this->metadata()->getId()
			);
		}

		return $this->sanitize_locale( $locale );
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

		return $this->sanitize_locale( $locale );
	}

	/**
	 * Sanitizes a locale string.
	 *
	 * @since 1.2.2
	 *
	 * @param string $locale Raw locale.
	 * @return string Sanitized locale, for example "de-DE", or empty string.
	 */
	private function sanitize_locale( string $locale ): string {
		$locale = str_replace( '_', '-', trim( $locale ) );
		$locale = preg_replace( '/[^A-Za-z0-9-]/', '', $locale );

		if ( ! is_string( $locale ) ) {
			return '';
		}

		return $locale;
	}

	/**
	 * Tries to infer a response locale from prompt content.
	 *
	 * This primarily detects German source content so the model keeps summaries
	 * and other outputs in German, even if surrounding instructions are English.
	 *
	 * @since 1.2.2
	 *
	 * @param array<int, mixed> $messages Prepared request messages.
	 * @return string Detected locale or empty string.
	 */
	private function detect_locale_from_messages( array $messages ): string {
		$source_text = $this->extract_primary_source_text_for_locale_detection( $messages );
		if ( '' === $source_text ) {
			return '';
		}

		if ( $this->looks_like_german_text( $source_text ) ) {
			return 'de-DE';
		}

		return '';
	}

	/**
	 * Extracts source text for locale detection.
	 *
	 * If `<content>...</content>` blocks are present they are preferred, because
	 * they contain the primary text for summarization and title generation.
	 *
	 * @since 1.2.2
	 *
	 * @param array<int, mixed> $messages Prepared request messages.
	 * @return string Source text.
	 */
	private function extract_primary_source_text_for_locale_detection( array $messages ): string {
		$user_text_chunks     = array();
		$fallback_text_chunks = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$text = $this->extract_message_text( $message );
			if ( '' === $text ) {
				continue;
			}

			$fallback_text_chunks[] = $text;

			if ( isset( $message['role'] ) && 'user' === (string) $message['role'] ) {
				$user_text_chunks[] = $text;
			}
		}

		$source_text = implode( "\n", ! empty( $user_text_chunks ) ? $user_text_chunks : $fallback_text_chunks );
		if ( '' === trim( $source_text ) ) {
			return '';
		}

		if ( preg_match_all( '/<content>(.*?)<\/content>/is', $source_text, $matches ) && isset( $matches[1] ) && is_array( $matches[1] ) ) {
			$content_chunks = array_filter(
				array_map(
					'trim',
					$matches[1]
				),
				static function ( string $chunk ): bool {
					return '' !== $chunk;
				}
			);

			if ( ! empty( $content_chunks ) ) {
				return implode( "\n", $content_chunks );
			}
		}

		return $source_text;
	}

	/**
	 * Performs a lightweight German-language heuristic.
	 *
	 * @since 1.2.2
	 *
	 * @param string $text Source text.
	 * @return bool True if text likely is German.
	 */
	private function looks_like_german_text( string $text ): bool {
		if ( '' === trim( $text ) ) {
			return false;
		}

		if ( 1 === preg_match( '/[äöüß]/iu', $text ) ) {
			return true;
		}

		$normalized_text = wp_strip_all_tags( $text );
		if ( function_exists( 'mb_strtolower' ) ) {
			$normalized_text = mb_strtolower( $normalized_text, 'UTF-8' );
		} else {
			$normalized_text = strtolower( $normalized_text );
		}

		$normalized_text = preg_replace( '/\s+/u', ' ', $normalized_text );
		if ( ! is_string( $normalized_text ) ) {
			return false;
		}

		$normalized_text = ' ' . trim( $normalized_text ) . ' ';
		$markers         = array(
			' der ',
			' die ',
			' das ',
			' und ',
			' ist ',
			' nicht ',
			' mit ',
			' für ',
			' auf ',
			' eine ',
			' einem ',
			' dem ',
			' von ',
			' zu ',
		);

		$hits = 0;
		foreach ( $markers as $marker ) {
			if ( false !== strpos( $normalized_text, $marker ) ) {
				++$hits;
			}
		}

		return $hits >= 3;
	}

	/**
	 * Checks whether strict JSON output is expected.
	 *
	 * @since 1.2.2
	 *
	 * @param string|null $output_mime_type Requested output mime type.
	 * @return bool True if JSON output is expected.
	 */
	private function expects_structured_json_output( ?string $output_mime_type ): bool {
		return 'application/json' === $output_mime_type;
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
					$prepared_response_data = $this->maybe_expand_single_choice_for_candidate_count( $response_data );
					$prepared_response_data = $this->maybe_apply_alt_text_length_limit( $prepared_response_data );
					$prepared_response_data = $this->maybe_apply_excerpt_word_limit( $prepared_response_data );
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
				$normalized_response_data = $this->maybe_expand_single_choice_for_candidate_count( $normalized_response_data );
				$normalized_response_data = $this->maybe_apply_alt_text_length_limit( $normalized_response_data );
				$normalized_response_data = $this->maybe_apply_excerpt_word_limit( $normalized_response_data );
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
	 * Expands a single text choice into multiple choices when needed.
	 *
	 * Some Open WebUI setups ignore the `n` parameter and still return only one
	 * choice. For multi-candidate requests, this method tries to split that single
	 * text output into distinct candidates.
	 *
	 * @since 1.2.2
	 *
	 * @param array<string, mixed> $response_data Response payload.
	 * @return array<string, mixed> Updated response payload.
	 */
	private function maybe_expand_single_choice_for_candidate_count( array $response_data ): array {
		if ( $this->enforce_alt_text_constraints || $this->requested_candidate_count < 2 ) {
			return $response_data;
		}

		if (
			! isset( $response_data['choices'] )
			|| ! is_array( $response_data['choices'] )
			|| 1 !== count( $response_data['choices'] )
		) {
			return $response_data;
		}

		$first_choice = $response_data['choices'][0] ?? null;
		if ( ! is_array( $first_choice ) ) {
			return $response_data;
		}

		$content = '';
		if ( isset( $first_choice['message'] ) && is_array( $first_choice['message'] ) ) {
			$content = $this->extract_message_text( $first_choice['message'] );
		}
		if ( '' === $content && isset( $first_choice['text'] ) && is_string( $first_choice['text'] ) ) {
			$content = trim( $first_choice['text'] );
		}
		if ( '' === $content ) {
			return $response_data;
		}

		$candidate_texts = $this->extract_candidate_texts_from_single_content(
			$content,
			$this->requested_candidate_count
		);
		if ( count( $candidate_texts ) < 2 ) {
			return $response_data;
		}

		$expanded_choices = array();
		foreach ( $candidate_texts as $candidate_text ) {
			$choice = $first_choice;

			if ( isset( $choice['message'] ) && is_array( $choice['message'] ) ) {
				$choice['message']['content'] = $candidate_text;
			} else {
				$choice['message'] = array(
					'role'    => 'assistant',
					'content' => $candidate_text,
				);
			}

			if ( ! isset( $choice['finish_reason'] ) || ! is_string( $choice['finish_reason'] ) ) {
				$choice['finish_reason'] = 'stop';
			}

			$expanded_choices[] = $choice;

			if ( count( $expanded_choices ) >= $this->requested_candidate_count ) {
				break;
			}
		}

		if ( ! empty( $expanded_choices ) ) {
			$response_data['choices'] = array_values( $expanded_choices );
		}

		return $response_data;
	}

	/**
	 * Extracts candidate texts from a single model response string.
	 *
	 * @since 1.2.2
	 *
	 * @param string $content Single response text.
	 * @param int    $limit Maximum number of candidates to return.
	 * @return array<int, string> Candidate texts.
	 */
	private function extract_candidate_texts_from_single_content( string $content, int $limit ): array {
		$limit      = max( 1, $limit );
		$candidates = array();

		$decoded_json = json_decode( $content, true );
		if ( is_array( $decoded_json ) ) {
			$decoded_candidates = $this->extract_candidate_texts_from_decoded_json( $decoded_json );
			foreach ( $decoded_candidates as $candidate ) {
				if ( '' === $candidate || in_array( $candidate, $candidates, true ) ) {
					continue;
				}

				$candidates[] = $candidate;
				if ( count( $candidates ) >= $limit ) {
					return $candidates;
				}
			}
		}

		$lines = preg_split( '/\R/u', $content );
		if ( is_array( $lines ) ) {
			foreach ( $lines as $line ) {
				$line = preg_replace( '/^\s*(?:[-*•]+|\d+[.)])\s*/u', '', (string) $line );
				if ( ! is_string( $line ) ) {
					continue;
				}

				$line = trim( $line, " \t\n\r\0\x0B\"'`“”‘’" );
				if ( '' === $line || in_array( $line, $candidates, true ) ) {
					continue;
				}

				$candidates[] = $line;
				if ( count( $candidates ) >= $limit ) {
					return $candidates;
				}
			}
		}

		return $candidates;
	}

	/**
	 * Extracts candidate strings from decoded JSON structures.
	 *
	 * @since 1.2.2
	 *
	 * @param array<int|string, mixed> $decoded_json Decoded JSON payload.
	 * @return array<int, string> Candidate texts.
	 */
	private function extract_candidate_texts_from_decoded_json( array $decoded_json ): array {
		$candidates = array();

		if ( $this->is_array_list( $decoded_json ) ) {
			foreach ( $decoded_json as $value ) {
				if ( ! is_string( $value ) ) {
					continue;
				}

				$value = trim( $value, " \t\n\r\0\x0B\"'`“”‘’" );
				if ( '' === $value || in_array( $value, $candidates, true ) ) {
					continue;
				}

				$candidates[] = $value;
			}

			return $candidates;
		}

		if ( isset( $decoded_json['titles'] ) && is_array( $decoded_json['titles'] ) ) {
			foreach ( $decoded_json['titles'] as $title ) {
				if ( ! is_string( $title ) ) {
					continue;
				}

				$title = trim( $title, " \t\n\r\0\x0B\"'`“”‘’" );
				if ( '' === $title || in_array( $title, $candidates, true ) ) {
					continue;
				}

				$candidates[] = $title;
			}
		}

		return $candidates;
	}

	/**
	 * Determines whether an array uses consecutive numeric keys from 0.
	 *
	 * @since 1.2.2
	 *
	 * @param array<int|string, mixed> $value_list Array to inspect.
	 * @return bool True if the array is a list.
	 */
	private function is_array_list( array $value_list ): bool {
		$index = 0;

		foreach ( $value_list as $key => $_value ) {
			if ( $key !== $index ) {
				return false;
			}

			++$index;
		}

		return true;
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
				'obenweb_openwebui_provider_alt_text_max_length',
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
	 * Returns the maximum allowed excerpt length in words.
	 *
	 * @since 1.2.2
	 *
	 * @return int Maximum words.
	 */
	private function get_excerpt_max_words(): int {
		$max_words = 30;

		if ( function_exists( 'apply_filters' ) ) {
			$max_words = (int) apply_filters(
				'obenweb_openwebui_provider_excerpt_max_words',
				$max_words,
				$this->metadata()->getId()
			);
		}

		if ( $max_words < 5 ) {
			return 30;
		}

		return $max_words;
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
	 * Detects whether the request is for excerpt generation.
	 *
	 * @since 1.2.2
	 *
	 * @param array<int, mixed> $messages Prepared request messages.
	 * @return bool True if this appears to be an excerpt request.
	 */
	private function is_excerpt_request( array $messages ): bool {
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
				false !== strpos( $normalized_text, 'excerpt' )
				|| false !== strpos( $normalized_text, 'auszug' )
				|| false !== strpos( $normalized_text, 'kurzbeschreibung' )
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
	 * Applies excerpt max-word constraint to text candidates.
	 *
	 * @since 1.2.2
	 *
	 * @param array<string, mixed> $response_data Response payload.
	 * @return array<string, mixed> Updated response payload.
	 */
	private function maybe_apply_excerpt_word_limit( array $response_data ): array {
		if ( ! $this->enforce_excerpt_constraints || $this->excerpt_max_words < 5 ) {
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
					$message['content'] = $this->truncate_excerpt_words( $message['content'] );
				}

				if ( isset( $message['content'] ) && is_array( $message['content'] ) ) {
					foreach ( $message['content'] as $part_index => $part ) {
						if ( ! is_array( $part ) || ! isset( $part['text'] ) || ! is_string( $part['text'] ) ) {
							continue;
						}

						$part['text']                      = $this->truncate_excerpt_words( $part['text'] );
						$message['content'][ $part_index ] = $part;
					}
				}

				$choice['message'] = $message;
			}

			if ( isset( $choice['text'] ) && is_string( $choice['text'] ) ) {
				$choice['text'] = $this->truncate_excerpt_words( $choice['text'] );
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
	 * Truncates excerpt text to the configured maximum word count.
	 *
	 * @since 1.2.2
	 *
	 * @param string $text Excerpt candidate.
	 * @return string Truncated excerpt.
	 */
	private function truncate_excerpt_words( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return $text;
		}

		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) || count( $words ) <= $this->excerpt_max_words ) {
			return $text;
		}

		$words = array_slice( $words, 0, $this->excerpt_max_words );

		return trim( implode( ' ', $words ) );
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
