<?php
/**
 * Prompt constraint utilities for OpenWebUI requests.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

declare( strict_types=1 );

namespace OBenWeb\AiProviderForOpenWebUI\Util;

/**
 * Utility to build prompt constraints for language and response format.
 *
 * @since 1.1.0
 */
class OpenWebUIPromptConstraints {

	/**
	 * Builds a system instruction for language and format constraints.
	 *
	 * @since 1.1.0
	 *
	 * @param string      $locale Locale, for example "de-DE".
	 * @param string|null $output_mime_type Requested output mime type.
	 * @param bool        $has_output_schema Whether an output schema is configured.
	 * @return string Constraint instruction, or empty string.
	 */
	public static function build_constraints_instruction( string $locale, ?string $output_mime_type, bool $has_output_schema ): string {
		$constraints = array();

		$language_instruction = self::build_language_instruction( $locale );
		if ( '' !== $language_instruction ) {
			$constraints[] = $language_instruction;
		}

		$format_instruction = self::build_format_instruction( $output_mime_type, $has_output_schema );
		if ( '' !== $format_instruction ) {
			$constraints[] = $format_instruction;
		}

		return implode( "\n", $constraints );
	}

	/**
	 * Builds an instruction for multi-candidate text generation fallback.
	 *
	 * Some OpenAI-compatible backends may ignore the `n` parameter and still
	 * return a single choice. This instruction encourages line-delimited
	 * alternatives so the client can split them into choices.
	 *
	 * @since 1.2.2
	 *
	 * @param int $candidate_count Requested candidate count.
	 * @return string Constraint instruction, or empty string.
	 */
	public static function build_multi_candidate_instruction( int $candidate_count ): string {
		if ( $candidate_count < 2 ) {
			return '';
		}

		$instruction = OpenWebUIPromptTemplate::render(
			'multi-candidate-instruction',
			array(
				'candidate_count' => $candidate_count,
			)
		);

		if ( '' !== $instruction ) {
			return $instruction;
		}

		return sprintf(
			'When multiple candidates are requested, provide exactly %d distinct alternatives as a numbered plain-text list (one alternative per line).',
			$candidate_count
		);
	}

	/**
	 * Builds a dedicated instruction for image alt-text tasks.
	 *
	 * @since 1.1.0
	 *
	 * @param string $locale Locale, for example "de-DE".
	 * @param int    $max_length Maximum allowed alt-text length in characters.
	 * @return string Constraint instruction.
	 */
	public static function build_alt_text_instruction( string $locale, int $max_length ): string {
		$locale     = trim( str_replace( '_', '-', $locale ) );
		$max_length = max( 1, $max_length );

		if ( '' === $locale ) {
			$instruction = OpenWebUIPromptTemplate::render(
				'alt-text-instruction-fallback',
				array(
					'max_length' => $max_length,
				)
			);

			if ( '' !== $instruction ) {
				return $instruction;
			}

			return sprintf(
				'For image alt-text tasks, return only plain alt text with no quotes and no Markdown. Ensure the result is at most %d characters.',
				$max_length
			);
		}

		$instruction = OpenWebUIPromptTemplate::render(
			'alt-text-instruction-localized',
			array(
				'locale'     => $locale,
				'max_length' => $max_length,
			)
		);

		if ( '' !== $instruction ) {
			return $instruction;
		}

		return sprintf(
			'For image alt-text tasks, return only plain alt text in the same language as the user prompt/context. If unclear, use locale "%1$s". Do not repeat or paraphrase the prompt/instructions. Use no quotes and no Markdown. Ensure the result is at most %2$d characters.',
			$locale,
			$max_length
		);
	}

	/**
	 * Builds a dedicated instruction for excerpt tasks.
	 *
	 * @since 1.2.2
	 *
	 * @param string $locale Locale, for example "de-DE".
	 * @param int    $max_words Maximum allowed excerpt words.
	 * @return string Constraint instruction.
	 */
	public static function build_excerpt_instruction( string $locale, int $max_words ): string {
		$locale    = trim( str_replace( '_', '-', $locale ) );
		$max_words = max( 5, $max_words );

		if ( '' === $locale ) {
			$instruction = OpenWebUIPromptTemplate::render(
				'excerpt-instruction-fallback',
				array(
					'max_words' => $max_words,
				)
			);

			if ( '' !== $instruction ) {
				return $instruction;
			}

			return sprintf(
				'For excerpt tasks, return a single plain-text excerpt with at most %d words and no quotes, no bullets, and no Markdown.',
				$max_words
			);
		}

		$instruction = OpenWebUIPromptTemplate::render(
			'excerpt-instruction-localized',
			array(
				'locale'    => $locale,
				'max_words' => $max_words,
			)
		);

		if ( '' !== $instruction ) {
			return $instruction;
		}

		return sprintf(
			'For excerpt tasks, return a single plain-text excerpt in locale "%1$s" with at most %2$d words and no quotes, no bullets, and no Markdown.',
			$locale,
			$max_words
		);
	}

	/**
	 * Builds a language instruction from the locale.
	 *
	 * @since 1.1.0
	 *
	 * @param string $locale Locale string.
	 * @return string Language instruction, or empty string.
	 */
	private static function build_language_instruction( string $locale ): string {
		$locale = trim( str_replace( '_', '-', $locale ) );
		if ( '' === $locale ) {
			return '';
		}

		$instruction = OpenWebUIPromptTemplate::render(
			'language-instruction',
			array(
				'locale' => $locale,
			)
		);

		if ( '' !== $instruction ) {
			return $instruction;
		}

		return sprintf(
			'Always answer in the primary language of the source content. If <content>...</content> or similar source blocks are present, prioritize their language over surrounding instructions. Do not translate unless explicitly requested. If still unclear, use locale "%s".',
			$locale
		);
	}

	/**
	 * Builds format-specific output instruction.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null $output_mime_type Requested output mime type.
	 * @param bool        $has_output_schema Whether an output schema is configured.
	 * @return string Format instruction, or empty string.
	 */
	private static function build_format_instruction( ?string $output_mime_type, bool $has_output_schema ): string {
		if ( 'application/json' === $output_mime_type ) {
			if ( $has_output_schema ) {
				return 'Return only valid JSON with no Markdown fences and strictly match the requested schema.';
			}

			return 'Return only valid JSON with no Markdown fences and no additional commentary.';
		}

		if ( 'text/plain' === $output_mime_type ) {
			return 'Return plain text only.';
		}

		return '';
	}
}
