<?php
/**
 * Publish to Apple News: Admin_Apple_JSON class
 *
 * @package Apple_News
 */

use Apple_Exporter\Components\Component;

/**
 * This class is in charge of handling the management of custom JSON.
 */
class Admin_Apple_JSON extends Apple_News {

	/**
	 * JSON management page name.
	 *
	 * @var string
	 * @access public
	 */
	public $json_page_name;

	/**
	 * Namespace for component classes.
	 *
	 * @var string
	 * @access public
	 */
	private $namespace = '\\Apple_Exporter\\Components\\';

	/**
	 * Holds the selected component from the request.
	 *
	 * @since 1.4.0
	 * @var string
	 * @access private
	 */
	private $selected_component = '';

	/**
	 * Holds the selected subcomponent from the request.
	 *
	 * @since 2.5.0
	 * @var string
	 */
	private $selected_subcomponent = '';

	/**
	 * Holds the selected theme from the request.
	 *
	 * @since 1.4.0
	 * @var string
	 * @access private
	 */
	private $selected_theme = '';

	/**
	 * Valid actions handled by this class and their callback functions.
	 *
	 * @var array
	 * @access private
	 */
	private $valid_actions;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->json_page_name = $this->plugin_domain . '-json';

		$this->valid_actions = [
			'apple_news_get_json'   => [],
			'apple_news_reset_json' => [
				'callback' => [ $this, 'reset_json' ],
			],
			'apple_news_save_json'  => [
				'callback' => [ $this, 'save_json' ],
			],
		];

