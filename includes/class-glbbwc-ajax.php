<?php
/**
 * AJAX endpoints.
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Server-side add-to-cart handler.
 *
 * The browser's count is never trusted — every selection is re-validated here
 * against the bundle's own rules before it may reach the cart.
 *
 * @since 1.0.0
 */
class GLBBWC_Ajax {

	/**
	 * Single instance.
	 *
	 * @var GLBBWC_Ajax|null
	 */
	protected static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return GLBBWC_Ajax
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
		add_action( 'wp_ajax_glbbwc_add_bundle_to_cart', array( $this, 'add_bundle_to_cart' ) );
		add_action( 'wp_ajax_nopriv_glbbwc_add_bundle_to_cart', array( $this, 'add_bundle_to_cart' ) );
	}

	/**
	 * Read the posted `slots[<slot_id>][]` payload into a slot map.
	 *
	 * Unknown slot IDs are dropped rather than trusted, so the browser cannot
	 * invent a slot to smuggle products past per-slot validation.
	 *
	 * @since 1.1.0
	 *
	 * @param GLBBWC_Bundle $bundle Bundle being added.
	 *
	 * @return array|null Map of `slot_id => int[]`, or null when the request used
	 *                    the 1.0 flat `products[]` format.
	 */
	protected function parse_posted_slot_map( GLBBWC_Bundle $bundle ) {
		// Nonce is verified by the caller, add_bundle_to_cart().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['slots'] ) || ! is_array( $_POST['slots'] ) ) {
			return null;
		}

		// Sanitised on access; each value is re-cast with absint() below.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = map_deep( wp_unslash( (array) $_POST['slots'] ), 'absint' );
		$map = array();

		foreach ( $bundle->get_slots() as $slot ) {
			$picked = isset( $raw[ $slot['id'] ] ) ? (array) $raw[ $slot['id'] ] : array();

			$map[ $slot['id'] ] = array_values( array_unique( array_filter( array_map( 'absint', $picked ) ) ) );
		}

		return $map;
	}

	/**
	 * Validate a selection and add the bundle to the cart.
	 *
	 * @return void
	 */
	public function add_bundle_to_cart() {
		check_ajax_referer( GLBBWC_Frontend::NONCE_ACTION, 'nonce' );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error(
				array( 'messages' => array( __( 'The cart is unavailable. Please reload the page.', 'growthlab-bundle-builder-for-woocommerce' ) ) ),
				500
			);
		}

		$bundle_id = isset( $_POST['bundle_id'] ) ? absint( wp_unslash( $_POST['bundle_id'] ) ) : 0;
		$quantity  = isset( $_POST['quantity'] ) ? absint( wp_unslash( $_POST['quantity'] ) ) : 1;
		$quantity  = max( 1, $quantity );

		$bundle = GLBBWC_Bundle::get( $bundle_id );

		if ( ! $bundle ) {
			wp_send_json_error(
				array( 'messages' => array( __( 'That bundle no longer exists.', 'growthlab-bundle-builder-for-woocommerce' ) ) ),
				404
			);
		}

		/*
		 * 1.1.0 posts `slots[<slot_id>][]`; 1.0 posted a flat `products[]`. Accept
		 * both, so a cached script or a third-party integration written against
		 * the old endpoint keeps working — a flat list is assigned to slots by
		 * GLBBWC_Bundle::validate_selection().
		 */
		$slot_map = $this->parse_posted_slot_map( $bundle );

		if ( null !== $slot_map ) {
			$product_ids = array();

			foreach ( $slot_map as $picked ) {
				$product_ids = array_merge( $product_ids, $picked );
			}

			$product_ids = array_values( array_unique( $product_ids ) );
		} else {
			$raw_products = isset( $_POST['products'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['products'] ) ) : array();
			$product_ids  = array_values( array_unique( array_filter( $raw_products ) ) );
		}

		$validation = $bundle->validate_selection( $product_ids, $quantity, $slot_map );

		if ( is_wp_error( $validation ) ) {
			wp_send_json_error( array( 'messages' => $validation->get_error_messages() ), 400 );
		}

		$product_id = $bundle->get_product_id();

		if ( ! $product_id || ! wc_get_product( $product_id ) ) {
			// Self-heal a bundle whose hidden product was deleted outside the admin screen.
			$product_id = $bundle->sync_product();
		}

		if ( ! $product_id ) {
			wp_send_json_error(
				array( 'messages' => array( __( 'This bundle cannot be purchased right now. Please contact the store.', 'growthlab-bundle-builder-for-woocommerce' ) ) ),
				500
			);
		}

		$cart_item_data = array(
			'glbbwc_bundle_id'    => $bundle->get_id(),
			'glbbwc_selected_ids' => $product_ids,
			'glbbwc_price'        => $bundle->get_price(),
		);

		// Only carried for multi-slot bundles, so single-slot cart lines hash
		// identically to 1.0 and existing cart items are unaffected.
		if ( $bundle->has_multiple_slots() ) {
			$cart_item_data['glbbwc_slot_map'] = null !== $slot_map
				? $slot_map
				: $bundle->get_slot_map_for( $product_ids );
		}

		/**
		 * Filter the cart item data stored against a bundle line.
		 *
		 * Keys added here become part of the cart item hash, so two selections
		 * that differ only by custom data will occupy separate cart lines.
		 *
		 * @since 1.0.0
		 *
		 * @param array      $cart_item_data Cart item data.
		 * @param GLBBWC_Bundle $bundle         Bundle instance.
		 * @param int[]      $product_ids    Selected product IDs.
		 */
		$cart_item_data = apply_filters( 'glbbwc_bundle_cart_item_data', $cart_item_data, $bundle, $product_ids );

		/**
		 * Fires immediately before a validated bundle is added to the cart.
		 *
		 * @since 1.0.0
		 *
		 * @param GLBBWC_Bundle $bundle         Bundle instance.
		 * @param int[]      $product_ids    Selected product IDs.
		 * @param int        $quantity       Bundle quantity.
		 * @param array      $cart_item_data Cart item data about to be stored.
		 */
		do_action( 'glbbwc_before_add_bundle_to_cart', $bundle, $product_ids, $quantity, $cart_item_data );

		/*
		 * `$cart_item_data` carries the validated selection, which is what marks
		 * this line as a built bundle for GLBBWC_Cart::block_unbuilt_bundles().
		 */
		$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, 0, array(), $cart_item_data );

		if ( ! $cart_item_key ) {
			$messages = wc_get_notices( 'error' );
			wc_clear_notices();

			$messages = $messages
				? wp_list_pluck( $messages, 'notice' )
				: array( __( 'The bundle could not be added to your cart. Please try again.', 'growthlab-bundle-builder-for-woocommerce' ) );

			wp_send_json_error( array( 'messages' => array_map( 'wp_strip_all_tags', $messages ) ), 400 );
		}

		/**
		 * Fires after a bundle has been added to the cart.
		 *
		 * @since 1.0.0
		 *
		 * @param string     $cart_item_key Cart item key.
		 * @param GLBBWC_Bundle $bundle        Bundle instance.
		 * @param int[]      $product_ids   Selected product IDs.
		 * @param int        $quantity      Bundle quantity.
		 */
		do_action( 'glbbwc_after_add_bundle_to_cart', $cart_item_key, $bundle, $product_ids, $quantity );

		/*
		 * Follow the store's own "Redirect to the cart page after successful
		 * addition" setting rather than forcing the cart on every add, so a
		 * bundle behaves like every other product in the shop. When the setting
		 * is off the picker stays put, shows its success notice and clears the
		 * selection for the next build.
		 */
		$redirect = 'yes' === get_option( 'woocommerce_cart_redirect_after_add' ) ? wc_get_cart_url() : '';

		/**
		 * Filter where the customer is sent after adding a bundle.
		 *
		 * Defaults to the store's cart-redirect setting. Return an empty string
		 * to keep them on the picker, or a URL to always send them somewhere.
		 *
		 * @since 1.0.0
		 *
		 * @param string     $url    Redirect URL.
		 * @param GLBBWC_Bundle $bundle Bundle instance.
		 */
		$redirect = apply_filters( 'glbbwc_add_to_cart_redirect', $redirect, $bundle );

		wp_send_json_success(
			array(
				'cartItemKey' => $cart_item_key,
				'redirect'    => $redirect,
				'message'     => sprintf(
					/* translators: %s: bundle name. */
					__( '"%s" has been added to your cart.', 'growthlab-bundle-builder-for-woocommerce' ),
					$bundle->get_title()
				),
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core filter, applied so mini-cart widgets can refresh; not a hook this plugin owns.
				'fragments'   => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
				'cartHash'    => WC()->cart->get_cart_hash(),
			)
		);
	}
}
