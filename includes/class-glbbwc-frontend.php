<?php
/**
 * Frontend rendering: shortcode, single template, assets.
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the bundle picker and loads its assets only where it is used.
 *
 * @since 1.0.0
 */
class GLBBWC_Frontend {

	/**
	 * Nonce action used by the add-to-cart request.
	 */
	const NONCE_ACTION = 'glbbwc_add_bundle_to_cart';

	/**
	 * Single instance.
	 *
	 * @var GLBBWC_Frontend|null
	 */
	protected static $instance = null;

	/**
	 * Whether assets have already been enqueued this request.
	 *
	 * @var bool
	 */
	protected $assets_enqueued = false;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return GLBBWC_Frontend
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook into WordPress.
	 */
	protected function __construct() {
		add_shortcode( 'glbbwc_bundle', array( $this, 'shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ), 20 );
		add_filter( 'template_include', array( $this, 'template_include' ) );
		add_filter( 'the_content', array( $this, 'append_picker_to_content' ) );
		add_action( 'template_redirect', array( $this, 'redirect_bundle_products' ) );

		// A listed bundle is a real catalog tile, so its loop button must lead to
		// the picker rather than drop an unconfigured bundle straight in the cart.
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'loop_add_to_cart_link' ), 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_url', array( $this, 'add_to_cart_url' ), 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'add_to_cart_text' ), 10, 2 );
		add_filter( 'woocommerce_product_supports', array( $this, 'disable_ajax_add_to_cart' ), 10, 3 );
	}

	/**
	 * Point a bundle tile's catalog button at the picker.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $html    Rendered button markup.
	 * @param WC_Product $product Product being rendered.
	 *
	 * @return string
	 */
	public function loop_add_to_cart_link( $html, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $html;
		}

		$bundle = GLBBWC_Bundle::from_product_id( $product->get_id() );

		if ( ! $bundle ) {
			return $html;
		}

		return sprintf(
			'<a href="%1$s" class="button glbbwc-loop-button">%2$s</a>',
			esc_url( $bundle->get_permalink() ),
			esc_html( $this->build_bundle_button_text( $bundle ) )
		);
	}

	/**
	 * Send a bundle product's add-to-cart URL to the picker.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $url     Add-to-cart URL.
	 * @param WC_Product $product Product being rendered.
	 *
	 * @return string
	 */
	public function add_to_cart_url( $url, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $url;
		}

		$bundle = GLBBWC_Bundle::from_product_id( $product->get_id() );

