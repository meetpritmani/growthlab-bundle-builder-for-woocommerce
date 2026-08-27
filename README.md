# GrowthLab Bundle Builder for WooCommerce

A lightweight **"build your own bundle"** plugin for WooCommerce. Store admins define a bundle — *"pick 2 from Tops and 1 from Bottoms for $55"* — and customers get a picker grid that adds the whole selection to the cart as one line item at one flat price.

Since 1.1.0 it also ships two standalone extras: curated **"Frequently Bundled With"** recommendations on regular product pages, and automatic **BOGO / tiered discount rules** applied at cart calculation time.

| | |
| --- | --- |
| **Requires** | WordPress 6.4+, WooCommerce 8.0+, PHP 8.0+ |
| **Tested against** | WordPress 7.1, WooCommerce 11.0 |
| **Depends on** | WooCommerce only. No paid extensions, no build step, no frontend framework |
| **License** | GPL-2.0-or-later |

The plugin itself lives in [`growthlab-bundle-builder-for-woocommerce/`](growthlab-bundle-builder-for-woocommerce/) — that directory is what gets installed into `wp-content/plugins/`.

---

## Install

```bash
cd wp-content/plugins
git clone https://github.com/meetpritmani/growthlab-bundle-builder-for-woocommerce.git
```

WordPress needs the plugin folder directly under `wp-content/plugins/`, so either move it up or symlink it:

```bash
mv woocommerce-bundle-builder-lite/growthlab-bundle-builder-for-woocommerce ./growthlab-bundle-builder-for-woocommerce
# …or
ln -s "$(pwd)/woocommerce-bundle-builder-lite/growthlab-bundle-builder-for-woocommerce" ./growthlab-bundle-builder-for-woocommerce
```

Then activate it (`wp plugin activate growthlab-bundle-builder-for-woocommerce`, or via Plugins → Installed Plugins). There is no compile step — the JS and CSS ship as-is.

> **Permalinks:** activation flushes rewrite rules so `/bundle/your-bundle/` resolves. If you install by copying files rather than through the activation hook, visit **Settings → Permalinks** once and save.

---

## The three features

### 1. Bundles with category slots

Create a bundle under **WooCommerce → Bundles**. Each bundle owns one or more **slots**, and each slot is a group of categories plus an exact number of picks:

| Slot | Categories | Items to pick |
| --- | --- | --- |
| 1 | Tops | 2 |
| 2 | Bottoms | 1 |

The customer sees one section per slot, each with its own `2 of 2 selected` counter. **Add Bundle to Cart** only enables once *every* slot is satisfied, and the whole thing enters the cart as a single line at the flat bundle price.

A bundle with one slot renders exactly as it did in 1.0 — no section header, one grid, one counter — so upgrading changes nothing for existing bundles. A category can only be claimed by one slot, which is enforced both in the admin UI and on save.

Display it with the shortcode `[glbbwc_bundle id="123"]` or send customers to the bundle's own permalink.

### 2. Frequently Bundled With

On any regular product, the **Recommended Bundle Partners** box (product editor sidebar) lets you hand-pick up to 4 partner products. They appear beneath the product as a "Frequently Bundled With" row with an **Add all to cart** button.

This is a convenience multi-add, *not* a fixed-price bundle: each product enters the cart as its own normal line at its own price. Recommendations are admin-curated — there is no order-history analysis.

Themes that do not fire `woocommerce_after_single_product_summary` can use the shortcode `[glbbwc_recommended_with id="123"]`.

> The browser never sends the product list. The server re-reads the curated partners from product meta, so a tampered request cannot add arbitrary products to the cart.

### 3. BOGO / tiered discount rules

Under **WooCommerce → Discount Rules**, create automatic cart rules such as *"buy 2 from Skincare, get 1 free"* or *"buy 2, get 1 at 50% off"*. Rules apply themselves whenever the cart matches — no coupon code, no per-product configuration.

The discount always targets the **cheapest** qualifying units, so a customer cannot pad the cart with an expensive item to get it free. Bundle line items are excluded, since they are already sold at a flat price.

**Why a virtual coupon rather than a negative fee.** Rules are delivered by registering a runtime coupon through `woocommerce_get_shop_coupon_data`. WooCommerce then treats the discount as a real discount everywhere it already understands discounts: totals apportion tax correctly, the amount rides through to the order, and the customer sees it on the cart, checkout, confirmation page and emails. A negative fee would have to re-implement all of that, and fees carry their own tax class rather than the discounted product's — so the tax would only be correct while taxes were switched off. The coupons are generated at runtime and never create posts, so they do not litter the Coupons screen.

---

## Architecture

WooCommerce carts are keyed on real product IDs, so a bundle cannot enter the cart as a CPT alone. Two decisions follow from that, and they shape the whole plugin:

