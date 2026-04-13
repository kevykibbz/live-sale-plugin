<?php
/**
 * LSG Product Data — data retrieval, card rendering, admin row rendering,
 * giveaway/auction logic, and winner-processing helpers.
 *
 * @package LiveSale
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// Helpers
// ============================================================

/**
 * Sync the WooCommerce short description to reflect claimed users,
 * then return the new short description string.
 */
function lsg_sync_short_description( int $product_id ) : string {
    $claimed    = get_post_meta( $product_id, 'claimed_users', true ) ?: [];
    $short_desc = implode( ', ', array_map( 'sanitize_text_field', $claimed ) );
    wp_update_post( [
        'ID'           => $product_id,
        'post_excerpt' => $short_desc,
    ] );
    return $short_desc;
}

/**
 * Return all product data needed for front-end and admin.
 *
 * @param int $product_id
 * @return array|null
 */
function lsg_get_product_data( int $product_id ) : ?array {
    $product = wc_get_product( $product_id );
    if ( ! $product ) return null;

    $claimed_users   = get_post_meta( $product_id, 'claimed_users',   true ) ?: [];
    $waitlist        = get_post_meta( $product_id, 'lsg_waitlist',     true ) ?: [];
    $available_stock = (int) get_post_meta( $product_id, 'available_stock', true );
    $pinned          = (bool) get_post_meta( $product_id, 'lsg_pinned',     true );
    $version         = (int) get_post_meta( $product_id, '_lsg_version',    true );
    $current_user    = wp_get_current_user();
    $username        = $current_user->display_name ?: $current_user->user_login;

    $image_url = '';
    $thumb_id  = get_post_thumbnail_id( $product_id );
    if ( $thumb_id ) {
        $img = wp_get_attachment_image_src( $thumb_id, 'woocommerce_thumbnail' );
        if ( $img ) $image_url = $img[0];
    }
    if ( ! $image_url ) {
        $image_url = wc_placeholder_img_src( 'woocommerce_thumbnail' );
    }

    $is_giveaway           = (bool)   get_post_meta( $product_id, 'lsg_is_giveaway',           true );
    $giveaway_duration     = (int)    get_post_meta( $product_id, 'lsg_giveaway_duration',      true );
    $giveaway_end_time     = (int)    get_post_meta( $product_id, 'lsg_giveaway_end_time',      true );
    $giveaway_entrants     =          get_post_meta( $product_id, 'lsg_giveaway_entrants',      true ) ?: [];
    $giveaway_winner       = (string) get_post_meta( $product_id, 'lsg_giveaway_winner',        true );
    $giveaway_status       = (string) get_post_meta( $product_id, 'lsg_giveaway_status',        true ) ?: 'idle';
    $giveaway_claimed_only = (bool)   get_post_meta( $product_id, 'lsg_giveaway_claimed_only',  true );

    $is_auction            = (bool)   get_post_meta( $product_id, 'lsg_is_auction',             true );
    $auction_base_price    = (float)  get_post_meta( $product_id, 'lsg_auction_base_price',     true );
    $auction_duration      = (int)    get_post_meta( $product_id, 'lsg_auction_duration',       true );
    $auction_status        = (string) get_post_meta( $product_id, 'lsg_auction_status',         true ) ?: 'idle';
    $auction_end_time      = (int)    get_post_meta( $product_id, 'lsg_auction_end_time',       true );
    $auction_current_bid   = (float)  get_post_meta( $product_id, 'lsg_auction_current_bid',   true );
    $auction_current_bidder= (string) get_post_meta( $product_id, 'lsg_auction_current_bidder',true );
    $auction_bids          =          get_post_meta( $product_id, 'lsg_auction_bids',           true ) ?: [];

    // Server-side auto-advance: if giveaway timer expired but still running, roll now.
    if ( $is_giveaway && $giveaway_status === 'running' && $giveaway_end_time > 0 && $giveaway_end_time < time() ) {
        $lock_key = 'lsg_roll_lock_' . $product_id;
        if ( ! get_transient( $lock_key ) ) {
            set_transient( $lock_key, 1, 30 );
            
            // Auto-restart provision: if no entries, restart timer instead of ending
            if ( empty( $giveaway_entrants ) ) {
                $restart_count = (int) get_post_meta( $product_id, 'lsg_giveaway_restart_count', true );
                $max_restarts  = apply_filters( 'lsg_giveaway_max_restarts', 5 ); // Default 5 restarts
                
                if ( $restart_count < $max_restarts ) {
                    // Restart: extend end time by the original duration (convert minutes to seconds)
                    $new_end_time = time() + ( $giveaway_duration * 60 );
                    update_post_meta( $product_id, 'lsg_giveaway_end_time', $new_end_time );
                    update_post_meta( $product_id, 'lsg_giveaway_restart_count', $restart_count + 1 );
                    delete_transient( $lock_key );
                    
                    // Update local vars so card shows extended time
                    $giveaway_end_time = $new_end_time;
                    
                    // Publish restart event to Socket.io
                    lsg_socketio_publish( LSG_SOCKETIO_PRODUCT_CHANNEL, 'giveaway-restarted', [
                        'product_id'    => $product_id,
                        'new_end_time'  => $new_end_time,
                        'restart_count' => $restart_count + 1,
                    ] );
                } else {
                    // Max restarts reached, end with no winner
                    update_post_meta( $product_id, 'lsg_giveaway_winner', '' );
                    update_post_meta( $product_id, 'lsg_giveaway_status', 'ended' );
                    delete_transient( $lock_key );
                    $giveaway_winner = '';
                    $giveaway_status = 'ended';
                }
            } else {
                // Has entries - proceed with normal winner selection
                $winner_pick = $giveaway_entrants[ array_rand( $giveaway_entrants ) ];
                $cl   = get_post_meta( $product_id, 'claimed_users', true ) ?: [];
                $cl[] = $winner_pick;
                update_post_meta( $product_id, 'claimed_users', array_unique( $cl ) );
                lsg_sync_short_description( $product_id );
                update_post_meta( $product_id, 'lsg_giveaway_winner', $winner_pick );
                update_post_meta( $product_id, 'lsg_giveaway_status', 'ended' );
                delete_transient( $lock_key );
                $giveaway_winner = $winner_pick;
                $giveaway_status = 'ended';
                wp_schedule_single_event( time(), 'lsg_async_winner_broadcast', [ $product_id ] );
                spawn_cron();
            }
        } else {
            $giveaway_winner = (string) get_post_meta( $product_id, 'lsg_giveaway_winner', true );
            if ( $giveaway_winner || get_post_meta( $product_id, 'lsg_giveaway_status', true ) === 'ended' ) {
                $giveaway_status = 'ended';
            }
        }
    }

    return [
        'id'               => $product_id,
        'name'             => $product->get_name(),
        'sku'              => $product->get_sku(),
        'price'            => $product->get_price(),
        'total_stock'      => (int) $product->get_stock_quantity(),
        'available_stock'  => $available_stock,
        'claimed_users'    => $claimed_users,
        'waitlist'         => $waitlist,
        'pinned'           => $pinned,
        'version'          => $version,
        'image_url'        => $image_url,
        'is_logged_in'     => is_user_logged_in(),
        'login_url'        => wp_login_url( get_permalink() ),
        'product_url'      => get_permalink( $product_id ),
        'is_claimed'       => in_array( $username, $claimed_users, true ),
        'on_waitlist'      => in_array( $username, $waitlist,      true ),
        'is_giveaway'           => $is_giveaway,
        'giveaway_duration'     => $giveaway_duration,
        'giveaway_end_time'     => $giveaway_end_time,
        'giveaway_entrants'     => $giveaway_entrants,
        'giveaway_winner'       => $giveaway_winner,
        'giveaway_status'       => $giveaway_status,
        'giveaway_claimed_only' => $giveaway_claimed_only,
        'giveaway_restart_count'=> (int) get_post_meta( $product_id, 'lsg_giveaway_restart_count', true ),
        'has_entered'           => in_array( $username, $giveaway_entrants, true ),
        'is_giveaway_winner'    => ( $is_giveaway && $giveaway_status === 'ended' && $giveaway_winner !== '' && $giveaway_winner === $username ),
        'current_username'      => $username,
        'giveaway_order_id'     => (int) get_post_meta( $product_id, 'lsg_giveaway_order_id', true ),
        'is_auction'            => $is_auction,
        'auction_base_price'    => $auction_base_price,
        'auction_duration'      => $auction_duration,
        'auction_status'        => $auction_status,
        'auction_end_time'      => $auction_end_time,
        'auction_current_bid'   => $auction_current_bid,
        'auction_current_bidder'=> $auction_current_bidder,
        'auction_bids'          => $auction_bids,
        'is_auction_winner'     => ( $is_auction && $auction_status === 'ended' && $auction_current_bidder !== '' && $auction_current_bidder === $username ),
    ];
}

