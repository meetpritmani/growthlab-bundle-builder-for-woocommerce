=== GrowthLab Bundle Builder for WooCommerce ===
Contributors: meetgrowthlab
Tags: bundle, build your own, mix and match, product bundle, bogo
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: woocommerce
WC requires at least: 8.0
WC tested up to: 11.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build-your-own bundles, curated cross-sells and automatic BOGO rules, with stock handled natively by WooCommerce.

== Description ==

Three merchandising tools in one lightweight plugin: **build-your-own bundles** with multi-category slots, **curated "Frequently Bundled With" cross-sells**, and **automatic BOGO / tiered cart discounts**.

= What makes this different =

Most bundle plugins have to reimplement inventory. This one doesn't.

* **WooCommerce handles the stock, not the plugin.** Every product a customer picks is written to the order as its own zero-priced line item. WooCommerce then reduces stock, restocks on refund or cancellation, fires low-stock notifications and reports on the components natively — there is no parallel inventory system to drift out of sync. Buy a bundle at quantity 2 and each picked product decrements by exactly 2.
* **Bundle availability is derived, never typed in.** You are not asked how many bundles you have; the plugin works it out from the stock of the products a customer can pick. A "pick 2 from Tops" bundle backed by shirts with 3, 5 and 1 in stock can be sold four times — not nine — because no bundle may take the same shirt twice. That figure updates itself as components sell, so the shop badge stays honest and the last bundle in stock is still the last one sold.
* **The picker previews what the customer is building.** Choices assemble into a live bundle preview as they are made, so the product image for a bundle is the customer's own selection rather than a static photo.
* **Three tools, one codebase.** Fixed-price bundles, curated cross-sells and automatic BOGO rules normally mean three separate plugins. Here they share one plugin and one settings surface.
* **Discounts use WooCommerce's own discount machinery.** BOGO rules are applied as runtime coupons rather than negative fees, so tax apportions correctly and the discount carries through to the order, the emails and the reports. Nothing is left for the plugin to reimplement.
* **Built for the current stack.** Works with the block Cart and Checkout (Store API), High-Performance Order Storage, and block themes — not just the classic shortcode cart.
* **Nothing phones home.** No external requests, no telemetry, no bundled libraries, no licence checks, no build step and no jQuery.

= Bundles with category slots =

A bundle is made of one or more **slots**, each a group of categories plus an exact number of picks — for example 2 items from Tops plus 1 item from Bottoms. The customer sees a section per slot with its own counter, and the Add to Cart button only enables once every slot is satisfied. The whole selection enters the cart as one line at your flat price.

Use a single slot for a straightforward "pick any 5 for $49" bundle.

Show a bundle with the shortcode `[glbbwc_bundle id="123"]`, or send customers to the bundle's own page.

= Frequently Bundled With =

Hand-pick up to four partner products on any product, and they appear beneath it with an "Add all to cart" button. This is a convenience multi-add rather than a fixed-price bundle: each product enters the cart as its own line at its own price. Recommendations are curated by you, so nothing is guessed from order history.

Falls back to `[glbbwc_recommended_with id="123"]` for themes that need it.

= BOGO and tiered discount rules =

Create rules such as "buy 2 from Skincare, get 1 free" or "buy 2, get 1 at 50% off". They apply automatically whenever the cart matches — no coupon code for the customer to find, and no per-product configuration. The discount always targets the cheapest qualifying items, so nobody games it by adding an expensive item to get it free.

= Server-side validation =

Selections are re-checked on the server three times over: when the bundle is added to the cart, on every cart and checkout load, and again if the customer re-orders. Count, category membership and stock are all verified. The browser is never trusted.

= Requirements =

* WordPress 6.4 or newer
* WooCommerce 8.0 or newer
* PHP 8.0 or newer

No paid extensions or third-party services are required.

== Installation ==

1. Upload the `growthlab-bundle-builder-for-woocommerce` folder to `/wp-content/plugins/`, or install the ZIP through Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins screen.
3. Go to **WooCommerce → Bundles → Add New** to create your first bundle.
4. Add `[glbbwc_bundle id="123"]` to a page, or link customers to the bundle's permalink.

== Frequently Asked Questions ==

= Which products can customers pick? =

Published, purchasable, in-stock **simple** products in the bundle's source categories. Child categories are included automatically. Variable products are not offered, which keeps the picker free of variation selection.

= How is stock reduced? =

Every picked product is added to the order as its own line item priced at 0.00, shown indented beneath the bundle. WooCommerce then handles stock reduction and restocking natively. The order total still equals the flat bundle price.

= Do I have to set a stock quantity for a bundle? =

No, and there is no field asking you to. Availability is calculated from the stock of the products the bundle can draw on, taking into account that a single bundle cannot contain the same product twice. If you want to sell fewer bundles than that allows, the optional Availability cap in the Shop Listing box sets a ceiling.

