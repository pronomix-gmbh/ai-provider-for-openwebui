<?php
/**
 * Plugin bootstrap class.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

declare( strict_types=1 );

namespace OBenWeb\AiProviderForOpenWebUI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OBenWeb\AiProviderForOpenWebUI\Provider\OpenWebUIProvider;
use OBenWeb\AiProviderForOpenWebUI\Settings\OpenWebUISettings;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;

/**
 * Plugin class.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * Initializes the plugin.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_provider' ), 5 );
		add_action( 'init', array( $this, 'register_fallback_auth' ), 15 );
		add_action( 'init', array( $this, 'initialize_settings' ) );
		add_filter( 'wpai_preferred_text_models', array( $this, 'filter_preferred_text_models' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AI_PROVIDER_FOR_OPENWEBUI_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );
		add_filter( 'http_request_host_is_external', array( $this, 'allow_localhost_requests' ), 10, 3 );
		add_filter( 'http_allowed_safe_ports', array( $this, 'allow_openwebui_ports' ) );
	}

	/**
	 * Gets the OpenWebUI host.
	 *
	 * Priority:
	 * 1. OPENWEBUI_BASE_URL environment variable
	 * 2. Plugin setting
	 * 3. Default localhost URL
	 *
	 * @since 1.0.0
	 *
	 * @return string The OpenWebUI host.
	 */
	private function get_openwebui_host(): string {
		$host = getenv( 'OPENWEBUI_BASE_URL' );
		if ( false !== $host && '' !== $host ) {
			return rtrim( $host, '/' );
		}

		$settings = OpenWebUISettings::get_settings();
		if ( isset( $settings['host'] ) && '' !== $settings['host'] ) {
			return rtrim( $settings['host'], '/' );
		}

		return 'http://localhost:3000';
	}

	/**
	 * Gets the OpenWebUI API key.
	 *
	 * Priority:
	 * 1. OPENWEBUI_API_KEY environment variable
	 * 2. Plugin setting
	 *
	 * @since 1.0.0
	 *
	 * @return string The API key or an empty string.
	 */
	private function get_openwebui_api_key(): string {
		$api_key = getenv( 'OPENWEBUI_API_KEY' );
		if ( false !== $api_key && '' !== $api_key ) {
			return $api_key;
		}

		$settings = OpenWebUISettings::get_settings();
		if ( isset( $settings['api_key'] ) && '' !== $settings['api_key'] ) {
			return (string) $settings['api_key'];
		}

		return '';
	}

	/**
	 * Sets the OPENWEBUI_BASE_URL environment variable.
	 *
	 * @since 1.0.0
	 */
	private function set_openwebui_host(): void {
		$host = $this->get_openwebui_host();

		if ( '' === $host ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Required so provider classes can read a stable base URL.
		putenv( 'OPENWEBUI_BASE_URL=' . $host );
	}

	/**
	 * Registers the OpenWebUI provider with the AI Client.
	 *
	 * @since 1.0.0
	 */
	public function register_provider(): void {
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		$this->set_openwebui_host();

		$registry = AiClient::defaultRegistry();

		if ( $registry->hasProvider( OpenWebUIProvider::class ) ) {
			return;
		}

		$registry->registerProvider( OpenWebUIProvider::class );
	}

	/**
	 * Registers fallback authentication for the OpenWebUI provider.
	 *
	 * If AI Client credentials are already configured, this does nothing.
	 * Otherwise it uses the API key from env/plugin settings.
	 *
	 * @since 1.0.0
	 */
	public function register_fallback_auth(): void {
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}

		$registry = AiClient::defaultRegistry();

		if ( ! $registry->hasProvider( 'openwebui' ) ) {
			return;
		}

		$auth = $registry->getProviderRequestAuthentication( 'openwebui' );
		if ( null !== $auth ) {
			return;
		}

		$api_key = $this->get_openwebui_api_key();
		if ( '' === $api_key ) {
			return;
		}

		$registry->setProviderRequestAuthentication(
			'openwebui',
			new ApiKeyRequestAuthentication( $api_key )
		);
	}

	/**
	 * Initializes the OpenWebUI settings.
	 *
	 * @since 1.0.0
	 */
	public function initialize_settings(): void {
		$settings = new OpenWebUISettings();
		$settings->init();
	}

	/**
	 * Prioritizes the selected OpenWebUI model for text generation.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $preferred_models Preferred model tuples from the AI plugin.
	 * @return array<int, mixed> Updated model tuples.
	 */
	public function filter_preferred_text_models( array $preferred_models ): array {
		$settings       = OpenWebUISettings::get_settings();
		$selected_model = isset( $settings['model'] ) ? trim( (string) $settings['model'] ) : '';

		if ( '' === $selected_model ) {
			return $preferred_models;
		}

		$filtered_models = array(
			array( 'openwebui', $selected_model ),
		);

		foreach ( $preferred_models as $preferred_model ) {
			if ( ! is_array( $preferred_model ) || ! isset( $preferred_model[0], $preferred_model[1] ) ) {
				$filtered_models[] = $preferred_model;
				continue;
			}

			$provider_id = (string) $preferred_model[0];
			$model_id    = (string) $preferred_model[1];

			if ( 'openwebui' === $provider_id && $selected_model === $model_id ) {
				continue;
			}

			$filtered_models[] = $preferred_model;
		}

		return $filtered_models;
	}

	/**
	 * Adds action links to the plugin list table.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string> $links Existing action links.
	 * @return array<string> Modified action links.
	 */
	public function plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			admin_url( 'options-general.php?page=ai-provider-for-open-webui' ),
			esc_html__( 'Settings', 'ai-provider-for-open-webui' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Allows localhost requests to the configured OpenWebUI host.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $external Whether the request is external.
	 * @param string $host The host of the request.
	 * @param string $url The URL of the request.
	 * @return bool Whether the request is allowed.
	 */
	public function allow_localhost_requests( $external, $host, $url ): bool {
		if ( false !== strpos( $url, $this->get_openwebui_host() ) ) {
			return true;
		}

		return $external;
	}

	/**
	 * Allows the configured OpenWebUI port.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int> $ports Existing allowed ports.
	 * @return array<int> Modified allowed ports.
	 */
	public function allow_openwebui_ports( $ports ): array {
		$openwebui_host = $this->get_openwebui_host();
		$openwebui_port = wp_parse_url( $openwebui_host, PHP_URL_PORT );

		if ( ! $openwebui_port ) {
			return $ports;
		}

		return array_merge( $ports, array( $openwebui_port ) );
	}
}
