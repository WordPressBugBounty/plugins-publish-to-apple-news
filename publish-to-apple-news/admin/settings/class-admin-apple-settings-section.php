<?php
/**
 * Publish to Apple News: Admin_Apple_Settings_Section class
 *
 * @package Apple_News
 */

use Apple_Exporter\Settings;

/**
 * Describes a WordPress setting section
 *
 * @since 0.6.0
 */
class Admin_Apple_Settings_Section extends Apple_News {

	/**
	 * Name of the settings section.
	 *
	 * @var string
	 * @access protected
	 */
	protected $name;

	/**
	 * Slug of the settings section.
	 *
	 * @var string
	 * @access protected
	 */
	protected $slug;

	/**
	 * Settings page.
	 *
	 * @var string
	 * @access protected
	 */
	protected $page;

	/**
	 * Allow for a settings section to be hidden.
	 *
	 * @var boolean
	 * @access protected
	 */
	protected $hidden = false;

	/**
	 * Option name used for the section.
	 *
	 * @var string
	 * @access protected
	 */
	protected static $section_option_name;

	/**
	 * Action used for saving the section.
	 *
	 * @var string
	 * @access protected
	 */
	protected $save_action;

	/**
	 * Base settings.
	 *
	 * @var Settings
	 * @access protected
	 */
	protected static $base_settings;

	/**
	 * Loaded settings.
	 *
	 * @var Settings
	 * @access protected
	 */
	protected static $loaded_settings;

	/**
	 * Settings for the section.
	 *
	 * @var array
	 * @access protected
	 */
	protected $settings = [];

	/**
	 * Groups for the section.
	 *
	 * @var array
	 * @access protected
	 */
	protected $groups = [];

	/**
	 * Allowed HTML for settings pages.
	 *
	 * @var array
	 * @access public
	 */
	public static $allowed_html = [
		'select'   => [
			'class'    => [],
			'name'     => [],
			'multiple' => [],
			'id'       => [],
			'size'     => [],
		],
		'textarea' => [
			'class' => [],
			'name'  => [],
			'id'    => [],
		],
		'option'   => [
			'value'    => [],
			'selected' => [],
		],
		'input'    => [
			'class'       => [],
			'name'        => [],
			'value'       => [],
			'placeholder' => [],
			'min'         => [],
			'max'         => [],
			'step'        => [],
			'type'        => [],
			'multiple'    => [],
			'required'    => [],
			'size'        => [],
			'id'          => [],
		],
		'br'       => [],
		'b'        => [],
		'strong'   => [],
		'i'        => [],
		'code'     => [],
		'em'       => [],
		'a'        => [
			'href'   => [],
			'target' => [],
		],
		'div'      => [
			'class' => [],
		],
		'h1'       => [
			'class' => [],
		],
		'h2'       => [
			'class' => [],
		],
		'h3'       => [
			'class' => [],
		],
		'h4'       => [
			'class' => [],
		],
		'h5'       => [
			'class' => [],
		],
		'h6'       => [
			'class' => [],
		],
	];

	/**
	 * Constructor.
	 *
	 * @param string  $page                The name of the page.
	 * @param boolean $hidden              Whether this section is hidden.
	 * @param string  $save_action         Action used for saving the section.
	 * @param string  $section_option_name Option name used for the section.
	 * @access public
	 */
	public function __construct( $page, $hidden = false, $save_action = 'apple_news_options', $section_option_name = null ) {
		$this->page                = $page;
		self::$section_option_name = ( ! empty( $section_option_name ) ) ? $section_option_name : self::$option_name;
		$this->save_action         = $save_action;
		$base_settings             = new \Apple_Exporter\Settings();
		self::$base_settings       = $base_settings->all();
		self::$loaded_settings     = get_option( self::$section_option_name );

		/**
		 * Modifies the setting values for the settings page.
		 *
		 * @param array  $settings An array of settings for this section.
		 * @param string $page     The name of the settings page.
		 */
		$this->settings = apply_filters( 'apple_news_section_settings', $this->settings, $page );

		/**
		 * Modifies the groups for the settings page.
		 *
		 * This could be used to add or remove a group or reorder them.
		 *
		 * @param array  $groups An array of groups for this section.
		 * @param string $page   The name of the settings page.
		 */
		$this->groups = apply_filters( 'apple_news_section_groups', $this->groups, $page );

		$this->hidden = $hidden;

		// Save settings if necessary.
		$this->save_settings();
	}