**1. Each bundle owns a hidden product.** Saving a bundle creates or updates a catalog-hidden, virtual `WC_Product_Simple` priced at the flat bundle price, cross-linked by meta (`_glbbwc_product_id` on the bundle, `_glbbwc_bundle_id` on the product). Taxes, coupons, order emails, refunds, and per-bundle analytics all work natively because the cart is holding a genuine product. Visitors who land on the hidden product URL are redirected to the bundle page.

**2. Picked products become zero-priced child line items.** At checkout, each selected product is added to the order as its own `WC_Order_Item_Product` with `total = 0`, carrying `_glbbwc_bundle_parent` meta. WooCommerce then reduces stock, restocks on refund or cancellation, and fires low-stock emails with no custom inventory code. The order total is set from cart totals, so it still equals the flat bundle price.

Selections are validated on the server three times over: in the AJAX handler before the cart accepts them, on every cart and checkout load (`woocommerce_check_cart_items`), and again when a customer re-orders. The browser's count is never authoritative.

### File structure

```
growthlab-bundle-builder-for-woocommerce/
├── growthlab-bundle-builder-for-woocommerce.php       Plugin header, constants, bootstrap, WC guard, activation hooks
├── includes/
│   ├── class-glbbwc-bundle.php          Bundle model: slots, eligible products, validation, product sync
│   ├── class-glbbwc-post-type.php       `glbbwc_bundle` CPT registration and lifecycle
│   ├── class-glbbwc-admin.php           Meta boxes, slot repeater, saving, list table columns
│   ├── class-glbbwc-frontend.php        Shortcode, single template, asset loading, picker markup
│   ├── class-glbbwc-ajax.php            Nonce-protected add-to-cart endpoint, per-slot validation
│   ├── class-glbbwc-cart.php            Flat pricing, selection display, cart re-validation
│   ├── class-glbbwc-order.php           Order item meta, child line items, "order again" handling
│   ├── class-glbbwc-recommendations.php  "Frequently Bundled With" meta box, frontend section, multi-add
│   └── class-glbbwc-discount-rules.php   BOGO / tiered rules, admin screen, virtual coupon application
├── templates/
│   └── single-glbbwc_bundle.php         Bundle permalink template for classic themes (theme-overridable)
├── assets/
│   ├── js/bundle-builder.js          Vanilla JS picker — per-slot state, no jQuery
│   ├── js/bundle-builder-admin.js    Admin slot repeater
│   ├── js/bundle-recommendations.js  "Add all to cart" multi-add
│   ├── css/bundle-builder.css        Responsive grid, slot sections, sticky action bar
│   ├── css/bundle-builder-admin.css
│   └── css/bundle-recommendations.css
├── phpcs.xml.dist                    WordPress Coding Standards ruleset
└── readme.txt                        WP.org-format readme
```

Every class is a singleton reached through `::instance()` and wired up in `GLBBWC_Plugin::init()`. Nothing runs unless WooCommerce 8.0+ is active.

### Data model

Meta stored on the `glbbwc_bundle` post:

| Meta key | Type | Meaning |
| --- | --- | --- |
| `_glbbwc_slots` | `array[]` | Category slots: `id`, `categories`, `count`. Added in 1.1.0 |
| `_glbbwc_categories` | `int[]` | Every category in use — kept in sync as a mirror for 1.0-era readers |
| `_glbbwc_item_count` | `int` | Combined pick count across all slots — likewise a mirror |
| `_glbbwc_price` | `float` | Flat bundle price |
| `_glbbwc_active` | `yes`/`no` | Whether customers can build and buy it |
| `_glbbwc_product_id` | `int` | The hidden WooCommerce product backing the cart line |

A bundle saved before 1.1.0 has no `_glbbwc_slots` value. `GLBBWC_Bundle::get_slots()` folds its categories and item count into a single derived slot, which is why a legacy bundle spanning several categories still lets the customer pick freely across all of them.

Cart item data on a bundle line: `glbbwc_bundle_id`, `glbbwc_selected_ids`, `glbbwc_price`, plus `glbbwc_slot_map` on multi-slot bundles only — so single-slot lines hash identically to 1.0. Because these are part of the cart item hash, two different selections of the same bundle occupy separate cart lines, while identical selections merge and increment quantity.

Other storage:

| Key | Where | Meaning |
| --- | --- | --- |
| `_glbbwc_recommended_ids` | Product meta | Curated "Frequently Bundled With" partner IDs |
| `glbbwc_discount_rules` | Option | Every BOGO / tiered rule |

Order item meta — internal keys are hidden from customers and the admin meta list:

