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
			return sprintf(
				'For image alt-text tasks, return only plain alt text with no quotes and no Markdown. Ensure the result is at most %d characters.',
				$max_length
			);
		}

		return sprintf(
			'For image alt-text tasks, return only plain alt text in the same language as the user prompt/context. If unclear, use locale "%1$s". Use no quotes and no Markdown. Ensure the result is at most %2$d characters.',
			$locale,
			$max_length
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

		return sprintf(
			'Always answer in the same language as the user prompt/content. If unclear, use locale "%s".',
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
