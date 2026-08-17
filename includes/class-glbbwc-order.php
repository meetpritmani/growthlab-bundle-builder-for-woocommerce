<?php
/**
 * Order integration.
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Persists bundle selections onto orders and materialises them as zero-priced
 * child line items so WooCommerce handles stock natively.
 *
 * Because each picked product becomes a real order line item, stock reduction,
 * restock-on-refund, low-stock notifications, and reports all work without any
 * custom inventory code.
 *
 * @since 1.0.0
 */
class GLBBWC_Order {

	const ITEM_META_BUNDLE_ID  = '_glbbwc_bundle_id';
	const ITEM_META_SELECTED   = '_glbbwc_selected_ids';
	const ITEM_META_PARENT     = '_glbbwc_bundle_parent';
	const ITEM_META_PARENT_KEY = '_glbbwc_bundle_parent_key';

	/**
	 * Slot map recorded on multi-slot bundle lines, added in 1.1.0.
	 */
	const ITEM_META_SLOT_MAP = '_glbbwc_slot_map';

	/**
	 * Single instance.
	 *
	 * @var GLBBWC_Order|null
	 */
	protected static $instance = null;

	/**
	 * Set while re-ordering, to skip re-adding zero-priced child items.
	 *
	 * @var bool
	 */
	protected $skip_next_add_to_cart = false;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return GLBBWC_Order
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
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'create_order_line_item' ), 10, 4 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( $this, 'display_meta_key' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hidden_item_meta' ) );
		add_filter( 'woocommerce_order_item_name', array( $this, 'order_item_name' ), 10, 2 );

		// "Order again" support: restore bundle data, never re-add free child items.
		add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 'order_again_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'maybe_skip_add_to_cart' ) );
	}

	/**
	 * Write bundle meta onto the order line and add its child items.
	 *
	 * @param WC_Order_Item_Product $item          Order line item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order being created.
	 *
	 * @return void
	 */
	public function create_order_line_item( $item, $cart_item_key, $values, $order ) {
		if ( ! GLBBWC_Cart::is_bundle_item( $values ) ) {
			return;
		}

		$bundle_id   = (int) $values['glbbwc_bundle_id'];
		$product_ids = array_map( 'absint', (array) $values['glbbwc_selected_ids'] );
		$quantity    = max( 1, (int) $values['quantity'] );
		$names       = GLBBWC_Cart::get_selected_names( $product_ids );

		$item->add_meta_data( self::ITEM_META_BUNDLE_ID, $bundle_id, true );
		$item->add_meta_data( self::ITEM_META_SELECTED, $product_ids, true );

		// Multi-slot bundles get one visible row per slot; single-slot bundles
		// keep the exact "Your selection" row they had in 1.0.
		$grouped = GLBBWC_Cart::get_grouped_selection( $values );

		if ( $grouped ) {
			$item->add_meta_data( self::ITEM_META_SLOT_MAP, (array) $values['glbbwc_slot_map'], true );

			foreach ( $grouped as $label => $group_names ) {
				$item->add_meta_data( $label, implode( ', ', $group_names ), true );
			}
		} else {
			$item->add_meta_data( __( 'Your selection', 'growthlab-bundle-builder-for-woocommerce' ), implode( ', ', $names ), true );
		}

		/**
		 * Fires while a bundle order line item is being created.
		 *
		 * @since 1.0.0
		 *
		 * @param WC_Order_Item_Product $item        Order line item.
		 * @param int                   $bundle_id   Bundle post ID.
		 * @param int[]                 $product_ids Selected product IDs.
		 * @param WC_Order              $order       Order being created.
		 */
		do_action( 'glbbwc_create_order_line_item', $item, $bundle_id, $product_ids, $order );

		/**
		 * Filter whether picked products are added as zero-priced child line items.
		 *
		 * Disabling this stops WooCommerce from reducing stock for the picked
		 * products — you would need to handle inventory yourself.
		 *
		 * @since 1.0.0
		 *
		 * @param bool     $enabled   Whether to add child line items.
		 * @param int      $bundle_id Bundle post ID.
		 * @param WC_Order $order     Order being created.
		 */
		if ( ! apply_filters( 'glbbwc_add_child_line_items', true, $bundle_id, $order ) ) {
			return;
		}

		$bundle_title = get_the_title( $bundle_id );

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			// Mirrors WC_Abstract_Order::add_product(), but priced at zero — the
			// flat bundle price is already carried by the parent line.
			$child = new WC_Order_Item_Product();
			$child->set_props(
				array(
					'name'         => $product->get_name(),
					'tax_class'    => $product->get_tax_class(),
					'product_id'   => $product->get_id(),
					'variation_id' => 0,
					'quantity'     => $quantity,
					'subtotal'     => 0,
					'total'        => 0,
					'subtotal_tax' => 0,
					'total_tax'    => 0,
					'taxes'        => array(
						'total'    => array(),
						'subtotal' => array(),
					),
				)
			);
			$child->set_backorder_meta();

			$child->add_meta_data( self::ITEM_META_PARENT, $bundle_id, true );
			$child->add_meta_data( self::ITEM_META_PARENT_KEY, $cart_item_key, true );
			$child->add_meta_data( __( 'Part of bundle', 'growthlab-bundle-builder-for-woocommerce' ), $bundle_title, true );

			$order->add_item( $child );
		}
	}

	/**
	 * Whether an order item is a zero-priced bundle child.
	 *
	 * @param WC_Order_Item $item Order item.
	 *
	 * @return bool
	 */
	public static function is_child_item( $item ) {
		return $item instanceof WC_Order_Item && (int) $item->get_meta( self::ITEM_META_PARENT, true ) > 0;
	}

	/**
	 * Whether an order item is a bundle line.
	 *
	 * @param WC_Order_Item $item Order item.
	 *
	 * @return bool
	 */
	public static function is_bundle_item( $item ) {
		return $item instanceof WC_Order_Item && (int) $item->get_meta( self::ITEM_META_BUNDLE_ID, true ) > 0;
	}

	/**
	 * Indent child items so the bundle grouping reads clearly.
	 *
	 * @param string        $name Item name.
	 * @param WC_Order_Item $item Order item.
	 *
	 * @return string
	 */
	public function order_item_name( $name, $item ) {
		if ( ! self::is_child_item( $item ) ) {
			return $name;
		}

		/**
		 * Filter the prefix shown before a bundle child line item's name.
		 *
		 * @since 1.0.0
		 *
		 * @param string        $prefix Prefix string.
		 * @param WC_Order_Item $item   Order item.
		 */
		$prefix = apply_filters( 'glbbwc_child_item_name_prefix', '↳ ', $item );

		return $prefix . $name;
	}

	/**
	 * Keep internal meta out of the customer-facing meta list.
	 *
	 * @param string $display_key Display key.
	 *
	 * @return string
	 */
	public function display_meta_key( $display_key ) {
		$internal = array(
			self::ITEM_META_BUNDLE_ID,
			self::ITEM_META_SELECTED,
			self::ITEM_META_SLOT_MAP,
			self::ITEM_META_PARENT,
			self::ITEM_META_PARENT_KEY,
		);

		return in_array( $display_key, $internal, true ) ? '' : $display_key;
	}

	/**
	 * Hide internal meta on the admin order screen.
	 *
	 * @param array $hidden Hidden meta keys.
	 *
	 * @return array
	 */
	public function hidden_item_meta( $hidden ) {
		return array_merge(
			(array) $hidden,
			array(
				self::ITEM_META_BUNDLE_ID,
				self::ITEM_META_SELECTED,
				self::ITEM_META_SLOT_MAP,
				self::ITEM_META_PARENT,
				self::ITEM_META_PARENT_KEY,
			)
		);
	}

	/**
	 * Restore bundle data when a customer re-orders.
	 *
	 * Bundle lines keep their selection; the zero-priced child items are flagged
	 * to be skipped, otherwise re-ordering would drop free products in the cart.
	 *
	 * @param array         $data Cart item data.
	 * @param WC_Order_Item $item Order item being re-added.
	 *
	 * @return array
	 */
	public function order_again_cart_item_data( $data, $item ) {
		// Reset first: WC_Cart::add_to_cart can bail before our validation filter
		// runs, which would otherwise leave a stale flag set for the next item.
		$this->skip_next_add_to_cart = false;

		if ( self::is_child_item( $item ) ) {
			$this->skip_next_add_to_cart = true;

			return $data;
		}

		if ( ! self::is_bundle_item( $item ) ) {
			return $data;
		}

		$bundle_id   = (int) $item->get_meta( self::ITEM_META_BUNDLE_ID, true );
		$product_ids = array_map( 'absint', (array) $item->get_meta( self::ITEM_META_SELECTED, true ) );
		$bundle      = GLBBWC_Bundle::get( $bundle_id );

		if ( ! $bundle ) {
			$this->skip_next_add_to_cart = true;

			return $data;
		}

		$stored   = $item->get_meta( self::ITEM_META_SLOT_MAP, true );
		$slot_map = is_array( $stored ) && $stored ? $stored : null;

		if ( is_wp_error( $bundle->validate_selection( $product_ids, (int) $item->get_quantity(), $slot_map ) ) ) {
			// The old selection is no longer buyable — send them back to the picker instead.
			$this->skip_next_add_to_cart = true;

			return $data;
		}

		$data['glbbwc_bundle_id']    = $bundle_id;
		$data['glbbwc_selected_ids'] = $product_ids;
		$data['glbbwc_price']        = $bundle->get_price();

		if ( $bundle->has_multiple_slots() ) {
			$data['glbbwc_slot_map'] = null !== $slot_map ? $slot_map : $bundle->get_slot_map_for( $product_ids );
		}

		return $data;
	}

	/**
	 * Consume the skip flag set during "order again".
	 *
	 * @param bool $passed Whether validation passed.
	 *
	 * @return bool
	 */
	public function maybe_skip_add_to_cart( $passed ) {
		if ( $this->skip_next_add_to_cart ) {
			$this->skip_next_add_to_cart = false;

			return false;
		}

		return $passed;
	}
}
