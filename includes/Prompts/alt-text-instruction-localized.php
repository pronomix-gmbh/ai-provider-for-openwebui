<?php
/**
 * Localized alt-text constraint instruction template.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return sprintf(
	'For image alt-text tasks, return only plain alt text in the same language as the user prompt/context. If unclear, use locale "%1$s". Do not repeat or paraphrase the prompt/instructions. Use no quotes and no Markdown. Ensure the result is at most %2$d characters.',
	(string) ( $prompt_vars['locale'] ?? '' ),
	(int) ( $prompt_vars['max_length'] ?? 0 )
);
