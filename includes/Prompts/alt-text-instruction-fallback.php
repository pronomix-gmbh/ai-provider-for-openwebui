<?php
/**
 * Alt-text fallback constraint instruction template.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return sprintf(
	'For image alt-text tasks, return only plain alt text with no quotes and no Markdown. Ensure the result is at most %d characters.',
	(int) ( $prompt_vars['max_length'] ?? 0 )
);
