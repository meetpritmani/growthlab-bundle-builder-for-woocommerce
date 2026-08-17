<?php
/**
 * Single bundle template.
 *
 * Override in a theme by copying this file to:
 * yourtheme/growthlab-bundle-builder-for-woocommerce/single-glbbwc_bundle.php
 *
 * Follows WooCommerce's single-product wrapper conventions so Woo-aware themes
 * lay the page out exactly as they do their shop pages.
 *
 * @package GrowthLab_Bundle_Builder_For_WooCommerce
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );

/**
 * Themes hook their opening page wrappers here.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook, fired so Woo-aware themes open their page wrappers.
do_action( 'woocommerce_before_main_content' );

while ( have_posts() ) :
	the_post();

	$glbbwc_bundle = GLBBWC_Bundle::get( get_the_ID() );
	?>
	<article id="bundle-<?php the_ID(); ?>" <?php post_class( 'glbbwc-single-bundle' ); ?>>

		<header class="glbbwc-single-bundle__header">
			<h1 class="glbbwc-single-bundle__title entry-title"><?php the_title(); ?></h1>

			<?php if ( $glbbwc_bundle && $glbbwc_bundle->is_configured() ) : ?>
				<p class="glbbwc-single-bundle__price"><?php echo wp_kses_post( wc_price( $glbbwc_bundle->get_price() ) ); ?></p>
			<?php endif; ?>
		</header>

		<?php if ( has_post_thumbnail() ) : ?>
			<div class="glbbwc-single-bundle__image">
				<?php the_post_thumbnail( 'large' ); ?>
			</div>
		<?php endif; ?>

		<div class="glbbwc-single-bundle__content entry-content">
			<?php
			/**
			 * Renders the description. GLBBWC_Frontend appends the picker to this
			 * content unless the editor already placed the shortcode manually.
			 */
			the_content();
			?>
		</div>

	</article>
	<?php
endwhile;

/**
 * Themes hook their closing page wrappers here.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook, fired so Woo-aware themes close their page wrappers.
do_action( 'woocommerce_after_main_content' );

get_footer( 'shop' );
