<?php
/**
 * Prompt template renderer for OpenWebUI constraints.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

declare( strict_types=1 );

namespace OBenWeb\AiProviderForOpenWebUI\Util;

/**
 * Renders prompt templates from plugin files.
 *
 * @since 1.2.2
 */
class OpenWebUIPromptTemplate {

	/**
	 * Renders a template and returns the resulting prompt text.
	 *
	 * @since 1.2.2
	 *
	 * @param string               $template_name Template file name without ".php".
	 * @param array<string, mixed> $vars Variables available in template scope.
	 * @return string Rendered prompt text or empty string.
	 */
	public static function render( string $template_name, array $vars = array() ): string {
		$template_name = trim( $template_name );
		if ( '' === $template_name || ! defined( 'AI_PROVIDER_FOR_OPENWEBUI_PLUGIN_DIR' ) ) {
			return '';
		}

		$template_path = AI_PROVIDER_FOR_OPENWEBUI_PLUGIN_DIR . 'includes/Prompts/' . $template_name . '.php';
		if ( ! file_exists( $template_path ) || ! is_readable( $template_path ) ) {
			return '';
		}

		$prompt_vars = $vars;
		$result      = include $template_path;

		return is_string( $result ) ? trim( $result ) : '';
	}
}