	/**
	 * Get the settings section name.
	 *
	 * @access public
	 * @return string The name of this settings section.
	 */
	public function name() {
		return $this->name;
	}

	/**
	 * Return an array which contains all groups and their related settings,
	 * embedded.
	 *
	 * @access public
	 * @return array An array of all groups and their related settings.
	 */
	public function groups() {
		$result = [];
		foreach ( $this->groups as $name => $info ) {
			$settings = [];
			foreach ( $info['settings'] as $name ) {
				$settings[ $name ]             = $this->settings[ $name ];
				$settings[ $name ]['default']  = self::get_default_for( $name );
				$settings[ $name ]['callback'] = ( ! empty( $this->settings[ $name ]['callback'] ) ) ? $this->settings[ $name ]['callback'] : '';
			}

			$result[ $name ] = [
				'label'       => $info['label'],
				'description' => empty( $info['description'] ) ? null : $info['description'],
				'settings'    => $settings,
			];
		}

		return $result;
	}

	/**
	 * Get the ID of the settings section.
	 *
	 * @access public
	 * @return string The namespaced ID of the settings section.
	 */
	public function id() {
		return $this->plugin_slug . '_options_section_' . $this->slug;
	}

	/**
	 * Render a settings field.
	 *
	 * @param array $args Arguments for the field to be rendered.
	 * @access public
	 * @return mixed The result of the callback, if provided.
	 */
	public function render_field( $args ) {
		list( $name, , $callback ) = $args;

		$type = $this->get_type_for( $name );

		// If the field has its own render callback, use that here.
		// This is because the options page doesn't actually use do_settings_section.
		if ( ! empty( $callback ) ) {
			return call_user_func( $callback, $type );
		}

		$value = self::get_value( $name, self::$loaded_settings );
		$field = null;

		// Get the field size.
		$size = $this->get_size_for( $name );

		/**
		 * TODO: A cleaner object-oriented solution would create Input objects
		 * and instantiate them according to their type.
		 */
		if ( is_array( $type ) ) {
			// Check if this is a multiple select.
			$multiple_name = '';
			$multiple_attr = '';
			if ( $this->is_multiple( $name ) ) {
				$multiple_name = '[]';
				$multiple_attr = 'multiple="multiple"';
				$size          = min( $size, count( $type ) );
			} else {
				$size = 1;
			}

			// Check if we're using names as values.
			$keys              = array_keys( $type );
			$use_name_as_value = ( array_keys( $keys ) === $keys );

			// Use select2 only when there is a considerable amount of options available.
			if ( count( $type ) > 10 ) {
				$field = '<select class="select2 standard" id="%s" name="%s' . $multiple_name . '" ' . $multiple_attr . ' size="%s">';
			} else {
				$field = '<select id="%s" name="%s' . $multiple_name . '" ' . $multiple_attr . ' size="%s">';
			}

			foreach ( $type as $key => $option ) {
				$store_value = $use_name_as_value ? $option : $key;
				$field      .= "<option value='" . esc_attr( $store_value ) . "' ";
				if ( $this->is_multiple( $name ) ) {
					if ( in_array( $store_value, $value, true ) ) {
						$field .= 'selected="selected"';
					}
				} else {
					$field .= selected( $value, $store_value, false );
				}
				$field .= '>' . esc_html( $option ) . '</option>';
			}
			$field .= '</select>';
		} elseif ( 'password' === $type ) {
			$field = '<input type="password" id="%s" name="%s" value="%s" size="%s" %s>';
		} elseif ( 'hidden' === $type ) {
			$field = '<input type="hidden" id="%s" name="%s" value="%s">';
		} elseif ( 'file' === $type ) {
			$field = '<input type="file" id="%s" name="%s">';
		} elseif ( 'textarea' === $type ) {
			$field = '<textarea id="%s" name="%s">%s</textarea>';
		} elseif ( 'number' === $type ) {
			$field = '<input type="number" id="%s" name="%s" value="%s" size="%s" min="%s" max="%s" step="%s" %s>';
		} elseif ( 'email' === $type ) {
			$field = '<input type="email" id="%s" name="%s" value="%s" size="%s"';

			if ( $this->is_multiple( $name ) ) {
				$field .= ' multiple %s>';
			} else {
				$field .= ' %s>';
			}
		} else {
			// If nothing else matches, it's a string.
			$field = '<input type="text" id="%s" name="%s" value="%s" size="%s" %s>';
		}

		// Add a description, if set.
		$description = $this->get_description_for( $name );
		if ( ! empty( $description ) && 'hidden' !== $type ) {
			/**
			 * Modifies the HTML output for the description of any field.
			 *
			 * @param string $html The HTML for the field description.
			 * @param string $name The name of the field.
			 */
			$field .= apply_filters( 'apple_news_field_description_output_html', '<br/><i>' . $description . '</i>', $name );
		}

		// Use the proper template to build the field.
		if ( is_array( $type ) ) {
			return sprintf(
				$field,
				esc_attr( $name ),
				esc_attr( $name ),
				intval( $size )
			);
		} elseif ( 'hidden' === $type ) {
			return sprintf(
				$field,
				esc_attr( $name ),
				esc_attr( $name ),
				esc_attr( $value )
			);
		} elseif ( 'file' === $type ) {
			return sprintf(
				$field,
				esc_attr( $name ),
				esc_attr( $name )
			);
		} elseif ( 'textarea' === $type ) {
			return sprintf(
				$field,
				esc_attr( $name ),
				esc_attr( $name ),
				esc_attr( $value )
			);
		} elseif ( 'number' === $type ) {
			return sprintf(
				$field,
				esc_attr( $name ),
				esc_attr( $name ),
				esc_attr( $value ),
				5,
				esc_attr( $this->get_min_for( $name ) ),
				esc_attr( $this->get_max_for( $name ) ),
				esc_attr( $this->get_step_for( $name ) ),
				esc_attr( $this->is_required( $name ) )
			);
		} else {
			return sprintf(
				$field,
				esc_attr( $name ),
				esc_attr( $name ),
				esc_attr( $value ),
				intval( $size ),
				esc_attr( $this->is_required( $name ) )
			);
		}
	}