		return $bundle && $bundle->get_permalink() ? $bundle->get_permalink() : $url;
	}

	/**
	 * Label a bundle's catalog button for what it actually does.
	 *
	 * @since 1.2.0
	 *
	 * @param string     $text    Button text.
	 * @param WC_Product $product Product being rendered.
	 *
	 * @return string
	 */
	public function add_to_cart_text( $text, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $text;
		}

		$bundle = GLBBWC_Bundle::from_product_id( $product->get_id() );

		return $bundle ? $this->build_bundle_button_text( $bundle ) : $text;
	}

	/**
	 * Wording for a bundle's catalog button.
	 *
	 * @since 1.2.0
	 *
	 * @param GLBBWC_Bundle $bundle Bundle instance.
	 *
	 * @return string
	 */
	protected function build_bundle_button_text( GLBBWC_Bundle $bundle ) {
		if ( ! $bundle->is_in_stock() ) {
			return __( 'Out of stock', 'growthlab-bundle-builder-for-woocommerce' );
		}

		return __( 'Build your bundle', 'growthlab-bundle-builder-for-woocommerce' );
	}

	/**
	 * Stop WooCommerce offering AJAX add-to-cart for a bundle tile.
	 *
	 * Without this the catalog's one-click button would add a bundle carrying no
	 * selection at all, at the full bundle price.
	 *
	 * @since 1.2.0
	 *
	 * @param bool       $supports Whether the feature is supported.
	 * @param string     $feature  Feature being queried.
	 * @param WC_Product $product  Product being queried.
	 *
	 * @return bool
	 */
	public function disable_ajax_add_to_cart( $supports, $feature, $product ) {
		if ( 'ajax_add_to_cart' !== $feature || ! $product instanceof WC_Product ) {
			return $supports;
		}

		return GLBBWC_Bundle::from_product_id( $product->get_id() ) ? false : $supports;
	}

	/**
	 * Register (but do not enqueue) the picker assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			'glbbwc-bundle-builder',
			GLBBWC_PLUGIN_URL . 'assets/css/bundle-builder.css',
			array(),
			GLBBWC_VERSION
		);

		wp_register_script(
			'glbbwc-bundle-builder',
			GLBBWC_PLUGIN_URL . 'assets/js/bundle-builder.js',
			array(),
			GLBBWC_VERSION,
			true
		);

		wp_localize_script(
			'glbbwc-bundle-builder',
			'glbbwcParams',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				// Offered next to the success notice when the shopper stays on the
				// picker, so they still have a one-click route to the cart.
				'cartUrl' => wc_get_cart_url(),
				'i18n'    => array(
					/* translators: 1: number selected, 2: number required. */
					'counter'    => __( '%1$d of %2$d selected', 'growthlab-bundle-builder-for-woocommerce' ),
					'limit'      => __( 'You have picked the maximum number of items. Deselect one to swap it.', 'growthlab-bundle-builder-for-woocommerce' ),
					'adding'     => __( 'Adding…', 'growthlab-bundle-builder-for-woocommerce' ),
					'addToCart'  => __( 'Add Bundle to Cart', 'growthlab-bundle-builder-for-woocommerce' ),
					'viewCart'   => __( 'View cart', 'growthlab-bundle-builder-for-woocommerce' ),
					'genericErr' => __( 'Something went wrong. Please refresh the page and try again.', 'growthlab-bundle-builder-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Enqueue assets when the current page will render a picker.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets() {
		if ( is_singular( GLBBWC_Bundle::POST_TYPE ) ) {
			$this->enqueue_assets();

			return;
		}

		$post = get_post();

		if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'glbbwc_bundle' ) ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Enqueue the picker assets once.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( $this->assets_enqueued ) {
			return;
		}

		wp_enqueue_style( 'glbbwc-bundle-builder' );
		wp_enqueue_script( 'glbbwc-bundle-builder' );

		$this->assets_enqueued = true;
	}

	/**
	 * Render the `[glbbwc_bundle]` shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			'glbbwc_bundle'
		);

		$bundle_id = absint( $atts['id'] );

		if ( ! $bundle_id && is_singular( GLBBWC_Bundle::POST_TYPE ) ) {
			$bundle_id = (int) get_the_ID();
		}

		$bundle = GLBBWC_Bundle::get( $bundle_id );

		if ( ! $bundle ) {
			return $this->notice( __( 'Bundle not found.', 'growthlab-bundle-builder-for-woocommerce' ) );
		}

		return $this->render( $bundle );
	}

	/**
	 * Append the picker below the bundle content on its own page.
	 *
	 * Skipped when the content already contains the shortcode, so the admin can
	 * position it manually.
	 *
	 * @param string $content Post content.
	 *
	 * @return string
	 */
	public function append_picker_to_content( $content ) {
		if ( ! is_singular( GLBBWC_Bundle::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		if ( has_shortcode( (string) $content, 'glbbwc_bundle' ) ) {
			return $content;
		}

		$bundle = GLBBWC_Bundle::get( get_the_ID() );

		if ( ! $bundle ) {
			return $content;
		}

		return $content . $this->render( $bundle );
	}

	/**
	 * Use the plugin's single-bundle template, allowing theme overrides.
	 *
	 * @param string $template Resolved template path.
	 *
	 * @return string
	 */
	public function template_include( $template ) {
		if ( ! is_singular( GLBBWC_Bundle::POST_TYPE ) ) {
			return $template;
		}

		$theme_template = locate_template(
			array(
				'growthlab-bundle-builder-for-woocommerce/single-glbbwc_bundle.php',
				'single-glbbwc_bundle.php',
			)
		);

		if ( $theme_template ) {
			return $theme_template;
		}

		/*
		 * Block themes have no header.php/footer.php, so the classic template's
		 * get_header()/get_footer() calls would both log deprecation notices and
		 * strip the site header, navigation and footer from the bundle page.
		 * Let core resolve the block template instead — append_picker_to_content()
		 * still puts the picker on the page.
		 */
		if ( wp_is_block_theme() ) {
			return $template;
		}

		$plugin_template = GLBBWC_PLUGIN_DIR . 'templates/single-glbbwc_bundle.php';

		return file_exists( $plugin_template ) ? $plugin_template : $template;
	}

	/**
	 * Send visitors who land on a bundle's product page to the bundle page.
	 *
	 * @since 1.2.0 Renamed from `redirect_hidden_products()`; bundle products are
	 *              no longer necessarily hidden, but the picker still lives on the
	 *              bundle post, so the redirect applies either way.
	 *
	 * @return void
	 */
	public function redirect_bundle_products() {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		// template_redirect runs before the loop, so use the queried object.
		$bundle = GLBBWC_Bundle::from_product_id( get_queried_object_id() );

		if ( $bundle && $bundle->get_permalink() ) {
			wp_safe_redirect( $bundle->get_permalink(), 302 );
			exit;
		}
	}

	/**
	 * Build the picker markup for a bundle.
	 *
	 * @param GLBBWC_Bundle $bundle Bundle instance.
	 *
	 * @return string
	 */
	public function render( GLBBWC_Bundle $bundle ) {
		// An unpublished bundle is shown the same way an inactive one is, so a
		// shortcode pointing at a draft never renders a picker nobody can buy from.
		if ( ! $bundle->is_active() || ! $bundle->is_published() ) {
			return $this->notice( __( 'This bundle is not currently available.', 'growthlab-bundle-builder-for-woocommerce' ) );
		}

		if ( ! $bundle->is_configured() ) {
			return $this->notice( __( 'This bundle has not been fully configured yet.', 'growthlab-bundle-builder-for-woocommerce' ) );
		}

		$slots = $bundle->get_slots();

		if ( ! $slots ) {
			return $this->notice( __( 'There are no products available in this bundle right now.', 'growthlab-bundle-builder-for-woocommerce' ) );
		}

		// Each slot must be able to fill itself, otherwise the bundle is unbuildable.
		$slot_products = array();

		foreach ( $slots as $slot ) {
			$slot_products[ $slot['id'] ] = $bundle->get_eligible_products( $slot['id'] );

			if ( ! $slot_products[ $slot['id'] ] ) {
				return $this->notice( __( 'There are no products available in this bundle right now.', 'growthlab-bundle-builder-for-woocommerce' ) );
			}

			if ( count( $slot_products[ $slot['id'] ] ) < $slot['count'] ) {
				return $this->notice( __( 'There are not enough products in stock to build this bundle right now.', 'growthlab-bundle-builder-for-woocommerce' ) );
			}
		}

		if ( ! $bundle->is_in_stock() ) {
			return $this->notice( __( 'This bundle is out of stock — there are not enough products left to build it.', 'growthlab-bundle-builder-for-woocommerce' ) );
		}

		$this->enqueue_assets();

		$required  = $bundle->get_item_count();
		$is_multi  = count( $slots ) > 1;
		$buildable = $bundle->get_buildable_quantity();

		/**
		 * Filter the level at which a bundle starts warning about low stock.
		 *
		 * @since 1.2.0
		 *
		 * @param int           $threshold Bundles remaining at or below which to warn.
		 * @param GLBBWC_Bundle $bundle    Bundle instance.
		 */
		$low_stock_threshold = (int) apply_filters( 'glbbwc_bundle_low_stock_threshold', 5, $bundle );

		ob_start();
		?>
		<div
			class="<?php echo esc_attr( $is_multi ? 'glbbwc-bundle glbbwc-bundle--multi-slot' : 'glbbwc-bundle' ); ?>"
			data-bundle-id="<?php echo esc_attr( (string) $bundle->get_id() ); ?>"
			data-required="<?php echo esc_attr( (string) $required ); ?>"
		>

			<?php
			/**
			 * Fires before the bundle picker grid is rendered.
			 *
			 * @since 1.0.0
			 *
			 * @param GLBBWC_Bundle $bundle Bundle instance.
			 */
			do_action( 'glbbwc_before_bundle_picker', $bundle );
			?>

			<div class="glbbwc-bundle__intro">
				<p class="glbbwc-bundle__summary"><?php echo esc_html( $bundle->get_summary() ); ?></p>
				<?php if ( null !== $buildable && $buildable <= $low_stock_threshold ) : ?>
					<p class="glbbwc-bundle__stock">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of bundles left. */
								_n( 'Only %d left — built from the stock of the items below.', 'Only %d left — built from the stock of the items below.', $buildable, 'growthlab-bundle-builder-for-woocommerce' ),
								$buildable
							)
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<?php
			/*
			 * The preview is populated by JavaScript from the tiles the customer
			 * picks, so it ships empty and is hidden until the script fills it —
			 * no image is duplicated into the page twice.
			 */
			?>
			<div class="glbbwc-bundle__preview" aria-hidden="true" hidden>
				<h3 class="glbbwc-bundle__preview-title"><?php esc_html_e( 'Your bundle', 'growthlab-bundle-builder-for-woocommerce' ); ?></h3>
				<ul class="glbbwc-bundle__preview-list">
					<?php for ( $position = 0; $position < $required; $position++ ) : ?>
						<li class="glbbwc-bundle__preview-slot is-empty" data-position="<?php echo esc_attr( (string) $position ); ?>">
							<span class="glbbwc-bundle__preview-placeholder" aria-hidden="true"><?php echo esc_html( (string) ( $position + 1 ) ); ?></span>
						</li>
					<?php endfor; ?>
				</ul>
			</div>

			<?php foreach ( $slots as $slot ) : ?>
				<div
					class="glbbwc-bundle__slot"
					data-slot-id="<?php echo esc_attr( $slot['id'] ); ?>"
					data-slot-required="<?php echo esc_attr( (string) $slot['count'] ); ?>"
				>
					<?php if ( $is_multi ) : ?>
						<div class="glbbwc-bundle__slot-header">
							<h3 class="glbbwc-bundle__slot-title"><?php echo esc_html( $slot['label'] ); ?></h3>
							<span class="glbbwc-bundle__slot-counter" role="status" aria-live="polite">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: number selected, 2: number required. */
										__( '%1$d of %2$d selected', 'growthlab-bundle-builder-for-woocommerce' ),
										0,
										$slot['count']
									)
								);
								?>
							</span>
						</div>
					<?php endif; ?>

					<ul class="glbbwc-bundle__grid">
						<?php foreach ( $slot_products[ $slot['id'] ] as $product ) : ?>
							<li class="glbbwc-product" data-product-id="<?php echo esc_attr( (string) $product->get_id() ); ?>">
								<button type="button" class="glbbwc-product__toggle" aria-pressed="false">
									<span class="glbbwc-product__media">
										<?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?>
										<span class="glbbwc-product__check" aria-hidden="true"></span>
									</span>
									<span class="glbbwc-product__title"><?php echo esc_html( $product->get_name() ); ?></span>
									<span class="glbbwc-product__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
								</button>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>

			<div class="glbbwc-bundle__notices" role="alert" aria-live="assertive"></div>

			<div class="glbbwc-bundle__bar">
				<div class="glbbwc-bundle__status">
					<span class="glbbwc-bundle__counter" role="status" aria-live="polite">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number selected, 2: number required. */
								__( '%1$d of %2$d selected', 'growthlab-bundle-builder-for-woocommerce' ),
								0,
								$required
							)
						);
						?>
					</span>
					<span class="glbbwc-bundle__total">
						<span class="glbbwc-bundle__total-label"><?php esc_html_e( 'Total', 'growthlab-bundle-builder-for-woocommerce' ); ?></span>
						<span class="glbbwc-bundle__total-value"><?php echo wp_kses_post( wc_price( $bundle->get_price() ) ); ?></span>
					</span>
				</div>
				<button type="button" class="glbbwc-bundle__submit button alt" disabled>
					<?php esc_html_e( 'Add Bundle to Cart', 'growthlab-bundle-builder-for-woocommerce' ); ?>
				</button>
			</div>

			<?php
			/**
			 * Fires after the bundle picker has been rendered.
			 *
			 * @since 1.0.0
			 *
			 * @param GLBBWC_Bundle $bundle Bundle instance.
			 */
			do_action( 'glbbwc_after_bundle_picker', $bundle );
			?>
		</div>
		<?php

		/**
		 * Filter the rendered bundle picker markup.
		 *
		 * @since 1.0.0
		 *
		 * @param string     $html   Picker markup.
		 * @param GLBBWC_Bundle $bundle Bundle instance.
		 */
		return apply_filters( 'glbbwc_bundle_picker_html', (string) ob_get_clean(), $bundle );
	}

	/**
	 * Render a standalone notice block.
	 *
	 * @param string $message Notice text.
	 *
	 * @return string
	 */
	protected function notice( $message ) {
		return sprintf( '<p class="glbbwc-notice">%s</p>', esc_html( $message ) );
	}
}