| Key | On | Meaning |
| --- | --- | --- |
| `_glbbwc_bundle_id` | Bundle line | Source bundle post ID |
| `_glbbwc_selected_ids` | Bundle line | Picked product IDs |
| `_glbbwc_slot_map` | Bundle line | Picks grouped by slot (multi-slot only) |
| `Your selection` | Bundle line | Human-readable list (visible, single-slot bundles) |
| *slot label* | Bundle line | One visible row per slot (multi-slot bundles) |
| `_glbbwc_bundle_parent` | Child line | Bundle post ID this child belongs to |
| `_glbbwc_bundle_parent_key` | Child line | Originating cart item key |
| `Part of bundle` | Child line | Bundle title (visible) |

### Eligibility rules

A product may be picked when it is published, purchasable, in stock, of type `simple`, and in one of the slot's categories or their descendants. The result is capped at 200 products per slot by default and is authoritative — it drives both the rendered grid and add-to-cart validation, so anything filtered out becomes unpurchasable rather than merely hidden.

---

## Template overrides

Copy `templates/single-glbbwc_bundle.php` into your theme at either path:

```
yourtheme/growthlab-bundle-builder-for-woocommerce/single-glbbwc_bundle.php
yourtheme/single-glbbwc_bundle.php
```

On **classic themes** the plugin's template follows WooCommerce's `single-product.php` wrapper conventions, so Woo-aware themes lay bundle pages out like shop pages.

On **block themes** the plugin deliberately does *not* take over the template. Block themes have no `header.php`/`footer.php`, so a classic template would strip the site header, navigation and footer from the page (and log a PHP deprecation on every view). Core resolves the block template instead and the picker is appended to the content. A theme override, if you provide one, still wins on either kind of theme.

---

## Developer hooks

### Actions

| Hook | Arguments | Fires |
| --- | --- | --- |
| `glbbwc_loaded` | `GLBBWC_Plugin $plugin` | Once every subsystem is registered |
| `glbbwc_bundle_saved` | `GLBBWC_Bundle $bundle` | After bundle settings are saved in the admin |
| `glbbwc_bundle_product_synced` | `int $product_id`, `GLBBWC_Bundle $bundle` | After the hidden product is created or updated |
| `glbbwc_before_bundle_picker` | `GLBBWC_Bundle $bundle` | Before the picker grid is rendered |
| `glbbwc_after_bundle_picker` | `GLBBWC_Bundle $bundle` | After the picker is rendered |
| `glbbwc_before_add_bundle_to_cart` | `GLBBWC_Bundle $bundle`, `int[] $product_ids`, `int $quantity`, `array $cart_item_data` | After validation passes, before the cart accepts the bundle |
| `glbbwc_after_add_bundle_to_cart` | `string $cart_item_key`, `GLBBWC_Bundle $bundle`, `int[] $product_ids`, `int $quantity` | After the bundle is in the cart |
| `glbbwc_create_order_line_item` | `WC_Order_Item_Product $item`, `int $bundle_id`, `int[] $product_ids`, `WC_Order $order` | While the bundle order line is built |
| `glbbwc_after_add_recommended_to_cart` <sup>1.1</sup> | `int $product_id`, `string[] $added`, `string[] $skipped` | After a "Frequently Bundled With" set is added |

### Filters

