<?php
/**
 * Publish to Apple News: \Apple_Exporter\Components\Image class
 *
 * @package Apple_News
 * @subpackage Apple_Exporter\Components
 */

namespace Apple_Exporter\Components;

use WP_HTML_Tag_Processor;

/**
 * Represents a simple image.
 *
 * @since 0.2.0
 */
class Image extends Component {

	/**
	 * Look for node matches for this component.
	 *
	 * @param \DOMElement $node The node to examine for matches.
	 * @access public
	 * @return \DOMElement|null The node on success, or null on no match.
	 */
	public static function node_matches( $node ) {
		/* phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase */

		$has_image_child = false;

		// If this is a figure, and it has children, see if we can find an image at any depth.
		if ( $node->hasChildNodes() && 'figure' === $node->tagName ) {
			$html            = $node->ownerDocument->saveHTML( $node );
			$proc            = new WP_HTML_Tag_Processor( $html );
			$has_image_child = false !== $proc->next_tag( 'img' );
		}

		// Is this an image node?
		if ( self::node_has_class( $node, 'wp-block-cover' )
			|| 'img' === $node->nodeName
			|| ( 'figure' === $node->nodeName
				&& ( self::node_has_class( $node, 'wp-caption' )
					|| $has_image_child
				)
			)
		) {
			return $node;
		}

		return null;
		/* phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase */
	}

	/**
	 * Register all specs for the component.
	 *
	 * @access public
	 */
	public function register_specs() {
		$theme = \Apple_Exporter\Theme::get_used();
		$this->register_spec(
			'json-without-caption',
			__( 'JSON without caption', 'apple-news' ),
			[
				'role'   => '#role#',
				'URL'    => '#url#',
				'layout' => '#layout#',
			]
		);

		$conditional = [];
		if ( ! empty( $theme->get_value( 'caption_color_dark' ) ) ) {
			$conditional = [
				'conditional' => [
					'textColor'  => '#caption_color_dark#',
					'conditions' => [
						'minSpecVersion'       => '1.14',
						'preferredColorScheme' => 'dark',
					],
				],
			];
		}

		$this->register_spec(
			'json-with-caption',
			__( 'JSON with caption', 'apple-news' ),
			[
				'role'       => 'container',
				'components' => [
					[
						'role'    => '#role#',
						'URL'     => '#url#',
						'layout'  => '#layout#',
						'caption' => [
							'format'    => 'html',
							'text'      => '#caption#',
							'textStyle' => [
								'fontName' => '#caption_font#',
							],
						],
					],
					[
						'role'      => 'caption',
						'text'      => '#caption_text#',
						'format'    => 'html',
						'textStyle' => array_merge(
							[
								'textAlignment' => '#text_alignment#',
								'fontName'      => '#caption_font#',
								'fontSize'      => '#caption_size#',
								'tracking'      => '#caption_tracking#',
								'lineHeight'    => '#caption_line_height#',
								'textColor'     => '#caption_color#',
							],
							$conditional
						),
						'layout'    => [
							'ignoreDocumentMargin' => '#full_bleed_images#',
							'margin'               => [
								'bottom' => '#caption_margin_bottom#',
							],
						],
					],
				],
				'layout'     => [
					'ignoreDocumentMargin' => '#full_bleed_images#',
				],
			]
		);

		$this->register_spec(
			'anchored-image',
			__( 'Anchored Layout (Without Caption)', 'apple-news' ),
			[
				'margin' => [
					'bottom' => 25,
					'top'    => 25,
				],
			]
		);

		$this->register_spec(
			'anchored-image-with-caption',
			__( 'Anchored Layout (With Caption)', 'apple-news' ),
			[
				'margin' => [
					'bottom' => 10,
					'top'    => 25,
				],
			]
		);

		$this->register_spec(
			'non-anchored-image',
			__( 'Non Anchored Layout (Without Caption)', 'apple-news' ),
			[
				'margin'      => [
					'bottom' => 25,
					'top'    => 25,
				],
				'columnSpan'  => '#layout_columns_minus_4#',
				'columnStart' => 2,
			]
		);

		$this->register_spec(
			'non-anchored-image-with-caption',
			__( 'Non Anchored Layout (With Caption)', 'apple-news' ),
			[
				'margin'      => [
					'bottom' => 10,
					'top'    => 25,
				],
				'columnSpan'  => '#layout_columns_minus_4#',
				'columnStart' => 2,
			]
		);

		$this->register_spec(
			'non-anchored-full-bleed-image',
			__( 'Non Anchored with Full Bleed Images Layout (Without Caption)', 'apple-news' ),
			[
				'margin'               => [
					'bottom' => 25,
					'top'    => 25,
				],
				'ignoreDocumentMargin' => true,
			]
		);

		$this->register_spec(
			'non-anchored-full-bleed-image-with-caption',
			__( 'Non Anchored with Full Bleed Images Layout (With Caption)', 'apple-news' ),
			[
				'margin'               => [
					'bottom' => 10,
					'top'    => 25,
				],
				'ignoreDocumentMargin' => true,
			]
		);
	}

