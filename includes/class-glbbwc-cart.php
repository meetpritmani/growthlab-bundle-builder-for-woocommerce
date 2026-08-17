<?php
/**
 * Cart integration.
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prices bundle lines, describes them, and re-validates them on every cart load.
 *
 * @since 1.0.0
 */
class GLBBWC_Cart {

	/**
	 * Single instance.
	 *
	 * @var GLBBWC_Cart|null
	 */
	protected static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return GLBBWC_Cart
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook into WooCommerce.
	 */
	protected function __construct() {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_bundle_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_selection' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_permalink', array( $this, 'cart_item_permalink' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'revalidate_cart' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'block_unbuilt_bundles' ), 10, 6 );
	}

	/**
	 * Refuse a bundle product that arrives in the cart without a selection.
	 *
	 * WooCommerce applies this filter from the form handler, the AJAX handler,
	 * the Store API, and when a persistent cart is restored — but *not* from
	 * `WC_Cart::add_to_cart()` itself, which is how this plugin adds a bundle
	 * once it has validated the selection.
	 *
	 * The decision therefore rests on the cart item data rather than on any
	 * in-flight flag. A line carrying a bundle ID and a selection has been
	 * through GLBBWC_Bundle::validate_selection(); one arriving bare came from a
	 * hand-written `?add-to-cart=` URL, a Store API call or a theme's catalog
	 * button, and would otherwise sell an empty bundle at the full price.
	 *
	 * Relying on a flag instead would drop legitimate bundles out of a returning
	 * customer's saved cart, because the session restore above runs with no such
	 * add in progress.
	 *
	 * @since 1.2.0
	 *
	 * @param bool  $passed         Whether validation has passed so far.
	 * @param int   $product_id     Product being added.
	 * @param int   $quantity       Quantity being added.
	 * @param int   $variation_id   Variation ID, unused for bundles.
	 * @param array $variations     Variation data, unused for bundles.
	 * @param array $cart_item_data Custom data travelling with the line.
	 *
	 * @return bool
	 */
	public function block_unbuilt_bundles( $passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		if ( ! $passed ) {
			return $passed;
		}

		$bundle = GLBBWC_Bundle::from_product_id( (int) $product_id );

		if ( ! $bundle ) {
			return $passed;
		}

		if ( self::is_bundle_item( (array) $cart_item_data ) ) {
			/*
			 * A built line. Whether its products are still purchasable is settled
			 * by revalidate_cart() on the next cart or checkout load, which can
			 * explain itself; refusing here would make the line disappear from a
			 * restored cart with no message at all.
			 */
			return $passed;
		}

		wc_add_notice(
			sprintf(
				/* translators: 1: opening link tag to the bundle page, 2: closing link tag. */
				__( 'Please choose the items for this bundle first — %1$sbuild your bundle%2$s.', 'growthlab-bundle-builder-for-woocommerce' ),
				'<a href="' . esc_url( $bundle->get_permalink() ) . '">',
				'</a>'
			),
			'error'
		);

		return false;
	}

	/**
	 * Whether a cart item is a bundle line.
	 *
	 * @param array $cart_item Cart item.
	 *
	 * @return bool
	 */
	public static function is_bundle_item( $cart_item ) {
		return ! empty( $cart_item['glbbwc_bundle_id'] ) && ! empty( $cart_item['glbbwc_selected_ids'] );
	}

	/**
	 * Charge the flat bundle price rather than the sum of the picked products.
	 *
	 * @param WC_Cart $cart Cart instance.
	 *
	 * @return void
	 */
	public function set_bundle_prices( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! self::is_bundle_item( $cart_item ) || ! isset( $cart_item['data'] ) ) {
				continue;
			}

			$bundle = GLBBWC_Bundle::get( (int) $cart_item['glbbwc_bundle_id'] );
			$price  = $bundle ? $bundle->get_price() : (float) ( $cart_item['glbbwc_price'] ?? 0 );

			/**
			 * Filter the price applied to a bundle cart line.
			 *
			 * This is the extension point for per-product surcharges: add the
			 * surcharge total for `$cart_item['glbbwc_selected_ids']` here and it
			 * flows through cart, checkout, and order totals unchanged.
			 *
			 * @since 1.0.0
			 *
			 * @param float           $price     Flat bundle price.
			 * @param array           $cart_item Cart item.
			 * @param GLBBWC_Bundle|null $bundle    Bundle instance, if it still exists.
			 */
			$price = (float) apply_filters( 'glbbwc_cart_item_price', $price, $cart_item, $bundle );

			$cart_item['data']->set_price( $price );
		}
	}

	/**
	 * List the picked products under the bundle line in cart and checkout.
	 *
	 * @param array $item_data Existing item data rows.
	 * @param array $cart_item Cart item.
	 *
	 * @return array
	 */
	public function display_selection( $item_data, $cart_item ) {
		if ( ! self::is_bundle_item( $cart_item ) ) {
			return $item_data;
		}

		// Multi-slot bundles read far better broken out per slot, so the customer
		// can see they picked the right things from each group.
		$grouped = self::get_grouped_selection( $cart_item );

		if ( $grouped ) {
			foreach ( $grouped as $label => $group_names ) {
				$item_data[] = array(
					'key'     => $label,
					'value'   => implode( ', ', $group_names ),
					'display' => self::format_names( $group_names ),
				);
			}

			return $item_data;
		}

		$names = self::get_selected_names( (array) $cart_item['glbbwc_selected_ids'] );

		if ( ! $names ) {
			return $item_data;
		}

		$item_data[] = array(
			'key'     => __( 'Your selection', 'growthlab-bundle-builder-for-woocommerce' ),
			'value'   => implode( ', ', $names ),
			'display' => self::format_names( $names ),
		);

		return $item_data;
	}

	/**
	 * Render product names as inline, comma-separated markup.
	 *
	 * The block Cart and Checkout strip tags out of a meta row's `display` value
	 * rather than rendering them, so block-level markup such as `<ul><li>` would
	 * collapse into "ItemOneItemTwoItemThree" with no separator. Keeping the
	 * commas outside the spans means the names stay readable whether the markup
	 * survives or is stripped back to plain text.
	 *
	 * @since 1.1.0
	 *
	 * @param string[] $names Product names.
	 *
	 * @return string
	 */
	protected static function format_names( array $names ) {
		return implode(
			', ',
			array_map(
				static function ( $name ) {
					return '<span class="glbbwc-selection-item">' . esc_html( $name ) . '</span>';
				},
				$names
			)
		);
	}

	/**
	 * Group a bundle cart item's selection by slot label.
	 *
	 * @since 1.1.0
	 *
	 * @param array $cart_item Cart item.
	 *
	 * @return array<string,string[]> Slot label => product names, empty when the
	 *                                item is a single-slot or pre-1.1 line.
	 */
	public static function get_grouped_selection( $cart_item ) {
		if ( empty( $cart_item['glbbwc_slot_map'] ) || ! is_array( $cart_item['glbbwc_slot_map'] ) ) {
			return array();
		}

		$bundle = GLBBWC_Bundle::get( (int) $cart_item['glbbwc_bundle_id'] );

		if ( ! $bundle || ! $bundle->has_multiple_slots() ) {
			return array();
		}

		$grouped = array();

		foreach ( $bundle->get_slots() as $slot ) {
			$picked = isset( $cart_item['glbbwc_slot_map'][ $slot['id'] ] )
				? (array) $cart_item['glbbwc_slot_map'][ $slot['id'] ]
				: array();

			if ( ! $picked ) {
				continue;
			}

			$grouped[ $slot['label'] ] = self::get_selected_names( $picked );
		}

		return $grouped;
	}

	/**
	 * Point the cart line at the bundle page, not the hidden product.
	 *
	 * @param string $permalink Product permalink.
	 * @param array  $cart_item Cart item.
	 *
	 * @return string
	 */
	public function cart_item_permalink( $permalink, $cart_item ) {
		if ( ! self::is_bundle_item( $cart_item ) ) {
			return $permalink;
		}

		$bundle = GLBBWC_Bundle::get( (int) $cart_item['glbbwc_bundle_id'] );

		return $bundle ? $bundle->get_permalink() : $permalink;
	}

	/**
	 * Re-check every bundle line whenever the cart or checkout is loaded.
	 *
	 * Catches products that sold out, left the category, or were deleted after
	 * the customer built their bundle.
	 *
	 * @return void
	 */
	public function revalidate_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! self::is_bundle_item( $cart_item ) ) {
				continue;
			}

			$bundle = GLBBWC_Bundle::get( (int) $cart_item['glbbwc_bundle_id'] );

			/*
			 * A trashed or unpublished bundle still resolves to an object, so the
			 * status has to be checked separately — otherwise trashing a bundle
			 * would leave it sitting in carts that already held it, still
			 * checkout-able.
			 */
			if ( ! $bundle || ! $bundle->is_published() ) {
				wc_add_notice( __( 'A bundle in your cart is no longer available and has been removed.', 'growthlab-bundle-builder-for-woocommerce' ), 'error' );
				WC()->cart->remove_cart_item( $cart_item_key );
				continue;
			}

			// Pre-1.1 lines carry no slot map; validate_selection() derives one.
			$slot_map = ! empty( $cart_item['glbbwc_slot_map'] ) && is_array( $cart_item['glbbwc_slot_map'] )
				? $cart_item['glbbwc_slot_map']
				: null;

			$validation = $bundle->validate_selection(
				(array) $cart_item['glbbwc_selected_ids'],
				(int) $cart_item['quantity'],
				$slot_map
			);

			if ( is_wp_error( $validation ) ) {
				foreach ( $validation->get_error_messages() as $message ) {
					wc_add_notice(
						esc_html(
							sprintf(
								/* translators: 1: bundle name, 2: reason the bundle is invalid. */
								__( '%1$s: %2$s', 'growthlab-bundle-builder-for-woocommerce' ),
								$bundle->get_title(),
								$message
							)
						),
						'error'
					);
				}
			}
		}
	}

	/**
	 * Resolve product names for a set of IDs, falling back gracefully.
	 *
	 * @param int[] $product_ids Selected product IDs.
	 *
	 * @return string[]
	 */
	public static function get_selected_names( array $product_ids ) {
		$names = array();

		foreach ( array_map( 'absint', $product_ids ) as $product_id ) {
			$product = wc_get_product( $product_id );

			$names[] = $product instanceof WC_Product
				? $product->get_name()
				: sprintf(
					/* translators: %d: product ID. */
					__( 'Removed product (#%d)', 'growthlab-bundle-builder-for-woocommerce' ),
					$product_id
				);
		}

		return $names;
	}
}
