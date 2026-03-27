<?php
/**
 * Multi-candidate constraint instruction template.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return sprintf(
	'When multiple candidates are requested, provide exactly %d distinct alternatives as a numbered plain-text list (one alternative per line).',
	(int) ( $prompt_vars['candidate_count'] ?? 0 )
);
