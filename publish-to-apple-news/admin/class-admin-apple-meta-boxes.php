<?php
/**
 * Publish to Apple News: Admin_Apple_Meta_Boxes class
 *
 * @package Apple_News
 */

use Apple_News\Admin\Automation;

/**
 * This class provides a meta box to publish posts to Apple News from the edit screen.
 *
 * @since 0.9.0
 */
class Admin_Apple_Meta_Boxes extends Apple_News {

	/**
	 * Publish action.
	 *
	 * @since 0.9.0
	 * @var array
	 */
	const PUBLISH_ACTION = 'apple_news_publish';

	/**
	 * Current settings.
	 *
	 * @since 0.9.0
	 * @var array
	 * @access private
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param \Apple_Exporter\Settings $settings Optional. Settings to use during this run. Defaults to null.
	 * @access public
	 */
	public function __construct( $settings = null ) {
		parent::__construct();
		$this->settings = $settings;

		// Register hooks if enabled.
		if ( 'yes' === $settings->get( 'show_metabox' ) ) {
			// Add the custom meta boxes to each post type.
			$post_types = $settings->get( 'post_types' );
			if ( ! is_array( $post_types ) ) {
				$post_types = [ $post_types ];
			}

			foreach ( $post_types as $post_type ) {
				add_action( 'add_meta_boxes_' . $post_type, [ $this, 'add_meta_boxes' ] );
				add_action( 'save_post_' . $post_type, [ $this, 'do_publish' ], 10, 2 );
			}

			// Register assets used by the meta box.
			add_action( 'admin_enqueue_scripts', [ $this, 'register_assets' ] );

			// Refresh the nonce after the user re-authenticates due to a wp_auth_check() to avoid failing check_admin_referrer().
			add_action( 'wp_refresh_nonces', [ $this, 'refresh_nonce' ], 20, 1 );
		}
	}

	/**
	 * Check for a publish action from the meta box.
	 *
	 * @since 0.9.0
	 * @param int      $post_id The ID of the post being published.
	 * @param \WP_Post $post    The post object being published.
	 * @access public
	 */
	public function do_publish( $post_id, $post ) {

		// Check the post ID.
		$post_id = isset( $_REQUEST['post_ID'] )
			? absint( $_REQUEST['post_ID'] )
			: 0;
		if ( empty( $post_id ) ) {
			return;
		}

		// Avoid problems when the metabox doesn't load, e.g., quick edit.
		if ( empty( $_REQUEST['apple_news_nonce'] ) ) {
			return;
		}

		// Verify the post types.
		$post_types = $this->settings->get( 'post_types' );
		if ( ! is_array( $post_types ) ) {
			$post_types = [ $post_types ];
		}

		if ( ! empty( $post_types ) && ! in_array( get_post_type( $post ), $post_types, true ) ) {
			return;
		}

		// Check the nonce if we're not in testing mode.
		if ( ! defined( 'MANTLE_IS_TESTING' ) || ! MANTLE_IS_TESTING ) {
			check_admin_referer( self::PUBLISH_ACTION, 'apple_news_nonce' );
		}

		// Save meta box fields.
		self::save_post_meta( $post_id );

		// If this is set to autosync or no action is set, we're done here.
		if ( 'yes' === $this->settings->get( 'api_autosync' )
			|| 'publish' !== $post->post_status
			|| empty( $_POST['apple_news_publish_action'] )
			|| self::PUBLISH_ACTION !== $_POST['apple_news_publish_action'] ) {
			return;
		}

		// Proceed with the push.
		$action = new Apple_Actions\Index\Push( $this->settings, $post_id );
		try {
			$action->perform();

			// In async mode, success or failure will be displayed later.
			if ( 'yes' !== $this->settings->get( 'api_async' ) ) {
				Admin_Apple_Notice::success( __( 'Your article has been pushed successfully to Apple News!', 'apple-news' ) );
			} else {
				Admin_Apple_Notice::success( __( 'Your article will be pushed shortly to Apple News.', 'apple-news' ) );
			}
		} catch ( Apple_Actions\Action_Exception $e ) {
			Admin_Apple_Notice::error( $e->getMessage() );
		}
	}