		add_action( 'admin_menu', [ $this, 'setup_json_page' ], 99 );
		add_action( 'admin_init', [ $this, 'action_router' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'register_assets' ] );
		add_filter( 'admin_title', [ $this, 'set_title' ], 10, 2 );
	}

	/**
	 * Route all possible theme actions to the right place.
	 *
	 * @access public
	 */
	public function action_router() {

		// Check for a valid action.
		$action = isset( $_REQUEST['apple_news_action'] )
			? sanitize_text_field( wp_unslash( $_REQUEST['apple_news_action'] ) )
			: null;
		if ( ( empty( $action ) || ! array_key_exists( $action, $this->valid_actions ) ) ) {
			return;
		}

		// Check the nonce.
		check_admin_referer( 'apple_news_json' );

		// Store the selected component value for use later.
		$this->selected_component = isset( $_POST['apple_news_component'] )
			? sanitize_text_field( wp_unslash( $_POST['apple_news_component'] ) )
			: '';
		if ( ! array_key_exists( $this->selected_component, $this->list_components() ) ) {
			$this->selected_component = '';
		}

		// Store the selected subcomponent value for use later.
		$this->selected_subcomponent = isset( $_POST['apple_news_subcomponent'] )
			? sanitize_text_field( wp_unslash( $_POST['apple_news_subcomponent'] ) )
			: '';
		if ( ! array_key_exists( $this->selected_subcomponent, $this->list_components() ) ) {
			$this->selected_subcomponent = '';
		}

		// Store the selected theme for use later.
		if ( ! empty( $_POST['apple_news_theme'] ) ) {
			$this->selected_theme = sanitize_text_field( wp_unslash( $_POST['apple_news_theme'] ) );
		}

		// Call the callback for the action for further processing.
		if ( isset( $this->valid_actions[ $action ]['callback'] )
			&& is_callable( $this->valid_actions[ $action ]['callback'] )
		) {
			call_user_func( $this->valid_actions[ $action ]['callback'] );
		}
	}

	/**
	 * Fix the title since WordPress doesn't set one.
	 *
	 * @param string $admin_title The title to be filtered.
	 * @return string
	 * @access public
	 */
	public function set_title( $admin_title ) {
		$screen = get_current_screen();
		if ( 'admin_page_' . $this->json_page_name === $screen->base ) {
			$admin_title = sprintf(
				// translators: token is the admin page title.
				__( 'Customize JSON %s', 'apple-news' ),
				trim( $admin_title )
			);
		}

		return $admin_title;
	}

	/**
	 * Options page setup.
	 *
	 * @access public
	 */
	public function setup_json_page() {

		// Don't add the submenu page if the settings aren't initialized.
		if ( ! self::is_initialized() ) {
			return;
		}

		add_submenu_page(
			'apple_news_index',
			__( 'Customize Apple News JSON', 'apple-news' ),
			__( 'Customize JSON', 'apple-news' ),
			/** This filter is documented in admin/class-admin-apple-settings.php */
			apply_filters( 'apple_news_settings_capability', 'manage_options' ),
			$this->json_page_name,
			[ $this, 'page_json_render' ]
		);
	}

	/**
	 * JSON page render.
	 *
	 * @access public
	 */
	public function page_json_render() {
		/** This filter is documented in admin/class-admin-apple-settings.php */
		if ( ! current_user_can( apply_filters( 'apple_news_settings_capability', 'manage_options' ) ) ) {
			wp_die( esc_html__( 'You do not have permissions to access this page.', 'apple-news' ) );
		}

		/* phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable */

		// Get components for the dropdown.
		$components = $this->list_components();

		// Get theme info for reference purposes.
		$themes          = new Admin_Apple_Themes();
		$theme_admin_url = $themes->theme_admin_url();
		$all_themes      = \Apple_Exporter\Theme::get_registry();

		// Negotiate selected theme.
		$selected_theme = $this->get_selected_theme();

		// Check if there is a valid selected component.
		$selected_component = ( ! empty( $selected_theme ) )
			? $this->get_selected_component()
			: '';

		// Handle subcomponents.
		$component_can_be_parent = $this->get_component_class( $selected_component )?->can_be_parent();
		$selected_subcomponent   = ( ! empty( $selected_component ) )
			? $this->get_selected_subcomponent()
			: '';

		// If we have a component or subcomponent, get its specs.
		$specs = $this->get_specs( $selected_component, $selected_subcomponent );

		/* phpcs:enable */

		// Load the template.
		include __DIR__ . '/partials/page-json.php';
	}

	/**
	 * Register assets for the options page.
	 *
	 * @param string $hook The hook context.
	 * @access public
	 */
	public function register_assets( $hook ) {
		if ( 'apple-news_page_apple-news-json' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'apple-news-json-css',
			plugin_dir_url( __FILE__ ) . '../assets/css/json.css',
			[],
			self::$version
		);

		wp_enqueue_script(
			'apple-news-json-js',
			plugin_dir_url( __FILE__ ) . '../assets/js/json.js',
			[ 'jquery' ],
			self::$version,
			false
		);

		wp_enqueue_script(
			'ace-js',
			'https://cdnjs.cloudflare.com/ajax/libs/ace/1.2.6/ace.js',
			[ 'jquery' ],
			'1.2.6',
			false
		);
	}

	/**
	 * Resets the JSON snippets for the component.
	 *
	 * @access private
	 */
	private function reset_json() {

		// Check the nonce.
		check_admin_referer( 'apple_news_json' );

		// Ensure a theme was selected.
		if ( empty( $_POST['apple_news_theme'] ) ) {
			\Admin_Apple_Notice::error(
				__( 'Unable to reset JSON since no theme was selected.', 'apple-news' )
			);

			return;
		}

		// Get the selected component.
		$component = $this->get_selected_component();
		if ( empty( $component ) ) {
			\Admin_Apple_Notice::error(
				__( 'Unable to reset JSON since no component was provided', 'apple-news' )
			);

			return;
		}

		// Get the selected subcomponent.
		$subcomponent = $this->get_selected_subcomponent();

		// Get the specs for the component.
		$specs = $this->get_specs( $component, $subcomponent );
		if ( empty( $specs ) ) {
			\Admin_Apple_Notice::error(
				sprintf(
					// translators: token is component name.
					__( 'The component %s has no specs and cannot be reset', 'apple-news' ),
					$component
				)
			);

			return;
		}

		// Iterate over the specs and reset each one.
		foreach ( $specs as $spec ) {
			$spec->delete( $this->selected_theme );
		}

		\Admin_Apple_Notice::success(
			sprintf(
				// translators: token is component name.
				__( 'Reset the custom specs for %s.', 'apple-news' ),
				$component
			)
		);
	}

	/**
	 * Saves the JSON snippets for the component
	 *
	 * @access private
	 */
	private function save_json() {

		// Check the nonce.
		check_admin_referer( 'apple_news_json' );

		// Ensure a theme was selected.
		if ( empty( $_POST['apple_news_theme'] ) ) {
			\Admin_Apple_Notice::error(
				__( 'Unable to save JSON since no theme was selected.', 'apple-news' )
			);

			return;
		}

		// Get the selected component.
		$component = $this->get_selected_component();
		if ( empty( $component ) ) {
			\Admin_Apple_Notice::error(
				__( 'Unable to save JSON since no component was provided', 'apple-news' )
			);

			return;
		}

		// Get the selected subcomponent.
		$subcomponent = $this->get_selected_subcomponent();

		// Get the specs for the component or subcomponent.
		$theme = sanitize_text_field( wp_unslash( $_POST['apple_news_theme'] ) );
		$specs = $this->get_specs( $component, $subcomponent );
		if ( empty( $specs ) ) {
			\Admin_Apple_Notice::error(
				sprintf(
					// translators: token is component name.
					__( 'The component %s has no specs and cannot be saved', 'apple-news' ),
					$component
				)
			);

			return;
		}

		// Iterate over the specs and save each one.
		// Keep track of which ones were updated.
		$updates = [];
		foreach ( $specs as $spec ) {
			// Ensure the value exists.
			$key = 'apple_news_json_' . $spec->key_from_name( $spec->name );
			if ( isset( $_POST[ $key ] ) ) {
				if ( true === $spec->save( wp_unslash( $_POST[ $key ] ), $theme ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$updates[] = $spec->label;
				}
			}
		}

		if ( empty( $updates ) ) {
			\Admin_Apple_Notice::info(
				sprintf(
					// translators: token is component name.
					__( 'No spec updates were found for %s', 'apple-news' ),
					$component
				)
			);
		} else {
			\Admin_Apple_Notice::success(
				sprintf(
					// translators: first token is the component name, second is spec names.
					__( 'Saved the following custom specs for %1$s: %2$s', 'apple-news' ),
					$component,
					implode( ', ', $updates )
				)
			);
		}
	}

	/**
	 * Loads the JSON specs that can be customized for the component
	 *
	 * @param string  $component    The component to get specs for.
	 * @param ?string $subcomponent The subcomponent to get specs for.
	 *
	 * @return array
	 */
	private function get_specs( $component, $subcomponent = null ) {
		$component_class = $this->get_component_class( $component, $subcomponent );
		return $component_class ? $component_class->get_specs() : [];
	}

	/**
	 * Given a component slug, returns the associated component class.
	 *
	 * @param string  $component    The component to get the class for.
	 * @param ?string $subcomponent The subcomponent to get the class for.
	 *
	 * @return ?Component
	 */
	private function get_component_class( $component, $subcomponent = null ) {
		$classname = $this->namespace . $component;
		if ( ! class_exists( $classname ) ) {
			return null;
		}

		// Handle subcomponents.
		if ( ! empty( $subcomponent ) ) {
			$subcomponent_classname = $this->namespace . $subcomponent;
			return class_exists( $subcomponent_classname ) ? new $subcomponent_classname( null, null, null, null, null, null, null, new $classname() ) : null;
		}

		return new $classname();
	}

	/**
	 * Lists all components that can be customized
	 *
	 * @return array
	 * @access private
	 */
	private function list_components() {
		$component_factory = new \Apple_Exporter\Component_Factory();
		$component_factory->initialize();
		$components = $component_factory::get_components();

		// Make this alphabetized and pretty.
		$components_sanitized = [];
		foreach ( $components as $component ) {
			$component_key                          = str_replace( $this->namespace, '', $component );
			$component_name                         = str_replace( '_', ' ', $component_key );
			$components_sanitized[ $component_key ] = $component_name;
		}
		ksort( $components_sanitized );
		return $components_sanitized;
	}

	/**
	 * Checks for a valid selected component
	 *
	 * @return string
	 * @access public
	 */
	public function get_selected_component() {
		return $this->selected_component;
	}

	/**
	 * Gets the currently selected subcomponent.
	 *
	 * @return string
	 */
	public function get_selected_subcomponent() {
		return $this->selected_subcomponent;
	}

	/**
	 * Checks for a valid selected theme.
	 *
	 * @since 1.4.0
	 * @access public
	 * @return string
	 */
	public function get_selected_theme() {

		// First, check for a theme loaded in from postdata.
		if ( ! empty( $this->selected_theme ) ) {
			return $this->selected_theme;
		}

		// Next, check for a theme loaded in from the query string.
		if ( ! empty( $_GET['theme'] ) ) { // phpcs:ignore WordPress.VIP.SuperGlobalInputUsage.AccessDetected, WordPress.Security.NonceVerification.Recommended
			return sanitize_text_field( wp_unslash( $_GET['theme'] ) ); // phpcs:ignore WordPress.VIP.SuperGlobalInputUsage.AccessDetected, WordPress.Security.NonceVerification.Recommended
		}

		return '';
	}

	/**
	 * Returns the URL of the JSON admin page
	 *
	 * @return string
	 * @access public
	 */
	public function json_admin_url() {
		return add_query_arg( 'page', $this->json_page_name, admin_url( 'admin.php' ) );
	}
}
