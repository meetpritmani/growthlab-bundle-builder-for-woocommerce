<?php
/**
 * Plugin bootstrap.
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin bootstrap.
 *
 * Wires every subsystem together and guards against running without WooCommerce.
 *
 * @since 1.0.0
 */
final class GLBBWC_Plugin {

	/**
	 * Single instance of the plugin.
	 *
	 * @var GLBBWC_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return GLBBWC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook the bootstrap into WordPress.
	 */
	protected function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Declare compatibility with WooCommerce feature flags.
	 *
	 * @return void
	 */
	public function declare_compatibility() {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', GLBBWC_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', GLBBWC_PLUGIN_FILE, true );
	}

	/**
	 * Load includes and instantiate subsystems.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! $this->woocommerce_ready() ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );

			return;
		}

		$this->includes();

		GLBBWC_Post_Type::instance();
		GLBBWC_Frontend::instance();
		GLBBWC_Ajax::instance();
		GLBBWC_Cart::instance();
		GLBBWC_Order::instance();
		GLBBWC_Recommendations::instance();
		GLBBWC_Discount_Rules::instance();

		if ( is_admin() ) {
			GLBBWC_Admin::instance();
		}

		/**
		 * Fires once every Bundle Builder subsystem is registered.
		 *
		 * @since 1.0.0
		 *
		 * @param GLBBWC_Plugin $plugin Plugin instance.
		 */
		do_action( 'glbbwc_loaded', $this );
	}

	/**
	 * Whether WooCommerce is active and new enough.
	 *
	 * @return bool
	 */
	protected function woocommerce_ready() {
		return class_exists( 'WooCommerce' ) && defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '8.0', '>=' );
	}

	/**
	 * Load class files.
	 *
	 * @return void
	 */
	protected function includes() {
		require_once GLBBWC_PLUGIN_DIR . 'includes/class-glbbwc-bundle.php';
		require_once GLBBWC_PLUGIN_DIR . 'includes/class-glbbwc-post-type.php';
		require_once GLBBWC_PLUGIN_DIR . 'includes/class-glbbwc-admin.php';
		require_once GLBBWC_PLUGIN_DIR . 'includes/class-glbbwc-frontend.php';
		require_once GLBBWC_PLUGIN_DIR . 'includes/class-glbbwc-ajax.php';
		require_once GLBBWC_PLUGIN_DIR . 'includes/class-glbbwc-cart.php';
		require_once GLBBWC_PLUGIN_DIR . 'includes/class-glbbwc-order.php';
		require_once GLBBWC_PLUGIN_DIR . 'includes/class-glbbwc-recommendations.php';
		require_once GLBBWC_PLUGIN_DIR . 'includes/class-glbbwc-discount-rules.php';
	}

	/**
	 * Render the "WooCommerce required" admin notice.
	 *
	 * @return void
	 */
	public function missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'GrowthLab Bundle Builder for WooCommerce requires WooCommerce 8.0 or newer to be installed and active.', 'growthlab-bundle-builder-for-woocommerce' )
		);
	}

	/**
	 * Activation routine: register the post type, then flush rewrites.
	 *
	 * @return void
	 */
	public static function activate() {
		require_once GLBBWC_PLUGIN_DIR . 'includes/class-glbbwc-bundle.php';
		require_once GLBBWC_PLUGIN_DIR . 'includes/class-glbbwc-post-type.php';

		GLBBWC_Post_Type::register_post_type();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation routine.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
