<?php
/**
 * BOGO / tiered cart discount rules.
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Automatic "buy X get Y" rules applied at cart calculation time.
 *
 * Discounts are delivered as a runtime ("virtual") coupon rather than a negative
 * fee. Registering the coupon through `woocommerce_get_shop_coupon_data` means
 * WooCommerce treats it as a real discount everywhere it already understands
 * discounts: totals split tax correctly, the amount rides through to the order,
 * the customer sees it on the cart, checkout, confirmation page and emails, and
 * it lands in Woo's own reports. A negative fee would have to re-implement all
 * of that, and fees carry their own tax class rather than the discounted
 * product's, so the tax would only be right while taxes were switched off.
 *
 * The discount always targets the cheapest qualifying units, so a customer
 * cannot pad the cart with an expensive item to get it free.
 *
 * @since 1.1.0
 */
class GLBBWC_Discount_Rules {

	/**
	 * Option holding every rule.
	 */
	const OPTION = 'glbbwc_discount_rules';

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'glbbwc-discount-rules';

	/**
	 * Prefix for the generated coupon codes.
	 */
	const COUPON_PREFIX = 'glbbwc-rule-';

	/**
	 * Single instance.
	 *
	 * @var GLBBWC_Discount_Rules|null
	 */
	protected static $instance = null;

	/**
	 * Discount amount per coupon code, computed for the current request.
	 *
	 * @var array<string,float>
	 */
	protected $amounts = array();