= Will my bundles suddenly appear in the shop after upgrading? =

No. Listing a bundle in the catalog is opt-in per bundle, under Shop Listing on the bundle edit screen. Bundles created before 1.2.0 stay exactly as they were until you tick the box.

= What happens if someone opens the bundle's product URL directly? =

They are redirected to the bundle page, where the picker is. A bundle product cannot be added to the cart without a selection — direct add-to-cart links, Store API calls and theme catalog buttons are all refused with a message pointing at the picker.

= What happens if a product sells out while the customer is choosing? =

The add-to-cart request is rejected with a message naming the product, and the customer picks something else. Bundles already in the cart are re-validated on every cart and checkout load, so a sold-out item cannot slip through to payment.

= Can a customer buy more than one of the same bundle? =

Yes. Quantity is respected and stock is validated against the total — two copies of a "pick 5" bundle require two units of each picked product.

= Does it support per-product surcharges or premium items? =

Not in this version; every bundle charges a single flat price. The `glbbwc_cart_item_price` filter is the documented extension point for adding surcharge logic without modifying the plugin.

= Does it work with the Cart and Checkout blocks? =

Yes. Bundle lines, their selections, and their pricing work in both the classic shortcode cart and the block-based Cart and Checkout.

= Is it compatible with High-Performance Order Storage? =

Yes. The plugin declares compatibility with HPOS (custom order tables).

== Screenshots ==

1. The bundle picker with a separate section and counter for each category slot, and a running total in the sticky action bar.
2. Bundle settings in the WordPress admin, showing the category slot repeater.
3. The bundle list table with categories, item counts, price, status and shortcode.
4. A bundle line item in the cart, listing what the customer picked.
5. The "Frequently Bundled With" section on a product page, with an add-all-to-cart button.
6. Automatic BOGO and tiered discount rules in the admin.

== Changelog ==

= 1.2.0 =
* Bundles can now be listed in the shop, category archives and search results, with their own product categories and gallery images. Clicking one opens the picker rather than adding an unbuilt bundle to the cart.
* New: bundle availability is worked out from the stock of the products a customer can pick, instead of being typed in and maintained by hand. A "pick 2 from Tops" bundle backed by three shirts correctly reports three, and drops as those shirts sell.
* New: optional availability cap, for selling fewer bundles than component stock would allow.
* New: the bundle picker now shows a live preview that assembles itself from the items the customer selects.
* Bundles are excluded from their own pickers, so listing a bundle in a category it also draws from can no longer nest it inside itself.
* Bundle products added to the cart without a selection — by direct URL, the Store API or a theme's catalog button — are now refused and the customer sent to the picker.
* Existing bundles stay hidden from the catalog until you tick the new Shop Listing box, so upgrading never publishes a bundle you did not choose to list.
* Fixed: a bundle saved as a draft was still purchasable by anyone with its link, and its product was listed in the catalog. A bundle's product now follows the bundle's own status, whether you change it from the edit screen, Quick Edit, bulk edit or a scheduled publish.
* Fixed: trashing a bundle left it sitting in carts that already held it, still able to reach checkout. Those lines are now removed with an explanation.

= 1.1.0 =
* Multi-category bundles: a bundle can now define several category "slots", each with its own exact pick count (e.g. 2 from Tops + 1 from Bottoms). Existing single-category bundles are unchanged.
* New "Frequently Bundled With" recommendations, curated per product, with an "Add all to cart" convenience button.
* New automatic BOGO / tiered discount rules under WooCommerce -> Discount Rules, always discounting the cheapest qualifying items.
* Fixed: bundle permalink pages lost the site header, navigation and footer on block themes, and logged a PHP deprecation on every view.
* Fixed: selected product names ran together without separators in the block Cart and Checkout.

= 1.0.0 =
* Initial release.
* Bundle custom post type with category, item count, flat price, and active/inactive settings.
* Frontend picker via `[glbbwc_bundle]` shortcode and a per-bundle permalink.
* Nonce-protected AJAX add-to-cart with full server-side validation.
* Flat bundle pricing in cart, checkout, and orders.
* Zero-priced child line items so WooCommerce handles stock natively.
* Cart re-validation for out-of-stock, removed, or re-categorised products.
* HPOS and Cart/Checkout blocks compatibility declared.

== Upgrade Notice ==

= 1.2.0 =
Bundles can now appear in the shop with their own categories and gallery, and their availability is derived from component stock instead of maintained by hand. Existing bundles keep working and stay hidden from the catalog until you opt in.

= 1.1.0 =
Adds multi-category bundle slots, product recommendations, and automatic BOGO discount rules. Existing bundles keep working unchanged.

= 1.0.0 =
Initial release.
