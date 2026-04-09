<?php
/**
 * OpenWebUI admin settings implementation.
 *
 * @package OBenWeb\AiProviderForOpenWebUI
 */

declare( strict_types=1 );

namespace OBenWeb\AiProviderForOpenWebUI\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\AiClient;

/**
 * Class for the OpenWebUI settings in the WordPress admin.
 *
 * @since 1.0.0
 */
class OpenWebUISettings {

	private const OPTION_GROUP             = 'obenweb-openwebui-provider-settings';
	private const OPTION_NAME              = 'obenweb_openwebui_provider_settings';
	private const CONNECTOR_API_KEY_OPTION = 'connectors_ai_openwebui_api_key';
	private const PAGE_SLUG                = 'ai-provider-for-open-webui';
	private const SECTION_ID               = 'obenweb_openwebui_provider_main';
	private const AJAX_ACTION              = 'obenweb_openwebui_provider_list_models';
	private const NONCE_ACTION             = 'obenweb_openwebui_provider_nonce';

	/**
	 * Initializes the settings.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_screen' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_script' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_list_models' ) );
	}

	/**
	 * Registers the setting and settings fields.
	 *
	 * @since 1.0.0
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		add_settings_section(
			self::SECTION_ID,
			'',
			'__return_empty_string',
			self::PAGE_SLUG
		);

		add_settings_field(
			self::OPTION_NAME . '_host',
			__( 'Open WebUI URL', 'ai-provider-for-open-webui' ),
			array( $this, 'render_host_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID,
			array( 'label_for' => self::OPTION_NAME . '-host' )
		);

		add_settings_field(
			self::OPTION_NAME . '_api_key',
			__( 'API Key', 'ai-provider-for-open-webui' ),
			array( $this, 'render_api_key_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID,
			array( 'label_for' => self::OPTION_NAME . '-api-key' )
		);

		add_settings_field(
			self::OPTION_NAME . '_models',
			__( 'Model', 'ai-provider-for-open-webui' ),
			array( $this, 'render_model_field' ),
			self::PAGE_SLUG,
			self::SECTION_ID,
			array( 'label_for' => self::OPTION_NAME . '-model' )
		);
	}

	/**
	 * Registers the settings screen.
	 *
	 * @since 1.0.0
	 */
	public function register_settings_screen(): void {
		add_options_page(
			__( 'Open WebUI Settings', 'ai-provider-for-open-webui' ),
			__( 'Open WebUI', 'ai-provider-for-open-webui' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_screen' )
		);
	}

	/**
	 * Sanitizes the settings array.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The input value.
	 * @return array<string, string> The sanitized settings.
	 */
	public function sanitize_settings( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$host = isset( $value['host'] ) ? trim( (string) $value['host'] ) : '';
		if ( '' !== $host ) {
			$host = rtrim( esc_url_raw( $host ), '/' );
		}

		$api_key = isset( $value['api_key'] ) ? trim( (string) $value['api_key'] ) : '';
		if ( '' !== $api_key ) {
			$api_key = sanitize_text_field( $api_key );
		}

		$current_settings = (array) get_option( self::OPTION_NAME, array() );
		$selected_model   = '';
		if ( isset( $value['model'] ) ) {
			$selected_model = self::sanitize_model_value( $value['model'] );
		}

		// Backward compatibility: migrate previous capability-specific model fields.
		if ( '' === $selected_model ) {
			foreach ( array( 'model_text', 'model_image', 'model_vision' ) as $legacy_key ) {
				if ( isset( $value[ $legacy_key ] ) ) {
					$selected_model = self::sanitize_model_value( $value[ $legacy_key ] );
					if ( '' !== $selected_model ) {
						break;
					}
				}
			}
		}

		if ( '' === $selected_model ) {
			$selected_model = isset( $current_settings['model'] ) ? self::sanitize_model_value( $current_settings['model'] ) : '';
		}

		self::sync_connector_api_key( $api_key );

		return array(
			'host'    => $host,
			'api_key' => $api_key,
			'model'   => $selected_model,
		);
	}

	/**
	 * Sanitizes a model setting value.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $value Model value.
	 * @return string Sanitized model value.
	 */
	private static function sanitize_model_value( $value ): string {
		$model = trim( (string) $value );
		if ( '' === $model ) {
			return '';
		}

		return sanitize_text_field( $model );
	}

	/**
	 * Renders the settings screen.
	 *
	 * @since 1.0.0
	 */
	public function render_screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>

		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<div class="ai-experiments obenweb-openwebui-provider-settings">
				<form action="options.php" method="post" class="obenweb-openwebui-provider-settings__form">
					<?php settings_fields( self::OPTION_GROUP ); ?>

					<div class="ai-experiments__card obenweb-openwebui-provider-settings__card">
						<div class="ai-experiments__card-heading">
							<h2><?php esc_html_e( 'Connection Settings', 'ai-provider-for-open-webui' ); ?></h2>
							<p class="description">
								<?php
								printf(
									/* translators: 1: link to Connectors settings, 2: closing link tag */
									esc_html__( 'Configure your Open WebUI URL. The API key is synchronized with %1$sSettings > Connectors%2$s and can be created in Open WebUI under Settings > Account.', 'ai-provider-for-open-webui' ),
									'<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">',
									'</a>'
								);
								?>
							</p>
							<p class="description">
								<?php
								printf(
									/* translators: 1: code tag, 2: closing code tag */
									esc_html__( 'Default URL is %1$shttp://localhost:3000%2$s. The endpoint path %1$s/api%2$s is handled automatically by this plugin.', 'ai-provider-for-open-webui' ),
									'<code>',
									'</code>'
								);
								?>
							</p>
						</div>

						<div class="obenweb-openwebui-provider-settings__fields">
							<?php do_settings_sections( self::PAGE_SLUG ); ?>
						</div>

						<div class="obenweb-openwebui-provider-settings__actions">
							<?php submit_button(); ?>
						</div>
					</div>
				</form>
			</div>
		</div>

		<?php
	}

