<?php
/**
 * Unit tests for OpenWebUI prompt constraints.
 *
 * @package OBenWeb\AiProviderForOpenWebUI\Tests
 */

declare( strict_types=1 );

namespace OBenWeb\AiProviderForOpenWebUI\Tests\Unit;

use OBenWeb\AiProviderForOpenWebUI\Util\OpenWebUIPromptConstraints;
use PHPUnit\Framework\TestCase;

/**
 * Tests prompt constraint generation.
 *
 * @since 1.1.0
 */
class OpenWebUIPromptConstraintsTest extends TestCase {

	/**
	 * @return array<string, array{locale: string, mime: string|null, has_schema: bool, contains: list<string>, exact: string|null}>
	 */
	public function constraints_provider(): array {
		return array(
			'language and json schema constraints' => array(
				'locale'     => 'de_DE',
				'mime'       => 'application/json',
				'has_schema' => true,
				'contains'   => array(
					'locale "de-DE"',
					'Return only valid JSON',
					'strictly match the requested schema',
				),
				'exact'      => null,
			),
			'plain text constraints' => array(
				'locale'     => 'en-GB',
				'mime'       => 'text/plain',
				'has_schema' => false,
				'contains'   => array(
					'locale "en-GB"',
					'Return plain text only.',
				),
				'exact'      => null,
			),
			'no constraints' => array(
				'locale'     => '',
				'mime'       => null,
				'has_schema' => false,
				'contains'   => array(),
				'exact'      => '',
			),
		);
	}

	/**
	 * @dataProvider constraints_provider
	 *
	 * @param string       $locale Locale.
	 * @param string|null  $mime Mime type.
	 * @param bool         $has_schema Whether output schema is present.
	 * @param list<string> $contains Substrings that must be contained.
	 * @param string|null  $exact Exact expected instruction.
	 */
	public function test_build_constraints_instruction( string $locale, ?string $mime, bool $has_schema, array $contains, ?string $exact ): void {
		$instruction = OpenWebUIPromptConstraints::build_constraints_instruction( $locale, $mime, $has_schema );

		if ( null !== $exact ) {
			self::assertSame( $exact, $instruction );

			return;
		}

		foreach ( $contains as $needle ) {
			self::assertStringContainsString( $needle, $instruction );
		}
	}

	/**
	 * @return array<string, array{locale: string, max_length: int, contains: list<string>}>
	 */
	public function alt_text_instruction_provider(): array {
		return array(
			'localized alt text instruction' => array(
				'locale'     => 'de-DE',
				'max_length' => 125,
				'contains'   => array(
					'locale "de-DE"',
					'at most 125 characters',
				),
			),
			'fallback locale with minimum length guard' => array(
				'locale'     => '',
				'max_length' => 0,
				'contains'   => array(
					'at most 1 characters',
					'plain alt text',
				),
			),
		);
	}

	/**
	 * @dataProvider alt_text_instruction_provider
	 *
	 * @param string       $locale Locale.
	 * @param int          $max_length Requested maximum length.
	 * @param list<string> $contains Substrings that must be contained.
	 */
	public function test_build_alt_text_instruction( string $locale, int $max_length, array $contains ): void {
		$instruction = OpenWebUIPromptConstraints::build_alt_text_instruction( $locale, $max_length );

		foreach ( $contains as $needle ) {
			self::assertStringContainsString( $needle, $instruction );
		}
	}

	/**
	 * @return array<string, array{locale: string, max_words: int, contains: list<string>}>
	 */
	public function excerpt_instruction_provider(): array {
		return array(
			'localized excerpt instruction' => array(
				'locale'    => 'de-DE',
				'max_words' => 30,
				'contains'  => array(
					'locale "de-DE"',
					'at most 30 words',
				),
			),
			'fallback excerpt instruction' => array(
				'locale'    => '',
				'max_words' => 1,
				'contains'  => array(
					'at most 5 words',
					'single plain-text excerpt',
				),
			),
		);
	}

	/**
	 * @dataProvider excerpt_instruction_provider
	 *
	 * @param string       $locale Locale.
	 * @param int          $max_words Requested maximum words.
	 * @param list<string> $contains Substrings that must be contained.
	 */
	public function test_build_excerpt_instruction( string $locale, int $max_words, array $contains ): void {
		$instruction = OpenWebUIPromptConstraints::build_excerpt_instruction( $locale, $max_words );

		foreach ( $contains as $needle ) {
			self::assertStringContainsString( $needle, $instruction );
		}
	}

	/**
	 * @return array<string, array{candidate_count: int, exact: string|null, contains: list<string>}>
	 */
	public function multi_candidate_instruction_provider(): array {
		return array(
			'no multi candidate constraint' => array(
				'candidate_count' => 1,
				'exact'           => '',
				'contains'        => array(),
			),
			'multi candidate constraint' => array(
				'candidate_count' => 3,
				'exact'           => null,
				'contains'        => array(
					'exactly 3 distinct alternatives',
					'numbered plain-text list',
				),
			),
		);
	}

	/**
	 * @dataProvider multi_candidate_instruction_provider
	 *
	 * @param int          $candidate_count Requested candidate count.
	 * @param string|null  $exact Exact expected instruction.
	 * @param list<string> $contains Substrings that must be contained.
	 */
	public function test_build_multi_candidate_instruction( int $candidate_count, ?string $exact, array $contains ): void {
		$instruction = OpenWebUIPromptConstraints::build_multi_candidate_instruction( $candidate_count );

		if ( null !== $exact ) {
			self::assertSame( $exact, $instruction );

			return;
		}

		foreach ( $contains as $needle ) {
			self::assertStringContainsString( $needle, $instruction );
		}
	}
}
