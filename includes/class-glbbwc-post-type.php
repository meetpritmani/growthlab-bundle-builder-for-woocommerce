<?php
/**
 * Bundle custom post type.
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `glbbwc_bundle` post type and keeps its hidden product in sync.
 *
 * @since 1.0.0
 */
class GLBBWC_Post_Type {

	/**
	 * Single instance.
	 *
	 * @var GLBBWC_Post_Type|null
	 */
	protected static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return GLBBWC_Post_Type
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Bundle IDs whose derived stock needs re-checking before the request ends.
	 *
	 * @since 1.2.0
	 *
	 * @var int[]
	 */
	protected $pending_stock_refresh = array();

	/**
	 * Hook into WordPress.
	 */
	protected function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'trashed_post', array( $this, 'trash_bundle_product' ) );
		add_action( 'untrashed_post', array( $this, 'restore_bundle_product' ) );
		add_action( 'before_delete_post', array( $this, 'delete_bundle_product' ) );

		/*
		 * The linked product's status mirrors the bundle's, and a bundle can
		 * change status without the edit screen ever being submitted — Quick
		 * Edit, bulk edit, a scheduled publish, or another plugin. Watching the
		 * transition keeps the two in step wherever the change came from.
		 */
		add_action( 'transition_post_status', array( $this, 'sync_product_status' ), 10, 3 );

		/*
		 * A bundle's availability is derived from its components, so it has to be
		 * re-checked whenever one of them moves. The work is deferred to shutdown
		 * and de-duplicated, so an order touching six products — or a bulk stock
		 * import — still costs one pass per affected bundle.
		 */
		add_action( 'woocommerce_product_set_stock', array( $this, 'queue_stock_refresh' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'queue_stock_refresh' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'queue_stock_refresh_by_id' ) );
		add_action( 'shutdown', array( $this, 'run_stock_refresh' ) );
	}

	/**
	 * Queue the bundles affected by a product whose stock changed.
	 *
	 * @since 1.2.0
	 *
	 * @param WC_Product $product Product whose stock changed.
	 *
	 * @return void
	 */
	public function queue_stock_refresh( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$this->queue_stock_refresh_by_id( $product->get_id() );
	}

	/**
	 * Queue the bundles affected by a product ID whose stock changed.
	 *
	 * @since 1.2.0
	 *
	 * @param int $product_id Product whose stock changed.
	 *
	 * @return void
	 */
	public function queue_stock_refresh_by_id( $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return;
		}

		$this->pending_stock_refresh = array_unique(
			array_merge(
				$this->pending_stock_refresh,
				GLBBWC_Bundle::get_bundle_ids_using_product( $product_id )
			)
		);
	}

	/**
	 * Re-derive stock status for every queued bundle.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function run_stock_refresh() {
		if ( ! $this->pending_stock_refresh ) {
			return;
		}

		$bundle_ids                  = $this->pending_stock_refresh;
		$this->pending_stock_refresh = array();

		foreach ( $bundle_ids as $bundle_id ) {
			$bundle = GLBBWC_Bundle::get( $bundle_id );

			if ( $bundle ) {
				$bundle->refresh_stock_status();
			}
		}
	}

	/**
	 * Register the bundle post type.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		$labels = array(
			'name'                  => _x( 'Bundles', 'Post type general name', 'growthlab-bundle-builder-for-woocommerce' ),
			'singular_name'         => _x( 'Bundle', 'Post type singular name', 'growthlab-bundle-builder-for-woocommerce' ),
			'menu_name'             => _x( 'Bundles', 'Admin menu text', 'growthlab-bundle-builder-for-woocommerce' ),
			'add_new'               => __( 'Add New', 'growthlab-bundle-builder-for-woocommerce' ),
			'add_new_item'          => __( 'Add New Bundle', 'growthlab-bundle-builder-for-woocommerce' ),
			'edit_item'             => __( 'Edit Bundle', 'growthlab-bundle-builder-for-woocommerce' ),
			'new_item'              => __( 'New Bundle', 'growthlab-bundle-builder-for-woocommerce' ),
			'view_item'             => __( 'View Bundle', 'growthlab-bundle-builder-for-woocommerce' ),
			'search_items'          => __( 'Search Bundles', 'growthlab-bundle-builder-for-woocommerce' ),
			'not_found'             => __( 'No bundles found.', 'growthlab-bundle-builder-for-woocommerce' ),
			'not_found_in_trash'    => __( 'No bundles found in Trash.', 'growthlab-bundle-builder-for-woocommerce' ),
			'all_items'             => __( 'Bundles', 'growthlab-bundle-builder-for-woocommerce' ),
			'featured_image'        => __( 'Bundle image', 'growthlab-bundle-builder-for-woocommerce' ),
			'set_featured_image'    => __( 'Set bundle image', 'growthlab-bundle-builder-for-woocommerce' ),
			'remove_featured_image' => __( 'Remove bundle image', 'growthlab-bundle-builder-for-woocommerce' ),
			'use_featured_image'    => __( 'Use as bundle image', 'growthlab-bundle-builder-for-woocommerce' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => 'woocommerce',
			'show_in_nav_menus'  => true,
			'show_in_rest'       => false,
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => 58,
			'supports'           => array( 'title', 'editor', 'thumbnail' ),
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
			'capabilities'       => array(
				'edit_posts'             => 'manage_woocommerce',
				'edit_others_posts'      => 'manage_woocommerce',
				'edit_published_posts'   => 'manage_woocommerce',
				'publish_posts'          => 'manage_woocommerce',
				'delete_posts'           => 'manage_woocommerce',
				'delete_others_posts'    => 'manage_woocommerce',
				'delete_published_posts' => 'manage_woocommerce',
				'read_private_posts'     => 'manage_woocommerce',
				'create_posts'           => 'manage_woocommerce',
			),
			'rewrite'            => array(
				'slug'       => _x( 'bundle', 'Bundle permalink slug', 'growthlab-bundle-builder-for-woocommerce' ),
				'with_front' => false,
			),
		);

		/**
		 * Filter the arguments used to register the bundle post type.
		 *
		 * @since 1.0.0
		 *
		 * @param array $args Post type arguments.
		 */
		register_post_type( GLBBWC_Bundle::POST_TYPE, apply_filters( 'glbbwc_post_type_args', $args ) );
	}

	/**
	 * Re-sync a bundle's product whenever the bundle changes status.
	 *
	 * Reads nothing from the request and writes nothing a store admin has not
	 * already saved: it copies the bundle's own stored state onto the product it
	 * owns. Trashing is left to trash_bundle_product(), and a bundle with no
	 * product yet is ignored so abandoned auto-drafts never create one.
	 *
	 * @since 1.2.0
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       Post being transitioned.
	 *
	 * @return void
	 */
	public function sync_product_status( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || GLBBWC_Bundle::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( $new_status === $old_status || 'trash' === $new_status ) {
			return;
		}

		$bundle = GLBBWC_Bundle::get( $post );

		if ( ! $bundle || ! $bundle->get_product_id() ) {
			return;
		}

		$bundle->sync_product();
	}

	/**
	 * Hide the bundle's product when the bundle is trashed.
	 *
	 * @param int $post_id Trashed post ID.
	 *
	 * @return void
	 */
	public function trash_bundle_product( $post_id ) {
		$bundle = GLBBWC_Bundle::get( $post_id );

		if ( ! $bundle ) {
			return;
		}

		$product_id = $bundle->get_product_id();

		if ( $product_id && 'trash' !== get_post_status( $product_id ) ) {
			wp_trash_post( $product_id );
		}
	}

	/**
	 * Restore the bundle's product alongside the bundle.
	 *
	 * @param int $post_id Restored post ID.
	 *
	 * @return void
	 */
	public function restore_bundle_product( $post_id ) {
		$bundle = GLBBWC_Bundle::get( $post_id );

		if ( ! $bundle ) {
			return;
		}

		$product_id = $bundle->get_product_id();

		if ( $product_id && 'trash' === get_post_status( $product_id ) ) {
			wp_untrash_post( $product_id );

			/*
			 * Re-syncing rather than force-publishing: a bundle restored from the
			 * trash goes back to draft, and its product must follow rather than
			 * reappear in the catalog on its own.
			 */
			$bundle->sync_product();
		}
	}

	/**
	 * Permanently delete the bundle's product with the bundle.
	 *
	 * @param int $post_id Post about to be deleted.
	 *
	 * @return void
	 */
	public function delete_bundle_product( $post_id ) {
		$bundle = GLBBWC_Bundle::get( $post_id );

		if ( ! $bundle ) {
			return;
		}

		$product_id = $bundle->get_product_id();

		if ( $product_id ) {
			wp_delete_post( $product_id, true );
		}
	}
}
