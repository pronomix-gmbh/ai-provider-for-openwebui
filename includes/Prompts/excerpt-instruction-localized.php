<?php
/**
 * Localized excerpt constraint instruction template.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return sprintf(
	'For excerpt tasks, return a single plain-text excerpt in locale "%1$s" with at most %2$d words and no quotes, no bullets, and no Markdown.',
	(string) ( $prompt_vars['locale'] ?? '' ),
	(int) ( $prompt_vars['max_words'] ?? 0 )
);
