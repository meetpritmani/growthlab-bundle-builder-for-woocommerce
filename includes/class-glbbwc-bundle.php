<?php
/**
 * Bundle model.
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read/write wrapper around a single `glbbwc_bundle` post.
 *
 * Owns everything that answers "what is this bundle and what may go in it" —
 * settings, eligible products, selection validation, and the hidden
 * WooCommerce product that represents the bundle in the cart.
 *
 * @since 1.0.0
 */
class GLBBWC_Bundle {

	const POST_TYPE = 'glbbwc_bundle';

	const META_CATEGORIES = '_glbbwc_categories';
	const META_ITEM_COUNT = '_glbbwc_item_count';
	const META_PRICE      = '_glbbwc_price';
	const META_ACTIVE     = '_glbbwc_active';
	const META_PRODUCT_ID = '_glbbwc_product_id';

	/**
	 * Product categories the bundle itself is listed under, added in 1.2.0.
	 *
	 * Deliberately separate from META_CATEGORIES: that key says which products a
	 * customer may pick, this one says where the finished bundle appears in the
	 * shop. Conflating them would put the bundle inside its own picker.
	 *
	 * @since 1.2.0
	 */
	const META_SHOP_CATEGORIES = '_glbbwc_shop_categories';

	/**
	 * Attachment IDs shown in the bundle's product gallery, added in 1.2.0.
	 *
	 * @since 1.2.0
	 */
	const META_GALLERY_IDS = '_glbbwc_gallery_ids';

	/**
	 * Whether the bundle is listed in the shop catalog, added in 1.2.0.
	 *
	 * Absent on bundles saved before 1.2.0, which stay hidden exactly as they
	 * were so an upgrade never publishes a bundle the store did not choose to
	 * list.
	 *
	 * @since 1.2.0
	 */
	const META_CATALOG_VISIBLE = '_glbbwc_catalog_visible';

	/**
	 * Optional manual ceiling on how many bundles may be sold, added in 1.2.0.
	 *
	 * Empty means "no ceiling" — availability is then purely what the component
	 * stock supports. See get_buildable_quantity().
	 *
	 * @since 1.2.0
	 */
	const META_STOCK_LIMIT = '_glbbwc_stock_limit';

	/**
	 * Category slots, added in 1.1.0.
	 *
	 * Stored as a list of `array( 'id', 'categories', 'count' )`. Bundles saved
	 * before 1.1.0 have no value here and are represented by a single slot
	 * derived from META_CATEGORIES + META_ITEM_COUNT, so they keep behaving
	 * exactly as they did in 1.0.
	 */
	const META_SLOTS = '_glbbwc_slots';

	/**
	 * Meta key written on the hidden product pointing back at the bundle.
	 */
	const PRODUCT_META_BUNDLE_ID = '_glbbwc_bundle_id';

	/**
	 * Bundle post ID.
	 *
	 * @var int
	 */
	protected $id = 0;

	/**
	 * Bundle post object.
	 *
	 * @var WP_Post|null
	 */
	protected $post = null;

	/**
	 * Memoised buildable quantity for this request.
	 *
	 * Holds `false` until computed, then an int or null (unlimited). Component
	 * stock cannot change within a single request in any way that matters here,
	 * and the figure is re-derived from live data on every add-to-cart.
	 *
	 * @since 1.2.0
	 *
	 * @var int|null|false
	 */
	protected $buildable = false;

	/**
	 * Constructor.
	 *
	 * @param int|WP_Post $bundle Bundle post ID or object.
	 */
	public function __construct( $bundle ) {
		$post = get_post( $bundle );

		if ( $post instanceof WP_Post && self::POST_TYPE === $post->post_type ) {
			$this->post = $post;
			$this->id   = (int) $post->ID;
		}
	}

	/**
	 * Load a bundle, or null when the ID is not a bundle.
	 *
	 * @param int|WP_Post $bundle Bundle post ID or object.
	 *
	 * @return GLBBWC_Bundle|null
	 */
	public static function get( $bundle ) {
		$instance = new self( $bundle );

		return $instance->exists() ? $instance : null;
	}

	/**
	 * Whether the underlying post resolved.
	 *
	 * @return bool
	 */
	public function exists() {
		return $this->id > 0;
	}

	/**
	 * Bundle post ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Bundle post object.
	 *
	 * @return WP_Post|null
	 */
	public function get_post() {
		return $this->post;
	}

	/**
	 * Bundle title.
	 *
	 * @return string
	 */
	public function get_title() {
		return $this->post ? $this->post->post_title : '';
	}

	/**
	 * Bundle permalink.
	 *
	 * @return string
	 */
	public function get_permalink() {
		return $this->exists() ? (string) get_permalink( $this->id ) : '';
	}

