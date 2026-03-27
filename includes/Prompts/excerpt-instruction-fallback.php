<?php
/**
 * Excerpt fallback constraint instruction template.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return sprintf(
	'For excerpt tasks, return a single plain-text excerpt with at most %d words and no quotes, no bullets, and no Markdown.',
	(int) ( $prompt_vars['max_words'] ?? 0 )
);
