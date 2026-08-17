<?php
/**
 * Plugin Name:       GrowthLab Bundle Builder for WooCommerce
 * Plugin URI:        https://github.com/meetpritmani/growthlab-bundle-builder-for-woocommerce
 * Description:       Create "build your own bundle" offers with multi-category slots, curated "frequently bundled with" recommendations, and automatic BOGO / tiered cart discounts.
 * Version:           1.2.0
 * Author:            meetpritmani
 * Author URI:        https://github.com/meetpritmani
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       growthlab-bundle-builder-for-woocommerce
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.0
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'GLBBWC_VERSION', '1.2.0' );
define( 'GLBBWC_PLUGIN_FILE', __FILE__ );
define( 'GLBBWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GLBBWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GLBBWC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once GLBBWC_PLUGIN_DIR . 'includes/class-glbbwc-plugin.php';

register_activation_hook( GLBBWC_PLUGIN_FILE, array( 'GLBBWC_Plugin', 'activate' ) );
register_deactivation_hook( GLBBWC_PLUGIN_FILE, array( 'GLBBWC_Plugin', 'deactivate' ) );

GLBBWC_Plugin::instance();
