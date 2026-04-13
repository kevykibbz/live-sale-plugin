<?php
/**
 * Plugin Name: Live Sale Combined – Socket.io Real‑Time
 * Description: Live product grid with claiming, waitlist, pinning, real-time updates via self-hosted Socket.io, chat, and video overlay.
 * Version: 6.0
 * Requires Plugins: woocommerce
 *
 * SHORTCODES
 *   [live_sale_grid]           — product grid
 *   [live_sale_chat]           — standalone chat panel
 *   [lsg_live_view video_url="…" chat_side="left" chat_width="30"]
 *                              — video with chat overlay (TikTok / IG Live style)
 *   [lsg_giveaway_timer product_id="X"]
 *   [lsg_auction_widget product_id="X"]
 *
 * CONFIG (wp-config.php)
 *   define( 'LSG_SOCKETIO_URL',    'https://your-droplet:3000' );
 *   define( 'LSG_SOCKETIO_SECRET', 'your_shared_secret_here' );
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// No-texturize filter (must run before shortcodes register)
// ============================================================
add_filter( 'no_texturize_shortcodes', function ( $shortcodes ) {
    $shortcodes[] = 'live_sale_grid';
    $shortcodes[] = 'live_sale_chat';
    $shortcodes[] = 'lsg_live_view';
    return $shortcodes;
} );

// ============================================================
// Socket.io Configuration — set these in wp-config.php:
//   define( 'LSG_SOCKETIO_URL',    'https://your-droplet:3000' );
//   define( 'LSG_SOCKETIO_SECRET', 'your_shared_secret_here' );
// ============================================================
if ( ! defined( 'LSG_SOCKETIO_URL' ) )    define( 'LSG_SOCKETIO_URL',    '' );
if ( ! defined( 'LSG_SOCKETIO_SECRET' ) ) define( 'LSG_SOCKETIO_SECRET', '' );
define( 'LSG_SOCKETIO_CHAT_CHANNEL',    'live-sale-chat' );
define( 'LSG_SOCKETIO_PRODUCT_CHANNEL', 'live-sale-products' );

// ============================================================
// Load modules
// ============================================================
$_lsg_modules = [ 'helpers', 'product-data', 'admin', 'ajax', 'shortcodes', 'live-view', 'customizer' ];
foreach ( $_lsg_modules as $_lsg_module ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/' . $_lsg_module . '.php';
}
unset( $_lsg_modules, $_lsg_module );

// Remove Botiga's footer copyright bar
add_action( 'after_setup_theme', function () {
    if ( class_exists( 'Botiga_Footer' ) ) {
        remove_action( 'botiga_footer', [ Botiga_Footer::get_instance(), 'footer_markup' ] );
    }
}, 20 );

// ============================================================
// Hide page title (entry-header) on any page that uses [lsg_live_view]
// Uses Botiga's own filter — avoids CSS flash entirely
// ============================================================
add_action( 'wp', function () {
    global $post;
    if ( ! $post || ! has_shortcode( $post->post_content, 'lsg_live_view' ) ) return;
    add_filter( 'botiga_entry_header', '__return_false' );
} );

// ============================================================
// Remove Shop page title + breadcrumb
// Botiga reads these three theme_mods and does an early return
// (no <header> rendered at all) when all three are falsy.
// ============================================================
add_filter( 'theme_mod_shop_page_title',       '__return_zero' );
add_filter( 'theme_mod_shop_breadcrumbs',      '__return_zero' );
add_filter( 'theme_mod_shop_page_description', '__return_zero' );

// ============================================================
// Disable regular WooCommerce Add to Cart for Live Sale products
// These products can ONLY be claimed through the Live Sale grid
// ============================================================
add_filter( 'woocommerce_is_purchasable', function ( $purchasable, $product ) {
    if ( ! $product ) return $purchasable;
    if ( has_term( 'live-sale', 'product_cat', $product->get_id() ) ) {
        return false;
    }
    return $purchasable;
}, 10, 2 );

// Hide short description for Live Sale products (contains claimed users list for grid only)
add_filter( 'woocommerce_short_description', function ( $short_desc ) {
    global $product;
    if ( ! $product || ! is_singular( 'product' ) ) return $short_desc;
    if ( has_term( 'live-sale', 'product_cat', $product->get_id() ) ) {
        return '';
    }
    return $short_desc;
} );

// Show custom message on Live Sale product pages instead of add-to-cart button
add_action( 'woocommerce_single_product_summary', function () {
    global $product;
    if ( ! $product || ! has_term( 'live-sale', 'product_cat', $product->get_id() ) ) return;
    
    echo '<div class="lsg-live-sale-notice" style="background: #f0f9ff; border-left: 4px solid #0284c7; padding: 16px; margin: 20px 0; border-radius: 4px;">';
    echo '<p style="margin: 0 0 12px; font-weight: 600; color: #0c4a6e;">📺 This product is available through Live Sale only!</p>';
    echo '<p style="margin: 0; color: #334155;">Visit the <a href="' . esc_url( home_url( '/live-sales/' ) ) . '" style="color: #0284c7; text-decoration: none; font-weight: 600;">Live Sales page</a> to claim, bid, or enter giveaways.</p>';
    echo '</div>';
}, 30 );

// Enqueue hero CSS on front page (so body.home rules always apply)
// Also register Ionicons v8 ESM web component (enqueued on demand by shortcodes)
add_action( 'wp_enqueue_scripts', function () {
    if ( is_front_page() ) {
        wp_enqueue_style(
            'lsg-hero',
            plugin_dir_url( __FILE__ ) . 'css/livesale-hero.css',
            [],
            '1.0'
        );
    }

    // Register Ionicons — shortcodes call wp_enqueue_script('ionicons-esm') to pull it in
    wp_register_script(
        'ionicons-esm',
        'https://cdnjs.cloudflare.com/ajax/libs/ionicons/8.0.13/ionicons/ionicons.esm.min.js',
        [],
        null,
        true
    );
} );

// Swap WordPress's default <script> tag for Ionicons to the correct ESM module tag
add_filter( 'script_loader_tag', function ( $tag, $handle ) {
    if ( $handle !== 'ionicons-esm' ) {
        return $tag;
    }
    $esm  = 'https://cdnjs.cloudflare.com/ajax/libs/ionicons/8.0.13/ionicons/ionicons.esm.min.js';
    $cjs  = 'https://cdnjs.cloudflare.com/ajax/libs/ionicons/8.0.13/ionicons/p-BKJPfAGl.min.js';
    return '<script type="module" crossorigin="anonymous" src="' . esc_url( $esm ) . '"></script>' . "\n"
         . '<script nomodule crossorigin="anonymous" src="' . esc_url( $cjs ) . '"></script>' . "\n";
}, 10, 2 );
