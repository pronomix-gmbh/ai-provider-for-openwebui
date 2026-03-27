<?php
/**
 * Language constraint instruction template.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return sprintf(
	'Always answer in the primary language of the source content. If <content>...</content> or similar source blocks are present, prioritize their language over surrounding instructions. Do not translate unless explicitly requested. If still unclear, use locale "%s".',
	(string) ( $prompt_vars['locale'] ?? '' )
);