	/**
	 * Source category term IDs.
	 *
	 * @return int[]
	 */
	public function get_category_ids() {
		$categories = get_post_meta( $this->id, self::META_CATEGORIES, true );

		if ( ! is_array( $categories ) ) {
			$categories = array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $categories ) ) ) );
	}

	/**
	 * Product categories the finished bundle is listed under in the shop.
	 *
	 * @since 1.2.0
	 *
	 * @return int[]
	 */
	public function get_shop_category_ids() {
		$categories = get_post_meta( $this->id, self::META_SHOP_CATEGORIES, true );

		if ( ! is_array( $categories ) ) {
			$categories = array();
		}

		$categories = array_values( array_unique( array_filter( array_map( 'absint', $categories ) ) ) );

		/**
		 * Filter the product categories a bundle is listed under.
		 *
		 * @since 1.2.0
		 *
		 * @param int[]         $categories Term IDs.
		 * @param GLBBWC_Bundle $bundle     Bundle instance.
		 */
		return (array) apply_filters( 'glbbwc_bundle_shop_categories', $categories, $this );
	}

	/**
	 * Attachment IDs making up the bundle's product gallery.
	 *
	 * @since 1.2.0
	 *
	 * @return int[]
	 */
	public function get_gallery_image_ids() {
		$ids = get_post_meta( $this->id, self::META_GALLERY_IDS, true );

		if ( ! is_array( $ids ) ) {
			$ids = array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Whether the bundle should be listed in the shop catalog.
	 *
	 * Bundles saved before 1.2.0 have no stored value and stay hidden, matching
	 * how they behaved on the version the store installed.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_catalog_visible() {
		return 'yes' === get_post_meta( $this->id, self::META_CATALOG_VISIBLE, true );
	}

	/**
	 * Optional manual ceiling on how many bundles may be sold.
	 *
	 * @since 1.2.0
	 *
	 * @return int|null Ceiling, or null when the store has not set one.
	 */
	public function get_stock_limit() {
		$limit = get_post_meta( $this->id, self::META_STOCK_LIMIT, true );

		if ( '' === $limit || null === $limit ) {
			return null;
		}

		return max( 0, (int) $limit );
	}

	/**
	 * How many products the customer must pick.
	 *
	 * @return int
	 */
	public function get_item_count() {
		return max( 0, (int) get_post_meta( $this->id, self::META_ITEM_COUNT, true ) );
	}

	/**
	 * The bundle's category slots.
	 *
	 * Always returns at least one slot for a configured bundle. Bundles saved
	 * before 1.1.0 have no slot meta, so their categories and item count are
	 * folded into a single slot — which is why a legacy bundle spanning several
	 * categories still lets the customer pick freely across all of them.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] List of `array( 'id', 'categories', 'count', 'label' )`.
	 */
	public function get_slots() {
		$raw   = get_post_meta( $this->id, self::META_SLOTS, true );
		$slots = array();

		if ( is_array( $raw ) ) {
			$index = 0;

			foreach ( $raw as $slot ) {
				if ( ! is_array( $slot ) ) {
					continue;
				}

				$categories = isset( $slot['categories'] ) ? (array) $slot['categories'] : array();
				$categories = array_values( array_unique( array_filter( array_map( 'absint', $categories ) ) ) );
				$count      = isset( $slot['count'] ) ? (int) $slot['count'] : 0;

				if ( ! $categories || $count < 1 ) {
					continue;
				}

				$slots[] = array(
					'id'         => isset( $slot['id'] ) && '' !== $slot['id'] ? sanitize_key( $slot['id'] ) : 'slot-' . $index,
					'categories' => $categories,
					'count'      => $count,
					'label'      => $this->build_slot_label( $categories ),
				);

				++$index;
			}
		}

		if ( ! $slots ) {
			$categories = $this->get_category_ids();
			$count      = $this->get_item_count();

			if ( $categories && $count > 0 ) {
				$slots[] = array(
					'id'         => 'slot-0',
					'categories' => $categories,
					'count'      => $count,
					'label'      => $this->build_slot_label( $categories ),
				);
			}
		}

		/**
		 * Filter the bundle's category slots.
		 *
		 * @since 1.1.0
		 *
		 * @param array[]    $slots  Normalised slots.
		 * @param GLBBWC_Bundle $bundle Bundle instance.
		 */
		return (array) apply_filters( 'glbbwc_bundle_slots', $slots, $this );
	}

	/**
	 * A single slot by ID.
	 *
	 * @since 1.1.0
	 *
	 * @param string $slot_id Slot ID.
	 *
	 * @return array|null
	 */
	public function get_slot( $slot_id ) {
		foreach ( $this->get_slots() as $slot ) {
			if ( $slot['id'] === $slot_id ) {
				return $slot;
			}
		}

		return null;
	}

	/**
	 * Whether the bundle splits its picks across more than one slot.
	 *
	 * Single-slot bundles render and validate exactly as they did in 1.0.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function has_multiple_slots() {
		return count( $this->get_slots() ) > 1;
	}

	/**
	 * Human-readable name for a slot, built from its categories.
	 *
	 * @since 1.1.0
	 *
	 * @param int[] $categories Term IDs.
	 *
	 * @return string
	 */
	protected function build_slot_label( array $categories ) {
		$names = array();

		foreach ( $categories as $term_id ) {
			$term = get_term( $term_id, 'product_cat' );

			if ( $term instanceof WP_Term ) {
				$names[] = $term->name;
			}
		}

		return $names ? implode( ' + ', $names ) : __( 'Products', 'growthlab-bundle-builder-for-woocommerce' );
	}

	/**
	 * Flat bundle price.
	 *
	 * @return float
	 */
	public function get_price() {
		$price = (float) get_post_meta( $this->id, self::META_PRICE, true );

		/**
		 * Filter the flat price charged for a bundle.
		 *
		 * Runs both when pricing the cart line and when rendering the picker, so
		 * dynamic pricing extensions stay consistent between the two.
		 *
		 * @since 1.0.0
		 *
		 * @param float      $price  Flat bundle price.
		 * @param GLBBWC_Bundle $bundle Bundle instance.
		 */
		return (float) apply_filters( 'glbbwc_bundle_price', $price, $this );
	}

	/**
	 * Whether the bundle is switched on.
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'yes' === get_post_meta( $this->id, self::META_ACTIVE, true );
	}

	/**
	 * Whether the bundle post is actually published.
	 *
	 * `get_post()` resolves drafts, pending posts and trashed posts alike, so a
	 * GLBBWC_Bundle object existing says nothing about whether the store has
	 * published it. Without this check a draft bundle stays purchasable through
	 * the AJAX endpoint by anyone who knows its ID, and a trashed one lingers in
	 * carts that already hold it.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_published() {
		return 'publish' === get_post_status( $this->id );
	}

	/**
	 * Whether the bundle has everything it needs to be rendered and purchased.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return $this->exists() && $this->get_item_count() > 0 && $this->get_category_ids() && $this->get_price() >= 0;
	}

	/**
	 * ID of the hidden WooCommerce product representing this bundle.
	 *
	 * @return int
	 */
	public function get_product_id() {
		return (int) get_post_meta( $this->id, self::META_PRODUCT_ID, true );
	}

	/**
	 * The hidden WooCommerce product representing this bundle.
	 *
	 * @return WC_Product|null
	 */
	public function get_product() {
		$product_id = $this->get_product_id();

		if ( ! $product_id ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * Human-readable "Pick any N" label.
	 *
	 * @return string
	 */
	public function get_summary() {
		// wc_price() returns markup with HTML-encoded currency symbols; decode so
		// the caller can escape the finished string exactly once.
		$price = html_entity_decode(
			wp_strip_all_tags( wc_price( $this->get_price() ) ),
			ENT_QUOTES,
			get_bloginfo( 'charset' )
		);

		$slots = $this->get_slots();

		if ( count( $slots ) > 1 ) {
			$parts = array();

			foreach ( $slots as $slot ) {
				$parts[] = sprintf(
					/* translators: 1: number of items, 2: category name. */
					__( '%1$d from %2$s', 'growthlab-bundle-builder-for-woocommerce' ),
					$slot['count'],
					$slot['label']
				);
			}

			return sprintf(
				/* translators: 1: per-slot breakdown such as "2 from Tops, 1 from Bottoms", 2: formatted bundle price. */
				__( 'Pick %1$s for %2$s', 'growthlab-bundle-builder-for-woocommerce' ),
				implode( ', ', $parts ),
				$price
			);
		}

		return sprintf(
			/* translators: 1: number of items to pick, 2: formatted bundle price. */
			__( 'Pick any %1$d for %2$s', 'growthlab-bundle-builder-for-woocommerce' ),
			$this->get_item_count(),
			$price
		);
	}

	/**
	 * IDs of products a customer may choose from.
	 *
	 * Simple, purchasable, published, in-stock products in the bundle's source
	 * categories (child categories included).
	 *
	 * @since 1.1.0 Accepts an optional slot ID to narrow the result to one slot.
	 *
	 * @param string|null $slot_id Slot to scope to, or null for every category
	 *                             the bundle draws from.
	 *
	 * @return int[]
	 */
	public function get_eligible_product_ids( $slot_id = null ) {
		$slot = null;

		if ( null !== $slot_id ) {
			$slot = $this->get_slot( $slot_id );

			if ( ! $slot ) {
				return array();
			}

			$categories = $slot['categories'];
		} else {
			$categories = $this->get_category_ids();
		}

		if ( ! $categories ) {
			return array();
		}

		/**
		 * Filter the maximum number of eligible products loaded for a bundle.
		 *
		 * Keeps the picker page from unbounded growth on large catalogs.
		 *
		 * @since 1.0.0
		 *
		 * @param int        $limit  Maximum products to load.
		 * @param GLBBWC_Bundle $bundle Bundle instance.
		 */
		$limit = (int) apply_filters( 'glbbwc_bundle_max_eligible_products', 200, $this );

		$args = array(
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'fields'              => 'ids',
			'orderby'             => 'title',
			'order'               => 'ASC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,

			/*
			 * Bundles are backed by simple products, so a bundle listed in one of
			 * its own source categories would otherwise show up inside its own
			 * picker — and nest, once picked. Excluding anything carrying the
			 * back-reference meta keeps bundles out of every bundle.
			 */
			'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::PRODUCT_META_BUNDLE_ID,
					'compare' => 'NOT EXISTS',
				),
			),
			'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'relation' => 'AND',
				array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $categories,
					'include_children' => true,
				),
				array(
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => array( 'simple' ),
				),
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => array( 'outofstock' ),
					'operator' => 'NOT IN',
				),
			),
		);

		/**
		 * Filter the WP_Query arguments used to find eligible products.
		 *
		 * @since 1.0.0
		 *
		 * @param array      $args   Query arguments.
		 * @param GLBBWC_Bundle $bundle Bundle instance.
		 * @param array|null $slot   Slot being queried, or null for all categories.
		 */
		$args = apply_filters( 'glbbwc_bundle_eligible_products_query_args', $args, $this, $slot );

		$query = new WP_Query( $args );
		$ids   = array_map( 'absint', (array) $query->posts );

		// Belt and braces: the visibility term can drift out of sync with real stock state.
		$ids = array_values(
			array_filter(
				$ids,
				static function ( $product_id ) {
					// The meta_query above already drops bundle products; this
					// second check also covers IDs added by the query filter.
					if ( get_post_meta( $product_id, self::PRODUCT_META_BUNDLE_ID, true ) ) {
						return false;
					}

					$product = wc_get_product( $product_id );

					return $product instanceof WC_Product && $product->is_purchasable() && $product->is_in_stock();
				}
			)
		);

		/**
		 * Filter the products a customer may pick for a bundle.
		 *
		 * The returned IDs are authoritative: they drive both the rendered grid
		 * and server-side add-to-cart validation, so anything removed here
		 * becomes unpurchasable, not merely hidden.
		 *
		 * @since 1.0.0
		 *
		 * @param int[]      $ids    Eligible product IDs.
		 * @param GLBBWC_Bundle $bundle Bundle instance.
		 * @param array|null $slot   Slot being queried, or null for all categories.
		 */
		$ids = apply_filters( 'glbbwc_bundle_eligible_products', $ids, $this, $slot );

		return array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Eligible products as WC_Product objects.
	 *
	 * @since 1.1.0 Accepts an optional slot ID.
	 *
	 * @param string|null $slot_id Slot to scope to, or null for every category.
	 *
	 * @return WC_Product[]
	 */
	public function get_eligible_products( $slot_id = null ) {
		$products = array();

		foreach ( $this->get_eligible_product_ids( $slot_id ) as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( $product instanceof WC_Product ) {
				$products[] = $product;
			}
		}

		return $products;
	}

	/**
	 * How many complete bundles the current component stock supports.
	 *
	 * The store never types this number in — it is derived, which is the whole
	 * point: a "pick 2 from Tops" bundle backed by three shirts can be sold
	 * three times at most, and a hand-maintained figure drifts out of step the
	 * moment one of those shirts sells.
	 *
	 * @since 1.2.0
	 *
	 * @return int|null Number of bundles, or null when supply is effectively
	 *                  unlimited (no component manages stock).
	 */
	public function get_buildable_quantity() {
		if ( false !== $this->buildable ) {
			return $this->buildable;
		}

		$slots     = $this->get_slots();
		$buildable = null;

		if ( ! $slots ) {
			$buildable = 0;
		}

		foreach ( $slots as $slot ) {
			$available = $this->get_slot_buildable_quantity( $slot );

			// One empty slot makes the whole bundle unbuildable, so stop early.
			if ( 0 === $available ) {
				$buildable = 0;
				break;
			}

			if ( null === $available ) {
				continue;
			}

			$buildable = null === $buildable ? $available : min( $buildable, $available );
		}

		$limit = $this->get_stock_limit();

		if ( null !== $limit ) {
			$buildable = null === $buildable ? $limit : min( $buildable, $limit );
		}

		/**
		 * Filter how many complete bundles the current stock supports.
		 *
		 * @since 1.2.0
		 *
		 * @param int|null      $buildable Bundles available, or null for unlimited.
		 * @param GLBBWC_Bundle $bundle    Bundle instance.
		 */
		$buildable = apply_filters( 'glbbwc_bundle_buildable_quantity', $buildable, $this );

		$this->buildable = null === $buildable ? null : max( 0, (int) $buildable );

		return $this->buildable;
	}

	/**
	 * How many bundles one slot's stock can fill.
	 *
	 * A slot asking for `N` items draws `N` *different* products per bundle, so
	 * a product with 40 in stock still only contributes one unit to each bundle
	 * built. `K` bundles are therefore fillable exactly when
	 * `sum( min( stock, K ) ) >= K * N` across the slot's products — monotonic
	 * in `K`, so the largest workable `K` is found by binary search.
	 *
	 * @since 1.2.0
	 *
	 * @param array $slot Slot definition.
	 *
	 * @return int|null Bundles this slot supports, or null when unlimited.
	 */
	protected function get_slot_buildable_quantity( array $slot ) {
		$needed    = max( 1, (int) $slot['count'] );
		$unlimited = 0;
		$stocks    = array();

		foreach ( $this->get_eligible_products( $slot['id'] ) as $product ) {
			// Backorders make a managed product effectively bottomless too.
			if ( ! $product->managing_stock() || $product->backorders_allowed() ) {
				++$unlimited;
				continue;
			}

			$quantity = (int) $product->get_stock_quantity();

			if ( $quantity > 0 ) {
				$stocks[] = $quantity;
			}
		}

		// Enough bottomless products to fill the slot outright.
		if ( $unlimited >= $needed ) {
			return null;
		}

		if ( ! $stocks ) {
			return 0;
		}

		/*
		 * Each bundle needs `needed - unlimited` units from the finite pool and
		 * no product may contribute twice to the same bundle, so total finite
		 * stock divided by that shortfall bounds the search from above.
		 */
		$high = (int) floor( array_sum( $stocks ) / ( $needed - $unlimited ) );
		$low  = 0;

		while ( $low < $high ) {
			$mid = (int) ceil( ( $low + $high + 1 ) / 2 );

			if ( $this->slot_supports_quantity( $stocks, $unlimited, $needed, $mid ) ) {
				$low = $mid;
			} else {
				$high = $mid - 1;
			}
		}

		return $low;
	}

	/**
	 * Whether a slot's stock can fill a given number of bundles.
	 *
	 * @since 1.2.0
	 *
	 * @param int[] $stocks    Stock quantity of each finite-stock product.
	 * @param int   $unlimited How many products carry unlimited stock.
	 * @param int   $needed    Items the slot asks for per bundle.
	 * @param int   $quantity  Bundles to test for.
	 *
	 * @return bool
	 */
	protected function slot_supports_quantity( array $stocks, $unlimited, $needed, $quantity ) {
		if ( $quantity < 1 ) {
			return true;
		}

		// Each unlimited product can supply one unit to every bundle.
		$supply = $unlimited * $quantity;

		foreach ( $stocks as $stock ) {
			$supply += min( $stock, $quantity );

			// Short-circuit once the requirement is met on large catalogs.
			if ( $supply >= $quantity * $needed ) {
				return true;
			}
		}

		return $supply >= $quantity * $needed;
	}

	/**
	 * Whether the bundle can currently be built at all.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_in_stock() {
		$buildable = $this->get_buildable_quantity();

		return null === $buildable || $buildable > 0;
	}

	/**
	 * Forget the memoised buildable quantity.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function flush_stock_cache() {
		$this->buildable = false;
	}

	/**
	 * Re-derive the linked product's stock status from component stock.
	 *
	 * Only writes when the status actually flips, so the common case — a
	 * component selling one unit out of many — costs a read and nothing more.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function refresh_stock_status() {
		$product = $this->get_product();

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$this->flush_stock_cache();

		$status = $this->is_in_stock() ? 'instock' : 'outofstock';

		if ( $product->get_stock_status() === $status ) {
			return;
		}

		$product->set_stock_status( $status );
		$product->save();

		/**
		 * Fires when a bundle's derived stock status changes.
		 *
		 * @since 1.2.0
		 *
		 * @param string        $status New stock status.
		 * @param GLBBWC_Bundle $bundle Bundle instance.
		 */
		do_action( 'glbbwc_bundle_stock_status_changed', $status, $this );
	}

	/**
	 * IDs of every published bundle that can draw on a given product.
	 *
	 * A bundle listing a parent category also draws on its children, so the
	 * product's category ancestors are matched too.
	 *
	 * @since 1.2.0
	 *
	 * @param int $product_id Product whose stock changed.
	 *
	 * @return int[] Bundle post IDs.
	 */
	public static function get_bundle_ids_using_product( $product_id ) {
		$product_id = absint( $product_id );

		// A bundle's own product is never a component of anything.
		if ( ! $product_id || get_post_meta( $product_id, self::PRODUCT_META_BUNDLE_ID, true ) ) {
			return array();
		}

		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

		if ( is_wp_error( $terms ) || ! $terms ) {
			return array();
		}

		$categories = array_map( 'absint', $terms );

		foreach ( $terms as $term_id ) {
			$categories = array_merge( $categories, array_map( 'absint', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) );
		}

		$categories = array_unique( $categories );

		/**
		 * Filter how many bundles are examined when a product's stock changes.
		 *
		 * @since 1.2.0
		 *
		 * @param int $limit Maximum bundles to load.
		 */
		$limit = (int) apply_filters( 'glbbwc_stock_refresh_bundle_limit', 500 );

		$bundle_ids = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => $limit,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);

		$matched = array();

		foreach ( array_map( 'absint', (array) $bundle_ids ) as $bundle_id ) {
			$sources = get_post_meta( $bundle_id, self::META_CATEGORIES, true );

			if ( ! is_array( $sources ) ) {
				continue;
			}

			if ( array_intersect( $categories, array_map( 'absint', $sources ) ) ) {
				$matched[] = $bundle_id;
			}
		}

		return $matched;
	}

	/**
	 * Validate a customer selection before it may enter the cart.
	 *
	 * Never trusts the browser: re-checks count, category membership, and stock.
	 *
	 * @since 1.1.0 Accepts an optional slot map so multi-slot bundles are checked
	 *              slot by slot. Omit it and the map is derived from the IDs,
	 *              which keeps 1.0 callers and pre-1.1 cart items working.
	 *
	 * @param int[]      $product_ids Selected product IDs.
	 * @param int        $quantity    How many copies of the bundle.
	 * @param array|null $slot_map    Optional `slot_id => int[]` selection map.
	 *
	 * @return true|WP_Error True when the selection is purchasable.
	 */
	public function validate_selection( array $product_ids, $quantity = 1, $slot_map = null ) {
		$quantity    = max( 1, (int) $quantity );
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
		$error       = new WP_Error();

		if ( ! $this->is_configured() ) {
			$error->add( 'glbbwc_not_configured', __( 'This bundle is not fully configured yet.', 'growthlab-bundle-builder-for-woocommerce' ) );
		}

		if ( ! $this->is_active() || ! $this->is_published() ) {
			$error->add( 'glbbwc_inactive', __( 'This bundle is not currently available.', 'growthlab-bundle-builder-for-woocommerce' ) );
		}

		/*
		 * Derived availability is checked ahead of the per-product stock loop
		 * below. That loop catches "this shirt has only 2 left"; this catches
		 * "three shirts between them cannot fill a pick-2 bundle four times",
		 * which no single product's stock reveals.
		 */
		$buildable = $this->get_buildable_quantity();

		if ( null !== $buildable && $buildable < $quantity ) {
			$error->add(
				'glbbwc_not_buildable',
				0 === $buildable
					? __( 'This bundle is out of stock — there are not enough products left to build it.', 'growthlab-bundle-builder-for-woocommerce' )
					: sprintf(
						/* translators: %d: number of bundles that can still be built. */
						_n(
							'Only %d of this bundle can be built from the products left in stock.',
							'Only %d of these bundles can be built from the products left in stock.',
							$buildable,
							'growthlab-bundle-builder-for-woocommerce'
						),
						$buildable
					)
			);
		}

		$slots    = $this->get_slots();
		$is_multi = count( $slots ) > 1;

		if ( null === $slot_map ) {
			$slot_map = $this->derive_slot_map( $product_ids );
		} else {
			$slot_map = $this->normalise_slot_map( $slot_map );
		}

		if ( ! $error->has_errors() && null === $slot_map ) {
			$error->add(
				'glbbwc_slot_unassignable',
				__( 'Some of the selected products do not fit this bundle. Please refresh and pick again.', 'growthlab-bundle-builder-for-woocommerce' )
			);
		}

		if ( ! $error->has_errors() ) {
			// Anything the map could not place belongs to no slot at all.
			$mapped = array();

			foreach ( $slot_map as $picked ) {
				$mapped = array_merge( $mapped, $picked );
			}

			foreach ( array_diff( $product_ids, $mapped ) as $stray_id ) {
				$stray = wc_get_product( $stray_id );

				$error->add(
					'glbbwc_not_eligible',
					sprintf(
						/* translators: %s: product name. */
						__( '"%s" is no longer part of this bundle. Please refresh and pick another item.', 'growthlab-bundle-builder-for-woocommerce' ),
						$stray instanceof WC_Product ? $stray->get_name() : (string) $stray_id
					)
				);
			}
		}

		if ( ! $error->has_errors() ) {
			foreach ( $slots as $slot ) {
				$picked   = isset( $slot_map[ $slot['id'] ] ) ? $slot_map[ $slot['id'] ] : array();
				$eligible = $this->get_eligible_product_ids( $slot['id'] );

				if ( count( $picked ) !== $slot['count'] ) {
					$error->add(
						'glbbwc_wrong_count',
						$is_multi
							? sprintf(
								/* translators: 1: required item count, 2: slot name, 3: selected item count. */
								_n(
									'Please select exactly %1$d item from %2$s — you selected %3$d.',
									'Please select exactly %1$d items from %2$s — you selected %3$d.',
									$slot['count'],
									'growthlab-bundle-builder-for-woocommerce'
								),
								$slot['count'],
								$slot['label'],
								count( $picked )
							)
							: sprintf(
								/* translators: 1: required item count, 2: selected item count. */
								__( 'Please select exactly %1$d items — you selected %2$d.', 'growthlab-bundle-builder-for-woocommerce' ),
								$slot['count'],
								count( $picked )
							)
					);

					continue;
				}

				foreach ( $picked as $product_id ) {
					$product = wc_get_product( $product_id );

					if ( ! $product instanceof WC_Product ) {
						$error->add( 'glbbwc_missing_product', __( 'One of the selected products no longer exists. Please refresh and try again.', 'growthlab-bundle-builder-for-woocommerce' ) );
						continue;
					}

					if ( ! in_array( $product_id, $eligible, true ) ) {
						$error->add(
							'glbbwc_not_eligible',
							sprintf(
								/* translators: %s: product name. */
								__( '"%s" is no longer part of this bundle. Please refresh and pick another item.', 'growthlab-bundle-builder-for-woocommerce' ),
								$product->get_name()
							)
						);
						continue;
					}

					if ( ! $product->is_in_stock() ) {
						$error->add(
							'glbbwc_out_of_stock',
							sprintf(
								/* translators: %s: product name. */
								__( '"%s" just went out of stock. Please pick another item.', 'growthlab-bundle-builder-for-woocommerce' ),
								$product->get_name()
							)
						);
						continue;
					}

					if ( ! $product->has_enough_stock( $quantity ) ) {
						$error->add(
							'glbbwc_insufficient_stock',
							sprintf(
								/* translators: 1: product name, 2: quantity in stock. */
								__( 'There is not enough stock of "%1$s" (%2$s available) for this many bundles.', 'growthlab-bundle-builder-for-woocommerce' ),
								$product->get_name(),
								wc_format_stock_quantity_for_display( (float) $product->get_stock_quantity(), $product )
							)
						);
					}
				}
			}
		}

		/**
		 * Filter the validation result for a bundle selection.
		 *
		 * Add errors here to block an add-to-cart, or remove them to permit one.
		 *
		 * @since 1.0.0
		 * @since 1.1.0 Added the `$slot_map` argument.
		 *
		 * @param WP_Error   $error       Accumulated errors (may be empty).
		 * @param int[]      $product_ids Selected product IDs.
		 * @param int        $quantity    Bundle quantity.
		 * @param GLBBWC_Bundle $bundle      Bundle instance.
		 * @param array|null $slot_map    Selection grouped by slot ID.
		 */
		$error = apply_filters( 'glbbwc_validate_bundle_selection', $error, $product_ids, $quantity, $this, $slot_map );

		return ( $error instanceof WP_Error && $error->has_errors() ) ? $error : true;
	}

	/**
	 * Public wrapper around slot derivation, for callers holding a flat ID list.
	 *
	 * @since 1.1.0
	 *
	 * @param int[] $product_ids Selected product IDs.
	 *
	 * @return array Map of `slot_id => int[]`; empty when nothing could be placed.
	 */
	public function get_slot_map_for( array $product_ids ) {
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
		$map         = $this->derive_slot_map( $product_ids );

		return null === $map ? array() : $map;
	}

	/**
	 * Clean a caller-supplied slot map down to known slots and integer IDs.
	 *
	 * @since 1.1.0
	 *
	 * @param array $slot_map Raw `slot_id => int[]` map.
	 *
	 * @return array
	 */
	protected function normalise_slot_map( $slot_map ) {
		$clean = array();

		foreach ( $this->get_slots() as $slot ) {
			$picked = isset( $slot_map[ $slot['id'] ] ) ? (array) $slot_map[ $slot['id'] ] : array();

			$clean[ $slot['id'] ] = array_values( array_unique( array_filter( array_map( 'absint', $picked ) ) ) );
		}

		return $clean;
	}

	/**
	 * Work out which slot each picked product belongs to.
	 *
	 * Used for 1.0-era cart items and any caller that only has a flat list of
	 * IDs. Products that fit exactly one slot are placed first, so the harder
	 * cases — a product sitting in categories owned by two different slots —
	 * are resolved against whatever capacity is left.
	 *
	 * @since 1.1.0
	 *
	 * @param int[] $product_ids Selected product IDs.
	 *
	 * @return array|null Map of `slot_id => int[]`, or null if a product fits nowhere.
	 */
	protected function derive_slot_map( array $product_ids ) {
		$slots = $this->get_slots();

		if ( ! $slots ) {
			return null;
		}

		$map = array();

		foreach ( $slots as $slot ) {
			$map[ $slot['id'] ] = array();
		}

		// Single-slot bundles are the 1.0 case: everything lands in the one slot.
		if ( 1 === count( $slots ) ) {
			$map[ $slots[0]['id'] ] = $product_ids;

			return $map;
		}

		$eligible = array();

		foreach ( $slots as $slot ) {
			$eligible[ $slot['id'] ] = $this->get_eligible_product_ids( $slot['id'] );
		}

		$options = array();

		foreach ( $product_ids as $product_id ) {
			$fits = array();

			foreach ( $slots as $slot ) {
				if ( in_array( $product_id, $eligible[ $slot['id'] ], true ) ) {
					$fits[] = $slot['id'];
				}
			}

			if ( ! $fits ) {
				return null;
			}

			$options[ $product_id ] = $fits;
		}

		// Fewest possible homes first, so forced placements happen before guesses.
		uasort(
			$options,
			static function ( $a, $b ) {
				return count( $a ) <=> count( $b );
			}
		);

		$capacity = wp_list_pluck( $slots, 'count', 'id' );

		foreach ( $options as $product_id => $fits ) {
			$placed = false;

			foreach ( $fits as $slot_id ) {
				if ( count( $map[ $slot_id ] ) < $capacity[ $slot_id ] ) {
					$map[ $slot_id ][] = $product_id;
					$placed            = true;
					break;
				}
			}

			if ( ! $placed ) {
				// Every slot it could go in is already full — let the count check report it.
				$map[ $fits[0] ][] = $product_id;
			}
		}

		return $map;
	}

	/**
	 * Create or update the WooCommerce product backing this bundle.
	 *
	 * WooCommerce carts are keyed on real products, so each bundle owns one
	 * virtual product priced at the flat bundle price. Since 1.2.0 that product
	 * also carries the bundle's shop categories, gallery and derived stock
	 * status, which is what lets a bundle appear in the shop loop, category
	 * archives and search alongside ordinary products.
	 *
	 * @return int Product ID, or 0 on failure.
	 */
	public function sync_product() {
		if ( ! $this->exists() ) {
			return 0;
		}

		$product_id = $this->get_product_id();
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof WC_Product_Simple ) {
			$product = new WC_Product_Simple();
		}

		$price  = wc_format_decimal( $this->get_price(), wc_get_price_decimals() );
		$post   = $this->get_post();
		$parent = $post instanceof WP_Post ? $post : null;

		$product->set_name( $this->get_title() ? $this->get_title() : __( 'Bundle', 'growthlab-bundle-builder-for-woocommerce' ) );

		/*
		 * The linked product mirrors the bundle's own status. Publishing it
		 * unconditionally would leave a draft bundle sitting in the catalog,
		 * purchasable, before the store had finished writing it.
		 */
		$product->set_status( $this->is_published() ? 'publish' : 'draft' );
		$product->set_catalog_visibility( $this->is_catalog_visible() ? 'visible' : 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( false );
		$product->set_regular_price( $price );
		$product->set_price( $price );
		$product->set_image_id( (int) get_post_thumbnail_id( $this->id ) );
		$product->set_gallery_image_ids( $this->get_gallery_image_ids() );
		$product->set_category_ids( $this->get_shop_category_ids() );
		$product->update_meta_data( self::PRODUCT_META_BUNDLE_ID, $this->id );

		// The listing needs something to say in the shop loop and search results.
		$product->set_short_description( $parent ? (string) $parent->post_excerpt : '' );

		/*
		 * Stock is derived, never stored as a count: WooCommerce would decrement
		 * a managed figure on checkout while the real constraint lives in the
		 * component products, which Woo decrements itself. Recording only the
		 * status keeps the shop badge honest without a second ledger to reconcile.
		 */
		$product->set_manage_stock( false );
		$product->set_stock_status( $this->is_in_stock() ? 'instock' : 'outofstock' );

		$product_id = $product->save();

		if ( $product_id ) {
			update_post_meta( $this->id, self::META_PRODUCT_ID, (int) $product_id );
		}

		/**
		 * Fires after a bundle's hidden product has been created or updated.
		 *
		 * @since 1.0.0
		 *
		 * @param int        $product_id Hidden product ID.
		 * @param GLBBWC_Bundle $bundle     Bundle instance.
		 */
		do_action( 'glbbwc_bundle_product_synced', (int) $product_id, $this );

		return (int) $product_id;
	}

	/**
	 * Look up a bundle from its hidden product ID.
	 *
	 * @param int $product_id Hidden product ID.
	 *
	 * @return GLBBWC_Bundle|null
	 */
	public static function from_product_id( $product_id ) {
		$bundle_id = (int) get_post_meta( (int) $product_id, self::PRODUCT_META_BUNDLE_ID, true );

		return $bundle_id ? self::get( $bundle_id ) : null;
	}
}