	/**
	 * Get the type for a field.
	 *
	 * @param string $name The name of the field for which to fetch a type.
	 * @access protected
	 * @return string The type of the field.
	 */
	protected function get_type_for( $name ) {
		if ( $this->hidden ) {
			return 'hidden';
		}

		return empty( $this->settings[ $name ]['type'] ) ? 'string' : $this->settings[ $name ]['type'];
	}

	/**
	 * Get the description for a field.
	 *
	 * @param string $name The name of the field for which to fetch a description.
	 * @access protected
	 * @return string The description for the field.
	 */
	protected function get_description_for( $name ) {
		return empty( $this->settings[ $name ]['description'] ) ? '' : $this->settings[ $name ]['description'];
	}

	/**
	 * Get the maximum value for a field.
	 *
	 * @param string $name The name of the field for which to fetch a maximum value.
	 *
	 * @return int|float|string The maximum value, or an empty string if not set.
	 */
	protected function get_max_for( $name ) {
		return isset( $this->settings[ $name ]['max'] ) && ( is_int( $this->settings[ $name ]['max'] ) || is_float( $this->settings[ $name ]['max'] ) )
			? $this->settings[ $name ]['max']
			: '';
	}

	/**
	 * Get the minimum value for a field.
	 *
	 * @param string $name The name of the field for which to fetch a minimum value.
	 *
	 * @return int|float|string The minimum value, or an empty string if not set.
	 */
	protected function get_min_for( $name ) {
		return isset( $this->settings[ $name ]['min'] ) && ( is_int( $this->settings[ $name ]['min'] ) || is_float( $this->settings[ $name ]['min'] ) )
			? $this->settings[ $name ]['min']
			: '';
	}

	/**
	 * Get the stepping value for a field.
	 *
	 * @param string $name The name of the field for which to fetch a stepping value.
	 *
	 * @return int|float The stepping value, or a default value of 1 if not set.
	 */
	protected function get_step_for( $name ) {
		return isset( $this->settings[ $name ]['step'] ) && ( is_int( $this->settings[ $name ]['step'] ) || is_float( $this->settings[ $name ]['step'] ) )
			? $this->settings[ $name ]['step']
			: 1;
	}

	/**
	 * Get the size for a field.
	 *
	 * @param string $name The name of the field for which to fetch a size.
	 * @access protected
	 * @return int The size of the field.
	 */
	protected function get_size_for( $name ) {
		return empty( $this->settings[ $name ]['size'] ) ? 20 : $this->settings[ $name ]['size'];
	}

	/**
	 * Check if a field is required.
	 *
	 * @param string $name The name of the field for which to fetch the required flag.
	 * @access protected
	 * @return string The string 'required' if required, or a blank string otherwise.
	 */
	protected function is_required( $name ) {
		$required = ! isset( $this->settings[ $name ]['required'] ) ? true : $this->settings[ $name ]['required'];
		return ( $required ) ? 'required' : '';
	}