	/**
	 * Renders the host URL field.
	 *
	 * @since 1.0.0
	 */
	public function render_host_field(): void {
		$settings = self::get_settings();
		$value    = isset( $settings['host'] ) ? $settings['host'] : '';
		?>

		<input
			type="url"
			id="<?php echo esc_attr( self::OPTION_NAME . '-host' ); ?>"
			name="<?php echo esc_attr( self::OPTION_NAME . '[host]' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="http://localhost:3000"
		/>
		<p class="description">
			<?php
			printf(
				/* translators: 1: code tag, 2: closing code tag */
				esc_html__( 'Enter the Open WebUI base URL only (for example %1$shttp://localhost:3000%2$s). Do not append %1$s/api%2$s.', 'ai-provider-for-open-webui' ),
				'<code>',
				'</code>'
			);
			?>
		</p>

		<?php
	}

	/**
	 * Renders the API key field.
	 *
	 * @since 1.0.0
	 */
	public function render_api_key_field(): void {
		$settings = self::get_settings();
		$value    = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
		?>

		<input
			type="password"
			id="<?php echo esc_attr( self::OPTION_NAME . '-api-key' ); ?>"
			name="<?php echo esc_attr( self::OPTION_NAME . '[api_key]' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			autocomplete="off"
			placeholder="sk-..."
		/>
		<p class="description">
			<?php
			printf(
				/* translators: 1: link to Connectors settings, 2: closing link tag */
				esc_html__( 'Create the key in Open WebUI (Settings > Account). This field is synchronized with %1$sSettings > Connectors%2$s.', 'ai-provider-for-open-webui' ),
				'<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">',
				'</a>'
			);
			?>
		</p>

		<?php
	}

	/**
	 * Renders the model field.
	 *
	 * @since 1.1.0
	 */
	public function render_model_field(): void {
		$settings       = self::get_settings();
		$selected_model = isset( $settings['model'] ) ? (string) $settings['model'] : '';
		?>

		<div class="obenweb-openwebui-provider-settings__model-field">
			<p class="description">
				<?php esc_html_e( 'Choose one Open WebUI model to use as preferred model for text, image, and vision requests.', 'ai-provider-for-open-webui' ); ?>
			</p>

			<p>
				<label for="<?php echo esc_attr( self::OPTION_NAME . '-model' ); ?>"><strong><?php esc_html_e( 'Preferred model', 'ai-provider-for-open-webui' ); ?></strong></label><br />
				<input
					type="text"
					id="<?php echo esc_attr( self::OPTION_NAME . '-model' ); ?>"
					name="<?php echo esc_attr( self::OPTION_NAME . '[model]' ); ?>"
					class="regular-text"
					list="obenweb-openwebui-provider-model-suggestions-all"
					value="<?php echo esc_attr( $selected_model ); ?>"
					placeholder="<?php esc_attr_e( 'for example gpt-oss:20b', 'ai-provider-for-open-webui' ); ?>"
					autocomplete="off"
					spellcheck="false"
				/>
				<datalist id="obenweb-openwebui-provider-model-suggestions-all"></datalist>
				<span class="description"><?php esc_html_e( 'If empty, normal AI plugin model discovery applies.', 'ai-provider-for-open-webui' ); ?></span>
			</p>
		</div>

		<div id="openwebui-models-container">
			<span id="openwebui-model-status"></span>
		</div>

		<?php
	}