	/**
	 * Given a $_POST key, safely extract metadata values and sanitize them.
	 *
	 * @param string $key The $_POST key to process.
	 *
	 * @return array An array of sanitized values.
	 */
	private static function sanitize_metadata_array( $key ) {
		// Nonce verification happens in save_post_meta, which calls this function.
		/* phpcs:disable WordPress.Security.NonceVerification.Missing */

		// If the given key doesn't exist in POST data, bail.
		if ( empty( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return [];
		}

		return array_map(
			function ( $value ) {
				return sanitize_text_field( wp_unslash( $value ) );
			},
			// phpcs is going to yell about this, because it doesn't understand sanitizing via array_map.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$_POST[ $key ]
		);

		/* phpcs:enable */
	}

	/**
	 * Saves the Apple News meta fields associated with a post
	 *
	 * @param int $post_id The ID of the post being saved.
	 * @access public
	 */
	public static function save_post_meta( $post_id ) {

		// If there is no postdata, bail.
		if ( empty( $_POST ) ) {
			return;
		}

		// Check the nonce.
		check_admin_referer( self::PUBLISH_ACTION, 'apple_news_nonce' );

		// Save straightforward fields.
		$fields = [
			'apple_news_coverimage'              => 'integer',
			'apple_news_coverimage_caption'      => 'textarea',
			'apple_news_cover_embedwebvideo_url' => 'string',
			'apple_news_cover_media_provider'    => 'string',
			'apple_news_cover_video_id'          => 'integer',
			'apple_news_cover_video_url'         => 'string',
			'apple_news_is_hidden'               => 'string',
			'apple_news_is_paid'                 => 'string',
			'apple_news_is_preview'              => 'string',
			'apple_news_is_sponsored'            => 'string',
			'apple_news_pullquote'               => 'string',
			'apple_news_pullquote_position'      => 'string',
			'apple_news_slug'                    => 'string',
			'apple_news_suppress_video_url'      => 'boolean',
			'apple_news_use_image_component'     => 'boolean',
		];
		foreach ( $fields as $meta_key => $type ) {
			switch ( $type ) {
				case 'boolean':
					$value = isset( $_POST[ $meta_key ] ) && 1 === intval( $_POST[ $meta_key ] );
					break;
				case 'integer':
					$value = isset( $_POST[ $meta_key ] ) ? (int) $_POST[ $meta_key ] : 0;
					break;
				case 'textarea':
					$value = isset( $_POST[ $meta_key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $meta_key ] ) ) : '';
					break;
				default:
					$value = isset( $_POST[ $meta_key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) ) : '';
					break;
			}
			update_post_meta( $post_id, $meta_key, $value );
		}

		// Determine whether to save sections.
		if ( empty( $_POST['apple_news_sections_by_taxonomy'] ) ) {
			$sections = [];
			if ( ! empty( $_POST['apple_news_sections'] )
				&& is_array( $_POST['apple_news_sections'] )
			) {
				$sections = array_map(
					'sanitize_text_field',
					array_map(
						'wp_unslash',
						$_POST['apple_news_sections'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					)
				);
			}
			update_post_meta( $post_id, 'apple_news_sections', $sections );
		} else {
			delete_post_meta( $post_id, 'apple_news_sections' );
		}

		// Handle maturity rating, ensuring the value is one of the allowed values for the field.
		if ( ! empty( $_POST['apple_news_maturity_rating'] ) ) {
			$maturity_rating = sanitize_text_field( wp_unslash( $_POST['apple_news_maturity_rating'] ) );
			if ( ! in_array( $maturity_rating, self::$maturity_ratings, true ) ) {
				$maturity_rating = '';
			}
		}
		if ( ! empty( $maturity_rating ) ) {
			update_post_meta( $post_id, 'apple_news_maturity_rating', $maturity_rating );
		}

		// Compile custom metadata and save to postmeta.
		$metadata_keys   = self::sanitize_metadata_array( 'apple_news_metadata_keys' );
		$metadata_types  = self::sanitize_metadata_array( 'apple_news_metadata_types' );
		$metadata_values = self::sanitize_metadata_array( 'apple_news_metadata_values' );
		if ( ! empty( $metadata_keys ) && ! empty( $metadata_types ) && ! empty( $metadata_values ) ) {
			$metadata = [];
			foreach ( $metadata_keys as $index => $key ) {
				if ( ! isset( $metadata_types[ $index ] ) || ! isset( $metadata_values[ $index ] ) ) {
					continue;
				}

				// Juggle value cast.
				$type  = $metadata_types[ $index ];
				$value = $metadata_values[ $index ];
				if ( 'boolean' === $type ) {
					$value = ( 'true' === $metadata_values[ $index ] || '1' === $metadata_values[ $index ] );
				} elseif ( 'number' === $type ) {
					if ( false === strpos( $value, '.' ) ) {
						$value = (int) $metadata_values[ $index ];
					} else {
						$value = (float) $metadata_values[ $index ];
					}
				}

				// Add the metadata object to the array.
				$metadata[] = [
					'key'   => $key,
					'type'  => $type,
					'value' => $value,
				];
			}
			update_post_meta( $post_id, 'apple_news_metadata', $metadata );
		}
	}

	/**
	 * Filters the Heartbeat response to refresh the apple_news_nonce
	 *
	 * @param array $response Heartbeat response.
	 * @return array Filtered Heartbeat $response.
	 * @access public
	 */
	public function refresh_nonce( $response ) {
		$response['wp-refresh-post-nonces']['replace']['apple_news_nonce'] = wp_create_nonce( self::PUBLISH_ACTION );

		return $response;
	}

	/**
	 * Add the Apple News meta boxes
	 *
	 * @since 0.9.0
	 * @param \WP_Post $post The post object for which meta boxes are being added.
	 * @access public
	 */
	public function add_meta_boxes( $post ) {

		// If the block editor is active, do not add meta boxes.
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post->ID ) ) {
			return;
		}

