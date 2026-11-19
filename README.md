# Valolink WooCommerce

A **modular** WooCommerce / GeneratePress toolkit. Each feature is a module you toggle under
**Valolink WooCommerce** in wp-admin; a disabled module loads no code (zero footprint). The framework
mirrors the Valolink Plugin toolkit (Module contract, Registry, Loader, single non-autoloaded
`valolink_woo_settings` option). WooCommerce modules go here whether or not they need
GeneratePress; each module checks its own dependencies.

## Replacing Valolink GP Woo

Until 0.1.10 this plugin was **Valolink GP Woo** (`valolink-gp-woo/valolink-gp-woo.php`, repo
`valolink/valolink-gp-woo`). It got a new slug and a new repo in 0.2.0: WordPress can't move an
active plugin to another folder in an update, so the old repo keeps its last release and old installs
are never offered this one.

To switch a site over:

1. `wp plugin install valolink-woocommerce-<version>.zip`, then `wp plugin activate valolink-woocommerce`.
   Activation copies `valolink_gp_woo_settings` into `valolink_woo_settings` and deactivates the old
   plugin. While both are active this plugin stands down (both declare the `gpwc_*` functions).
2. Clear the page cache: cached pages still point at the old plugin's carousel CSS/JS.
3. Remove the old `valolink-gp-woo` folder **by hand**. Never delete it from wp-admin or with
   `wp plugin delete`: its uninstall deletes the `_gpwc_product_card` flags this plugin still uses.

Kept from the old name on purpose, because sites store them: the block name
`valolink-gp-woo/product-carousel` (in post content), the `_gpwc_product_card` Element meta, and the
`gpwc-*` CSS classes.

## Modules

### Product Card
Render a **GeneratePress Block Element** as the **WooCommerce product-loop card**, plus a set of
WooCommerce **dynamic tags**, a **block condition**, and an **add-to-cart shortcode** for
GenerateBlocks. Build your product card visually with GenerateBlocks, flag it, and it replaces
WooCommerce's default `content-product.php` on shop/archive loops.

Requires: WooCommerce, GeneratePress + GP Premium (Elements). GenerateBlocks (+ Pro for the
condition) for building the card.

### Product Carousel
A **Product Carousel** block: a horizontally-scrollable row of products with prev/next arrows.
Each product is rendered through the *same* `content-product.php` card the Product Card module
swaps in — so a carousel of "newest products" on the front page uses the exact same card as the
shop loop (one source of truth, no duplicated card markup). Sidebar settings: **source** (newest,
on sale, featured, best selling, top rated, or a category), **number of products**, **visible
columns**, and an arrows toggle. The block only owns the carousel shell (arrows + scrollable
track); card appearance comes from the shared template, and surrounding chrome (headings, panels)
is just regular blocks you wrap around it.

Requires: WooCommerce. The shared card appears only where a flagged Product Card Element's
Location matches the page the carousel sits on (otherwise WooCommerce's default card renders).

## Usage

1. Create a GeneratePress **Block Element**, design the card with GenerateBlocks.
2. On the Element edit screen, tick **“Use this Element as the WooCommerce product-loop card”**
   (the *WooCommerce Product Card* box).
3. Set the Element's **GeneratePress display rules (Location)** to choose where the card applies —
   e.g. *Entire Site*, or a specific *Product Category Archive*. A location is **required**: an
   Element with no location set shows nowhere (same as a normal GeneratePress Element). Flag
   several Elements with different locations to show different cards on different archives.

### Building the card

- **Dynamic tags** (GenerateBlocks → Dynamic Data, or type them):
  `{{wc_price}}`, `{{wc_regular_price}}`, `{{wc_sale_price}}`, `{{wc_sale_percentage}}`,
  `{{wc_sale_start}}`, `{{wc_sale_end}}`, `{{wc_sale_days_left}}`,
  `{{wc_sku}}`, `{{wc_short_description}}`, `{{wc_categories}}`, `{{wc_weight}}`,
  `{{wc_dimensions}}`, `{{wc_stock_status}}`, `{{wc_stock_quantity}}`, `{{wc_availability}}`,
  `{{wc_rating}}`, `{{wc_review_count}}`, `{{wc_add_to_cart_url}}`, `{{wc_permalink}}`.
  The sale-date tags take a PHP `date()` format option, e.g. `{{wc_sale_end format:"j.n.Y"}}`.
  Product image: GenerateBlocks' built-in `{{featured_image}}` (a product image *is* the
  WordPress featured image). Empty tags hide their block unless you add `required:false`.
- **Conditions** (GenerateBlocks Pro → block conditions):
  - **WooCommerce Product** — rules `is on sale / featured / in stock / on backorder / purchasable / has reviews`.
  - **WooCommerce Product Archive** — rule `has description` (true when the current product
    category/tag archive's term description is non-empty).
- **Add to cart**: a core *Shortcode* block with `[wc_loop_cart]` (WooCommerce's ajax loop button).

## Building a release

```sh
./build.sh        # -> dist/valolink-woocommerce-<version>.zip
```

The zip contains a top-level `valolink-woocommerce/` folder, ready to install in WordPress.

## Updates from wp-admin

This plugin self-updates from this repo's GitHub **Releases**.

1. Set the repo in `valolink-woocommerce.php` (already set to `valolink/valolink-woocommerce`):
   ```php
   define('VALOLINK_WOO_GITHUB_REPO', 'valolink/valolink-woocommerce');
   ```
   (Set to `OWNER/REPO`, the updater stays disabled.)
2. Bump `Version:` in the header (and `VALOLINK_WOO_VERSION`).
3. Push a tag `vX.Y.Z`. The included GitHub Action builds the zip and publishes a Release with it
   attached. WordPress then shows the update on the Plugins screen.

A **Check for updates** row action on the Plugins screen forces an immediate re-check (clears the
cached GitHub release and WordPress's update transient) instead of waiting for the periodic cron.