	/**
	 * Enqueues the settings page script.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_settings_script( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$plugin_dir = OBENWEB_OPENWEBUI_PROVIDER_PLUGIN_DIR;
		$asset_file = $plugin_dir . 'build/admin/settings.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(); // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Asset file path is built from a known constant.

		$dependencies = isset( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$version      = isset( $asset['version'] ) ? $asset['version'] : false;

		wp_enqueue_style(
			'obenweb-openwebui-provider-settings-page',
			plugins_url( 'build/admin/settings-page.css', OBENWEB_OPENWEBUI_PROVIDER_PLUGIN_FILE ),
			array(),
			$version
		);

		wp_enqueue_script(
			'obenweb-openwebui-provider-settings',
			plugins_url( 'build/admin/settings.js', OBENWEB_OPENWEBUI_PROVIDER_PLUGIN_FILE ),
			$dependencies,
			$version,
			true
		);

		wp_set_script_translations(
			'obenweb-openwebui-provider-settings',
			'ai-provider-for-open-webui'
		);

		wp_localize_script(
			'obenweb-openwebui-provider-settings',
			'obenwebOpenWebUIProviderSettings',
			array(
				'ajaxUrl'     => esc_url( admin_url( 'admin-ajax.php' ) . '?action=' . self::AJAX_ACTION . '&_wpnonce=' . wp_create_nonce( self::NONCE_ACTION ) ),
				'modelFields' => array(
					array(
						'fieldId'            => self::OPTION_NAME . '-model',
						'modelSuggestionsId' => 'obenweb-openwebui-provider-model-suggestions-all',
						'capability'         => 'any',
					),
				),
			)
		);
	}

	/**
	 * Handles the AJAX request to list available OpenWebUI models.
	 *
	 * @since 1.0.0
	 */
	public function ajax_list_models(): void {
		check_ajax_referer( self::NONCE_ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'ai-provider-for-open-webui' ), 403 );
		}

		if ( ! class_exists( AiClient::class ) ) {
			wp_send_json_error( __( 'WordPress AI Client is not available.', 'ai-provider-for-open-webui' ), 500 );
		}

		$provider_id = 'openwebui';
		$registry    = AiClient::defaultRegistry();

		if ( ! $registry->hasProvider( $provider_id ) ) {
			wp_send_json_error( __( 'AI provider not found.', 'ai-provider-for-open-webui' ), 404 );
		}

		$provider_classname = $registry->getProviderClassName( $provider_id );

		try {
			// phpcs:ignore Generic.Commenting.DocComment.MissingShort
			$model_metadata_directory = $provider_classname::modelMetadataDirectory();
			$model_metadata_objects   = $model_metadata_directory->listModelMetadata();

			wp_send_json_success( $model_metadata_objects );
		} catch ( \Throwable $e ) {
			/* translators: %s: Error message. */
			wp_send_json_error( sprintf( __( 'Could not list models from Open WebUI. Check URL/API key. Error: %s', 'ai-provider-for-open-webui' ), $e->getMessage() ), 500 );
		}
	}

	/**
	 * Gets the settings from the WordPress option.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> The settings.
	 */
	public static function get_settings(): array {
		$settings = (array) get_option( self::OPTION_NAME, array() );

		$host = '';
		if ( isset( $settings['host'] ) && '' !== (string) $settings['host'] ) {
			$host = trim( (string) $settings['host'] );
		}

		$legacy_api_key = '';
		if ( isset( $settings['api_key'] ) && '' !== (string) $settings['api_key'] ) {
			$legacy_api_key = trim( (string) $settings['api_key'] );
		}

		$selected_model = '';
		if ( isset( $settings['model'] ) && '' !== (string) $settings['model'] ) {
			$selected_model = trim( (string) $settings['model'] );
		}

		// Backward compatibility: use previous capability-specific model values if needed.
		if ( '' === $selected_model ) {
			foreach ( array( 'model_text', 'model_image', 'model_vision' ) as $legacy_key ) {
				if ( isset( $settings[ $legacy_key ] ) && '' !== (string) $settings[ $legacy_key ] ) {
					$selected_model = trim( (string) $settings[ $legacy_key ] );
					break;
				}
			}
		}

		$connector_api_key = self::get_connector_api_key();
		if ( '' === $connector_api_key && '' !== $legacy_api_key ) {
			self::sync_connector_api_key( $legacy_api_key );
			$connector_api_key = $legacy_api_key;
		}

		return array(
			'host'    => $host,
			'api_key' => '' !== $connector_api_key ? $connector_api_key : $legacy_api_key,
			'model'   => $selected_model,
		);
	}

	/**
	 * Gets the Open WebUI API key from the connector option.
	 *
	 * @since 1.0.0
	 *
	 * @return string The API key or an empty string.
	 */
	private static function get_connector_api_key(): string {
		$value = get_option( self::CONNECTOR_API_KEY_OPTION, '' );
		if ( ! is_string( $value ) ) {
			return '';
		}

		return trim( $value );
	}

	/**
	 * Synchronizes the plugin API key with the Connector API key option.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key The sanitized API key.
	 */
	private static function sync_connector_api_key( string $api_key ): void {
		$current_connector_key = self::get_connector_api_key();
		if ( $current_connector_key === $api_key ) {
			return;
		}

		update_option( self::CONNECTOR_API_KEY_OPTION, $api_key );
	}
}
