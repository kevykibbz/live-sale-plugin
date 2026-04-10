<?php
/**
 * LSG Helpers — shared utility functions used across all modules.
 *
 * @package LiveSale
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Async cron: broadcast giveaway winner via Ably ─────────
// Scheduled by lsg_do_roll_winner(); runs out-of-band so the
// request that triggered the roll is not blocked.
add_action( 'lsg_async_winner_broadcast', function ( int $pid ) {
    $product = wc_get_product( $pid );
    $winner  = (string) get_post_meta( $pid, 'lsg_giveaway_winner', true );
    lsg_ably_publish( ABLY_PRODUCT_CHANNEL, 'giveaway-winner', [
        'product_id' => $pid,
        'name'       => $product ? $product->get_name() : '',
        'winner'     => $winner ?: 'No entrants',
    ] );
    if ( $winner ) {
        lsg_process_giveaway_win( $pid, $winner );
    }
} );

/**
 * Check WooCommerce is active; show admin notice if not.
 *
 * @return bool
 */
function lsg_check_woocommerce() : bool {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="error"><p><strong>Live Sale</strong> requires WooCommerce.</p></div>';
        } );
        return false;
    }
    return true;
}

/**
 * Get (or create) the "Live Sale" product category term ID.
 *
 * @return int|false
 */
function lsg_get_live_sale_category() {
    $term = get_term_by( 'slug', 'live-sale', 'product_cat' )
         ?: get_term_by( 'name', 'Live Sale', 'product_cat' );
    if ( ! $term ) {
        $term = wp_insert_term( 'Live Sale', 'product_cat', [ 'slug' => 'live-sale' ] );
        if ( is_wp_error( $term ) ) return false;
        return $term['term_id'];
    }
    return $term->term_id;
}

/**
 * Bump the global product-list version number so polling clients reload.
 */
function lsg_increment_global_version() : void {
    $v = (int) get_option( 'lsg_global_version', 0 );
    update_option( 'lsg_global_version', $v + 1 );
}

/**
 * Publish a message to an Ably channel (fire-and-forget).
 *
 * The Ably\AblyRest class is loaded at runtime from the ably-php library;
 * it is not available at static-analysis time, so we instantiate via a
 * variable class name to avoid IDE "Undefined type" warnings.
 *
 * @param string $channel_name
 * @param string $event
 * @param array  $data
 */
function lsg_ably_publish( string $channel_name, string $event, array $data ) : void {
    if ( defined( 'ABLY_DISABLED' ) || ! class_exists( 'Ably\AblyRest' ) ) return;
    try {
        $ably_class = '\\Ably\\AblyRest';
        /** @var object $ably */
        $ably = new $ably_class( ABLY_API_KEY );
        $ch   = $ably->channel( $channel_name );
        $ch->publish( $event, $data );
    } catch ( Exception $e ) {
        error_log( '[LSG Ably] ' . $e->getMessage() );
    }
}
