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
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Plugin class.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * Cached Open WebUI model metadata map.
	 *
	 * @since 1.2.1
	 *
	 * @var array<string, ModelMetadata>|null
	 */
	private ?array $openwebui_models_cache = null;

	/**
	 * Cached state whether a non-Open WebUI provider is configured.
	 *
	 * @since 1.1.0
	 *
	 * @var bool|null
	 */
	private ?bool $has_other_provider_cache = null;

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
		add_filter( 'wpai_preferred_image_models', array( $this, 'filter_preferred_image_models' ) );
		add_filter( 'wpai_preferred_vision_models', array( $this, 'filter_preferred_vision_models' ) );
		add_filter( 'wpai_feature_image-generation_enabled', array( $this, 'filter_image_generation_feature_enabled' ) );
		add_filter( 'wpai_feature_alt-text-generation_enabled', array( $this, 'filter_alt_text_generation_feature_enabled' ) );
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
		return $this->prepend_openwebui_models( $preferred_models, 'text_generation' );
	}

	/**
	 * Prioritizes the selected OpenWebUI model for image generation.
	 *
	 * @since 1.1.0
	 *
	 * @param array<int, mixed> $preferred_models Preferred model tuples from the AI plugin.
	 * @return array<int, mixed> Updated model tuples.
	 */
	public function filter_preferred_image_models( array $preferred_models ): array {
		return $this->prepend_openwebui_models( $preferred_models, 'image_generation' );
	}

	/**
	 * Prioritizes the selected OpenWebUI model for vision requests.
	 *
	 * @since 1.1.0
	 *
	 * @param array<int, mixed> $preferred_models Preferred model tuples from the AI plugin.
	 * @return array<int, mixed> Updated model tuples.
	 */
	public function filter_preferred_vision_models( array $preferred_models ): array {
		return $this->prepend_openwebui_models( $preferred_models, 'vision' );
	}

	/**
	 * Prepends Open WebUI models to preferred models for a required capability.
	 *
	 * The selected model is always prioritized first if set. Additional Open WebUI
	 * models that satisfy the requested capability are then added as fallbacks.
	 *
	 * @since 1.2.1
	 *
	 * @param array<int, mixed> $preferred_models Preferred model tuples.
	 * @param string            $required_capability Required capability key.
	 * @return array<int, mixed> Updated model tuples.
	 */
	private function prepend_openwebui_models( array $preferred_models, string $required_capability ): array {
		$ordered_model_ids = array();
		$selected_model    = $this->get_selected_openwebui_model_id();
		if ( '' !== $selected_model ) {
			$ordered_model_ids[] = $selected_model;
		}

		$capability_model_ids = $this->get_openwebui_model_ids_by_capability( $required_capability );
		if ( is_array( $capability_model_ids ) ) {
			foreach ( $capability_model_ids as $model_id ) {
				if ( in_array( $model_id, $ordered_model_ids, true ) ) {
					continue;
				}

				$ordered_model_ids[] = $model_id;
			}
		}

		if ( empty( $ordered_model_ids ) ) {
			return $preferred_models;
		}

		$filtered_models = array();
		foreach ( $ordered_model_ids as $model_id ) {
			$filtered_models[] = array( 'openwebui', $model_id );
		}

		foreach ( $preferred_models as $preferred_model ) {
			if ( ! is_array( $preferred_model ) || ! isset( $preferred_model[0], $preferred_model[1] ) ) {
				$filtered_models[] = $preferred_model;
				continue;
			}

			$provider_id = (string) $preferred_model[0];
			$model_id    = (string) $preferred_model[1];

			if ( 'openwebui' === $provider_id && in_array( $model_id, $ordered_model_ids, true ) ) {
				continue;
			}

			$filtered_models[] = $preferred_model;
		}

		return $filtered_models;
	}

	/**
	 * Disables image generation experiment only if no Open WebUI model supports it
	 * and no other provider is configured.
	 *
	 * @since 1.1.0
	 *
	 * @param bool $enabled Current enabled state.
	 * @return bool Filtered enabled state.
	 */
	public function filter_image_generation_feature_enabled( bool $enabled ): bool {
		return $this->filter_feature_enabled_by_openwebui_capability( $enabled, 'image_generation' );
	}

	/**
	 * Disables alt-text experiment only if no Open WebUI model supports vision
	 * and no other provider is configured.
	 *
	 * @since 1.1.0
	 *
	 * @param bool $enabled Current enabled state.
	 * @return bool Filtered enabled state.
	 */
	public function filter_alt_text_generation_feature_enabled( bool $enabled ): bool {
		return $this->filter_feature_enabled_by_openwebui_capability( $enabled, 'vision' );
	}

	/**
	 * Filters a feature flag based on available Open WebUI model capabilities.
	 *
	 * The feature is only force-disabled when:
	 * 1) no Open WebUI model with the required capability is available, and
	 * 2) no other configured provider is available as fallback.
	 *
	 * @since 1.1.0
	 *
	 * @param bool   $enabled Current enabled state.
	 * @param string $required_capability Capability key (`image_generation` or `vision`).
	 * @return bool Filtered enabled state.
	 */
	private function filter_feature_enabled_by_openwebui_capability( bool $enabled, string $required_capability ): bool {
		if ( ! $enabled ) {
			return false;
		}

		if ( $this->has_configured_non_openwebui_provider() ) {
			return $enabled;
		}

		$capability_model_ids = $this->get_openwebui_model_ids_by_capability( $required_capability );
		if ( ! is_array( $capability_model_ids ) ) {
			// Unknown state: keep feature enabled to avoid false negatives.
			return $enabled;
		}

		return ! empty( $capability_model_ids );
	}

	/**
	 * Gets the selected Open WebUI model ID.
	 *
	 * @since 1.1.0
	 *
	 * @return string Selected model ID or empty string.
	 */
	private function get_selected_openwebui_model_id(): string {
		$settings = OpenWebUISettings::get_settings();
		if ( ! isset( $settings['model'] ) || '' === (string) $settings['model'] ) {
			return '';
		}

		return trim( (string) $settings['model'] );
	}

	/**
	 * Checks whether any configured non-Open WebUI provider exists.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if a fallback provider is configured.
	 */
	private function has_configured_non_openwebui_provider(): bool {
		if ( null !== $this->has_other_provider_cache ) {
			return $this->has_other_provider_cache;
		}

		if ( ! class_exists( AiClient::class ) ) {
			$this->has_other_provider_cache = false;
			return false;
		}

		$registry = AiClient::defaultRegistry();
		foreach ( $registry->getRegisteredProviderIds() as $provider_id ) {
			if ( 'openwebui' === $provider_id ) {
				continue;
			}

			if ( $registry->isProviderConfigured( $provider_id ) ) {
				$this->has_other_provider_cache = true;
				return true;
			}
		}

		$this->has_other_provider_cache = false;
		return false;
	}

	/**
	 * Gets all Open WebUI model IDs that support a required capability.
	 *
	 * @since 1.2.1
	 *
	 * @param string $required_capability Capability key.
	 * @return array<int, string>|null Matching model IDs, or null when unknown.
	 */
	private function get_openwebui_model_ids_by_capability( string $required_capability ): ?array {
		$models_map = $this->get_openwebui_models_map();
		if ( ! is_array( $models_map ) ) {
			return null;
		}

		$model_ids = array();
		foreach ( $models_map as $model_key => $model_metadata ) {
			if ( ! $model_metadata instanceof ModelMetadata ) {
				continue;
			}

			$model_id = trim( $model_metadata->getId() );
			// Backward compatibility for map-like payloads keyed by model ID.
			if ( '' === $model_id && is_string( $model_key ) ) {
				$model_id = trim( $model_key );
			}
			if ( '' === $model_id ) {
				continue;
			}

			if ( 'image_generation' === $required_capability ) {
				if ( ! $this->model_supports_image_generation( $model_metadata ) ) {
					continue;
				}
			} elseif ( 'vision' === $required_capability ) {
				if ( ! $this->model_supports_vision( $model_metadata ) ) {
					continue;
				}
			} elseif ( 'text_generation' === $required_capability ) {
				if ( ! $this->model_supports_text_generation( $model_metadata ) ) {
					continue;
				}
			}

			$model_ids[] = $model_id;
		}

		sort( $model_ids, \SORT_STRING );

		return $model_ids;
	}

	/**
	 * Gets the Open WebUI model metadata map from the AI registry.
	 *
	 * @since 1.2.1
	 *
	 * @return array<string, ModelMetadata>|null Models map or null on unknown state.
	 */
	private function get_openwebui_models_map(): ?array {
		if ( null !== $this->openwebui_models_cache ) {
			return $this->openwebui_models_cache;
		}

		if ( ! class_exists( AiClient::class ) ) {
			return null;
		}

		$registry = AiClient::defaultRegistry();
		if ( ! $registry->hasProvider( 'openwebui' ) ) {
			return null;
		}

		try {
			$provider_classname       = $registry->getProviderClassName( 'openwebui' );
			$model_metadata_directory = $provider_classname::modelMetadataDirectory();
			$models_map               = $model_metadata_directory->listModelMetadata();
		} catch ( \Throwable $e ) {
			return null;
		}

		$this->openwebui_models_cache = is_array( $models_map ) ? $models_map : array();

		return $this->openwebui_models_cache;
	}

	/**
	 * Checks if a model supports text generation.
	 *
	 * @since 1.2.1
	 *
	 * @param ModelMetadata $model_metadata Model metadata.
	 * @return bool True if text generation is supported.
	 */
	private function model_supports_text_generation( ModelMetadata $model_metadata ): bool {
		foreach ( $model_metadata->getSupportedCapabilities() as $capability ) {
			if ( $capability->isTextGeneration() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks if a model supports image generation.
	 *
	 * @since 1.1.0
	 *
	 * @param ModelMetadata $model_metadata Model metadata.
	 * @return bool True if image generation is supported.
	 */
	private function model_supports_image_generation( ModelMetadata $model_metadata ): bool {
		foreach ( $model_metadata->getSupportedCapabilities() as $capability ) {
			if ( $capability->isImageGeneration() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks if a model supports image input (vision).
	 *
	 * @since 1.1.0
	 *
	 * @param ModelMetadata $model_metadata Model metadata.
	 * @return bool True if vision is supported.
	 */
	private function model_supports_vision( ModelMetadata $model_metadata ): bool {
		foreach ( $model_metadata->getSupportedOptions() as $option ) {
			if ( ! $option->getName()->isInputModalities() ) {
				continue;
			}

			$supported_values = $option->getSupportedValues();
			if ( null === $supported_values ) {
				return true;
			}

			foreach ( $supported_values as $modality_group ) {
				if ( ! is_array( $modality_group ) ) {
					continue;
				}

				foreach ( $modality_group as $modality ) {
					if ( $modality instanceof ModalityEnum && $modality->isImage() ) {
						return true;
					}

					if ( is_string( $modality ) && 'image' === strtolower( trim( $modality ) ) ) {
						return true;
					}
				}
			}
		}

		return false;
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