	/**
	 * Guards against re-entering totals calculation while applying a coupon.
	 *
	 * @var bool
	 */
	protected $applying = false;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return GLBBWC_Discount_Rules
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
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_rules' ), 5 );
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'coupon_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'coupon_label' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( $this, 'coupon_html' ), 10, 2 );
		add_filter( 'woocommerce_coupon_message', array( $this, 'silence_coupon_message' ), 10, 3 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
			add_action( 'admin_post_glbbwc_save_discount_rule', array( $this, 'handle_save' ) );
			add_action( 'admin_post_glbbwc_delete_discount_rule', array( $this, 'handle_delete' ) );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Rule storage
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Every stored rule, normalised.
	 *
	 * @since 1.1.0
	 *
	 * @return array[]
	 */
	public static function get_rules() {
		$stored = get_option( self::OPTION, array() );
		$rules  = array();

		foreach ( (array) $stored as $rule ) {
			$normalised = self::normalise_rule( $rule );

			if ( $normalised ) {
				$rules[] = $normalised;
			}
		}

		/**
		 * Filter the stored BOGO/tiered discount rules.
		 *
		 * @since 1.1.0
		 *
		 * @param array[] $rules Normalised rules.
		 */
		return (array) apply_filters( 'glbbwc_discount_rules', $rules );
	}

	/**
	 * Coerce a raw rule array into a known shape.
	 *
	 * @since 1.1.0
	 *
	 * @param array $rule Raw rule.
	 *
	 * @return array|null Null when the rule is unusable.
	 */
	protected static function normalise_rule( $rule ) {
		if ( ! is_array( $rule ) || empty( $rule['id'] ) ) {
			return null;
		}

		$category = isset( $rule['category'] ) ? absint( $rule['category'] ) : 0;
		$buy      = isset( $rule['buy'] ) ? max( 1, (int) $rule['buy'] ) : 0;
		$get      = isset( $rule['get'] ) ? max( 1, (int) $rule['get'] ) : 0;

		if ( ! $category || ! $buy || ! $get ) {
			return null;
		}

		$type = isset( $rule['type'] ) && 'percent' === $rule['type'] ? 'percent' : 'free';

		return array(
			'id'       => sanitize_key( $rule['id'] ),
			'title'    => isset( $rule['title'] ) ? sanitize_text_field( $rule['title'] ) : '',
			'category' => $category,
			'buy'      => $buy,
			'get'      => $get,
			'type'     => $type,
			'percent'  => isset( $rule['percent'] ) ? min( 100, max( 1, (int) $rule['percent'] ) ) : 100,
			'active'   => ! empty( $rule['active'] ),
		);
	}

	/**
	 * The coupon code representing a rule.
	 *
	 * @since 1.1.0
	 *
	 * @param array $rule Rule.
	 *
	 * @return string
	 */
	public static function get_coupon_code( array $rule ) {
		return self::COUPON_PREFIX . $rule['id'];
	}

	/**
	 * A readable name for a rule, generated when the admin left the title blank.
	 *
	 * @since 1.1.0
	 *
	 * @param array $rule Rule.
	 *
	 * @return string
	 */
	public static function get_rule_title( array $rule ) {
		if ( '' !== $rule['title'] ) {
			return $rule['title'];
		}

		$term = get_term( $rule['category'], 'product_cat' );
		$name = $term instanceof WP_Term ? $term->name : __( 'products', 'growthlab-bundle-builder-for-woocommerce' );

		if ( 'free' === $rule['type'] ) {
			return sprintf(
				/* translators: 1: buy quantity, 2: category name, 3: free quantity. */
				__( 'Buy %1$d from %2$s, get %3$d free', 'growthlab-bundle-builder-for-woocommerce' ),
				$rule['buy'],
				$name,
				$rule['get']
			);
		}

		return sprintf(
			/* translators: 1: buy quantity, 2: category name, 3: discounted quantity, 4: percentage off. */
			__( 'Buy %1$d from %2$s, get %3$d at %4$d%% off', 'growthlab-bundle-builder-for-woocommerce' ),
			$rule['buy'],
			$name,
			$rule['get'],
			$rule['percent']
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Cart application
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Work out the discount a rule earns against the current cart.
	 *
	 * Every qualifying unit is expanded to its own price so quantities are
	 * handled, then the cheapest units in each completed set are discounted.
	 *
	 * @since 1.1.0
	 *
	 * @param array   $rule Rule.
	 * @param WC_Cart $cart Cart instance.
	 *
	 * @return float Discount amount, 0 when the rule does not match.
	 */
	public function calculate_discount( array $rule, $cart ) {
		if ( ! $rule['active'] || ! $cart instanceof WC_Cart ) {
			return 0.0;
		}

		$prices = array();

		foreach ( $cart->get_cart() as $cart_item ) {
			// Bundle lines are priced as a unit, so they never feed a BOGO set.
			if ( GLBBWC_Cart::is_bundle_item( $cart_item ) ) {
				continue;
			}

			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$product_id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();

			if ( ! has_term( $rule['category'], 'product_cat', $product_id ) ) {
				continue;
			}

			$price    = (float) $product->get_price();
			$quantity = max( 0, (int) $cart_item['quantity'] );

			for ( $i = 0; $i < $quantity; $i++ ) {
				$prices[] = $price;
			}
		}

		$block = $rule['buy'] + $rule['get'];

		if ( count( $prices ) < $block ) {
			return 0.0;
		}

		$sets  = (int) floor( count( $prices ) / $block );
		$units = $sets * $rule['get'];

		// Cheapest first: the customer never gets the expensive item free.
		sort( $prices, SORT_NUMERIC );

		$discounted = array_slice( $prices, 0, $units );
		$amount     = array_sum( $discounted );

		if ( 'percent' === $rule['type'] ) {
			$amount = $amount * ( $rule['percent'] / 100 );
		}

		/**
		 * Filter the discount a rule earns against the current cart.
		 *
		 * @since 1.1.0
		 *
		 * @param float   $amount Discount amount.
		 * @param array   $rule   Rule.
		 * @param WC_Cart $cart   Cart instance.
		 */
		return (float) apply_filters( 'glbbwc_rule_discount_amount', round( (float) $amount, wc_get_price_decimals() ), $rule, $cart );
	}

	/**
	 * Apply or withdraw each rule's coupon as the cart changes.
	 *
	 * @since 1.1.0
	 *
	 * @param WC_Cart $cart Cart instance.
	 *
	 * @return void
	 */
	public function apply_rules( $cart ) {
		if ( $this->applying || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}

		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		$rules = self::get_rules();

		if ( ! $rules ) {
			return;
		}

		// apply_coupon()/remove_coupon() recalculate totals, which re-enters this hook.
		$this->applying = true;

		foreach ( $rules as $rule ) {
			$code    = self::get_coupon_code( $rule );
			$amount  = $this->calculate_discount( $rule, $cart );
			$applied = $cart->has_discount( $code );

			if ( $amount > 0 ) {
				$this->amounts[ $code ] = $amount;

				if ( ! $applied ) {
					$cart->apply_coupon( $code );
				}
			} elseif ( $applied ) {
				unset( $this->amounts[ $code ] );
				$cart->remove_coupon( $code );
			}
		}

		$this->applying = false;
	}

	/**
	 * Describe a rule's coupon to WooCommerce.
	 *
	 * Returning data here makes the code resolve without a coupon post existing,
	 * so rules never litter the Coupons screen.
	 *
	 * @since 1.1.0
	 *
	 * @param array|false $data Existing coupon data.
	 * @param string      $code Coupon code being looked up.
	 *
	 * @return array|false
	 */
	public function coupon_data( $data, $code ) {
		$rule = $this->get_rule_by_code( $code );

		if ( ! $rule ) {
			return $data;
		}

		$code   = wc_format_coupon_code( $code );
		$amount = isset( $this->amounts[ $code ] ) ? $this->amounts[ $code ] : 0;

		return array(
			'id'                          => -1,
			'amount'                      => $amount,
			'discount_type'               => 'fixed_cart',
			'individual_use'              => false,
			'usage_limit'                 => 0,
			'usage_limit_per_user'        => 0,
			'limit_usage_to_x_items'      => 0,
			'free_shipping'               => false,
			'exclude_sale_items'          => false,
			'minimum_amount'              => '',
			'maximum_amount'              => '',
			'product_ids'                 => array(),
			'excluded_product_ids'        => array(),
			'product_categories'          => array(),
			'excluded_product_categories' => array(),
			'email_restrictions'          => array(),
			'used_by'                     => array(),
		);
	}

	/**
	 * Show the rule's name instead of the generated coupon code.
	 *
	 * @since 1.1.0
	 *
	 * @param string    $label  Default label.
	 * @param WC_Coupon $coupon Coupon.
	 *
	 * @return string
	 */
	public function coupon_label( $label, $coupon ) {
		$rule = $coupon instanceof WC_Coupon ? $this->get_rule_by_code( $coupon->get_code() ) : null;

		if ( ! $rule ) {
			return $label;
		}

		return esc_html( self::get_rule_title( $rule ) );
	}

	/**
	 * Drop the "[Remove]" link from an automatic discount.
	 *
	 * The rule re-applies itself on the next calculation anyway, so offering to
	 * remove it only invites confusion.
	 *
	 * @since 1.1.0
	 *
	 * @param string    $html   Default markup.
	 * @param WC_Coupon $coupon Coupon.
	 *
	 * @return string
	 */
	public function coupon_html( $html, $coupon ) {
		$rule = $coupon instanceof WC_Coupon ? $this->get_rule_by_code( $coupon->get_code() ) : null;

		if ( ! $rule ) {
			return $html;
		}

		return wc_price( $this->amounts[ wc_format_coupon_code( $coupon->get_code() ) ] ?? 0 );
	}

	/**
	 * Suppress the "Coupon code applied successfully" notice for rule coupons.
	 *
	 * A rule's coupon is applied by the cart itself, not typed in by the
	 * customer, so announcing it only prompts "which coupon did I use?". The
	 * discount is still shown in the cart totals under the rule's own name.
	 * Coupons the customer actually entered keep their message.
	 *
	 * WC_Coupon::add_coupon_message() bails on an empty string, so returning
	 * one here drops the notice rather than printing a blank one.
	 *
	 * @since 1.1.0
	 *
	 * @param string    $msg      Coupon message.
	 * @param int       $msg_code Message code.
	 * @param WC_Coupon $coupon   Coupon the message belongs to.
	 *
	 * @return string
	 */
	public function silence_coupon_message( $msg, $msg_code, $coupon ) {
		if ( $coupon instanceof WC_Coupon && $this->get_rule_by_code( $coupon->get_code() ) ) {
			return '';
		}

		return $msg;
	}

	/**
	 * Find the rule behind a coupon code.
	 *
	 * @since 1.1.0
	 *
	 * @param string $code Coupon code.
	 *
	 * @return array|null
	 */
	protected function get_rule_by_code( $code ) {
		$code = wc_format_coupon_code( $code );

		if ( 0 !== strpos( $code, self::COUPON_PREFIX ) ) {
			return null;
		}

		foreach ( self::get_rules() as $rule ) {
			if ( wc_format_coupon_code( self::get_coupon_code( $rule ) ) === $code ) {
				return $rule;
			}
		}

		return null;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Admin screen
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Register the Discount Rules screen alongside Bundles.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Bundle Discount Rules', 'growthlab-bundle-builder-for-woocommerce' ),
			__( 'Discount Rules', 'growthlab-bundle-builder-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the rules list and the add-rule form.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage discount rules.', 'growthlab-bundle-builder-for-woocommerce' ) );
		}

		$rules = self::get_rules();
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$terms = is_wp_error( $terms ) ? array() : $terms;
		?>
		<div class="wrap glbbwc-rules">
			<h1><?php esc_html_e( 'Bundle Discount Rules', 'growthlab-bundle-builder-for-woocommerce' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Automatic cart discounts, applied whenever the cart matches. The cheapest qualifying items are always the ones discounted. Bundle line items are excluded, since they are already sold at a flat price.', 'growthlab-bundle-builder-for-woocommerce' ); ?>
			</p>

			<?php if ( isset( $_GET['glbbwc_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Discount rule saved.', 'growthlab-bundle-builder-for-woocommerce' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['glbbwc_deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Discount rule deleted.', 'growthlab-bundle-builder-for-woocommerce' ); ?></p></div>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Rule', 'growthlab-bundle-builder-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Category', 'growthlab-bundle-builder-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Buy', 'growthlab-bundle-builder-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Get', 'growthlab-bundle-builder-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Discount', 'growthlab-bundle-builder-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'growthlab-bundle-builder-for-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'growthlab-bundle-builder-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rules ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No discount rules yet.', 'growthlab-bundle-builder-for-woocommerce' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rules as $rule ) : ?>
							<?php $term = get_term( $rule['category'], 'product_cat' ); ?>
							<tr>
								<td><strong><?php echo esc_html( self::get_rule_title( $rule ) ); ?></strong></td>
								<td><?php echo esc_html( $term instanceof WP_Term ? $term->name : '—' ); ?></td>
								<td><?php echo esc_html( (string) $rule['buy'] ); ?></td>
								<td><?php echo esc_html( (string) $rule['get'] ); ?></td>
								<td>
									<?php
									echo esc_html(
										'free' === $rule['type']
											? __( 'Free', 'growthlab-bundle-builder-for-woocommerce' )
											: sprintf(
												/* translators: %d: percentage off. */
												__( '%d%% off', 'growthlab-bundle-builder-for-woocommerce' ),
												$rule['percent']
											)
									);
									?>
								</td>
								<td>
									<span class="glbbwc-status glbbwc-status--<?php echo esc_attr( $rule['active'] ? 'active' : 'inactive' ); ?>">
										<?php echo esc_html( $rule['active'] ? __( 'Active', 'growthlab-bundle-builder-for-woocommerce' ) : __( 'Inactive', 'growthlab-bundle-builder-for-woocommerce' ) ); ?>
									</span>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<?php wp_nonce_field( 'glbbwc_delete_discount_rule_' . $rule['id'] ); ?>
										<input type="hidden" name="action" value="glbbwc_delete_discount_rule" />
										<input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule['id'] ); ?>" />
										<button type="submit" class="button-link delete"><?php esc_html_e( 'Delete', 'growthlab-bundle-builder-for-woocommerce' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Add a rule', 'growthlab-bundle-builder-for-woocommerce' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'glbbwc_save_discount_rule' ); ?>
				<input type="hidden" name="action" value="glbbwc_save_discount_rule" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="glbbwc-rule-title"><?php esc_html_e( 'Name', 'growthlab-bundle-builder-for-woocommerce' ); ?></label></th>
							<td>
								<input type="text" id="glbbwc-rule-title" name="glbbwc_rule_title" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Shown to customers in the cart totals. Leave blank to generate one.', 'growthlab-bundle-builder-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="glbbwc-rule-category"><?php esc_html_e( 'Category', 'growthlab-bundle-builder-for-woocommerce' ); ?></label></th>
							<td>
								<select id="glbbwc-rule-category" name="glbbwc_rule_category" required>
									<option value=""><?php esc_html_e( '— Select —', 'growthlab-bundle-builder-for-woocommerce' ); ?></option>
									<?php foreach ( $terms as $term ) : ?>
										<option value="<?php echo esc_attr( (string) $term->term_id ); ?>">
											<?php echo esc_html( sprintf( '%1$s (%2$d)', $term->name, (int) $term->count ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="glbbwc-rule-buy"><?php esc_html_e( 'Buy quantity', 'growthlab-bundle-builder-for-woocommerce' ); ?></label></th>
							<td><input type="number" id="glbbwc-rule-buy" name="glbbwc_rule_buy" class="small-text" min="1" max="50" step="1" value="2" required /></td>
						</tr>
						<tr>
							<th scope="row"><label for="glbbwc-rule-get"><?php esc_html_e( 'Get quantity', 'growthlab-bundle-builder-for-woocommerce' ); ?></label></th>
							<td><input type="number" id="glbbwc-rule-get" name="glbbwc_rule_get" class="small-text" min="1" max="50" step="1" value="1" required /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Discount', 'growthlab-bundle-builder-for-woocommerce' ); ?></th>
							<td>
								<label><input type="radio" name="glbbwc_rule_type" value="free" checked /> <?php esc_html_e( 'Free', 'growthlab-bundle-builder-for-woocommerce' ); ?></label><br />
								<label>
									<input type="radio" name="glbbwc_rule_type" value="percent" />
									<?php esc_html_e( 'Percentage off', 'growthlab-bundle-builder-for-woocommerce' ); ?>
								</label>
								<input type="number" name="glbbwc_rule_percent" class="small-text" min="1" max="100" step="1" value="50" />%
								<p class="description"><?php esc_html_e( 'Applied to the cheapest qualifying items in each completed set.', 'growthlab-bundle-builder-for-woocommerce' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'growthlab-bundle-builder-for-woocommerce' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="glbbwc_rule_active" value="yes" checked />
									<?php esc_html_e( 'Active — apply this rule automatically', 'growthlab-bundle-builder-for-woocommerce' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Add rule', 'growthlab-bundle-builder-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist a submitted rule.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function handle_save() {
		check_admin_referer( 'glbbwc_save_discount_rule' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage discount rules.', 'growthlab-bundle-builder-for-woocommerce' ) );
		}

		$rule = self::normalise_rule(
			array(
				'id'       => uniqid( 'r' ),
				'title'    => isset( $_POST['glbbwc_rule_title'] ) ? sanitize_text_field( wp_unslash( $_POST['glbbwc_rule_title'] ) ) : '',
				'category' => isset( $_POST['glbbwc_rule_category'] ) ? absint( wp_unslash( $_POST['glbbwc_rule_category'] ) ) : 0,
				'buy'      => isset( $_POST['glbbwc_rule_buy'] ) ? absint( wp_unslash( $_POST['glbbwc_rule_buy'] ) ) : 0,
				'get'      => isset( $_POST['glbbwc_rule_get'] ) ? absint( wp_unslash( $_POST['glbbwc_rule_get'] ) ) : 0,
				'type'     => isset( $_POST['glbbwc_rule_type'] ) ? sanitize_text_field( wp_unslash( $_POST['glbbwc_rule_type'] ) ) : 'free',
				'percent'  => isset( $_POST['glbbwc_rule_percent'] ) ? absint( wp_unslash( $_POST['glbbwc_rule_percent'] ) ) : 100,
				'active'   => isset( $_POST['glbbwc_rule_active'] ),
			)
		);

		if ( $rule ) {
			$rules   = get_option( self::OPTION, array() );
			$rules   = is_array( $rules ) ? $rules : array();
			$rules[] = $rule;

			update_option( self::OPTION, $rules );
		}

		wp_safe_redirect( add_query_arg( 'glbbwc_saved', '1', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	/**
	 * Delete a rule.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function handle_delete() {
		$rule_id = isset( $_POST['rule_id'] ) ? sanitize_key( wp_unslash( $_POST['rule_id'] ) ) : '';

		check_admin_referer( 'glbbwc_delete_discount_rule_' . $rule_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage discount rules.', 'growthlab-bundle-builder-for-woocommerce' ) );
		}

		$rules = get_option( self::OPTION, array() );
		$rules = is_array( $rules ) ? $rules : array();

		$rules = array_values(
			array_filter(
				$rules,
				static function ( $rule ) use ( $rule_id ) {
					return ! is_array( $rule ) || ! isset( $rule['id'] ) || $rule['id'] !== $rule_id;
				}
			)
		);

		update_option( self::OPTION, $rules );

		wp_safe_redirect( add_query_arg( 'glbbwc_deleted', '1', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}
}