// ============================================================
// Front-end card rendering
// ============================================================

/**
 * Render a single front-end product card (regular, giveaway, or auction).
 *
 * @param array $d Data from lsg_get_product_data()
 * @return string HTML
 */
function lsg_render_product_card( array $d ) : string {
    $pinned_badge = $d['pinned']
        ? '<span class="lsg-pinned-badge">📌 Pinned</span>'
        : '';

    // ---- Auction card ----
    if ( ! empty( $d['is_auction'] ) ) {
        $status      = $d['auction_status'];
        $base        = (float) $d['auction_base_price'];
        $current_bid = (float) $d['auction_current_bid'];
        $bidder      = esc_html( $d['auction_current_bidder'] );
        $display_bid = $current_bid > 0 ? $current_bid : $base;
        $bid_label   = $current_bid > 0 ? 'Current Bid' : 'Starting Bid';
        $img_html    = '<div class="lsg-card-img lsg-zoomable"><img src="' . esc_url( $d['image_url'] ) . '" alt="' . esc_attr( $d['name'] ) . '"><div class="lsg-zoom-overlay">🔍</div></div>';

        if ( $status === 'ended' ) {
            $is_winner    = ! empty( $d['is_auction_winner'] );
            $winner_banner = $is_winner
                ? '<div class="lsg-giveaway-winner-banner lsg-winner-you">🏆 You won this auction!</div>'
                : '<div class="lsg-auction-winner-banner">🔨 Winner: <strong>' . $bidder . '</strong> — ' . wc_price( $current_bid ) . '</div>';
            $btn        = '<button class="lsg-btn lsg-btn-waitlist-joined" disabled>Auction Ended</button>';
            $timer_html = $winner_banner;
        } elseif ( $status === 'running' ) {
            $timer_html = '<div class="lsg-auction-timer" data-end="' . esc_attr( $d['auction_end_time'] ) . '" data-id="' . esc_attr( $d['id'] ) . '">'
                . '<span class="lsg-timer-label">⏱ Ends in</span> <span class="lsg-timer-count"></span>'
                . '</div>';
            if ( empty( $d['is_logged_in'] ) ) {
                $btn = '<a href="' . esc_url( $d['login_url'] ) . '" class="lsg-btn lsg-btn-login">🔒 Login to Bid</a>';
            } else {
                $bid_count     = count( $d['auction_bids'] );
                $bid_label_btn = '🔨 Bid Now ' . strip_tags( wc_price( $display_bid ) ) . ' · ' . $bid_count . ' ' . _n( 'bid', 'bids', $bid_count, 'livesale' );
                $btn = '<div class="lsg-auction-bid-wrap">'
                    . '<input type="number" class="lsg-bid-input" data-id="' . esc_attr( $d['id'] ) . '" placeholder="Your bid" step="0.01" min="' . esc_attr( $display_bid + 0.01 ) . '">'
                    . '<button class="lsg-btn lsg-btn-auction place-bid" data-id="' . esc_attr( $d['id'] ) . '">' . esc_html( $bid_label_btn ) . '</button>'
                    . '</div>';
            }
        } else {
            $timer_html = '<div class="lsg-auction-timer lsg-timer-idle">⏳ Auction Starting Soon</div>';
            $btn        = '<button class="lsg-btn lsg-btn-waitlist-joined" disabled>⏳ Starting Soon</button>';
        }

        return '<div id="product-' . esc_attr( $d['id'] ) . '" class="live-sale-card lsg-auction-card' . ( $d['pinned'] ? ' is-pinned' : '' ) . '" data-version="' . esc_attr( $d['version'] ) . '">'
            . $pinned_badge
            . '<span class="lsg-auction-badge">🔨 Auction</span>'
            . $img_html
            . '<div class="lsg-card-body">'
            . '<h3 class="lsg-card-title"><span class="lsg-card-title-text">' . esc_html( $d['name'] ) . '</span></h3>'
            . '<div class="lsg-card-sku">SKU: ' . esc_html( $d['sku'] ) . '</div>'
            . '<div class="lsg-auction-bid-display"><span class="lsg-bid-label">' . esc_html( $bid_label ) . ':</span> <strong class="lsg-current-bid-val">' . wc_price( $display_bid ) . '</strong>'
            . ( $bidder ? ' <span class="lsg-bidder-name">by ' . $bidder . '</span>' : '' ) . '</div>'
            . $timer_html
            . '<div class="lsg-card-actions">' . $btn . '</div>'
            . '</div>'
            . '</div>';
    }

    // ---- Giveaway card ----
    if ( ! empty( $d['is_giveaway'] ) ) {
        $status            = $d['giveaway_status'];
        $claimed_only_badge = ! empty( $d['giveaway_claimed_only'] )
            ? '<span class="lsg-claimed-only-badge">🔒 Claimants Only</span>'
            : '';
        $restart_badge     = '';
        if ( ! empty( $d['giveaway_restart_count'] ) && $status === 'running' ) {
            $restart_badge = '<span class="lsg-restart-badge" title="Timer extended ' . $d['giveaway_restart_count'] . ' time(s) due to no entries">🔄 Extended ×' . $d['giveaway_restart_count'] . '</span>';
        }

        if ( $status === 'ended' ) {
            $winner    = esc_html( $d['giveaway_winner'] ?: 'No entrants' );
            $is_winner = ! empty( $d['is_giveaway_winner'] );
            $order_id  = ! empty( $d['giveaway_order_id'] ) ? (int) $d['giveaway_order_id'] : 0;

            if ( $is_winner ) {
                if ( $order_id ) {
                    $order_url = esc_url( wc_get_account_endpoint_url( 'orders' ) );
                    $btn = '<a href="' . $order_url . '" class="lsg-btn lsg-btn-winner-cta">🎁 View Your Order #' . $order_id . '</a>';
                } else {
                    $btn = '<button class="lsg-btn lsg-btn-claimed" disabled>🎉 You Won! Check your email.</button>';
                }
                $timer_html = '<div class="lsg-giveaway-winner-banner lsg-winner-you">🏆 <strong>You won this giveaway!</strong> A confirmation email has been sent to you.</div>';
            } else {
                $btn        = '<button class="lsg-btn lsg-btn-waitlist-joined" disabled>Giveaway Ended</button>';
                $timer_html = '<div class="lsg-giveaway-winner-banner">🏆 Winner: <strong>' . $winner . '</strong></div>';
            }
        } elseif ( $status === 'running' ) {
            if ( empty( $d['is_logged_in'] ) ) {
                $btn = '<a href="' . esc_url( $d['login_url'] ) . '" class="lsg-btn lsg-btn-login">🔒 Login to Enter</a>';
            } elseif ( $d['has_entered'] ) {
                $btn = '<button class="lsg-btn lsg-btn-claimed" disabled>✓ Entered</button>';
            } else {
                $btn = '<button class="lsg-btn lsg-btn-giveaway enter-giveaway" data-id="' . esc_attr( $d['id'] ) . '">🎟 Enter Giveaway</button>';
            }
            $timer_html = '<div class="lsg-giveaway-timer" data-end="' . esc_attr( $d['giveaway_end_time'] ) . '" data-id="' . esc_attr( $d['id'] ) . '">'
                . '<span class="lsg-timer-label">⏱ Ends in</span> <span class="lsg-timer-count"></span>'
                . '</div>';
        } else {
            $btn        = '<button class="lsg-btn lsg-btn-waitlist-joined" disabled>⏳ Starting Soon</button>';
            $timer_html = '<div class="lsg-giveaway-timer lsg-timer-idle">⏳ Giveaway Starting Soon</div>';
        }

        $img_html      = '<div class="lsg-card-img lsg-zoomable"><img src="' . esc_url( $d['image_url'] ) . '" alt="' . esc_attr( $d['name'] ) . '"><div class="lsg-zoom-overlay">🔍</div></div>';
        $entrants_count = count( $d['giveaway_entrants'] );

        return '<div id="product-' . esc_attr( $d['id'] ) . '" class="live-sale-card lsg-giveaway-card' . ( $d['pinned'] ? ' is-pinned' : '' ) . '" data-version="' . esc_attr( $d['version'] ) . '">'
            . $pinned_badge
            . '<span class="lsg-giveaway-badge">🎁 Giveaway</span>'
            . $claimed_only_badge
            . $restart_badge
            . $img_html
            . '<div class="lsg-card-body">'
            . '<h3 class="lsg-card-title"><span class="lsg-card-title-text">' . esc_html( $d['name'] ) . '</span></h3>'
            . '<div class="lsg-card-sku">SKU: ' . esc_html( $d['sku'] ) . '</div>'
            . '<div class="lsg-card-price"><span class="lsg-price-text">' . wc_price( $d['price'] ) . '</span></div>'
            . '<div class="lsg-giveaway-entrants">👥 ' . esc_html( $entrants_count ) . ' entered</div>'
            . $timer_html
            . '<div class="lsg-card-actions">' . $btn . '</div>'
            . '</div>'
            . '</div>';
    }

    // ---- Regular claim card ----
    if ( empty( $d['is_logged_in'] ) ) {
        $btn = '<a href="' . esc_url( $d['login_url'] ) . '" class="lsg-btn lsg-btn-login">🔒 Login to Claim</a>';
    } elseif ( $d['is_claimed'] ) {
        $btn = '<button class="lsg-btn lsg-btn-claimed" disabled>✓ Claimed</button>';
    } elseif ( $d['available_stock'] > 0 ) {
        $btn = '<button class="lsg-btn lsg-btn-claim claim-now" data-id="' . esc_attr( $d['id'] ) . '">Claim Now</button>';
    } elseif ( $d['on_waitlist'] ) {
        $btn = '<button class="lsg-btn lsg-btn-waitlist-joined" disabled>✓ On Waitlist</button>';
    } else {
        $btn = '<button class="lsg-btn lsg-btn-waitlist join-waitlist" data-id="' . esc_attr( $d['id'] ) . '">Join Waitlist</button>';
    }

    $img_html = '<div class="lsg-card-img lsg-zoomable"><img src="' . esc_url( $d['image_url'] ) . '" alt="' . esc_attr( $d['name'] ) . '"><div class="lsg-zoom-overlay">🔍</div></div>';

    return '<div id="product-' . esc_attr( $d['id'] ) . '" class="live-sale-card' . ( $d['pinned'] ? ' is-pinned' : '' ) . '" data-version="' . esc_attr( $d['version'] ) . '">'
        . $pinned_badge
        . $img_html
        . '<div class="lsg-card-body">'
        . '<h3 class="lsg-card-title"><span class="lsg-card-title-text">' . esc_html( $d['name'] ) . '</span></h3>'
        . '<div class="lsg-card-sku">SKU: ' . esc_html( $d['sku'] ) . '</div>'
        . '<div class="lsg-card-price"><span class="lsg-price-text">' . wc_price( $d['price'] ) . '</span></div>'
        . '<div class="lsg-card-stock">Available: <span class="stock-count">' . esc_html( $d['available_stock'] ) . '</span></div>'
        . '<div class="lsg-card-actions">' . $btn . '</div>'
        . '</div>'
        . '</div>';
}

