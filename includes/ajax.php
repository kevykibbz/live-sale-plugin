<?php
/**
 * LSG AJAX Handlers — all public and admin wp_ajax_* handlers.
 *
 * @package LiveSale
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ------------------------------------------------------------
// Utility: return current user's display name (or guest slug)
// ------------------------------------------------------------
function lsg_current_username() : string {
    $u = wp_get_current_user();
    return $u && $u->exists() ? ( $u->display_name ?: $u->user_login ) : 'guest_' . substr( session_id(), -6 );
}

// ============================================================
// Product grid AJAX
// ============================================================

add_action( 'wp_ajax_lsg_get_products',        'lsg_ajax_get_products' );
add_action( 'wp_ajax_nopriv_lsg_get_products', 'lsg_ajax_get_products' );
function lsg_ajax_get_products() {
    check_ajax_referer( 'lsg_actions', '_ajax_nonce' );
    $cat_id = lsg_get_live_sale_category();
    if ( ! $cat_id ) wp_send_json_success( [ 'html' => '<p>No live-sale products.</p>', 'version' => 0 ] );

    $q = new WP_Query( [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    $ids = [];
    while ( $q->have_posts() ) { $q->the_post(); $ids[] = get_the_ID(); }
    wp_reset_postdata();

    usort( $ids, fn( $a, $b ) => (int) get_post_meta( $b, 'lsg_pinned', true ) - (int) get_post_meta( $a, 'lsg_pinned', true ) );

    $html = '';
    foreach ( $ids as $pid ) {
        $d = lsg_get_product_data( $pid );
        if ( $d ) $html .= lsg_render_product_card( $d );
    }

    wp_send_json_success( [
        'html'    => $html ?: '<p>No products yet.</p>',
        'version' => (int) get_option( 'lsg_global_version', 0 ),
    ] );
}

add_action( 'wp_ajax_lsg_get_product_card',        'lsg_ajax_get_product_card' );
add_action( 'wp_ajax_nopriv_lsg_get_product_card', 'lsg_ajax_get_product_card' );
function lsg_ajax_get_product_card() {
    check_ajax_referer( 'lsg_actions', '_ajax_nonce' );
    $pid = absint( $_POST['product_id'] ?? $_GET['product_id'] ?? 0 );
    $d   = $pid ? lsg_get_product_data( $pid ) : null;
    if ( ! $d ) wp_send_json_error( 'Product not found.' );
    wp_send_json_success( [ 'html' => lsg_render_product_card( $d ), 'version' => $d['version'] ] );
}

add_action( 'wp_ajax_lsg_get_global_version',        'lsg_ajax_get_global_version' );
add_action( 'wp_ajax_nopriv_lsg_get_global_version', 'lsg_ajax_get_global_version' );
function lsg_ajax_get_global_version() {
    check_ajax_referer( 'lsg_actions', '_ajax_nonce' );
    wp_send_json_success( [ 'version' => (int) get_option( 'lsg_global_version', 0 ) ] );
}

// ============================================================
// Claim / Waitlist
// ============================================================

add_action( 'wp_ajax_lsg_claim_product', 'lsg_ajax_claim_product' );
function lsg_ajax_claim_product() {
    check_ajax_referer( 'lsg_actions', '_ajax_nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Login required.' );

    $pid      = absint( $_POST['product_id'] ?? 0 );
    $username = lsg_current_username();

    if ( ! $pid ) wp_send_json_error( 'Invalid product.' );

    $available = (int) get_post_meta( $pid, 'available_stock', true );
    if ( $available <= 0 ) wp_send_json_error( 'No stock available.' );

    $claimed = get_post_meta( $pid, 'claimed_users', true ) ?: [];
    if ( in_array( $username, $claimed, true ) ) wp_send_json_error( 'Already claimed.' );

    $claimed[] = $username;
    update_post_meta( $pid, 'claimed_users',  $claimed );
    update_post_meta( $pid, 'available_stock', $available - 1 );
    lsg_sync_short_description( $pid );
    lsg_increment_global_version();
    update_post_meta( $pid, '_lsg_version', (int) get_post_meta( $pid, '_lsg_version', true ) + 1 );

    // Auto add to WooCommerce cart
    $product = wc_get_product( $pid );
    if ( $product ) WC()->cart->add_to_cart( $pid, 1 );

    $product_data = wc_get_product( $pid );
    lsg_socketio_publish( LSG_SOCKETIO_PRODUCT_CHANNEL, 'product-updated', [
        'product_id' => $pid,
        'name'       => $product_data ? $product_data->get_name() : '',
        'claimed_by' => $username,
        'available'  => $available - 1,
    ] );

    $d = lsg_get_product_data( $pid );
    wp_send_json_success( [
        'message' => 'Claimed!',
        'html'    => $d ? lsg_render_product_card( $d ) : '',
    ] );
}

add_action( 'wp_ajax_lsg_join_waitlist', 'lsg_ajax_join_waitlist' );
function lsg_ajax_join_waitlist() {
    check_ajax_referer( 'lsg_actions', '_ajax_nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Login required.' );

    $pid      = absint( $_POST['product_id'] ?? 0 );
    $username = lsg_current_username();
    $waitlist = get_post_meta( $pid, 'lsg_waitlist', true ) ?: [];

    if ( in_array( $username, $waitlist, true ) ) wp_send_json_error( 'Already on waitlist.' );

    $waitlist[] = $username;
    update_post_meta( $pid, 'lsg_waitlist', $waitlist );

    $d = lsg_get_product_data( $pid );
    wp_send_json_success( [
        'message' => 'Added to waitlist!',
        'html'    => $d ? lsg_render_product_card( $d ) : '',
    ] );
}

// ============================================================
// Giveaway
// ============================================================

add_action( 'wp_ajax_lsg_enter_giveaway', 'lsg_ajax_enter_giveaway' );
function lsg_ajax_enter_giveaway() {
    check_ajax_referer( 'lsg_actions', '_ajax_nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Login required.' );

    $pid      = absint( $_POST['product_id'] ?? 0 );
    $username = lsg_current_username();

    $status = get_post_meta( $pid, 'lsg_giveaway_status', true );
    if ( $status !== 'running' ) wp_send_json_error( 'Giveaway not active.' );

    // Claimed-only restriction - check if user has claimed ANY Live Sale product
    if ( get_post_meta( $pid, 'lsg_giveaway_claimed_only', true ) ) {
        $has_claimed = false;
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'tax_query'      => [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => 'live-sale',
                ],
            ],
            'fields'         => 'ids',
        ];
        $live_sale_products = get_posts( $args );
        
        foreach ( $live_sale_products as $product_id ) {
            $claimed_users = get_post_meta( $product_id, 'claimed_users', true ) ?: [];
            if ( in_array( $username, $claimed_users, true ) ) {
                $has_claimed = true;
                break;
            }
        }
        
        if ( ! $has_claimed ) {
            wp_send_json_error( 'Only claimed users can enter this giveaway.' );
        }
    }

    $entrants = get_post_meta( $pid, 'lsg_giveaway_entrants', true ) ?: [];
    if ( in_array( $username, $entrants, true ) ) wp_send_json_error( 'Already entered.' );

    $entrants[] = $username;
    update_post_meta( $pid, 'lsg_giveaway_entrants', $entrants );
    lsg_socketio_publish( LSG_SOCKETIO_PRODUCT_CHANNEL, 'giveaway-entered', [
        'product_id' => $pid,
        'entrants'   => count( $entrants ),
    ] );

    $d = lsg_get_product_data( $pid );
    wp_send_json_success( [
        'message' => 'Entered!',
        'html'    => $d ? lsg_render_product_card( $d ) : '',
    ] );
}

// Start giveaway (admin only, also used from admin tab)
add_action( 'wp_ajax_lsg_start_giveaway', 'lsg_ajax_start_giveaway' );
function lsg_ajax_start_giveaway() {
    check_ajax_referer( 'live_sale_update', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'No permission.' );

    $pid      = absint( $_POST['product_id'] ?? 0 );
    $duration = max( 1, (int) ( $_POST['duration'] ?? 5 ) );
    $end_time = time() + $duration * 60;

    update_post_meta( $pid, 'lsg_giveaway_status',        'running' );
    update_post_meta( $pid, 'lsg_giveaway_duration',      $duration );
    update_post_meta( $pid, 'lsg_giveaway_end_time',      $end_time );
    update_post_meta( $pid, 'lsg_giveaway_entrants',      [] );
    update_post_meta( $pid, 'lsg_giveaway_winner',        '' );
    update_post_meta( $pid, 'lsg_win_processed',          0 );
    update_post_meta( $pid, 'lsg_giveaway_restart_count', 0 ); // Reset restart counter on fresh start
    lsg_increment_global_version();

    lsg_socketio_publish( LSG_SOCKETIO_PRODUCT_CHANNEL, 'giveaway-started', [
        'product_id' => $pid,
        'end_time'   => $end_time,
        'duration'   => $duration,
    ] );

    wp_send_json_success( [ 'end_time' => $end_time, 'html' => lsg_render_admin_row( $pid ) ] );
}

// Roll winner (admin)
add_action( 'wp_ajax_lsg_roll_giveaway_winner', 'lsg_ajax_roll_giveaway_winner' );
function lsg_ajax_roll_giveaway_winner() {
    check_ajax_referer( 'live_sale_update', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'No permission.' );

    $pid    = absint( $_POST['product_id'] ?? 0 );
    $result = lsg_do_roll_winner( $pid );

    if ( isset( $result['error'] ) ) wp_send_json_error( $result['error'] );

    $winner = $result['winner'] ?: 'No entrants';
    wp_send_json_success( [ 'winner' => $winner, 'html' => lsg_render_admin_row( $pid ) ] );
}

// Auto-roll (public, triggered by front-end timer expiry)
add_action( 'wp_ajax_lsg_auto_roll_winner',        'lsg_ajax_auto_roll_winner' );
add_action( 'wp_ajax_nopriv_lsg_auto_roll_winner', 'lsg_ajax_auto_roll_winner' );
function lsg_ajax_auto_roll_winner() {
    check_ajax_referer( 'lsg_actions', '_ajax_nonce' );
    $pid    = absint( $_POST['product_id'] ?? 0 );
    $result = lsg_do_roll_winner( $pid );
    if ( isset( $result['already_done'] ) ) {
        wp_send_json_success( [ 'winner' => $result['winner'] ] );
    }
    if ( isset( $result['error'] ) ) wp_send_json_error( $result['error'] );
    wp_send_json_success( [ 'winner' => $result['winner'] ?: 'No entrants' ] );
}

// ============================================================
// Auction
// ============================================================

add_action( 'wp_ajax_lsg_place_bid',        'lsg_ajax_place_bid' );
add_action( 'wp_ajax_nopriv_lsg_place_bid', 'lsg_ajax_place_bid' );
function lsg_ajax_place_bid() {
    check_ajax_referer( 'lsg_actions', '_ajax_nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Login required.' );

    $pid      = absint( $_POST['product_id'] ?? 0 );
    $bid      = round( floatval( $_POST['bid'] ?? 0 ), 2 );
    $username = lsg_current_username();

    if ( $bid <= 0 ) wp_send_json_error( 'Invalid bid.' );

    $status     = get_post_meta( $pid, 'lsg_auction_status',      true );
    $base_price = (float) get_post_meta( $pid, 'lsg_auction_base_price', true );
    $current    = (float) get_post_meta( $pid, 'lsg_auction_current_bid', true );

    if ( $status !== 'running' ) wp_send_json_error( 'Auction not active.' );

    $min_bid = max( $base_price, $current ) + 0.01;
    if ( $bid < $min_bid ) {
        wp_send_json_error( sprintf( 'Minimum bid is %s.', strip_tags( wc_price( $min_bid ) ) ) );
    }

    // Anti-snipe: extend 10s if less than 10s remain
    $end_time = (int) get_post_meta( $pid, 'lsg_auction_end_time', true );
    if ( $end_time - time() < 10 ) {
        $end_time = time() + 10;
        update_post_meta( $pid, 'lsg_auction_end_time', $end_time );
    }

    update_post_meta( $pid, 'lsg_auction_current_bid',    $bid );
    update_post_meta( $pid, 'lsg_auction_current_bidder', $username );

    $bids = get_post_meta( $pid, 'lsg_auction_bids', true ) ?: [];
    $bids[] = [ 'user' => $username, 'bid' => $bid, 'time' => time() ];
    update_post_meta( $pid, 'lsg_auction_bids', $bids );

    lsg_increment_global_version();
    update_post_meta( $pid, '_lsg_version', (int) get_post_meta( $pid, '_lsg_version', true ) + 1 );

    $product = wc_get_product( $pid );
    lsg_socketio_publish( LSG_SOCKETIO_PRODUCT_CHANNEL, 'auction-bid', [
        'product_id' => $pid,
        'name'       => $product ? $product->get_name() : '',
        'bidder'     => $username,
        'bid'        => $bid,
        'end_time'   => $end_time,
    ] );

    $d = lsg_get_product_data( $pid );
    wp_send_json_success( [
        'message'  => 'Bid placed!',
        'bid'      => $bid,
        'end_time' => $end_time,
        'html'     => $d ? lsg_render_product_card( $d ) : '',
    ] );
}

add_action( 'wp_ajax_lsg_start_auction', 'lsg_ajax_start_auction' );
function lsg_ajax_start_auction() {
    check_ajax_referer( 'live_sale_update', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'No permission.' );

    $pid      = absint( $_POST['product_id'] ?? 0 );
    $duration = max( 1, (int) ( $_POST['duration'] ?? 30 ) );
    $base     = (float) get_post_meta( $pid, 'lsg_auction_base_price', true );
    $end_time = time() + $duration;

    update_post_meta( $pid, 'lsg_auction_status',          'running' );
    update_post_meta( $pid, 'lsg_auction_duration',        $duration );
    update_post_meta( $pid, 'lsg_auction_end_time',        $end_time );
    update_post_meta( $pid, 'lsg_auction_current_bid',    0 );
    update_post_meta( $pid, 'lsg_auction_current_bidder', '' );
    update_post_meta( $pid, 'lsg_auction_bids',            [] );
    lsg_increment_global_version();

    lsg_socketio_publish( LSG_SOCKETIO_PRODUCT_CHANNEL, 'auction-started', [
        'product_id' => $pid,
        'end_time'   => $end_time,
        'base_price' => $base,
    ] );

    wp_send_json_success( [ 'end_time' => $end_time, 'html' => lsg_render_admin_row( $pid ) ] );
}

add_action( 'wp_ajax_lsg_end_auction', 'lsg_ajax_end_auction' );
function lsg_ajax_end_auction() {
    check_ajax_referer( 'live_sale_update', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'No permission.' );

    $pid        = absint( $_POST['product_id'] ?? 0 );
    $winner     = (string) get_post_meta( $pid, 'lsg_auction_current_bidder', true );
    $final_bid  = (float) get_post_meta( $pid, 'lsg_auction_current_bid', true );

    update_post_meta( $pid, 'lsg_auction_status', 'ended' );
    lsg_increment_global_version();

    $product = wc_get_product( $pid );
    lsg_socketio_publish( LSG_SOCKETIO_PRODUCT_CHANNEL, 'auction-ended', [
        'product_id' => $pid,
        'name'       => $product ? $product->get_name() : '',
        'winner'     => $winner ?: 'No bids',
        'final_bid'  => $final_bid,
    ] );

    if ( $winner ) {
        lsg_send_auction_win_email( $pid, $winner, $final_bid );
    }

    wp_send_json_success( [
        'winner' => $winner ?: 'No bids',
        'html'   => lsg_render_admin_row( $pid ),
    ] );
}

// ============================================================
// Admin list refresh
// ============================================================

add_action( 'wp_ajax_lsg_refresh_admin_row', 'lsg_ajax_refresh_admin_row' );
function lsg_ajax_refresh_admin_row() {
    check_ajax_referer( 'live_sale_update', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'No permission.' );
    $pid = absint( $_POST['product_id'] ?? 0 );
    wp_send_json_success( [ 'html' => lsg_render_admin_row( $pid ) ] );
}

add_action( 'wp_ajax_lsg_refresh_admin_list', 'lsg_ajax_refresh_admin_list' );
function lsg_ajax_refresh_admin_list() {
    check_ajax_referer( 'lsg_refresh_admin_list', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No permission.' );
    $paged = max( 1, (int) ( $_GET['page'] ?? 1 ) );
    ob_start();
    lsg_render_admin_list( $paged );
    echo ob_get_clean();
    wp_die();
}

// ============================================================
// Inline field updates (admin)
// ============================================================

function _lsg_update_guard() {
    check_ajax_referer( 'live_sale_update', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'No permission.' );
}

add_action( 'wp_ajax_lsg_update_product_name', function () {
    _lsg_update_guard();
    $pid  = absint( $_POST['product_id'] ?? 0 );
    $name = sanitize_text_field( $_POST['value'] ?? '' );
    if ( ! $pid || ! $name ) wp_send_json_error( 'Invalid.' );
    $product = wc_get_product( $pid );
    if ( ! $product ) wp_send_json_error( 'Not found.' );
    $product->set_name( $name );
    $product->save();
    lsg_increment_global_version();
    wp_send_json_success( [ 'html' => lsg_render_admin_row( $pid ) ] );
} );

add_action( 'wp_ajax_lsg_update_price', function () {
    _lsg_update_guard();
    $pid   = absint( $_POST['product_id'] ?? 0 );
    $price = floatval( $_POST['value'] ?? 0 );
    if ( ! $pid || $price < 0 ) wp_send_json_error( 'Invalid.' );
    $product = wc_get_product( $pid );
    if ( ! $product ) wp_send_json_error( 'Not found.' );
    $product->set_regular_price( $price );
    $product->set_price( $price );
    $product->save();
    lsg_increment_global_version();
    wp_send_json_success( [ 'html' => lsg_render_admin_row( $pid ) ] );
} );

add_action( 'wp_ajax_lsg_update_total_stock', function () {
    _lsg_update_guard();
    $pid   = absint( $_POST['product_id'] ?? 0 );
    $stock = max( 0, (int) ( $_POST['value'] ?? 0 ) );
    $product = wc_get_product( $pid );
    if ( ! $product ) wp_send_json_error( 'Not found.' );
    $product->set_stock_quantity( $stock );
    $product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
    $product->save();
    lsg_increment_global_version();
    wp_send_json_success( [ 'html' => lsg_render_admin_row( $pid ) ] );
} );

add_action( 'wp_ajax_lsg_update_available_stock', function () {
    _lsg_update_guard();
    $pid   = absint( $_POST['product_id'] ?? 0 );
    $stock = max( 0, (int) ( $_POST['value'] ?? 0 ) );
    update_post_meta( $pid, 'available_stock', $stock );
    lsg_increment_global_version();
    wp_send_json_success( [ 'html' => lsg_render_admin_row( $pid ) ] );
} );

add_action( 'wp_ajax_lsg_update_claimed_users', function () {
    _lsg_update_guard();
    $pid   = absint( $_POST['product_id'] ?? 0 );
    $raw   = sanitize_text_field( $_POST['value'] ?? '' );
    $users = array_map( 'trim', explode( ',', $raw ) );
    $users = array_filter( array_unique( $users ) );
    update_post_meta( $pid, 'claimed_users', array_values( $users ) );
    lsg_sync_short_description( $pid );
    lsg_increment_global_version();
    wp_send_json_success( [ 'html' => lsg_render_admin_row( $pid ) ] );
} );

add_action( 'wp_ajax_lsg_toggle_pin', function () {
    $pid   = absint( $_POST['product_id'] ?? 0 );
    $nonce = sanitize_text_field( $_POST['_ajax_nonce'] ?? '' );
    if ( ! wp_verify_nonce( $nonce, 'lsg_pin_product_' . $pid ) || ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'No permission.' );
    }
    $pinned = ! (bool) get_post_meta( $pid, 'lsg_pinned', true );
    update_post_meta( $pid, 'lsg_pinned', $pinned ? 1 : 0 );
    lsg_increment_global_version();
    wp_send_json_success( [ 'html' => lsg_render_admin_row( $pid ) ] );
} );

add_action( 'wp_ajax_lsg_delete_product', function () {
    $pid   = absint( $_POST['product_id'] ?? 0 );
    $nonce = sanitize_text_field( $_POST['_ajax_nonce'] ?? '' );
    if ( ! wp_verify_nonce( $nonce, 'lsg_delete_product_' . $pid ) || ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( 'No permission.' );
    }
    $deleted = wp_delete_post( $pid, true );
    if ( $deleted ) {
        lsg_increment_global_version();
        lsg_socketio_publish( LSG_SOCKETIO_PRODUCT_CHANNEL, 'product-deleted', [ 'product_id' => $pid ] );
        wp_send_json_success();
    } else {
        wp_send_json_error( 'Could not delete.' );
    }
} );

// ============================================================
// Cart validation (WooCommerce)
// ============================================================

add_action( 'woocommerce_check_cart_items',   'lsg_validate_cart_claims' );
add_action( 'woocommerce_checkout_process',   'lsg_validate_cart_claims' );
function lsg_validate_cart_claims() {
    if ( ! WC()->cart || is_admin() ) return;
    $username  = lsg_current_username();
    $cat_id    = lsg_get_live_sale_category();
    if ( ! $cat_id ) return;

    foreach ( WC()->cart->get_cart() as $key => $item ) {
        $pid     = $item['product_id'];
        $terms   = wp_get_post_terms( $pid, 'product_cat', [ 'fields' => 'ids' ] );
        if ( ! in_array( (int) $cat_id, array_map( 'intval', $terms ), true ) ) continue;
        $claimed = get_post_meta( $pid, 'claimed_users', true ) ?: [];
        if ( ! in_array( $username, $claimed, true ) ) {
            WC()->cart->remove_cart_item( $key );
            wc_add_notice(
                sprintf( __( 'Sorry, "%s" is a Live Sale exclusive and can only be purchased after claiming. It has been removed from your cart.', 'livesale' ), get_the_title( $pid ) ),
                'error'
            );
        }
    }
}

// ============================================================
// Chat AJAX
// ============================================================

add_action( 'wp_ajax_lsg_fetch_chat',        'lsg_ajax_fetch_chat' );
add_action( 'wp_ajax_nopriv_lsg_fetch_chat', 'lsg_ajax_fetch_chat' );
function lsg_ajax_fetch_chat() {
    check_ajax_referer( 'lsg_chat_nonce', '_ajax_nonce' );
    $messages = get_option( 'lsg_chat_messages', [] );
    if ( ! is_array( $messages ) ) $messages = [];
    $messages = array_slice( array_values( $messages ), -100 );

    // Normalize to the field names the JS expects: msg, ts (support both old and new storage keys)
    $normalized = array_map( function ( $m ) {
        return [
            'user' => $m['user'] ?? '',
            'msg'  => $m['msg'] ?? $m['text'] ?? '',
            'ts'   => $m['ts'] ?? $m['timestamp'] ?? 0,
        ];
    }, $messages );

    // Return as a flat array (not wrapped in {messages:...}) so JS can use .map()/.slice()
    wp_send_json_success( $normalized );
}

add_action( 'wp_ajax_lsg_send_chat', 'lsg_ajax_send_chat' );
function lsg_ajax_send_chat() {
    check_ajax_referer( 'lsg_chat_nonce', '_ajax_nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Login required.' );

    $text = sanitize_text_field( wp_unslash( $_POST['message'] ?? '' ) );
    if ( ! $text || strlen( $text ) > 500 ) wp_send_json_error( 'Invalid message.' );

    $username = lsg_current_username();
    $messages = get_option( 'lsg_chat_messages', [] );
    if ( ! is_array( $messages ) ) $messages = [];

    $messages[] = [
        'user'      => $username,
        'text'      => $text,
        'timestamp' => time(),
        'is_admin'  => current_user_can( 'manage_woocommerce' ),
    ];

    if ( count( $messages ) > 200 ) {
        $messages = array_slice( $messages, -200 );
    }

    update_option( 'lsg_chat_messages', $messages, false );
    lsg_socketio_publish( LSG_SOCKETIO_CHAT_CHANNEL, 'new-message', [
        'user'      => $username,
        'text'      => $text,
        'timestamp' => end( $messages )['timestamp'],
        'is_admin'  => current_user_can( 'manage_woocommerce' ),
    ] );

    wp_send_json_success();
}

add_action( 'wp_ajax_lsg_admin_send_chat', 'lsg_ajax_admin_send_chat' );
function lsg_ajax_admin_send_chat() {
    check_ajax_referer( 'lsg_chat_nonce', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'No permission.' );

    $text = sanitize_text_field( wp_unslash( $_POST['message'] ?? '' ) );
    if ( ! $text || strlen( $text ) > 500 ) wp_send_json_error( 'Invalid message.' );

    $username = lsg_current_username();
    $messages = get_option( 'lsg_chat_messages', [] );
    if ( ! is_array( $messages ) ) $messages = [];
    $messages[] = [
        'user'      => $username,
        'text'      => $text,
        'timestamp' => time(),
        'is_admin'  => true,
    ];
    if ( count( $messages ) > 200 ) $messages = array_slice( $messages, -200 );
    update_option( 'lsg_chat_messages', $messages, false );

    lsg_socketio_publish( LSG_SOCKETIO_CHAT_CHANNEL, 'new-message', [
        'user'      => $username,
        'text'      => $text,
        'timestamp' => end( $messages )['timestamp'],
        'is_admin'  => true,
    ] );

    wp_send_json_success();
}

add_action( 'wp_ajax_lsg_admin_delete_msg', 'lsg_ajax_admin_delete_msg' );
function lsg_ajax_admin_delete_msg() {
    check_ajax_referer( 'lsg_chat_nonce', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'No permission.' );

    $idx      = (int) ( $_POST['index'] ?? -1 );
    $messages = get_option( 'lsg_chat_messages', [] );
    if ( ! is_array( $messages ) || ! isset( $messages[ $idx ] ) ) wp_send_json_error( 'Not found.' );

    array_splice( $messages, $idx, 1 );
    update_option( 'lsg_chat_messages', array_values( $messages ), false );
    lsg_socketio_publish( LSG_SOCKETIO_CHAT_CHANNEL, 'chat-deleted', [ 'index' => $idx ] );
    wp_send_json_success();
}

add_action( 'wp_ajax_lsg_admin_clear_chat', 'lsg_ajax_admin_clear_chat' );
function lsg_ajax_admin_clear_chat() {
    check_ajax_referer( 'lsg_chat_nonce', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'No permission.' );
    update_option( 'lsg_chat_messages', [], false );
    lsg_socketio_publish( LSG_SOCKETIO_CHAT_CHANNEL, 'chat-cleared', [] );
    wp_send_json_success();
}
