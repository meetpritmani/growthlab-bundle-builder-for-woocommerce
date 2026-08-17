<?php
/**
 * "Frequently Bundled With" product recommendations.
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin-curated recommendations shown on regular WooCommerce product pages.
 *
 * Deliberately manual rather than algorithmic: the store owner picks the
 * partners, so there is no order-history analysis and nothing to schedule.
 *
 * Unlike a bundle, this is a plain convenience multi-add — each product enters
 * the cart as its own normal line at its own price, with no flat bundle price
 * and no hidden product involved.
 *
 * @since 1.1.0
 */
class GLBBWC_Recommendations {

	/**
	 * Product meta key holding the curated partner IDs.
	 */
	const META_KEY = '_glbbwc_recommended_ids';

	/**
	 * Nonce action for the add-all-to-cart request.
	 */
	const NONCE_ACTION = 'glbbwc_add_recommended_to_cart';

	/**
	 * Nonce action/name for the product meta box.
	 */
	const ADMIN_NONCE_ACTION = 'glbbwc_save_recommendations';
	const ADMIN_NONCE_NAME   = 'glbbwc_recommendations_nonce';

	/**
	 * Most partners an admin may curate for one product.
	 */
	const MAX_PARTNERS = 4;

	/**
	 * Single instance.
	 *
	 * @var GLBBWC_Recommendations|null
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
	 * @return GLBBWC_Recommendations
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook into WordPress and WooCommerce.
	 */
	protected function __construct() {
		add_shortcode( 'glbbwc_recommended_with', array( $this, 'shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'woocommerce_after_single_product_summary', array( $this, 'render_on_product_page' ), 21 );

		add_action( 'wp_ajax_glbbwc_add_recommended_to_cart', array( $this, 'add_recommended_to_cart' ) );
		add_action( 'wp_ajax_nopriv_glbbwc_add_recommended_to_cart', array( $this, 'add_recommended_to_cart' ) );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
			add_action( 'save_post_product', array( $this, 'save_meta_box' ), 10, 2 );
		}
	}

	/**
	 * Curated partner product IDs for a product.
	 *
	 * Only published, purchasable, in-stock products are returned, so a partner
	 * that sells out or is unpublished quietly drops out of the widget.
	 *
	 * @since 1.1.0
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return int[]
	 */
	public static function get_partner_ids( $product_id ) {
		$stored = get_post_meta( (int) $product_id, self::META_KEY, true );
		$ids    = is_array( $stored ) ? array_map( 'absint', $stored ) : array();
		$ids    = array_values( array_unique( array_filter( $ids ) ) );

		$ids = array_values(
			array_filter(
				$ids,
				static function ( $id ) use ( $product_id ) {
					if ( (int) $id === (int) $product_id ) {
						return false;
					}

					$product = wc_get_product( $id );

					return $product instanceof WC_Product
						&& 'publish' === $product->get_status()
						&& $product->is_purchasable()
						&& $product->is_in_stock();
				}
			)
		);

		$ids = array_slice( $ids, 0, self::MAX_PARTNERS );

		/**
		 * Filter the recommended partner products for a product.
		 *
		 * @since 1.1.0
		 *
		 * @param int[] $ids        Partner product IDs.
		 * @param int   $product_id Product the recommendations belong to.
		 */
		return (array) apply_filters( 'glbbwc_recommended_partners', $ids, (int) $product_id );
	}

	/**
	 * Register the recommendation assets without enqueuing them.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			'glbbwc-recommendations',
			GLBBWC_PLUGIN_URL . 'assets/css/bundle-recommendations.css',
			array(),
			GLBBWC_VERSION
		);

		wp_register_script(
			'glbbwc-recommendations',
			GLBBWC_PLUGIN_URL . 'assets/js/bundle-recommendations.js',
			array(),
			GLBBWC_VERSION,
			true
		);

		wp_localize_script(
			'glbbwc-recommendations',
			'glbbwcRecommendParams',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				// Matches the picker: a route to the cart for shoppers who stay put.
				'cartUrl' => wc_get_cart_url(),
				'i18n'    => array(
					'adding'     => __( 'Adding…', 'growthlab-bundle-builder-for-woocommerce' ),
					'viewCart'   => __( 'View cart', 'growthlab-bundle-builder-for-woocommerce' ),
					'genericErr' => __( 'Something went wrong. Please refresh the page and try again.', 'growthlab-bundle-builder-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Enqueue the recommendation assets once.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	protected function enqueue_assets() {
		if ( $this->assets_enqueued ) {
			return;
		}

		wp_enqueue_style( 'glbbwc-recommendations' );
		wp_enqueue_script( 'glbbwc-recommendations' );

		$this->assets_enqueued = true;
	}

	/**
	 * Render the section beneath a single product.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function render_on_product_page() {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		// Bundles own their own picker UI; never advertise the hidden product.
		if ( GLBBWC_Bundle::from_product_id( get_the_ID() ) ) {
			return;
		}

		echo $this->render( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render().
	}

	/**
	 * Render the `[glbbwc_recommended_with]` shortcode.
	 *
	 * Fallback for themes that do not fire `woocommerce_after_single_product_summary`.
	 *
	 * @since 1.1.0
	 *
	 * @param array $atts Shortcode attributes.
	 *
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'glbbwc_recommended_with' );

		$product_id = absint( $atts['id'] );

		if ( ! $product_id && is_singular( 'product' ) ) {
			$product_id = (int) get_the_ID();
		}

		return $this->render( $product_id );
	}

	/**
	 * Build the "Frequently Bundled With" markup.
	 *
	 * @since 1.1.0
	 *
	 * @param int $product_id Product the recommendations belong to.
	 *
	 * @return string Empty string when there is nothing to show.
	 */
	public function render( $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$partner_ids = self::get_partner_ids( $product_id );

		if ( ! $partner_ids ) {
			return '';
		}

		$partners     = array();
		$resolved_ids = array();
		$total        = (float) $product->get_price();

		foreach ( $partner_ids as $partner_id ) {
			$partner = wc_get_product( $partner_id );

			if ( $partner instanceof WC_Product ) {
				$partners[]     = $partner;
				$resolved_ids[] = $partner->get_id();
				$total         += (float) $partner->get_price();
			}
		}

		if ( ! $partners ) {
			return '';
		}

		$this->enqueue_assets();

		ob_start();
		?>
		<section
			class="glbbwc-recommend"
			data-product-id="<?php echo esc_attr( (string) $product->get_id() ); ?>"
			data-partner-ids="<?php echo esc_attr( implode( ',', $resolved_ids ) ); ?>"
		>
			<h2 class="glbbwc-recommend__title"><?php esc_html_e( 'Frequently Bundled With', 'growthlab-bundle-builder-for-woocommerce' ); ?></h2>

			<ul class="glbbwc-recommend__grid">
				<li class="glbbwc-recommend__item glbbwc-recommend__item--main">
					<span class="glbbwc-recommend__media"><?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?></span>
					<span class="glbbwc-recommend__name"><?php echo esc_html( $product->get_name() ); ?></span>
					<span class="glbbwc-recommend__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
					<span class="glbbwc-recommend__badge"><?php esc_html_e( 'This item', 'growthlab-bundle-builder-for-woocommerce' ); ?></span>
				</li>

				<?php foreach ( $partners as $partner ) : ?>
					<li class="glbbwc-recommend__item" data-product-id="<?php echo esc_attr( (string) $partner->get_id() ); ?>">
						<a class="glbbwc-recommend__link" href="<?php echo esc_url( get_permalink( $partner->get_id() ) ); ?>">
							<span class="glbbwc-recommend__media"><?php echo wp_kses_post( $partner->get_image( 'woocommerce_thumbnail' ) ); ?></span>
							<span class="glbbwc-recommend__name"><?php echo esc_html( $partner->get_name() ); ?></span>
						</a>
						<span class="glbbwc-recommend__price"><?php echo wp_kses_post( $partner->get_price_html() ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>

			<div class="glbbwc-recommend__notices" role="alert" aria-live="assertive"></div>

			<div class="glbbwc-recommend__bar">
				<span class="glbbwc-recommend__total">
					<?php
					$item_total = count( $partners ) + 1;

					printf(
						/* translators: %d: number of items being added. */
						esc_html( _n( 'Total for %d item:', 'Total for %d items:', $item_total, 'growthlab-bundle-builder-for-woocommerce' ) ),
						(int) $item_total
					);
					?>
					<strong><?php echo wp_kses_post( wc_price( $total ) ); ?></strong>
				</span>

				<button type="button" class="glbbwc-recommend__submit button alt">
					<?php esc_html_e( 'Add all to cart', 'growthlab-bundle-builder-for-woocommerce' ); ?>
				</button>
			</div>
		</section>
		<?php

		/**
		 * Filter the rendered "Frequently Bundled With" markup.
		 *
		 * @since 1.1.0
		 *
		 * @param string $html       Section markup.
		 * @param int    $product_id Product the recommendations belong to.
		 * @param int[]  $partner_ids Partner product IDs.
		 */
		return (string) apply_filters( 'glbbwc_recommendations_html', (string) ob_get_clean(), (int) $product_id, $partner_ids );
	}

	/**
	 * Add the product and every curated partner to the cart as separate lines.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function add_recommended_to_cart() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error(
				array( 'messages' => array( __( 'The cart is unavailable. Please reload the page.', 'growthlab-bundle-builder-for-woocommerce' ) ) ),
				500
			);
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof WC_Product ) {
			wp_send_json_error(
				array( 'messages' => array( __( 'That product no longer exists.', 'growthlab-bundle-builder-for-woocommerce' ) ) ),
				404
			);
		}

		/*
		 * The browser sends no product list at all: the partners are re-read from
		 * the curated meta, so a tampered request cannot add arbitrary products.
		 */
		$ids = array_merge( array( $product_id ), self::get_partner_ids( $product_id ) );

		$added   = array();
		$skipped = array();

		foreach ( $ids as $id ) {
			$candidate = wc_get_product( $id );

			if ( ! $candidate instanceof WC_Product || ! $candidate->is_purchasable() || ! $candidate->is_in_stock() ) {
				$skipped[] = $candidate instanceof WC_Product ? $candidate->get_name() : (string) $id;
				continue;
			}

			if ( WC()->cart->add_to_cart( $id, 1 ) ) {
				$added[] = $candidate->get_name();
			} else {
				$skipped[] = $candidate->get_name();
			}
		}

		if ( ! $added ) {
			$messages = wc_get_notices( 'error' );
			wc_clear_notices();

			$messages = $messages
				? wp_list_pluck( $messages, 'notice' )
				: array( __( 'These products could not be added to your cart. Please try again.', 'growthlab-bundle-builder-for-woocommerce' ) );

			wp_send_json_error( array( 'messages' => array_map( 'wp_strip_all_tags', $messages ) ), 400 );
		}

		// One product failing should not lose the rest of the customer's click.
		wc_clear_notices();

		/**
		 * Fires after a "Frequently Bundled With" set has been added to the cart.
		 *
		 * @since 1.1.0
		 *
		 * @param int      $product_id Main product ID.
		 * @param string[] $added      Names added.
		 * @param string[] $skipped    Names that could not be added.
		 */
		do_action( 'glbbwc_after_add_recommended_to_cart', $product_id, $added, $skipped );

		// Matches GLBBWC_Ajax: honour the store's cart-redirect setting instead
		// of sending every shopper to the cart.
		$redirect = 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ? wc_get_cart_url() : '';

		wp_send_json_success(
			array(
				/**
				 * Filter where the customer is sent after adding a recommended set.
				 *
				 * Defaults to the store's cart-redirect setting.
				 *
				 * @since 1.1.0
				 *
				 * @param string $url        Redirect URL.
				 * @param int    $product_id Main product ID.
				 */
				'redirect'  => apply_filters( 'glbbwc_recommended_add_to_cart_redirect', $redirect, $product_id ),
				'message'   => sprintf(
					/* translators: %d: number of products added. */
					_n( '%d product added to your cart.', '%d products added to your cart.', count( $added ), 'growthlab-bundle-builder-for-woocommerce' ),
					count( $added )
				),
				'added'     => $added,
				'skipped'   => $skipped,
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter, applied so mini-cart widgets can refresh; not a hook this plugin owns.
				'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
				'cartHash'  => WC()->cart->get_cart_hash(),
			)
		);
	}

	/**
	 * Register the meta box on the product editor.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box(
			'glbbwc-recommendations',
			__( 'Recommended Bundle Partners', 'growthlab-bundle-builder-for-woocommerce' ),
			array( $this, 'render_meta_box' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Render the partner picker.
	 *
	 * Uses WooCommerce's own product search control, the same one behind the
	 * Upsells and Cross-sells fields, so no extra script is needed.
	 *
	 * @since 1.1.0
	 *
	 * @param WP_Post $post Product post.
	 *
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$selected = get_post_meta( $post->ID, self::META_KEY, true );
		$selected = is_array( $selected ) ? array_map( 'absint', $selected ) : array();

		wp_nonce_field( self::ADMIN_NONCE_ACTION, self::ADMIN_NONCE_NAME );

		// Present on every product screen; enqueued by WooCommerce itself.
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		?>
		<p>
			<label for="glbbwc-recommended-ids" class="screen-reader-text">
				<?php esc_html_e( 'Recommended bundle partners', 'growthlab-bundle-builder-for-woocommerce' ); ?>
			</label>
			<select
				id="glbbwc-recommended-ids"
				name="glbbwc_recommended_ids[]"
				class="wc-product-search"
				multiple="multiple"
				style="width: 100%;"
				data-placeholder="<?php esc_attr_e( 'Search for a product…', 'growthlab-bundle-builder-for-woocommerce' ); ?>"
				data-action="woocommerce_json_search_products_and_variations"
				data-exclude="<?php echo esc_attr( (string) $post->ID ); ?>"
			>
				<?php
				foreach ( $selected as $partner_id ) {
					$partner = wc_get_product( $partner_id );

					if ( ! $partner instanceof WC_Product ) {
						continue;
					}

					printf(
						'<option value="%1$s" selected="selected">%2$s</option>',
						esc_attr( (string) $partner_id ),
						esc_html( wp_strip_all_tags( $partner->get_formatted_name() ) )
					);
				}
				?>
			</select>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %d: maximum number of partners. */
				esc_html__( 'Shown as "Frequently Bundled With" on this product page. Pick up to %d products. They are added to the cart as separate items at their own prices — this is not a fixed-price bundle.', 'growthlab-bundle-builder-for-woocommerce' ),
				(int) self::MAX_PARTNERS
			);
			?>
		</p>
		<?php
	}

	/**
	 * Persist the curated partners.
	 *
	 * @since 1.1.0
	 *
	 * @param int     $post_id Product ID.
	 * @param WP_Post $post    Product post.
	 *
	 * @return void
	 */
	public function save_meta_box( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		if ( ! isset( $_POST[ self::ADMIN_NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::ADMIN_NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::ADMIN_NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$ids = isset( $_POST['glbbwc_recommended_ids'] )
			? array_map( 'absint', (array) wp_unslash( $_POST['glbbwc_recommended_ids'] ) )
			: array();

		// Never let a product recommend itself into a loop.
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		$ids = array_values( array_diff( $ids, array( (int) $post_id ) ) );
		$ids = array_slice( $ids, 0, self::MAX_PARTNERS );

		if ( $ids ) {
			update_post_meta( $post_id, self::META_KEY, $ids );
		} else {
			delete_post_meta( $post_id, self::META_KEY );
		}
	}
}