// ============================================================
// Admin row / list rendering
// ============================================================

/**
 * Render a single admin table row for one product.
 */
function lsg_render_admin_row( int $pid ) : string {
    $product = wc_get_product( $pid );
    if ( ! $product ) return '';

    $total_stock     = (int) $product->get_stock_quantity();
    $available_stock = (int) get_post_meta( $pid, 'available_stock',  true );
    $claimed_users   = get_post_meta( $pid, 'claimed_users',   true ) ?: [];
    $waitlist        = get_post_meta( $pid, 'lsg_waitlist',    true ) ?: [];
    $pinned          = (bool) get_post_meta( $pid, 'lsg_pinned', true );
    $thumb_id        = get_post_thumbnail_id( $pid );
    $thumb_html      = '';
    if ( $thumb_id ) {
        $img = wp_get_attachment_image_src( $thumb_id, 'thumbnail' );
        if ( $img ) {
            $thumb_html = '<img src="' . esc_url( $img[0] ) . '" style="width:40px;height:40px;object-fit:cover;border-radius:4px;margin-right:6px;vertical-align:middle;">';
        }
    }

    $claimed_display = implode( ', ', $claimed_users );
    $waitlist_count  = count( $waitlist );
    $nonce_del       = wp_create_nonce( 'lsg_delete_product_' . $pid );
    $nonce_pin       = wp_create_nonce( 'lsg_pin_product_' . $pid );

    $is_giveaway      = (bool) get_post_meta( $pid, 'lsg_is_giveaway',       true );
    $giveaway_status  = (string) get_post_meta( $pid, 'lsg_giveaway_status', true ) ?: 'idle';
    $giveaway_end     = (int) get_post_meta( $pid, 'lsg_giveaway_end_time',  true );
    $giveaway_dur     = (int) get_post_meta( $pid, 'lsg_giveaway_duration',  true );
    $giveaway_entrants= get_post_meta( $pid, 'lsg_giveaway_entrants', true ) ?: [];
    $giveaway_winner  = (string) get_post_meta( $pid, 'lsg_giveaway_winner', true );

    $giveaway_html = '';
    if ( $is_giveaway ) {
        if ( $giveaway_status === 'idle' ) {
            $giveaway_html = '<div style="margin-top:6px;">'
                . '<button class="button button-primary button-small lsg-start-giveaway-btn" data-id="' . $pid . '" data-duration="' . esc_attr( $giveaway_dur ) . '">▶ Start Giveaway (' . esc_html( $giveaway_dur ) . 'min)</button>'
                . '</div>';
        } elseif ( $giveaway_status === 'running' ) {
            $seconds_left  = max( 0, $giveaway_end - time() );
            $giveaway_html = '<div style="margin-top:6px;">'
                . '<span class="lsg-admin-timer" data-end="' . esc_attr( $giveaway_end ) . '" style="font-weight:700;color:#8e44ad;font-size:13px;display:block;margin-bottom:4px;"></span>'
                . count( $giveaway_entrants ) . ' entered<br>'
                . '<button class="button button-small lsg-roll-winner-btn" data-id="' . $pid . '" style="margin-top:4px;' . ( $seconds_left > 0 ? 'opacity:.6;" title="Wait for timer to expire"' : '"' ) . '>🎲 Roll Winner</button>'
                . '</div>';
        } elseif ( $giveaway_status === 'ended' ) {
            $giveaway_html = '<div style="margin-top:6px;background:#fef9e7;border-radius:4px;padding:4px 8px;font-size:12px;">'
                . '🏆 <strong>Winner:</strong> ' . ( $giveaway_winner ? esc_html( $giveaway_winner ) : '<em>No entrants</em>' )
                . '<br><span style="color:#999;">' . count( $giveaway_entrants ) . ' entered</span>'
                . '</div>';
        }
    }

    $is_auction      = (bool) get_post_meta( $pid, 'lsg_is_auction', true );
    $auction_status  = (string) get_post_meta( $pid, 'lsg_auction_status', true ) ?: 'idle';
    $auction_end     = (int) get_post_meta( $pid, 'lsg_auction_end_time', true );
    $auction_dur     = (int) get_post_meta( $pid, 'lsg_auction_duration', true );
    $auction_base    = (float) get_post_meta( $pid, 'lsg_auction_base_price', true );
    $current_bid     = (float) get_post_meta( $pid, 'lsg_auction_current_bid', true );
    $current_bidder  = (string) get_post_meta( $pid, 'lsg_auction_current_bidder', true );

    $auction_html = '';
    if ( $is_auction ) {
        if ( $auction_status === 'idle' ) {
            $auction_html = '<div style="margin-top:6px;">'
                . '<button class="button button-primary button-small lsg-start-auction-btn" data-id="' . $pid . '" data-duration="' . esc_attr( $auction_dur ) . '">▶ Start Auction (' . esc_html( $auction_dur ) . 's)</button>'
                . '</div>';
        } elseif ( $auction_status === 'running' ) {
            $auction_html = '<div style="margin-top:6px;">'
                . '<span class="lsg-admin-auction-timer" data-end="' . esc_attr( $auction_end ) . '" style="font-weight:700;color:#e67e22;font-size:13px;display:block;margin-bottom:4px;"></span>'
                . 'Current bid: <strong>' . wc_price( $current_bid ?: $auction_base ) . '</strong>'
                . ( $current_bidder ? ' by <em>' . esc_html( $current_bidder ) . '</em>' : ' (base)' )
                . '<br><button class="button button-small lsg-end-auction-btn" data-id="' . $pid . '" style="margin-top:4px;">🔨 End Auction</button>'
                . '</div>';
        } elseif ( $auction_status === 'ended' ) {
            $auction_html = '<div style="margin-top:6px;background:#fef5e7;border-radius:4px;padding:4px 8px;font-size:12px;">'
                . '🔨 <strong>Winner:</strong> ' . ( $current_bidder ? esc_html( $current_bidder ) . ' — ' . wc_price( $current_bid ) : '<em>No bids</em>' )
                . '</div>';
        }
    }

    return '<tr id="product-row-' . $pid . '" class="' . ( $pinned ? 'lsg-row-pinned' : '' ) . ( $is_giveaway ? ' lsg-row-giveaway' : '' ) . ( $is_auction ? ' lsg-row-auction' : '' ) . '">'
        . '<td>' . $thumb_html
        . '<input type="text" class="name_admin" data-id="' . $pid . '" value="' . esc_attr( $product->get_name() ) . '" style="width:160px;font-weight:700;color:#00483e;">'
        . ( $is_giveaway ? ' <span style="background:#8e44ad;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;">🎁 Giveaway</span>' : '' )
        . ( $is_auction  ? ' <span style="background:#e67e22;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;">🔨 Auction</span>' : '' )
        . '<br><code>' . esc_html( $product->get_sku() ) . '</code>'
        . $giveaway_html
        . $auction_html
        . '</td>'
        . '<td><input type="number" step="0.01" class="price_admin" data-id="' . $pid . '" value="' . esc_attr( $product->get_price() ) . '" style="width:80px;"></td>'
        . '<td><input type="number" class="stock_admin" data-id="' . $pid . '" value="' . esc_attr( $total_stock ) . '" style="width:70px;"></td>'
        . '<td><input type="number" class="available_admin" data-id="' . $pid . '" value="' . esc_attr( $available_stock ) . '" style="width:70px;"></td>'
        . '<td><input type="text" class="claimed_admin" data-id="' . $pid . '" value="' . esc_attr( $claimed_display ) . '" style="width:180px;" placeholder="comma-separated"><br><small>' . count( $claimed_users ) . ' claims</small></td>'
        . '<td>' . ( $waitlist_count > 0 ? '<strong>' . $waitlist_count . '</strong> waiting' : '–' ) . '</td>'
        . '<td>'
            . '<button class="button button-small lsg-pin-btn" data-id="' . $pid . '" data-nonce="' . $nonce_pin . '" title="' . ( $pinned ? 'Unpin' : 'Pin to top' ) . '">'
            . ( $pinned ? '📌 Unpin' : '📌 Pin' ) . '</button> '
            . '<button class="button button-small lsg-delete-btn" data-id="' . $pid . '" data-nonce="' . $nonce_del . '" style="color:#a00;" title="Delete product">🗑 Delete</button>'
        . '</td>'
        . '</tr>';
}