| Hook | Arguments | Purpose |
| --- | --- | --- |
| `glbbwc_bundle_slots` <sup>1.1</sup> | `array[] $slots`, `GLBBWC_Bundle $bundle` | The bundle's normalised category slots |
| `glbbwc_parsed_bundle_slots` <sup>1.1</sup> | `array[] $slots` | Slots parsed from the admin screen, before saving |
| `glbbwc_bundle_eligible_products` | `int[] $ids`, `GLBBWC_Bundle $bundle`, `array\|null $slot` <sup>1.1</sup> | Final say on which products may be picked |
| `glbbwc_bundle_eligible_products_query_args` | `array $args`, `GLBBWC_Bundle $bundle`, `array\|null $slot` <sup>1.1</sup> | Adjust the `WP_Query` that finds eligible products |
| `glbbwc_bundle_max_eligible_products` | `int $limit`, `GLBBWC_Bundle $bundle` | Cap on products loaded into the picker (default `200`) |
| `glbbwc_bundle_price` | `float $price`, `GLBBWC_Bundle $bundle` | The flat price, used for both display and pricing |
| `glbbwc_cart_item_price` | `float $price`, `array $cart_item`, `GLBBWC_Bundle\|null $bundle` | Price applied to a cart line — the surcharge extension point |
| `glbbwc_validate_bundle_selection` | `WP_Error $error`, `int[] $product_ids`, `int $quantity`, `GLBBWC_Bundle $bundle`, `array\|null $slot_map` <sup>1.1</sup> | Add or clear validation errors |
| `glbbwc_bundle_cart_item_data` | `array $data`, `GLBBWC_Bundle $bundle`, `int[] $product_ids` | Extra cart item data (becomes part of the line's identity) |
| `glbbwc_add_to_cart_redirect` | `string $url`, `GLBBWC_Bundle $bundle` | Where to send the customer after adding; return `''` to stay put |
| `glbbwc_bundle_picker_html` | `string $html`, `GLBBWC_Bundle $bundle` | Rewrite the rendered picker markup |
| `glbbwc_add_child_line_items` | `bool $enabled`, `int $bundle_id`, `WC_Order $order` | Turn off zero-priced child items (you then own stock handling) |
| `glbbwc_child_item_name_prefix` | `string $prefix`, `WC_Order_Item $item` | Prefix shown before child item names (default `↳ `) |
| `glbbwc_post_type_args` | `array $args` | Adjust `register_post_type()` arguments |
| `glbbwc_recommended_partners` <sup>1.1</sup> | `int[] $ids`, `int $product_id` | Curated partners for a product |
| `glbbwc_recommendations_html` <sup>1.1</sup> | `string $html`, `int $product_id`, `int[] $partner_ids` | Rewrite the "Frequently Bundled With" markup |
| `glbbwc_recommended_add_to_cart_redirect` <sup>1.1</sup> | `string $url`, `int $product_id` | Where to send the customer after "Add all to cart" |
| `glbbwc_discount_rules` <sup>1.1</sup> | `array[] $rules` | The stored BOGO / tiered rules |
| `glbbwc_rule_discount_amount` <sup>1.1</sup> | `float $amount`, `array $rule`, `WC_Cart $cart` | The discount a rule earns against the current cart |

### Example: per-product surcharge

The flat-price model deliberately ships without surcharges, but `glbbwc_cart_item_price` is the seam. Surcharges added here flow through cart, checkout, and order totals with no other changes:

```php
add_filter( 'glbbwc_cart_item_price', function ( $price, $cart_item ) {
	foreach ( (array) $cart_item['glbbwc_selected_ids'] as $product_id ) {
		$price += (float) get_post_meta( $product_id, '_premium_surcharge', true );
	}

	return $price;
}, 10, 2 );
```

### Example: exclude sale items from one slot only

```php
add_filter( 'glbbwc_bundle_eligible_products', function ( $ids, $bundle, $slot ) {
	if ( ! $slot || 'slot-0' !== $slot['id'] ) {
		return $ids;
	}

	return array_values( array_filter( $ids, function ( $id ) {
		$product = wc_get_product( $id );

		return $product && ! $product->is_on_sale();
	} ) );
}, 10, 3 );
```

### JavaScript event

The picker emits a DOM event on every selection change, so surcharge UIs can update a live total without touching plugin code:

```js
document.addEventListener( 'glbbwc:selection-change', function ( event ) {
	// event.detail = {
	//   bundleId, required,
	//   selected: [ ...productIds ],
	//   slots: [ { id, required, selected: [ ...productIds ] } ]
	// }
} );
```

---

## Roadmap

- [x] Bundle CPT with category, item count, flat price and active/inactive status
- [x] Frontend picker via shortcode and per-bundle permalink
- [x] Nonce-protected AJAX add-to-cart with full server-side validation
- [x] Flat bundle pricing across cart, checkout and orders
- [x] Native stock handling via zero-priced child line items
- [x] HPOS and Cart/Checkout blocks compatibility
- [x] **Multi-category bundles with per-slot pick counts** *(1.1.0)*
- [x] **"Frequently Bundled With" product recommendations** *(1.1.0)*
- [x] **BOGO / tiered automatic discount rules** *(1.1.0)*
- [ ] Variable product support in the picker
- [ ] Per-product surcharges in the admin UI (the `glbbwc_cart_item_price` filter covers this in code today)
- [ ] Min/max ranges per slot, rather than an exact count
- [ ] Pagination or search in pickers with large catalogs
- [ ] Algorithmic recommendations derived from order history
- [ ] Drag-to-reorder slots in the admin repeater

---

## Coding standards

The plugin targets the WordPress Coding Standards ruleset:

```bash
composer require --dev wp-coding-standards/wpcs
vendor/bin/phpcs --standard=phpcs.xml.dist
```

Conventions in use: all classes and hooks prefixed `GLBBWC_` / `glbbwc_`, every input sanitised and every output escaped, nonces on all state-changing requests, and assets enqueued only on pages that render the relevant UI.

---

## Known limitations

- Simple products only — variable products are not offered in the picker.
- One flat price per bundle; surcharges require the filter shown above.
- Each slot loads up to 200 eligible products, with no pagination or search.
- Coupons apply to the bundle line at its flat price, not to the picked products individually.
- Discount rules match on a single category each; overlapping rules stack rather than competing for the best outcome.
- Recommendations are admin-curated, not generated from order history.