	/**
	 * Check if the field can hold multiple values.
	 *
	 * @param string $name The name of the field for which the multiple check should be performed.
	 * @access protected
	 * @return boolean True if the field can hold multiple values, false otherwise.
	 */
	protected function is_multiple( $name ) {
		return ! empty( $this->settings[ $name ]['multiple'] );
	}

	/**
	 * Get the default for a field.
	 *
	 * @param string $name The name of the field for which to fetch the default.
	 * @access protected
	 * @return string The default for the field.
	 */
	protected static function get_default_for( $name ) {
		return isset( self::$base_settings[ $name ] ) ? self::$base_settings[ $name ] : '';
	}

	/**
	 * Gets section info.
	 *
	 * Intended to be overridden by child classes.
	 *
	 * @access public
	 * @return string The section info to be displayed.
	 */
	public function get_section_info() {
		return '';
	}

	/**
	 * HTML to display before the section.
	 *
	 * Intended to be overridden by child classes.
	 *
	 * @access public
	 */
	public function before_section() {
		echo '';
	}

	/**
	 * HTML to display after the section.
	 *
	 * Intended to be overridden by child classes.
	 *
	 * @access public
	 */
	public function after_section() {
		echo '';
	}

	/**
	 * Get settings.
	 *
	 * @access public
	 * @return array The settings array.
	 */
	public function get_settings() {
		return $this->settings;
	}

	/**
	 * Get loaded settings.
	 *
	 * @access public
	 * @return array The loaded settings array.
	 */
	public function get_loaded_settings() {
		return $this->loaded_settings;
	}

	/**
	 * Check if the section is hidden on the settings page.
	 *
	 * @access public
	 * @return boolean Whether this section is hidden.
	 */
	public function is_hidden() {
		return $this->hidden;
	}

	/**
	 * Sanitizes a single dimension array with text values.
	 *
	 * @param array $value The array value to be sanitized.
	 * @access public
	 * @return array The sanitized array.
	 */
	public function sanitize_array( $value ) {
		return array_map( 'sanitize_text_field', $value );
	}

	/**
	 * Get the current value for an option.
	 *
	 * @param string $key            The key to look up.
	 * @param array  $saved_settings Optional. The settings to use. Defaults to null, which forces a lookup.
	 * @access public
	 * @return mixed The value for the option.
	 */
	public static function get_value( $key, $saved_settings = null ) {
		if ( empty( $saved_settings ) ) {
			$saved_settings = get_option( self::$section_option_name );
		}
		return ( isset( $saved_settings[ $key ] ) ) ? $saved_settings[ $key ] : self::get_default_for( $key );
	}

	/**
	 * Each section is responsible for saving its own settings
	 * since only it knows the nature of the fields and sanitization methods.
	 *
	 * @access public
	 */
	public function save_settings() {

		// Check if we're saving options and that there are settings to save.
		$action = isset( $_REQUEST['action'] )
			? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) )
			: null;
		if ( empty( $action ) || $this->save_action !== $action || empty( $this->settings ) ) {
			return;
		}

		// Form nonce check.
		check_admin_referer( $this->save_action );

		// Get the current Apple News settings.
		$settings = get_option( self::$section_option_name, [] );

		/**
		 * Iterate over the settings and save each value.
		 * Settings can't be empty unless allowed, so if no value is found
		 * use the default value to be safe.
		 */
		$default_settings = new Settings();
		foreach ( $this->settings as $key => $attributes ) {

			// Negotiate the value.
			$value = $default_settings->$key;
			if ( isset( $_POST[ $key ] ) ) {
				$sanitize        = ( empty( $attributes['sanitize'] ) || ! is_callable( $attributes['sanitize'] ) ) ? 'sanitize_text_field' : $attributes['sanitize'];
				$sanitized_value = call_user_func( $sanitize, wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( ! empty( $sanitized_value ) || in_array( $sanitized_value, [ 0, '0' ], true ) ) {
					$value = $sanitized_value;
				}
			}

			// Add to the array.
			$settings[ $key ] = $value;
		}

		// Clear certain caches.
		delete_transient( 'apple_news_channel' );
		delete_transient( 'apple_news_sections' );

		// Save to options.
		update_option( self::$section_option_name, $settings, 'no' );

		/**
		 * Update the cached settings with new one after an update.
		 *
		 * The `self::get_value` method uses this cached data. By resetting it, we ensure
		 * that the new value is used after an update instead of the old value.
		 */
		self::$loaded_settings = $settings;
	}
}