/**
 * Render the full admin products table (50 per page).
 */
function lsg_render_admin_list( int $paged = 1 ) : void {
    $cat_id = lsg_get_live_sale_category();
    if ( ! $cat_id ) {
        echo '<p>No live-sale category found.</p>';
        return;
    }

    $q = new WP_Query( [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    if ( ! $q->have_posts() ) {
        echo '<p>No products yet.</p>';
        wp_reset_postdata();
        return;
    }

    $all_ids = [];
    while ( $q->have_posts() ) { $q->the_post(); $all_ids[] = get_the_ID(); }
    wp_reset_postdata();

    usort( $all_ids, function( $a, $b ) {
        return (int) get_post_meta( $b, 'lsg_pinned', true ) - (int) get_post_meta( $a, 'lsg_pinned', true );
    } );

    $per_page    = 50;
    $total       = count( $all_ids );
    $total_pages = max( 1, ceil( $total / $per_page ) );
    $paged       = max( 1, min( $paged, $total_pages ) );
    $page_ids    = array_slice( $all_ids, ( $paged - 1 ) * $per_page, $per_page );

    echo '<table class="lsg-products-table widefat striped">'
        . '<thead><tr><th>Product</th><th>Price</th><th>Total Stock</th><th>Available</th><th>Claimed Users</th><th>Waitlist</th><th>Actions</th></tr></thead><tbody>';

    foreach ( $page_ids as $pid ) {
        echo lsg_render_admin_row( $pid );
    }
    echo '</tbody></table>';

    if ( $total_pages > 1 ) {
        echo '<div style="margin-top:15px;text-align:center;">';
        for ( $i = 1; $i <= $total_pages; $i++ ) {
            $cls = ( $i === $paged ) ? 'button-primary current' : 'button-secondary';
            echo '<button class="button ' . $cls . ' admin-pagination-link" data-page="' . $i . '" style="margin:0 2px;">' . $i . '</button>';
        }
        echo '</div>';
    }
}

// ============================================================
// Giveaway logic
// ============================================================

/**
 * Roll a giveaway winner (shared between admin manual roll and public auto-roll).
 *
 * @return array  Keys: 'winner' (string) or 'error' (string) or 'already_done' (bool)
 */
function lsg_do_roll_winner( int $pid ) : array {
    $status = get_post_meta( $pid, 'lsg_giveaway_status', true );
    if ( $status === 'ended' ) {
        return [ 'already_done' => true, 'winner' => get_post_meta( $pid, 'lsg_giveaway_winner', true ) ];
    }
    $end_time = (int) get_post_meta( $pid, 'lsg_giveaway_end_time', true );
    if ( $end_time > time() ) {
        return [ 'error' => 'Timer has not expired yet.' ];
    }

    $lock_key = 'lsg_roll_lock_' . $pid;
    if ( get_transient( $lock_key ) ) {
        sleep( 1 );
        return [ 'already_done' => true, 'winner' => get_post_meta( $pid, 'lsg_giveaway_winner', true ) ];
    }
    set_transient( $lock_key, 1, 10 );

    $entrants = get_post_meta( $pid, 'lsg_giveaway_entrants', true ) ?: [];
    $winner   = '';
    if ( ! empty( $entrants ) ) {
        $winner    = $entrants[ array_rand( $entrants ) ];
        $claimed   = get_post_meta( $pid, 'claimed_users', true ) ?: [];
        $claimed[] = $winner;
        update_post_meta( $pid, 'claimed_users', array_unique( $claimed ) );
        lsg_sync_short_description( $pid );
    }

    update_post_meta( $pid, 'lsg_giveaway_winner', $winner );
    update_post_meta( $pid, 'lsg_giveaway_status', 'ended' );
    delete_transient( $lock_key );

    if ( $winner ) {
        lsg_process_giveaway_win( $pid, $winner );
    }

    $product = wc_get_product( $pid );
    lsg_socketio_publish( LSG_SOCKETIO_PRODUCT_CHANNEL, 'giveaway-winner', [
        'product_id' => $pid,
        'name'       => $product ? $product->get_name() : '',
        'winner'     => $winner ?: 'No entrants',
    ] );

    return [ 'winner' => $winner ?: 'No entrants' ];
}

/**
 * Post-win handler: runs exactly once per giveaway.
 * Decrements available stock, creates a £0 WooCommerce order, sends congrats email.
 * Guarded by 'lsg_win_processed' meta to prevent double execution.
 */
function lsg_process_giveaway_win( int $pid, string $winner_username ) : void {
    if ( ! $winner_username ) return;
    if ( get_post_meta( $pid, 'lsg_win_processed', true ) ) return;
    update_post_meta( $pid, 'lsg_win_processed', 1 );

    $product = wc_get_product( $pid );
    if ( ! $product ) return;

    $available = (int) get_post_meta( $pid, 'available_stock', true );
    if ( $available > 0 ) {
        update_post_meta( $pid, 'available_stock', $available - 1 );
    }

    $winner_user = get_user_by( 'login', $winner_username );
    if ( ! $winner_user ) {
        $candidates = get_users( [ 'search' => $winner_username, 'search_columns' => [ 'display_name' ], 'number' => 10 ] );
        foreach ( $candidates as $u ) {
            if ( $u->display_name === $winner_username ) { $winner_user = $u; break; }
        }
    }
    if ( ! $winner_user ) {
        error_log( '[LiveSale] Cannot resolve WP user for giveaway winner: ' . $winner_username );
        return;
    }

    $order_id = null;
    try {
        $order = wc_create_order( [ 'customer_id' => $winner_user->ID ] );
        $item  = new WC_Order_Item_Product();
        $item->set_product( $product );
        $item->set_quantity( 1 );
        $item->set_name( $product->get_name() );
        $item->set_subtotal( 0 );
        $item->set_total( 0 );
        $order->add_item( $item );
        $order->set_billing_first_name( $winner_user->first_name );
        $order->set_billing_last_name( $winner_user->last_name );
        $order->set_billing_email( $winner_user->user_email );
        $order->set_payment_method( '' );
        $order->set_payment_method_title( 'Giveaway Win' );
        $order->set_created_via( 'live_sale_giveaway' );
        $order->set_total( 0 );
        $order->set_status( 'processing', 'Auto-created: winner of Live Sale giveaway for ' . $product->get_name() . '.' );
        $order->save();
        $order_id = $order->get_id();
        update_post_meta( $pid, 'lsg_giveaway_order_id', $order_id );
        $order->add_order_note(
            '🎉 Congratulations! This order was automatically created because you won the Live Sale giveaway for "'
            . $product->get_name() . '". Our team will be in touch to arrange delivery. No payment is required.',
            1
        );
    } catch ( \Exception $e ) {
        error_log( '[LiveSale] Giveaway order creation failed for product ' . $pid . ': ' . $e->getMessage() );
    }

    $site_name  = get_bloginfo( 'name' );
    $subject    = '🎉 You won the ' . $site_name . ' giveaway!';
    $order_line = $order_id
        ? 'We have automatically created order #' . $order_id . ' for you.' . "\n"
          . 'View it here: ' . wc_get_account_endpoint_url( 'orders' ) . "\n\n"
        : 'Our team will contact you shortly to arrange delivery.' . "\n\n";
    $message = 'Hi ' . $winner_user->display_name . ",\n\n"
        . 'Congratulations! You have won the Live Sale giveaway for:' . "\n\n"
        . '  * ' . $product->get_name() . ' (SKU: ' . $product->get_sku() . ')' . "\n\n"
        . $order_line
        . 'Thank you for participating!' . "\n\n"
        . 'Best regards,' . "\n" . $site_name;
    wp_mail( $winner_user->user_email, $subject, $message );
}

/**
 * Send the auction-win congratulations email.
 */
function lsg_send_auction_win_email( int $pid, string $winner_username, float $amount ) : void {
    $winner_user = get_user_by( 'login', $winner_username );
    if ( ! $winner_user ) {
        $candidates = get_users( [ 'search' => $winner_username, 'search_columns' => [ 'display_name' ], 'number' => 5 ] );
        foreach ( $candidates as $u ) {
            if ( $u->display_name === $winner_username ) { $winner_user = $u; break; }
        }
    }
    if ( ! $winner_user ) return;

    $product   = wc_get_product( $pid );
    $site_name = get_bloginfo( 'name' );
    $subject   = '🔨 You won the ' . $site_name . ' auction!';
    $message   = 'Hi ' . $winner_user->display_name . ",\n\n"
        . 'Congratulations! You won the auction for:' . "\n\n"
        . '  * ' . ( $product ? $product->get_name() : 'Product #' . $pid ) . "\n"
        . '  * Winning bid: ' . strip_tags( wc_price( $amount ) ) . "\n\n"
        . 'Our team will be in touch to arrange payment and delivery.' . "\n\n"
        . 'Thank you for participating!' . "\n\n"
        . 'Best regards,' . "\n" . $site_name;
    wp_mail( $winner_user->user_email, $subject, $message );
}