		// Add the publish meta box.
		add_meta_box(
			'apple_news_publish',
			__( 'Apple News', 'apple-news' ),
			[ $this, 'publish_meta_box' ],
			$post->post_type,
			/**
			 * Changes the context (i.e. column) where the Apple News meta box
			 * appears by default on the post edit screen. Defaults to 'side'.
			 *
			 * For proper usage, please read the documentation on add_meta_box at
			 * https://developer.wordpress.org/reference/functions/add_meta_box/.
			 *
			 * @param string $context The context where the meta box should display. Defaults to 'side'.
			 */
			apply_filters( 'apple_news_publish_meta_box_context', 'side' ),
			/**
			 * Changes the priority (i.e. vertical location) where the Apple News
			 * meta box appears by default on the post edit screen. Defaults to
			 * 'high'.
			 *
			 * For proper usage, please read the documentation on add_meta_box at
			 * https://developer.wordpress.org/reference/functions/add_meta_box/.
			 *
			 * @param string $priority The priority of the meta box. Defaults to 'high'.
			 */
			apply_filters( 'apple_news_publish_meta_box_priority', 'high' )
		);
	}

	/**
	 * Add the Apple News publish meta box
	 *
	 * @since 0.9.0
	 * @param \WP_Post $post The post object for which the meta box is being added.
	 * @access public
	 */
	public function publish_meta_box( $post ) {

		/* phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable */

		// Only show the publish feature if the user is authorized and auto sync is not enabled.
		// Also check if the post has been previously published and/or deleted.
		$api_id              = get_post_meta( $post->ID, 'apple_news_api_id', true );
		$deleted             = get_post_meta( $post->ID, 'apple_news_api_deleted', true );
		$pending             = get_post_meta( $post->ID, 'apple_news_api_pending', true );
		$is_paid             = get_post_meta( $post->ID, 'apple_news_is_paid', true );
		$is_preview          = get_post_meta( $post->ID, 'apple_news_is_preview', true );
		$is_hidden           = get_post_meta( $post->ID, 'apple_news_is_hidden', true );
		$maturity_rating     = get_post_meta( $post->ID, 'apple_news_maturity_rating', true );
		$is_sponsored        = get_post_meta( $post->ID, 'apple_news_is_sponsored', true );
		$pullquote           = get_post_meta( $post->ID, 'apple_news_pullquote', true );
		$pullquote_position  = get_post_meta( $post->ID, 'apple_news_pullquote_position', true );
		$slug                = get_post_meta( $post->ID, 'apple_news_slug', true );
		$suppress_video_url  = get_post_meta( $post->ID, 'apple_news_suppress_video_url', true );
		$use_image_component = get_post_meta( $post->ID, 'apple_news_use_image_component', true );

		// Set default values.
		if ( empty( $pullquote_position ) ) {
			$pullquote_position = 'middle';
		}

		// Handle backwards compatibility for is* fields that were previously stored as booleans.
		$is_hidden    = '1' === $is_hidden ? 'true' : $is_hidden;
		$is_paid      = '1' === $is_paid ? 'true' : $is_paid;
		$is_preview   = '1' === $is_preview ? 'true' : $is_preview;
		$is_sponsored = '1' === $is_sponsored ? 'true' : $is_sponsored;

		// Create local copies of values to pass into the partial.
		$publish_action = self::PUBLISH_ACTION;

		/* phpcs:enable */

		include __DIR__ . '/partials/metabox-publish.php';
	}

	/**
	 * Builds the custom metadata list from what is saved in postmeta.
	 *
	 * @param int $post_id The psot ID to query metadata for.
	 *
	 * @access public
	 */
	public static function build_metadata( $post_id ) {
		// Ensure metadata exists for this post.
		$metadata = get_post_meta( $post_id, 'apple_news_metadata', true );
		if ( empty( $metadata ) || ! is_array( $metadata ) ) {
			return;
		}

		// Loop over metadata and print edit interface for each.
		foreach ( $metadata as $index => $field ) {
			?>
			<div>
				<label for="apple-news-metadata-key-<?php echo absint( $index ); ?>">
					<?php esc_html_e( 'Key', 'apple-news' ); ?>
					<br />
					<input id="apple-news-metadata-key-<?php echo absint( $index ); ?>" name="apple_news_metadata_keys[]" type="text" value="<?php echo esc_attr( $field['key'] ); ?>" />
				</label>
				<label for="apple-news-metadata-type-<?php echo absint( $index ); ?>">
					<?php esc_html_e( 'Type', 'apple-news' ); ?>
					<br />
					<select id="apple-news-metadata-type-<?php echo absint( $index ); ?>" name="apple_news_metadata_types[]">
						<option <?php selected( empty( $field['type'] ) ); ?> value=""></option>
						<option <?php selected( 'string' === $field['type'] ); ?> value="string"><?php esc_html_e( 'string', 'apple-news' ); ?></option>
						<option <?php selected( 'boolean' === $field['type'] ); ?> value="boolean"><?php esc_html_e( 'boolean', 'apple-news' ); ?></option>
						<option <?php selected( 'number' === $field['type'] ); ?> value="number"><?php esc_html_e( 'number', 'apple-news' ); ?></option>
						<option <?php selected( 'array' === $field['type'] ); ?> value="array"><?php esc_html_e( 'array', 'apple-news' ); ?></option>
					</select>
				</label>
				<label for="apple-news-metadata-value-<?php echo absint( $index ); ?>">
					<?php esc_html_e( 'Value', 'apple-news' ); ?>
					<br />
					<input
						id="apple-news-metadata-value-<?php echo absint( $index ); ?>"
						name="apple_news_metadata_values[]"
						type="text"
						<?php if ( 'boolean' === $field['type'] ) : ?>
							value="<?php echo ! empty( $field['value'] ) ? 'true' : 'false'; ?>"
						<?php else : ?>
							value="<?php echo esc_attr( $field['value'] ); ?>"
						<?php endif; ?>
					/>
				</label>
				<button class="button-secondary apple-news-metadata-remove">
					<?php esc_html_e( 'Remove', 'apple-news' ); ?>
				</button>
			</div>
			<?php
		}
	}

	/**
	 * Builds the sections checkboxes.
	 *
	 * @param int $post_id The post ID to query sections for.
	 *
	 * @access public
	 */
	public static function build_sections_field( $post_id ) {

		// Ensure we have sections before trying to build the field.
		$sections = Admin_Apple_Sections::get_sections();
		if ( empty( $sections ) ) {
			return;
		}

		// Determine whether to print the subheading for manual selection.
		if ( ! empty( Automation::get_automation_rules() ) ) {
			printf(
				'<h4>%s</h4>',
				esc_html__( 'Manual Section Selection', 'apple-news' )
			);
		}

		// Iterate over the list of sections and print each.
		$apple_news_sections = get_post_meta( $post_id, 'apple_news_sections', true );
		foreach ( $sections as $section ) {
			?>
			<div class="section">
				<label for="apple-news-section-<?php echo esc_attr( $section->id ); ?>">
					<input id="apple-news-section-<?php echo esc_attr( $section->id ); ?>" name="apple_news_sections[]" type="checkbox" value="<?php echo esc_attr( $section->links->self ); ?>" <?php checked( self::section_is_checked( $apple_news_sections, $section->links->self, $section->isDefault ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase ?>>
					<?php echo esc_html( $section->name ); ?>
				</label>
			</div>
			<?php
		}
	}

	/**
	 * Builds the sections override checkbox, if necessary.
	 *
	 * @param int $post_id The ID of the post to build the checkbox field for.
	 *
	 * @access public
	 */
	public static function build_sections_override( $post_id ) {

		// Determine if there are section/taxonomy mappings set.
		if ( empty( Automation::get_automation_rules() ) ) {
			return;
		}

		// Add checkbox to allow override of automatic section assignment.
		$sections = get_post_meta( $post_id, 'apple_news_sections', true );
		?>
		<div class="section-override">
			<label for="apple-news-sections-by-taxonomy">
			<input id="apple-news-sections-by-taxonomy" name="apple_news_sections_by_taxonomy" type="checkbox" <?php checked( ! is_array( $sections ) ); ?> />
				<?php esc_html_e( 'Assign sections via Apple News Automation rules', 'apple-news' ); ?>
			</label>
		</div>
		<?php
	}

	/**
	 * Determine if a section is checked.
	 *
	 * @param array $sections   The list of sections applied to a particular post.
	 * @param int   $section_id The ID of the section to check.
	 * @param bool  $is_default Whether this section is the default section.
	 *
	 * @access public
	 * @return bool True if the section should be checked, false otherwise.
	 */
	public static function section_is_checked( $sections, $section_id, $is_default ) {
		// If no sections exist, return true if this is the default.
		// If sections is an empty array, this is intentional though and nothing should be checked.
		// If sections are provided, then only use those for matching.
		return ( ( empty( $sections ) && ! is_array( $sections ) && $is_default )
			|| ( ! empty( $sections ) && is_array( $sections ) && in_array( $section_id, $sections, true ) )
		);
	}

	/**
	 * Registers assets used by meta boxes.
	 *
	 * @param string $hook The initiator of the action hook.
	 *
	 * @access public
	 */
	public function register_assets( $hook ) {

		// Only fire on post and new post views.
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		// Enqueue metabox stylesheet.
		wp_enqueue_style(
			$this->plugin_slug . '_meta_boxes_css',
			plugin_dir_url( __FILE__ ) . '../assets/css/meta-boxes.css',
			[],
			self::$version
		);

		// Enqueue metabox script.
		wp_enqueue_script(
			$this->plugin_slug . '_meta_boxes_js',
			plugin_dir_url( __FILE__ ) . '../assets/js/meta-boxes.js',
			[ 'jquery' ],
			self::$version,
			true
		);

		// Localize the JS file for meta boxes.
		wp_localize_script(
			$this->plugin_slug . '_meta_boxes_js',
			'apple_news_meta_boxes',
			[
				'publish_action' => self::PUBLISH_ACTION,
			]
		);
	}
}