	/**
	 * Build the component.
	 *
	 * @param string $html The HTML to parse into text for processing.
	 * @access protected
	 */
	protected function build( $html ) {
		// Is this is Gutenberg Cover Block?
		$is_cover_block = preg_match( '#class="wp-block-cover#', $html );

		// Extract the URL from the text.
		$url = self::url_from_src( $html );

		/**
		 * Allows for an image src value to be filtered before being applied.
		 *
		 * @param string $url The URL to be filtered.
		 * @param string $text The raw text that was parsed for the URL.
		 */
		$url = esc_url_raw( apply_filters( 'apple_news_build_image_src', $url, $html ) );

		// If we don't have a valid URL at this point, bail.
		if ( empty( $url ) ) {
			return;
		}

		// Add the URL as a parameter for replacement.
		$filename = preg_replace( '/\\?.*/', '', \Apple_News::get_filename( $url ) );
		$values   = [
			'#url#' => $this->maybe_bundle_source( $url, $filename ),
		];

		// Use postmeta to determine if component role should be registered as 'image' or 'photo'.
		$use_image        = get_post_meta( $this->workspace->content_id, 'apple_news_use_image_component', true );
		$values['#role#'] = $use_image ? 'image' : 'photo';

		// Determine image alignment.
		if ( false !== stripos( $html, 'align="left"' )
			|| preg_match( '/class="[^"]*alignleft[^"]*"/i', $html )
		) {
			$this->set_anchor_position( Component::ANCHOR_LEFT );
		} elseif ( false !== stripos( $html, 'align="right"' )
			|| preg_match( '/class="[^"]*alignright[^"]*"/i', $html )
		) {
			$this->set_anchor_position( Component::ANCHOR_RIGHT );
		} else {
			$this->set_anchor_position( Component::ANCHOR_NONE );
		}

		// Check for caption.
		$caption_regex = $is_cover_block ? '#<div.*?>?\n(.*)#m' : '#<figcaption.*?>(.*?)</figcaption>#ms';
		if ( preg_match( $caption_regex, $html, $matches ) ) {
			$caption                  = trim( $matches[1] );
			$values['#caption#']      = $caption;
			$values['#caption_text#'] = $caption;
			$values                   = $this->group_component( $values['#caption#'], $values );
			$spec_name                = 'json-with-caption';
		} else {
			$spec_name = 'json-without-caption';
		}

		/**
		 * Full width images have top margin
		 * We can't use the standard layout registration due to grouping components
		 * with images so instead, send it through as a value.
		 */
		if ( Component::ANCHOR_NONE === $this->get_anchor_position() ) {
			$values = $this->register_non_anchor_layout( $values );
		} else {
			$values = $this->register_anchor_layout( $values );
		}

		// Register the JSON.
		$this->register_json( $spec_name, $values );
	}

	/**
	 * Register the anchor layout.
	 *
	 * @param array $values Values with corresponding replacement keys.
	 * @access private
	 * @return array The modified values array.
	 */
	private function register_anchor_layout( $values ) {
		$layout_name = 'anchored-image';

		if ( isset( $values['#caption#'] ) ) {
			$layout_name .= '-with-caption';
		}

		$this->register_layout( $layout_name, $layout_name );

		$values['#layout#'] = $this->get_component_object_key( $layout_name );
		return $values;
	}

	/**
	 * Register the non-anchor layout.
	 *
	 * @param array $values Values with corresponding replacement keys.
	 * @access private
	 * @return array The modified values array.
	 */
	private function register_non_anchor_layout( $values ) {

		// Get information about the currently loaded theme.
		$theme = \Apple_Exporter\Theme::get_used();

		// Set values to merge into the spec.
		$layout_values = [];

		if ( 'yes' === $this->get_setting( 'full_bleed_images' ) ) {
			$spec_name = 'non-anchored-full-bleed-image';
		} else {
			$layout_values['#layout_columns_minus_4#'] = $theme->get_layout_columns() - 4;
			$spec_name                                 = 'non-anchored-image';
		}

		$layout_name = 'full-width-image';

		if ( isset( $values['#caption#'] ) ) {
			$layout_name .= '-with-caption';
			$spec_name   .= '-with-caption';
		}

		// Register the layout.
		$this->register_full_width_layout( $layout_name, $spec_name, $layout_values );

		$values['#layout#'] = $this->get_component_object_key( $layout_name );
		return $values;
	}

	/**
	 * Find the caption alignment to use.
	 *
	 * @access private
	 * @return string The alignment value.
	 */
	private function find_caption_alignment() {

		// Get information about the currently loaded theme.
		$theme = \Apple_Exporter\Theme::get_used();

		if ( Component::ANCHOR_NONE === $this->get_anchor_position() ) {
			return 'left';
		}

		switch ( $this->get_anchor_position() ) {
			case Component::ANCHOR_LEFT:
				return 'left';
			case Component::ANCHOR_AUTO:
				if ( 'left' === $theme->get_value( 'body_orientation' ) ) {
					return 'right';
				}
		}

		return 'left';
	}

	/**
	 * If the image has a caption, we have to also show a caption component.
	 * Let's instead, return the values as a Container instead of an Image.
	 *
	 * @param string $caption The caption for the image.
	 * @param array  $values  The values array, containing replacement keys and values.
	 * @access private
	 * @return array The modified values array.
	 */
	private function group_component( $caption, $values ) {

		// Get information about the currently loaded theme.
		$theme = \Apple_Exporter\Theme::get_used();

		// Roll up the image component into a container.
		$values = array_merge(
			$values,
			[
				'#caption#'               => $caption,
				'#text_alignment#'        => $this->find_caption_alignment(),
				'#caption_font#'          => $theme->get_value( 'caption_font' ),
				'#caption_size#'          => intval( $theme->get_value( 'caption_size' ) ),
				'#caption_tracking#'      => intval( $theme->get_value( 'caption_tracking' ) ) / 100,
				'#caption_line_height#'   => intval( $theme->get_value( 'caption_line_height' ) ),
				'#caption_color#'         => $theme->get_value( 'caption_color' ),
				'#caption_color_dark#'    => $theme->get_value( 'caption_color_dark' ),
				'#caption_margin_bottom#' => intval( $theme->get_value( 'caption_margin_bottom' ) ),
				'#full_bleed_images#'     => ( 'yes' === $this->get_setting( 'full_bleed_images' ) ),
			]
		);

		return $values;
	}
}
