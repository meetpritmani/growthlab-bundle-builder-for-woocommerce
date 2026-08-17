<?php
/**
 * Admin screens: meta boxes, saving, and the bundle list table.
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything the store admin touches when configuring a bundle.
 *
 * @since 1.0.0
 */
class GLBBWC_Admin {

	/**
	 * Nonce action used by the settings meta box.
	 */
	const NONCE_ACTION = 'glbbwc_save_bundle';

	/**
	 * Nonce field name used by the settings meta box.
	 */
	const NONCE_NAME = 'glbbwc_bundle_nonce';

	/**
	 * Single instance.
	 *
	 * @var GLBBWC_Admin|null
	 */
	protected static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return GLBBWC_Admin
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
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . GLBBWC_Bundle::POST_TYPE, array( $this, 'save_bundle' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_filter( 'manage_' . GLBBWC_Bundle::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . GLBBWC_Bundle::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'post_updated_messages', array( $this, 'updated_messages' ) );
	}

	/**
	 * Load the admin stylesheet on bundle edit screens only.
	 *
	 * @param string $hook Current admin page hook.
	 *
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || GLBBWC_Bundle::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'glbbwc-admin',
			GLBBWC_PLUGIN_URL . 'assets/css/bundle-builder-admin.css',
			array(),
			GLBBWC_VERSION
		);

		// The slot repeater only exists on the bundle editor, not the list table.
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		// Required by the gallery picker, which opens the core media modal.
		wp_enqueue_media();

		wp_enqueue_script(
			'glbbwc-admin',
			GLBBWC_PLUGIN_URL . 'assets/js/bundle-builder-admin.js',
			array(),
			GLBBWC_VERSION,
			true
		);

		wp_localize_script(
			'glbbwc-admin',
			'glbbwcAdminParams',
			array(
				'i18n' => array(
					'emptySlot'     => __( 'One or more bundle slots have no category selected. They will be discarded — continue?', 'growthlab-bundle-builder-for-woocommerce' ),
					'galleryTitle'  => __( 'Bundle gallery images', 'growthlab-bundle-builder-for-woocommerce' ),
					'galleryButton' => __( 'Add to gallery', 'growthlab-bundle-builder-for-woocommerce' ),
					'removeImage'   => __( 'Remove image', 'growthlab-bundle-builder-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Register the bundle meta boxes.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'glbbwc-bundle-settings',
			__( 'Bundle Settings', 'growthlab-bundle-builder-for-woocommerce' ),
			array( $this, 'render_settings_meta_box' ),
			GLBBWC_Bundle::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'glbbwc-bundle-catalog',
			__( 'Shop Listing', 'growthlab-bundle-builder-for-woocommerce' ),
			array( $this, 'render_catalog_meta_box' ),
			GLBBWC_Bundle::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'glbbwc-bundle-usage',
			__( 'Display Bundle', 'growthlab-bundle-builder-for-woocommerce' ),
			array( $this, 'render_usage_meta_box' ),
			GLBBWC_Bundle::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Render the shop listing meta box.
	 *
	 * Everything here describes the bundle *as a catalog product* — where it is
	 * listed and how it is pictured — as opposed to the settings box above,
	 * which describes what a customer may put in it.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_Post $post Bundle post.
	 *
	 * @return void
	 */
	public function render_catalog_meta_box( $post ) {
		$is_new  = ! $post instanceof WP_Post || 'auto-draft' === $post->post_status;
		$bundle  = $is_new ? null : GLBBWC_Bundle::get( $post );
		$visible = $bundle ? $bundle->is_catalog_visible() : false;
		$shop    = $bundle ? $bundle->get_shop_category_ids() : array();
		$gallery = $bundle ? $bundle->get_gallery_image_ids() : array();
		$limit   = $bundle ? $bundle->get_stock_limit() : null;

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		$terms = is_wp_error( $terms ) ? array() : $terms;
		?>
		<table class="form-table glbbwc-form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Catalog visibility', 'growthlab-bundle-builder-for-woocommerce' ); ?></th>
					<td>
						<label for="glbbwc-catalog-visible">
							<input type="checkbox" id="glbbwc-catalog-visible" name="glbbwc_catalog_visible" value="yes" <?php checked( $visible ); ?> />
							<?php esc_html_e( 'List this bundle in the shop, category archives and search results', 'growthlab-bundle-builder-for-woocommerce' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Customers who click the bundle in the shop are taken to this bundle\'s page to choose their items. Leave unticked to keep the bundle reachable only by its own link or shortcode.', 'growthlab-bundle-builder-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Shop categories', 'growthlab-bundle-builder-for-woocommerce' ); ?></th>
					<td>
						<?php if ( ! $terms ) : ?>
							<p class="description"><?php esc_html_e( 'No product categories found. Create a product category first.', 'growthlab-bundle-builder-for-woocommerce' ); ?></p>
						<?php else : ?>
							<select
								name="glbbwc_shop_categories[]"
								id="glbbwc-shop-categories"
								class="glbbwc-shop-categories"
								multiple
								size="<?php echo esc_attr( (string) min( 8, max( 3, count( $terms ) ) ) ); ?>"
							>
								<?php foreach ( $terms as $term ) : ?>
									<option
										value="<?php echo esc_attr( (string) $term->term_id ); ?>"
										<?php selected( in_array( (int) $term->term_id, $shop, true ) ); ?>
									>
										<?php echo esc_html( $term->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Which categories the finished bundle is filed under. This is separate from the slot categories above — those decide what goes inside the bundle, these decide where the bundle itself appears.', 'growthlab-bundle-builder-for-woocommerce' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Gallery images', 'growthlab-bundle-builder-for-woocommerce' ); ?></th>
					<td>
						<div class="glbbwc-gallery" data-glbbwc-gallery>
							<ul class="glbbwc-gallery__list">
								<?php foreach ( $gallery as $attachment_id ) : ?>
									<?php $this->render_gallery_item( $attachment_id ); ?>
								<?php endforeach; ?>
							</ul>

							<input type="hidden" name="glbbwc_gallery_ids" class="glbbwc-gallery__input" value="<?php echo esc_attr( implode( ',', $gallery ) ); ?>" />

							<p>
								<button type="button" class="button glbbwc-gallery__add">
									<?php esc_html_e( 'Add gallery images', 'growthlab-bundle-builder-for-woocommerce' ); ?>
								</button>
							</p>
						</div>
						<p class="description"><?php esc_html_e( 'Shown on the bundle page alongside the featured image. The featured image is set in the Bundle image box.', 'growthlab-bundle-builder-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="glbbwc-stock-limit"><?php esc_html_e( 'Availability cap', 'growthlab-bundle-builder-for-woocommerce' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="glbbwc-stock-limit"
							name="glbbwc_stock_limit"
							class="small-text"
							min="0"
							step="1"
							value="<?php echo esc_attr( null === $limit ? '' : (string) $limit ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'Optional. Availability is worked out automatically from the stock of the products a customer can pick, so you do not need to set a number here. Fill this in only to sell fewer bundles than that stock would allow.', 'growthlab-bundle-builder-for-woocommerce' ); ?></p>
						<?php if ( $bundle && $bundle->is_configured() ) : ?>
							<?php $buildable = $bundle->get_buildable_quantity(); ?>
							<p class="glbbwc-stock-readout">
								<strong><?php esc_html_e( 'Currently available:', 'growthlab-bundle-builder-for-woocommerce' ); ?></strong>
								<?php
								if ( null === $buildable ) {
									esc_html_e( 'Unlimited — no picked product tracks stock.', 'growthlab-bundle-builder-for-woocommerce' );
								} else {
									echo esc_html(
										sprintf(
											/* translators: %d: number of bundles that can be built. */
											_n( '%d bundle can be built from current stock.', '%d bundles can be built from current stock.', $buildable, 'growthlab-bundle-builder-for-woocommerce' ),
											$buildable
										)
									);
								}
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render one thumbnail in the gallery picker.
	 *
	 * @since 1.2.0
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return void
	 */
	protected function render_gallery_item( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$image         = wp_get_attachment_image( $attachment_id, 'thumbnail' );

		if ( ! $image ) {
			return;
		}
		?>
		<li class="glbbwc-gallery__item" data-attachment-id="<?php echo esc_attr( (string) $attachment_id ); ?>">
			<?php echo wp_kses_post( $image ); ?>
			<button type="button" class="glbbwc-gallery__remove" aria-label="<?php esc_attr_e( 'Remove image', 'growthlab-bundle-builder-for-woocommerce' ); ?>">&times;</button>
		</li>
		<?php
	}

	/**
	 * Render the main settings meta box.
	 *
	 * @param WP_Post $post Bundle post.
	 *
	 * @return void
	 */
	public function render_settings_meta_box( $post ) {
		// `post-new.php` hands us a saved auto-draft, so an existing GLBBWC_Bundle
		// object is not proof the admin has configured anything yet.
		$is_new    = ! $post instanceof WP_Post || 'auto-draft' === $post->post_status;
		$bundle    = $is_new ? null : GLBBWC_Bundle::get( $post );
		$price     = $bundle ? $bundle->get_price() : '';
		$is_active = $bundle ? $bundle->is_active() : true;
		$slots     = $bundle ? $bundle->get_slots() : array();

		if ( ! $slots ) {
			$slots = array(
				array(
					'id'         => 'slot-0',
					'categories' => array(),
					'count'      => 3,
				),
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		$terms = is_wp_error( $terms ) ? array() : $terms;

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<table class="form-table glbbwc-form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Category slots', 'growthlab-bundle-builder-for-woocommerce' ); ?></th>
					<td>
						<?php if ( ! $terms ) : ?>
							<p class="description"><?php esc_html_e( 'No product categories found. Create a product category first.', 'growthlab-bundle-builder-for-woocommerce' ); ?></p>
						<?php else : ?>
							<div class="glbbwc-slots" data-next-index="<?php echo esc_attr( (string) count( $slots ) ); ?>">
								<?php foreach ( array_values( $slots ) as $index => $slot ) : ?>
									<?php $this->render_slot_row( $index, $slot, $terms ); ?>
								<?php endforeach; ?>
							</div>

							<p>
								<button type="button" class="button glbbwc-slots__add">
									<?php esc_html_e( '+ Add slot', 'growthlab-bundle-builder-for-woocommerce' ); ?>
								</button>
							</p>

							<p class="description">
								<?php esc_html_e( 'Each slot is a group the customer picks from — for example 2 items from Tops plus 1 item from Bottoms. Use a single slot for a straightforward "pick any N" bundle. Child categories are included automatically, and a category can only be used by one slot.', 'growthlab-bundle-builder-for-woocommerce' ); ?>
							</p>

							<script type="text/html" id="tmpl-glbbwc-slot-row">
								<?php
								$this->render_slot_row(
									'__INDEX__',
									array(
										'categories' => array(),
										'count'      => 1,
									),
									$terms
								);
								?>
							</script>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="glbbwc-price"><?php esc_html_e( 'Bundle price', 'growthlab-bundle-builder-for-woocommerce' ); ?></label>
					</th>
					<td>
						<input type="text" id="glbbwc-price" name="glbbwc_price" class="wc_input_price small-text" value="<?php echo esc_attr( wc_format_localized_price( $price ) ); ?>" />
						<span class="description"><?php echo esc_html( get_woocommerce_currency_symbol() ); ?></span>
						<p class="description"><?php esc_html_e( 'Flat price charged for the whole bundle, regardless of which products are picked.', 'growthlab-bundle-builder-for-woocommerce' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'growthlab-bundle-builder-for-woocommerce' ); ?></th>
					<td>
						<label for="glbbwc-active">
							<input type="checkbox" id="glbbwc-active" name="glbbwc_active" value="yes" <?php checked( $is_active ); ?> />
							<?php esc_html_e( 'Active — customers can build and buy this bundle', 'growthlab-bundle-builder-for-woocommerce' ); ?>
						</label>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render one repeater row in the category-slot list.
	 *
	 * @since 1.1.0
	 *
	 * @param int|string $index Row index, or `__INDEX__` for the JS template.
	 * @param array      $slot  Slot data (`categories`, `count`).
	 * @param WP_Term[]  $terms Available product categories.
	 *
	 * @return void
	 */
	protected function render_slot_row( $index, array $slot, array $terms ) {
		$selected = isset( $slot['categories'] ) ? array_map( 'absint', (array) $slot['categories'] ) : array();
		$count    = isset( $slot['count'] ) ? max( 1, (int) $slot['count'] ) : 1;
		$field    = 'glbbwc_slots[' . $index . ']';
		?>
		<div class="glbbwc-slot">
			<span class="glbbwc-slot__handle" aria-hidden="true">&#9776;</span>

			<label class="glbbwc-slot__field">
				<span class="glbbwc-slot__label"><?php esc_html_e( 'Categories', 'growthlab-bundle-builder-for-woocommerce' ); ?></span>
				<select
					name="<?php echo esc_attr( $field ); ?>[categories][]"
					class="glbbwc-slot__categories"
					multiple
					size="<?php echo esc_attr( (string) min( 5, max( 3, count( $terms ) ) ) ); ?>"
				>
					<?php foreach ( $terms as $term ) : ?>
						<option
							value="<?php echo esc_attr( (string) $term->term_id ); ?>"
							<?php selected( in_array( (int) $term->term_id, $selected, true ) ); ?>
						>
							<?php echo esc_html( sprintf( '%1$s (%2$d)', $term->name, (int) $term->count ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="glbbwc-slot__field glbbwc-slot__field--count">
				<span class="glbbwc-slot__label"><?php esc_html_e( 'Items to pick', 'growthlab-bundle-builder-for-woocommerce' ); ?></span>
				<input
					type="number"
					name="<?php echo esc_attr( $field ); ?>[count]"
					class="small-text glbbwc-slot__count"
					min="1"
					max="50"
					step="1"
					value="<?php echo esc_attr( (string) $count ); ?>"
				/>
			</label>

			<button type="button" class="button-link glbbwc-slot__remove">
				<?php esc_html_e( 'Remove', 'growthlab-bundle-builder-for-woocommerce' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Render the shortcode / permalink helper meta box.
	 *
	 * @param WP_Post $post Bundle post.
	 *
	 * @return void
	 */
	public function render_usage_meta_box( $post ) {
		$bundle = GLBBWC_Bundle::get( $post );

		if ( ! $bundle || 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save the bundle to get its shortcode and permalink.', 'growthlab-bundle-builder-for-woocommerce' ) . '</p>';

			return;
		}

		$shortcode  = sprintf( '[glbbwc_bundle id="%d"]', $bundle->get_id() );
		$product_id = $bundle->get_product_id();
		?>
		<p><strong><?php esc_html_e( 'Shortcode', 'growthlab-bundle-builder-for-woocommerce' ); ?></strong></p>
		<input type="text" class="widefat code" readonly value="<?php echo esc_attr( $shortcode ); ?>" onfocus="this.select();" />
		<p class="description"><?php esc_html_e( 'Paste into any page or post to render the picker.', 'growthlab-bundle-builder-for-woocommerce' ); ?></p>

		<p><strong><?php esc_html_e( 'Bundle page', 'growthlab-bundle-builder-for-woocommerce' ); ?></strong></p>
		<p><a href="<?php echo esc_url( $bundle->get_permalink() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $bundle->get_permalink() ); ?></a></p>

		<?php if ( $product_id ) : ?>
			<p class="description">
				<?php
				if ( $bundle->is_catalog_visible() ) {
					printf(
						/* translators: %d: linked product ID. */
						esc_html__( 'Linked cart product: #%d (listed in the catalog).', 'growthlab-bundle-builder-for-woocommerce' ),
						(int) $product_id
					);
				} else {
					printf(
						/* translators: %d: linked product ID. */
						esc_html__( 'Linked cart product: #%d (hidden from the catalog).', 'growthlab-bundle-builder-for-woocommerce' ),
						(int) $product_id
					);
				}
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Persist bundle settings and sync the hidden product.
	 *
	 * @param int     $post_id Bundle post ID.
	 * @param WP_Post $post    Bundle post.
	 *
	 * @return void
	 */
	public function save_bundle( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$slots = $this->parse_posted_slots();

		/*
		 * Legacy screens and the 1.0 meta keys stay authoritative for anything
		 * that has not learned about slots: the category list mirrors every
		 * category in use and the item count mirrors the combined total, so the
		 * list table, `is_configured()` and third-party reads keep working.
		 */
		$categories = array();
		$item_count = 0;

		foreach ( $slots as $slot ) {
			$categories  = array_merge( $categories, $slot['categories'] );
			$item_count += $slot['count'];
		}

		$categories = array_values( array_unique( array_filter( $categories ) ) );
		$item_count = min( 50, max( 0, $item_count ) );

		$raw_price = isset( $_POST['glbbwc_price'] ) ? sanitize_text_field( wp_unslash( $_POST['glbbwc_price'] ) ) : '';
		$price     = '' === $raw_price ? 0 : (float) wc_format_decimal( $raw_price );
		$price     = max( 0, $price );

		$active = isset( $_POST['glbbwc_active'] ) ? 'yes' : 'no';

		$catalog_visible = isset( $_POST['glbbwc_catalog_visible'] ) ? 'yes' : 'no';

		$shop_categories = isset( $_POST['glbbwc_shop_categories'] )
			? array_map( 'absint', (array) wp_unslash( $_POST['glbbwc_shop_categories'] ) )
			: array();
		$shop_categories = array_values( array_unique( array_filter( $shop_categories ) ) );

		// Only real product categories may be written, whatever was posted.
		$shop_categories = array_values(
			array_filter(
				$shop_categories,
				static function ( $term_id ) {
					return term_exists( $term_id, 'product_cat' ) ? true : false;
				}
			)
		);

		$gallery_ids = $this->parse_posted_gallery_ids();

		$raw_limit   = isset( $_POST['glbbwc_stock_limit'] ) ? sanitize_text_field( wp_unslash( $_POST['glbbwc_stock_limit'] ) ) : '';
		$stock_limit = '' === trim( $raw_limit ) ? '' : (string) max( 0, (int) $raw_limit );

		update_post_meta( $post_id, GLBBWC_Bundle::META_SLOTS, $slots );
		update_post_meta( $post_id, GLBBWC_Bundle::META_CATEGORIES, $categories );
		update_post_meta( $post_id, GLBBWC_Bundle::META_ITEM_COUNT, $item_count );
		update_post_meta( $post_id, GLBBWC_Bundle::META_PRICE, $price );
		update_post_meta( $post_id, GLBBWC_Bundle::META_ACTIVE, $active );
		update_post_meta( $post_id, GLBBWC_Bundle::META_CATALOG_VISIBLE, $catalog_visible );
		update_post_meta( $post_id, GLBBWC_Bundle::META_SHOP_CATEGORIES, $shop_categories );
		update_post_meta( $post_id, GLBBWC_Bundle::META_GALLERY_IDS, $gallery_ids );
		update_post_meta( $post_id, GLBBWC_Bundle::META_STOCK_LIMIT, $stock_limit );

		$bundle = GLBBWC_Bundle::get( $post_id );

		if ( $bundle ) {
			$bundle->sync_product();

			/**
			 * Fires after a bundle's settings have been saved.
			 *
			 * @since 1.0.0
			 *
			 * @param GLBBWC_Bundle $bundle Bundle instance.
			 */
			do_action( 'glbbwc_bundle_saved', $bundle );
		}
	}

	/**
	 * Read the posted gallery field into a list of real attachment IDs.
	 *
	 * @since 1.2.0
	 *
	 * @return int[]
	 */
	protected function parse_posted_gallery_ids() {
		// Nonce and capability are verified by the caller, save_bundle().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST['glbbwc_gallery_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['glbbwc_gallery_ids'] ) ) : '';

		if ( '' === $raw ) {
			return array();
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) );

		// Drop anything that is not an image the store actually holds.
		return array_values(
			array_filter(
				$ids,
				static function ( $attachment_id ) {
					return wp_attachment_is_image( $attachment_id );
				}
			)
		);
	}

	/**
	 * Read the posted slot repeater into clean, storable slot data.
	 *
	 * Rows without a category are dropped, and a category claimed by an earlier
	 * slot is stripped from later ones so every product maps to exactly one slot.
	 *
	 * @since 1.1.0
	 *
	 * @return array[] Storable slots.
	 */
	protected function parse_posted_slots() {
		// Nonce and capability are verified by the caller, save_bundle().
		// Sanitised on access; every value is re-cast to int below.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST['glbbwc_slots'] ) ? map_deep( wp_unslash( (array) $_POST['glbbwc_slots'] ), 'sanitize_text_field' ) : array();

		$slots   = array();
		$claimed = array();
		$index   = 0;

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$categories = isset( $row['categories'] ) ? array_map( 'absint', (array) $row['categories'] ) : array();
			$categories = array_values( array_unique( array_filter( $categories ) ) );
			$categories = array_values( array_diff( $categories, $claimed ) );

			if ( ! $categories ) {
				continue;
			}

			$count = isset( $row['count'] ) ? absint( $row['count'] ) : 0;
			$count = min( 50, max( 1, $count ) );

			$claimed = array_merge( $claimed, $categories );

			$slots[] = array(
				'id'         => 'slot-' . $index,
				'categories' => $categories,
				'count'      => $count,
			);

			++$index;
		}

		/**
		 * Filter the slots parsed from the bundle admin screen before saving.
		 *
		 * @since 1.1.0
		 *
		 * @param array[] $slots Storable slots.
		 */
		return (array) apply_filters( 'glbbwc_parsed_bundle_slots', $slots );
	}

	/**
	 * Define the bundle list table columns.
	 *
	 * @param array $columns Existing columns.
	 *
	 * @return array
	 */
	public function columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['glbbwc_categories'] = __( 'Categories', 'growthlab-bundle-builder-for-woocommerce' );
				$new['glbbwc_items']      = __( 'Items', 'growthlab-bundle-builder-for-woocommerce' );
				$new['glbbwc_price']      = __( 'Price', 'growthlab-bundle-builder-for-woocommerce' );
				$new['glbbwc_stock']      = __( 'Available', 'growthlab-bundle-builder-for-woocommerce' );
				$new['glbbwc_status']     = __( 'Status', 'growthlab-bundle-builder-for-woocommerce' );
				$new['glbbwc_shortcode']  = __( 'Shortcode', 'growthlab-bundle-builder-for-woocommerce' );
			}
		}

		return $new;
	}

	/**
	 * Render a bundle list table cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Bundle post ID.
	 *
	 * @return void
	 */
	public function column_content( $column, $post_id ) {
		$bundle = GLBBWC_Bundle::get( $post_id );

		if ( ! $bundle ) {
			return;
		}

		switch ( $column ) {
			case 'glbbwc_categories':
				$names = array();

				foreach ( $bundle->get_category_ids() as $term_id ) {
					$term = get_term( $term_id, 'product_cat' );

					if ( $term instanceof WP_Term ) {
						$names[] = $term->name;
					}
				}

				echo $names ? esc_html( implode( ', ', $names ) ) : '<span aria-hidden="true">—</span>';
				break;

			case 'glbbwc_items':
				$slots = $bundle->get_slots();

				if ( count( $slots ) > 1 ) {
					// Show the split, e.g. "3 (2 + 1)", so multi-slot bundles read at a glance.
					printf(
						'%1$s <span class="glbbwc-slot-split">(%2$s)</span>',
						esc_html( (string) $bundle->get_item_count() ),
						esc_html( implode( ' + ', wp_list_pluck( $slots, 'count' ) ) )
					);
				} else {
					echo esc_html( (string) $bundle->get_item_count() );
				}
				break;

			case 'glbbwc_price':
				echo wp_kses_post( wc_price( $bundle->get_price() ) );
				break;

			case 'glbbwc_stock':
				if ( ! $bundle->is_configured() ) {
					echo '<span aria-hidden="true">—</span>';
					break;
				}

				$buildable = $bundle->get_buildable_quantity();

				if ( null === $buildable ) {
					printf(
						'<span class="glbbwc-stock glbbwc-stock--in">%s</span>',
						esc_html__( 'Unlimited', 'growthlab-bundle-builder-for-woocommerce' )
					);
				} else {
					printf(
						'<span class="glbbwc-stock glbbwc-stock--%1$s">%2$s</span>',
						esc_attr( $buildable > 0 ? 'in' : 'out' ),
						esc_html(
							$buildable > 0
								? sprintf(
									/* translators: %d: number of bundles buildable from current stock. */
									_n( '%d bundle', '%d bundles', $buildable, 'growthlab-bundle-builder-for-woocommerce' ),
									$buildable
								)
								: __( 'Out of stock', 'growthlab-bundle-builder-for-woocommerce' )
						)
					);
				}
				break;

			case 'glbbwc_status':
				$active = $bundle->is_active();
				printf(
					'<span class="glbbwc-status glbbwc-status--%1$s">%2$s</span>',
					esc_attr( $active ? 'active' : 'inactive' ),
					esc_html( $active ? __( 'Active', 'growthlab-bundle-builder-for-woocommerce' ) : __( 'Inactive', 'growthlab-bundle-builder-for-woocommerce' ) )
				);
				break;

			case 'glbbwc_shortcode':
				printf( '<code>%s</code>', esc_html( sprintf( '[glbbwc_bundle id="%d"]', $bundle->get_id() ) ) );
				break;
		}
	}

	/**
	 * Use bundle-specific wording for post update notices.
	 *
	 * @param array $messages Update messages.
	 *
	 * @return array
	 */
	public function updated_messages( $messages ) {
		$messages[ GLBBWC_Bundle::POST_TYPE ] = array(
			0 => '',
			1 => __( 'Bundle updated.', 'growthlab-bundle-builder-for-woocommerce' ),
			4 => __( 'Bundle updated.', 'growthlab-bundle-builder-for-woocommerce' ),
			6 => __( 'Bundle published.', 'growthlab-bundle-builder-for-woocommerce' ),
			7 => __( 'Bundle saved.', 'growthlab-bundle-builder-for-woocommerce' ),
			8 => __( 'Bundle submitted.', 'growthlab-bundle-builder-for-woocommerce' ),
		);

		return $messages;
	}
}
