<?php
/**
 * Plugin Name: Live Sale Combined – Ably Real‑Time
 * Description: Live product grid with claiming, waitlist, pinning, real-time updates via Ably, and chat.
 * Version: 4.0
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Prevent wptexturize from converting && to &#038;&#038; inside our shortcode JS
add_filter( 'no_texturize_shortcodes', function ( $shortcodes ) {
    $shortcodes[] = 'live_sale_grid';
    $shortcodes[] = 'live_sale_chat';
    return $shortcodes;
} );

// ============================================================
// Ably Configuration — key is set in wp-config.php
// Add this line to wp-config.php:
//   define( 'LSG_ABLY_API_KEY', 'your_ably_api_key_here' );
// ============================================================
if ( ! defined( 'ABLY_API_KEY' ) ) {
    define( 'ABLY_API_KEY', defined( 'LSG_ABLY_API_KEY' ) ? LSG_ABLY_API_KEY : '' );
}
define( 'ABLY_CHAT_CHANNEL',     'live-sale-chat' );
define( 'ABLY_PRODUCT_CHANNEL',  'live-sale-products' );

// ============================================================
// Load Ably PHP library
// ============================================================
$_lsg_ably_paths = [
    ABSPATH . 'vendor/autoload.php',
    WP_PLUGIN_DIR . '/ably-php/vendor/autoload.php',   // Composer install inside ably-php/
    WP_PLUGIN_DIR . '/ably-php/autoload.php',
    WP_PLUGIN_DIR . '/ably-php/ably-loader.php',
    WP_CONTENT_DIR . '/ably-php/autoload.php',
    dirname( __FILE__ ) . '/ably-php/vendor/autoload.php',
    dirname( __FILE__ ) . '/ably-php/autoload.php',
    dirname( __FILE__ ) . '/ably-php/ably-loader.php',
];
$_lsg_ably_loaded = false;
foreach ( $_lsg_ably_paths as $_p ) {
    if ( file_exists( $_p ) ) {
        require_once $_p;
        $_lsg_ably_loaded = true;
        break;
    }
}
if ( ! $_lsg_ably_loaded || ! class_exists( 'Ably\AblyRest' ) ) {
    define( 'ABLY_DISABLED', true );
}

// ============================================================
// 0. Helpers
// ============================================================

function lsg_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="error"><p><strong>Live Sale</strong> requires WooCommerce.</p></div>';
        } );
        return false;
    }
    return true;
}

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

function lsg_increment_global_version() {
    $v = (int) get_option( 'lsg_global_version', 0 );
    update_option( 'lsg_global_version', $v + 1 );
}

/**
 * Publish a message to an Ably channel (fire-and-forget).
 * The Ably\AblyRest class is loaded at runtime from the ably-php library;
 * it is not available at static-analysis time, so we instantiate via a
 * variable class name to avoid IDE "Undefined type" warnings.
 */
function lsg_ably_publish( string $channel_name, string $event, array $data ) {
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

// Async cron: broadcast giveaway winner to Ably + run post-win processing.
add_action( 'lsg_async_winner_broadcast', function ( int $pid ) {
    $product = wc_get_product( $pid );
    $winner  = (string) get_post_meta( $pid, 'lsg_giveaway_winner', true );
    lsg_ably_publish( ABLY_PRODUCT_CHANNEL, 'giveaway-winner', [
        'product_id' => $pid,
        'name'       => $product ? $product->get_name() : '',
        'winner'     => $winner ?: 'No entrants',
    ] );
    // Also handle stock, order creation, and email for the auto-advance path
    if ( $winner ) {
        lsg_process_giveaway_win( $pid, $winner );
    }
} );

/**
 * Sync the WooCommerce short description to reflect claimed users,
 * then return the new short description string.
 */
function lsg_sync_short_description( int $product_id ) : string {
    $claimed = get_post_meta( $product_id, 'claimed_users', true ) ?: [];
    $short_desc = implode( ', ', array_map( 'sanitize_text_field', $claimed ) );
    wp_update_post( [
        'ID'           => $product_id,
        'post_excerpt' => $short_desc,
    ] );
    return $short_desc;
}

/**
 * Return all product data needed for front-end and admin.
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
    $thumb_id = get_post_thumbnail_id( $product_id );
    if ( $thumb_id ) {
        $img = wp_get_attachment_image_src( $thumb_id, 'woocommerce_thumbnail' );
        if ( $img ) $image_url = $img[0];
    }
    if ( ! $image_url ) {
        $image_url = wc_placeholder_img_src( 'woocommerce_thumbnail' );
    }

    $is_giveaway        = (bool) get_post_meta( $product_id, 'lsg_is_giveaway',       true );
    $giveaway_duration  = (int)  get_post_meta( $product_id, 'lsg_giveaway_duration', true );
    $giveaway_end_time  = (int)  get_post_meta( $product_id, 'lsg_giveaway_end_time', true );
    $giveaway_entrants  = get_post_meta( $product_id, 'lsg_giveaway_entrants', true ) ?: [];
    $giveaway_winner    = (string) get_post_meta( $product_id, 'lsg_giveaway_winner', true );
    $giveaway_status    = (string) get_post_meta( $product_id, 'lsg_giveaway_status', true ) ?: 'idle';

    // Server-side auto-advance: if timer expired but still marked running, roll now
    // without making any Ably network call (no blocking, no sleep).
    if ( $is_giveaway && $giveaway_status === 'running' && $giveaway_end_time > 0 && $giveaway_end_time < time() ) {
        $lock_key = 'lsg_roll_lock_' . $product_id;
        if ( ! get_transient( $lock_key ) ) {
            set_transient( $lock_key, 1, 30 );
            $winner_pick = ! empty( $giveaway_entrants ) ? $giveaway_entrants[ array_rand( $giveaway_entrants ) ] : '';
            if ( $winner_pick ) {
                $cl   = get_post_meta( $product_id, 'claimed_users', true ) ?: [];
                $cl[] = $winner_pick;
                update_post_meta( $product_id, 'claimed_users', array_unique( $cl ) );
                lsg_sync_short_description( $product_id );
            }
            update_post_meta( $product_id, 'lsg_giveaway_winner', $winner_pick );
            update_post_meta( $product_id, 'lsg_giveaway_status', 'ended' );
            delete_transient( $lock_key );
            $giveaway_winner = $winner_pick;
            // Schedule async Ably broadcast so THIS request is not blocked
            wp_schedule_single_event( time(), 'lsg_async_winner_broadcast', [ $product_id ] );
            spawn_cron();
        } else {
            // Another process is rolling — read current DB state
            $giveaway_winner = (string) get_post_meta( $product_id, 'lsg_giveaway_winner', true );
        }
        $giveaway_status = 'ended';
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
        'is_claimed'       => in_array( $username, $claimed_users, true ),
        'on_waitlist'      => in_array( $username, $waitlist,      true ),
        // Giveaway fields
        'is_giveaway'      => $is_giveaway,
        'giveaway_duration'=> $giveaway_duration,
        'giveaway_end_time'=> $giveaway_end_time,
        'giveaway_entrants'=> $giveaway_entrants,
        'giveaway_winner'  => $giveaway_winner,
        'giveaway_status'  => $giveaway_status,
        'has_entered'      => in_array( $username, $giveaway_entrants, true ),
        'is_giveaway_winner' => ( $is_giveaway && $giveaway_status === 'ended' && $giveaway_winner !== '' && $giveaway_winner === $username ),
        'current_username' => $username,
        'giveaway_order_id' => (int) get_post_meta( $product_id, 'lsg_giveaway_order_id', true ),
    ];
}

/**
 * Render a single front-end product card.
 */
function lsg_render_product_card( array $d ) : string {
    $pinned_badge = $d['pinned']
        ? '<span class="lsg-pinned-badge">📌 Pinned</span>'
        : '';

    // ---- Giveaway card ----
    if ( ! empty( $d['is_giveaway'] ) ) {
        $status = $d['giveaway_status'];

        if ( $status === 'ended' ) {
            $winner    = esc_html( $d['giveaway_winner'] ?: 'No entrants' );
            $is_winner = ! empty( $d['is_giveaway_winner'] );
            $order_id  = ! empty( $d['giveaway_order_id'] ) ? (int) $d['giveaway_order_id'] : 0;

            if ( $is_winner ) {
                if ( $order_id ) {
                    $order_url = esc_url( wc_get_account_endpoint_url( 'orders' ) );
                    $btn = '<a href="' . $order_url . '" class="lsg-btn lsg-btn-winner-cta">🎁 View Your Order #' . $order_id . '</a>';
                } else {
                    // Order not yet created (edge case: race with cron). Show encouraging message.
                    $btn = '<button class="lsg-btn lsg-btn-claimed" disabled>🎉 You Won! Check your email.</button>';
                }
                $timer_html = '<div class="lsg-giveaway-winner-banner lsg-winner-you">'
                    . '🏆 <strong>You won this giveaway!</strong> A confirmation email has been sent to you.'
                    . '</div>';
            } else {
                $btn = '<button class="lsg-btn lsg-btn-waitlist-joined" disabled>Giveaway Ended</button>';
                $timer_html = '<div class="lsg-giveaway-winner-banner">🏆 Winner: <strong>' . $winner . '</strong></div>';
            }
        } elseif ( $status === 'running' ) {
            $seconds_left = max( 0, $d['giveaway_end_time'] - time() );
            $btn = $d['has_entered']
                ? '<button class="lsg-btn lsg-btn-claimed" disabled>✓ Entered</button>'
                : '<button class="lsg-btn lsg-btn-giveaway enter-giveaway" data-id="' . esc_attr( $d['id'] ) . '">🎟 Enter Giveaway</button>';
            $timer_html = '<div class="lsg-giveaway-timer" data-end="' . esc_attr( $d['giveaway_end_time'] ) . '" data-id="' . esc_attr( $d['id'] ) . '">'
                . '<span class="lsg-timer-label">⏱ Ends in</span> <span class="lsg-timer-count"></span>'
                . '</div>';
        } else {
            // idle — not started yet
            $btn = '<button class="lsg-btn lsg-btn-waitlist-joined" disabled>⏳ Starting Soon</button>';
            $timer_html = '<div class="lsg-giveaway-timer lsg-timer-idle">⏳ Giveaway Starting Soon</div>';
        }

        $img_html = '<div class="lsg-card-img"><img src="' . esc_url( $d['image_url'] ) . '" alt="' . esc_attr( $d['name'] ) . '"></div>';
        $entrants_count = count( $d['giveaway_entrants'] );

        return '<div id="product-' . esc_attr( $d['id'] ) . '" class="live-sale-card lsg-giveaway-card' . ( $d['pinned'] ? ' is-pinned' : '' ) . '" data-version="' . esc_attr( $d['version'] ) . '">'
            . $pinned_badge
            . '<span class="lsg-giveaway-badge">🎁 Giveaway</span>'
            . $img_html
            . '<div class="lsg-card-body">'
            . '<h3 class="lsg-card-title">' . esc_html( $d['name'] ) . '</h3>'
            . '<div class="lsg-card-sku">SKU: ' . esc_html( $d['sku'] ) . '</div>'
            . '<div class="lsg-card-price">' . wc_price( $d['price'] ) . '</div>'
            . '<div class="lsg-giveaway-entrants">👥 ' . esc_html( $entrants_count ) . ' entered</div>'
            . $timer_html
            . '<div class="lsg-card-actions">' . $btn . '</div>'
            . '</div>'
            . '</div>';
    }

    // ---- Regular claim card ----
    if ( $d['is_claimed'] ) {
        $btn = '<button class="lsg-btn lsg-btn-claimed" disabled>✓ Claimed</button>';
    } elseif ( $d['available_stock'] > 0 ) {
        $btn = '<button class="lsg-btn lsg-btn-claim claim-now" data-id="' . esc_attr( $d['id'] ) . '">Claim Now</button>';
    } elseif ( $d['on_waitlist'] ) {
        $btn = '<button class="lsg-btn lsg-btn-waitlist-joined" disabled>✓ On Waitlist</button>';
    } else {
        $btn = '<button class="lsg-btn lsg-btn-waitlist join-waitlist" data-id="' . esc_attr( $d['id'] ) . '">Join Waitlist</button>';
    }

    $img_html = '<div class="lsg-card-img"><img src="' . esc_url( $d['image_url'] ) . '" alt="' . esc_attr( $d['name'] ) . '"></div>';

    return '<div id="product-' . esc_attr( $d['id'] ) . '" class="live-sale-card' . ( $d['pinned'] ? ' is-pinned' : '' ) . '" data-version="' . esc_attr( $d['version'] ) . '">'
        . $pinned_badge
        . $img_html
        . '<div class="lsg-card-body">'
        . '<h3 class="lsg-card-title">' . esc_html( $d['name'] ) . '</h3>'
        . '<div class="lsg-card-sku">SKU: ' . esc_html( $d['sku'] ) . '</div>'
        . '<div class="lsg-card-price">' . wc_price( $d['price'] ) . '</div>'
        . '<div class="lsg-card-stock">Available: <span class="stock-count">' . esc_html( $d['available_stock'] ) . '</span></div>'
        . '<div class="lsg-card-actions">' . $btn . '</div>'
        . '</div>'
        . '</div>';
}

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

    // Giveaway meta
    $is_giveaway      = (bool) get_post_meta( $pid, 'lsg_is_giveaway',       true );
    $giveaway_status  = (string) get_post_meta( $pid, 'lsg_giveaway_status', true ) ?: 'idle';
    $giveaway_end     = (int) get_post_meta( $pid, 'lsg_giveaway_end_time',  true );
    $giveaway_dur     = (int) get_post_meta( $pid, 'lsg_giveaway_duration',  true );
    $giveaway_entrants= get_post_meta( $pid, 'lsg_giveaway_entrants', true ) ?: [];
    $giveaway_winner  = (string) get_post_meta( $pid, 'lsg_giveaway_winner', true );

    // Build giveaway action cell
    $giveaway_html = '';
    if ( $is_giveaway ) {
        if ( $giveaway_status === 'idle' ) {
            $giveaway_html = '<div style="margin-top:6px;">'
                . '<button class="button button-primary button-small lsg-start-giveaway-btn" data-id="' . $pid . '" data-duration="' . esc_attr( $giveaway_dur ) . '">▶ Start Giveaway (' . esc_html( $giveaway_dur ) . 'min)</button>'
                . '</div>';
        } elseif ( $giveaway_status === 'running' ) {
            $seconds_left = max( 0, $giveaway_end - time() );
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

    return '<tr id="product-row-' . $pid . '" class="' . ( $pinned ? 'lsg-row-pinned' : '' ) . ( $is_giveaway ? ' lsg-row-giveaway' : '' ) . '">'
        . '<td>' . $thumb_html . '<strong class="product-name">' . esc_html( $product->get_name() ) . '</strong>'
        . ( $is_giveaway ? ' <span style="background:#8e44ad;color:#fff;font-size:10px;padding:1px 5px;border-radius:3px;">🎁 Giveaway</span>' : '' )
        . '<br><code>' . esc_html( $product->get_sku() ) . '</code>'
        . $giveaway_html
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
 * Render the full admin products table (paginated).
 */
function lsg_render_admin_list( int $paged = 1 ) {
    $cat_id = lsg_get_live_sale_category();
    if ( ! $cat_id ) {
        echo '<p>No live-sale category found.</p>';
        return;
    }

    $per_page = 50;
    $args = [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    $q = new WP_Query( $args );

    if ( ! $q->have_posts() ) {
        echo '<p>No products yet.</p>';
        wp_reset_postdata();
        return;
    }

    // Collect all IDs, sort pinned first, then paginate in PHP
    $all_ids = [];
    while ( $q->have_posts() ) {
        $q->the_post();
        $all_ids[] = get_the_ID();
    }
    wp_reset_postdata();

    usort( $all_ids, function( $a, $b ) {
        return (int) get_post_meta( $b, 'lsg_pinned', true ) - (int) get_post_meta( $a, 'lsg_pinned', true );
    } );

    $total       = count( $all_ids );
    $total_pages = max( 1, ceil( $total / $per_page ) );
    $paged       = max( 1, min( $paged, $total_pages ) );
    $offset      = ( $paged - 1 ) * $per_page;
    $page_ids    = array_slice( $all_ids, $offset, $per_page );

    echo '<table class="lsg-products-table widefat striped">'
        . '<thead><tr>'
        . '<th>Product</th><th>Price</th><th>Total Stock</th><th>Available</th><th>Claimed Users</th><th>Waitlist</th><th>Actions</th>'
        . '</tr></thead><tbody>';

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
// 1. Admin Menu
// ============================================================
add_action( 'admin_menu', function () {
    if ( ! lsg_check_woocommerce() ) return;
    add_menu_page(
        'Live Sale Manager', 'Live Sale', 'manage_woocommerce',
        'live-sale-manager', 'lsg_admin_page',
        'dashicons-cart', 56
    );
} );

function lsg_admin_page() {
    $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'products';
    ?>
    <div class="wrap">
        <h1>Live Sale Manager</h1>
        <h2 class="nav-tab-wrapper">
            <a href="?page=live-sale-manager&tab=products"  class="nav-tab <?php echo $tab === 'products'  ? 'nav-tab-active' : ''; ?>">Products</a>
            <a href="?page=live-sale-manager&tab=claimed"  class="nav-tab <?php echo $tab === 'claimed'  ? 'nav-tab-active' : ''; ?>">Claimed Users</a>
            <a href="?page=live-sale-manager&tab=giveaway" class="nav-tab <?php echo $tab === 'giveaway' ? 'nav-tab-active' : ''; ?>">🎁 Giveaways</a>
            <a href="?page=live-sale-manager&tab=waitlist" class="nav-tab <?php echo $tab === 'waitlist' ? 'nav-tab-active' : ''; ?>">Waitlists</a>
            <a href="?page=live-sale-manager&tab=chat"     class="nav-tab <?php echo $tab === 'chat'     ? 'nav-tab-active' : ''; ?>">Chat</a>
        </h2>
        <?php
        if ( $tab === 'products' )       lsg_admin_products_tab();
        elseif ( $tab === 'claimed' )  lsg_admin_claimed_tab();
        elseif ( $tab === 'giveaway' ) lsg_admin_giveaway_tab();
        elseif ( $tab === 'waitlist' ) lsg_admin_waitlist_tab();
        else                          echo do_shortcode( '[live_sale_chat]' );
        ?>
    </div>
    <?php
}

// ---- Products Tab ----
function lsg_admin_products_tab() {
    // Handle product creation
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_POST['create_live_sale_nonce'] )
         && wp_verify_nonce( $_POST['create_live_sale_nonce'], 'create_live_sale_action' ) ) {
        if ( lsg_handle_create_product() ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Product created.</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>❌ Error – check SKU is unique and all fields are valid.</p></div>';
        }
    }
    ?>

    <?php lsg_render_admin_summary(); ?>

    <div class="lsg-two-columns" style="display:grid;grid-template-columns:1fr 2fr;gap:30px;margin-top:20px;">

        <!-- CREATE FORM -->
        <div class="lsg-create-form" style="background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.08);">
            <h2 style="margin-top:0;border-bottom:2px solid #f0f0f0;padding-bottom:10px;">➕ Create New Product</h2>
            <form method="post" style="display:flex;flex-direction:column;gap:15px;">
                <?php wp_nonce_field( 'create_live_sale_action', 'create_live_sale_nonce' ); ?>
                <label>Product Name *<br><input type="text" name="product_name" required class="regular-text"></label>
                <label>SKU *<br><input type="text" name="sku" required class="regular-text"></label>
                <label>Price *<br><input type="number" name="price" step="0.01" required></label>
                <label>Total Stock<br><input type="number" name="stock_qty" min="0" value="1"></label>
                <label>Available Stock<br><input type="number" name="available_stock" min="0" value="1"></label>
                <label>Product Image
                    <div style="margin-top:6px;">
                        <input type="button" id="lsg_upload_image_button" class="button" value="Select Image">
                        <input type="hidden" id="lsg_image_id" name="image_id" value="">
                        <div id="lsg_image_preview" style="margin-top:8px;"></div>
                    </div>
                </label>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_giveaway" id="lsg_is_giveaway" value="1" style="width:auto;">
                    <span>🎁 This is a Giveaway</span>
                </label>
                <div id="lsg_giveaway_duration_wrap" style="display:none;">
                    <label>Timer Duration (minutes) *<br><input type="number" name="giveaway_duration" min="1" value="5" style="width:100px;"></label>
                </div>
                <button type="submit" class="button button-primary">Create Product</button>
            </form>
        </div>

        <!-- PRODUCTS TABLE -->
        <div style="background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.08);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                <h2 style="margin:0;">📋 Live Sale Products</h2>
                <button id="lsg-refresh-btn" class="button">🔄 Refresh</button>
            </div>
            <div id="lsg-admin-list">
                <?php lsg_render_admin_list( 1 ); ?>
            </div>
        </div>
    </div>

    <style>
        .lsg-products-table th { background:#f8f9fa; padding:10px; font-size:13px; }
        .lsg-products-table td { padding:10px; vertical-align:middle; }
        .lsg-row-pinned { background:#fff9e6 !important; }
        .lsg-row-giveaway { background:#fdf2ff !important; }
        .product-name { color:#00483e; }
        .lsg-saving { font-size:12px; animation:lsg-pulse 1s infinite; }
        .lsg-saved  { font-size:12px; animation:lsg-fade 1s ease-out forwards; }
        @keyframes lsg-pulse { 0%,100%{opacity:1} 50%{opacity:.5} }
        @keyframes lsg-fade  { 0%{opacity:1} 100%{opacity:0;visibility:hidden} }
    </style>

    <script>
    jQuery(function($){
        var saveNonce = '<?php echo wp_create_nonce( 'live_sale_update' ); ?>';
        var timers = {};

        function showIndicator(input, ok) {
            input.siblings('.lsg-saving,.lsg-saved').remove();
            var el = $('<span class="' + (ok === null ? 'lsg-saving' : 'lsg-saved') + '">' + (ok === null ? '⏳' : (ok ? '✓' : '✗')) + '</span>');
            if (ok === false) el.css('color','red');
            input.after(el);
            if (ok !== null) setTimeout(function(){ el.remove(); }, 1200);
        }

        function sendUpdate(input, action, value) {
            var pid = input.data('id');
            clearTimeout(timers[pid + action]);
            showIndicator(input, null);
            timers[pid + action] = setTimeout(function(){
                $.post(ajaxurl, {
                    action: action, product_id: pid, value: value,
                    _ajax_nonce: saveNonce
                }, function(r){
                    showIndicator(input, r.success);
                    if (r.success && r.data && r.data.html) {
                        $('#product-row-' + pid).replaceWith(r.data.html);
                    }
                }).fail(function(){ showIndicator(input, false); });
            }, 500);
        }

        $(document).on('blur', '.price_admin',     function(){ sendUpdate($(this), 'lsg_update_price',     parseFloat($(this).val())); });
        $(document).on('blur', '.stock_admin',     function(){ sendUpdate($(this), 'lsg_update_total_stock',  parseInt($(this).val())); });
        $(document).on('blur', '.available_admin', function(){ sendUpdate($(this), 'lsg_update_available_stock', parseInt($(this).val())); });
        $(document).on('blur', '.claimed_admin',   function(){ sendUpdate($(this), 'lsg_update_claimed_users',  $(this).val()); });

        // Pin / Unpin
        $(document).on('click', '.lsg-pin-btn', function(){
            var btn = $(this);
            var pid = btn.data('id');
            var n   = btn.data('nonce');
            btn.prop('disabled', true);
            $.post(ajaxurl, { action: 'lsg_toggle_pin', product_id: pid, _ajax_nonce: n }, function(r){
                btn.prop('disabled', false);
                if (r.success && r.data && r.data.html) {
                    $('#product-row-' + pid).replaceWith(r.data.html);
                } else { alert('Failed to pin/unpin.'); }
            });
        });

        // Delete
        $(document).on('click', '.lsg-delete-btn', function(){
            if (!confirm('Delete this product permanently?')) return;
            var btn = $(this);
            if (btn.prop('disabled')) return;
            var pid = btn.data('id');
            var n   = btn.data('nonce');
            btn.prop('disabled', true).html('⏳ Deleting…').css({'opacity':'0.7','cursor':'not-allowed'});
            $.post(ajaxurl, { action: 'lsg_delete_product', product_id: pid, _ajax_nonce: n }, function(r){
                if (r.success) {
                    btn.html('✓ Deleted').css('color','#006400');
                    $('#product-row-' + pid).fadeOut(400, function(){ $(this).remove(); });
                } else {
                    alert('Could not delete product.');
                    btn.prop('disabled', false).html('🗑 Delete').css({'opacity':'1','cursor':'pointer'});
                }
            }).fail(function(){
                alert('Network error. Please try again.');
                btn.prop('disabled', false).html('🗑 Delete').css({'opacity':'1','cursor':'pointer'});
            });
        });

        // Refresh list
        $('#lsg-refresh-btn').on('click', function(){
            var btn = $(this).prop('disabled',true).text('…');
            $.get(ajaxurl, { action: 'lsg_refresh_admin_list', page: 1, _ajax_nonce: '<?php echo wp_create_nonce( "lsg_refresh_admin_list" ); ?>' }, function(html){
                $('#lsg-admin-list').html(html);
                btn.prop('disabled',false).text('🔄 Refresh');
            });
        });

        // Pagination
        $(document).on('click', '.admin-pagination-link', function(){
            var page = $(this).data('page');
            $.get(ajaxurl, { action: 'lsg_refresh_admin_list', page: page, _ajax_nonce: '<?php echo wp_create_nonce( "lsg_refresh_admin_list" ); ?>' }, function(html){
                $('#lsg-admin-list').html(html);
            });
        });

        // Toggle giveaway duration field
        $('#lsg_is_giveaway').on('change', function(){
            $('#lsg_giveaway_duration_wrap').toggle(this.checked);
        });

        // Admin: Start Giveaway
        $(document).on('click', '.lsg-start-giveaway-btn', function(){
            var btn = $(this);
            var pid = btn.data('id');
            var dur = parseInt(btn.data('duration'), 10);
            if (!confirm('Start ' + dur + '-minute giveaway for this product?')) return;
            btn.prop('disabled', true).text('Starting…');
            $.post(ajaxurl, {
                action: 'lsg_start_giveaway', product_id: pid, duration: dur, _ajax_nonce: saveNonce
            }, function(r){
                if (r.success && r.data.html) {
                    $('#product-row-' + pid).replaceWith(r.data.html);
                } else {
                    alert('Could not start giveaway.');
                    btn.prop('disabled', false).text('▶ Start Giveaway');
                }
            });
        });

        // Admin: Roll Winner
        $(document).on('click', '.lsg-roll-winner-btn', function(){
            var btn = $(this);
            var pid = btn.data('id');
            if (!confirm('Roll the winner now?')) return;
            btn.prop('disabled', true).text('Rolling…');
            $.post(ajaxurl, {
                action: 'lsg_roll_giveaway_winner', product_id: pid, _ajax_nonce: saveNonce
            }, function(r){
                if (r.success) {
                    alert('🏆 Winner: ' + r.data.winner);
                    if (r.data.html) $('#product-row-' + pid).replaceWith(r.data.html);
                } else {
                    alert(r.data || 'Could not roll winner.');
                    btn.prop('disabled', false).text('🎲 Roll Winner');
                }
            });
        });

        // Admin timer countdown (in admin row)
        function updateAdminTimers() {
            var now = Math.floor(Date.now() / 1000);
            $('.lsg-admin-timer[data-end]').each(function(){
                var el   = $(this);
                var end  = parseInt(el.data('end'), 10);
                var left = end - now;
                if (left <= 0) {
                    el.text('⏰ Expired — Roll Winner');
                } else {
                    var m = Math.floor(left/60), s = left % 60;
                    el.text('⏱ ' + (m<10?'0':'')+m + ':' + (s<10?'0':'')+s + ' remaining');
                }
            });
        }
        setInterval(updateAdminTimers, 1000);

        // Media uploader
        var mediaFrame;
        $('#lsg_upload_image_button').on('click', function(e){
            e.preventDefault();
            if (mediaFrame) { mediaFrame.open(); return; }
            mediaFrame = wp.media({ title: 'Select Product Image', button: { text: 'Use Image' }, multiple: false });
            mediaFrame.on('select', function(){
                var att = mediaFrame.state().get('selection').first().toJSON();
                $('#lsg_image_id').val(att.id);
                $('#lsg_image_preview').html('<img src="' + att.url + '" style="max-width:150px;max-height:150px;border-radius:6px;">');
            });
            mediaFrame.open();
        });
    });
    </script>
    <?php
}

// ---- Claimed Users Tab ----
function lsg_admin_claimed_tab() {
    $cat_id = lsg_get_live_sale_category();
    if ( ! $cat_id ) { echo '<p>No live-sale category.</p>'; return; }

    $products = get_posts( [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ] );

    echo '<h2 style="margin-top:20px;">Claimed Users</h2>';
    echo '<table class="widefat striped"><thead><tr>'
        . '<th>Product</th><th>SKU</th><th>Price</th><th>Available</th><th>Claims</th><th>Claimed By</th>'
        . '</tr></thead><tbody>';

    $found = false;
    foreach ( $products as $p ) {
        $claimed = get_post_meta( $p->ID, 'claimed_users', true ) ?: [];
        $product  = wc_get_product( $p->ID );
        if ( ! $product ) continue;
        $found = true;
        $available = (int) get_post_meta( $p->ID, 'available_stock', true );
        $count     = count( $claimed );
        $users_html = $count
            ? implode( ', ', array_map( 'esc_html', $claimed ) )
            : '<em style="color:#999;">None yet</em>';
        echo '<tr>'
            . '<td><strong>' . esc_html( $product->get_name() ) . '</strong></td>'
            . '<td><code>' . esc_html( $product->get_sku() ) . '</code></td>'
            . '<td>' . wc_price( $product->get_price() ) . '</td>'
            . '<td>' . esc_html( $available ) . '</td>'
            . '<td><strong>' . esc_html( $count ) . '</strong></td>'
            . '<td>' . $users_html . '</td>'
            . '</tr>';
    }

    if ( ! $found ) {
        echo '<tr><td colspan="6" style="text-align:center;color:#999;">No products found.</td></tr>';
    }

    echo '</tbody></table>';
}

// ---- Giveaway Tab ----
function lsg_admin_giveaway_tab() {
    $cat_id = lsg_get_live_sale_category();
    if ( ! $cat_id ) { echo '<p>No live-sale category.</p>'; return; }

    $products = get_posts( [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ],
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [ [ 'key' => 'lsg_is_giveaway', 'value' => '1' ] ],
    ] );

    echo '<h2 style="margin-top:20px;">🎁 Giveaway Products</h2>';

    if ( empty( $products ) ) {
        echo '<p style="color:#999;">No giveaway products yet. Create a product and check "This is a Giveaway".</p>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>'
        . '<th>Product</th><th>Status</th><th>Duration</th><th>Timer</th><th>Entrants</th><th>Winner</th><th>Actions</th>'
        . '</tr></thead><tbody>';

    $save_nonce = wp_create_nonce( 'live_sale_update' );

    foreach ( $products as $p ) {
        $product         = wc_get_product( $p->ID );
        if ( ! $product ) continue;
        $status          = (string) get_post_meta( $p->ID, 'lsg_giveaway_status',   true ) ?: 'idle';
        $duration        = (int)    get_post_meta( $p->ID, 'lsg_giveaway_duration', true );
        $end_time        = (int)    get_post_meta( $p->ID, 'lsg_giveaway_end_time', true );
        $entrants        = get_post_meta( $p->ID, 'lsg_giveaway_entrants', true ) ?: [];
        $winner          = (string) get_post_meta( $p->ID, 'lsg_giveaway_winner',   true );

        $status_badge = match ( $status ) {
            'running' => '<span style="background:#e8f4fd;color:#1a6696;padding:2px 8px;border-radius:10px;font-size:12px;">▶ Running</span>',
            'ended'   => '<span style="background:#eafaf1;color:#1e8449;padding:2px 8px;border-radius:10px;font-size:12px;">✅ Ended</span>',
            default   => '<span style="background:#f5f5f5;color:#777;padding:2px 8px;border-radius:10px;font-size:12px;">⏸ Idle</span>',
        };

        $timer_cell = '–';
        if ( $status === 'running' ) {
            $timer_cell = '<span class="lsg-admin-timer" data-end="' . esc_attr( $end_time ) . '" style="font-weight:700;color:#8e44ad;"></span>';
        } elseif ( $status === 'ended' ) {
            $timer_cell = '<span style="color:#999;">Finished</span>';
        }

        $actions = '';
        if ( $status === 'idle' ) {
            $actions = '<button class="button button-primary button-small lsg-start-giveaway-btn" data-id="' . $p->ID . '" data-duration="' . esc_attr( $duration ) . '" data-nonce="' . $save_nonce . '">▶ Start</button>';
        } elseif ( $status === 'running' ) {
            $actions = '<button class="button button-small lsg-roll-winner-btn" data-id="' . $p->ID . '" data-nonce="' . $save_nonce . '">🎲 Roll Winner</button>';
        }

        echo '<tr id="giveaway-row-' . $p->ID . '">'
            . '<td><strong>' . esc_html( $product->get_name() ) . '</strong><br><code>' . esc_html( $product->get_sku() ) . '</code></td>'
            . '<td>' . $status_badge . '</td>'
            . '<td>' . esc_html( $duration ) . ' min</td>'
            . '<td>' . $timer_cell . '</td>'
            . '<td>' . esc_html( count( $entrants ) ) . ( ! empty( $entrants ) ? '<br><small style="color:#999;">' . esc_html( implode( ', ', $entrants ) ) . '</small>' : '' ) . '</td>'
            . '<td>' . ( $winner ? '<strong>' . esc_html( $winner ) . '</strong>' : '–' ) . '</td>'
            . '<td>' . $actions . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';
    ?>
    <script>
    jQuery(function($){
        var saveNonce = '<?php echo wp_create_nonce( 'live_sale_update' ); ?>';
        function updateGATimers() {
            var now = Math.floor(Date.now()/1000);
            $('.lsg-admin-timer[data-end]').each(function(){
                var el=$(this), end=parseInt(el.data('end'),10), left=end-now;
                if(left<=0){el.text('⏰ Expired');}
                else{var m=Math.floor(left/60),s=left%60;el.text((m<10?'0':'')+m+':'+(s<10?'0':'')+s+' left');}
            });
        }
        setInterval(updateGATimers,1000);
    });
    </script>
    <?php
}

// ---- Waitlist Tab ----
function lsg_admin_waitlist_tab() {
    $cat_id = lsg_get_live_sale_category();
    if ( ! $cat_id ) { echo '<p>No live-sale category.</p>'; return; }

    $products = get_posts( [
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ],
    ] );

    echo '<h2>Waitlists</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Product</th><th>SKU</th><th>Available Stock</th><th>Waitlist Users</th></tr></thead><tbody>';

    $found = false;
    foreach ( $products as $p ) {
        $waitlist = get_post_meta( $p->ID, 'lsg_waitlist', true ) ?: [];
        if ( empty( $waitlist ) ) continue;
        $found = true;
        $product = wc_get_product( $p->ID );
        $available = (int) get_post_meta( $p->ID, 'available_stock', true );
        echo '<tr>'
            . '<td><strong>' . esc_html( $product->get_name() ) . '</strong></td>'
            . '<td><code>' . esc_html( $product->get_sku() ) . '</code></td>'
            . '<td>' . esc_html( $available ) . '</td>'
            . '<td>' . esc_html( implode( ', ', $waitlist ) ) . '</td>'
            . '</tr>';
    }

    if ( ! $found ) {
        echo '<tr><td colspan="4" style="text-align:center;color:#999;">No waitlists yet.</td></tr>';
    }

    echo '</tbody></table>';
}

// ---- Summary dashboard ----
function lsg_render_admin_summary() {
    $cat_id = lsg_get_live_sale_category();
    $total_products = 0; $total_stock = 0; $available_stock_sum = 0; $total_claims = 0; $total_waitlisted = 0;

    if ( $cat_id ) {
        $ids = get_posts( [ 'post_type' => 'product', 'posts_per_page' => -1,
            'tax_query' => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ], 'fields' => 'ids' ] );
        $total_products = count( $ids );
        foreach ( $ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) continue;
            $total_stock        += max( 0, (int) $product->get_stock_quantity() );
            $available_stock_sum += max( 0, (int) get_post_meta( $pid, 'available_stock', true ) );
            $total_claims       += count( get_post_meta( $pid, 'claimed_users',  true ) ?: [] );
            $total_waitlisted   += count( get_post_meta( $pid, 'lsg_waitlist',   true ) ?: [] );
        }
    }
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin:20px 0;">
        <?php foreach ( [
            [ '📦', $total_products,       'Products' ],
            [ '📊', $total_stock,           'Total Stock' ],
            [ '✅', $available_stock_sum,   'Available' ],
            [ '👥', $total_claims,          'Claims' ],
            [ '⏳', $total_waitlisted,      'Waitlisted' ],
        ] as $card ) : ?>
        <div style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 6px rgba(0,0,0,.07);display:flex;align-items:center;gap:12px;">
            <span style="font-size:28px;"><?php echo $card[0]; ?></span>
            <div><div style="font-size:22px;font-weight:700;color:#00483e;"><?php echo esc_html( $card[1] ); ?></div><div style="color:#777;font-size:12px;"><?php echo esc_html( $card[2] ); ?></div></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}

// ============================================================
// 2. Handle Product Creation
// ============================================================
function lsg_handle_create_product() : bool {
    if ( ! class_exists( 'WooCommerce' ) ) return false;
    if ( ! isset( $_POST['product_name'], $_POST['sku'], $_POST['price'] ) ) return false;

    $name        = sanitize_text_field( $_POST['product_name'] );
    $sku         = sanitize_text_field( $_POST['sku'] );
    $price       = floatval( $_POST['price'] );
    $total_stock = max( 0, (int) ( $_POST['stock_qty'] ?? 1 ) );
    $avail_stock = max( 0, (int) ( $_POST['available_stock'] ?? $total_stock ) );
    $image_id    = ! empty( $_POST['image_id'] ) ? absint( $_POST['image_id'] ) : 0;

    if ( ! $name || ! $sku || $price <= 0 ) return false;
    if ( wc_get_product_id_by_sku( $sku ) ) return false;

    $cat_id = lsg_get_live_sale_category();
    if ( ! $cat_id ) return false;

    try {
        $product = new WC_Product_Simple();
        $product->set_name( $name );
        $product->set_sku( $sku );
        $product->set_regular_price( $price );
        $product->set_price( $price );
        $product->set_manage_stock( true );
        $product->set_stock_quantity( $total_stock );
        $product->set_stock_status( $total_stock > 0 ? 'instock' : 'outofstock' );
        $pid = $product->save();
        if ( ! $pid ) return false;

        update_post_meta( $pid, 'available_stock', $avail_stock );
        update_post_meta( $pid, 'claimed_users',   [] );
        update_post_meta( $pid, 'lsg_waitlist',    [] );
        update_post_meta( $pid, 'lsg_pinned',      0 );
        update_post_meta( $pid, '_lsg_version',    1 );

        // Giveaway meta
        $is_giveaway      = ! empty( $_POST['is_giveaway'] ) ? 1 : 0;
        $giveaway_duration= max( 1, (int) ( $_POST['giveaway_duration'] ?? 5 ) );
        update_post_meta( $pid, 'lsg_is_giveaway',       $is_giveaway );
        update_post_meta( $pid, 'lsg_giveaway_duration', $giveaway_duration );
        update_post_meta( $pid, 'lsg_giveaway_status',   'idle' );
        update_post_meta( $pid, 'lsg_giveaway_end_time', 0 );
        update_post_meta( $pid, 'lsg_giveaway_entrants', [] );
        update_post_meta( $pid, 'lsg_giveaway_winner',   '' );
        wp_set_object_terms( $pid, [ $cat_id ], 'product_cat' );
        if ( $image_id ) set_post_thumbnail( $pid, $image_id );

        lsg_increment_global_version();
        lsg_ably_publish( ABLY_PRODUCT_CHANNEL, 'new_product', [ 'product_id' => $pid ] );
        return true;
    } catch ( Exception $e ) {
        error_log( '[LSG] Create product error: ' . $e->getMessage() );
        return false;
    }
}

// ============================================================
// 2b. Auto-init meta for products added to Live Sale category via WC admin
// ============================================================
add_action( 'set_object_terms', function( $object_id, $terms, $tt_ids, $taxonomy ) {
    if ( $taxonomy !== 'product_cat' ) return;
    $cat_id = lsg_get_live_sale_category();
    if ( ! $cat_id || ! in_array( (int) $cat_id, array_map( 'intval', $tt_ids ), true ) ) return;
    if ( get_post_meta( $object_id, '_lsg_version', true ) ) return; // already initialised
    $product = wc_get_product( $object_id );
    if ( ! $product ) return;
    $stock = max( 0, (int) $product->get_stock_quantity() );
    update_post_meta( $object_id, 'available_stock', $stock );
    update_post_meta( $object_id, 'claimed_users',   [] );
    update_post_meta( $object_id, 'lsg_waitlist',    [] );
    update_post_meta( $object_id, 'lsg_pinned',      0 );
    update_post_meta( $object_id, '_lsg_version',    1 );
    lsg_increment_global_version();
}, 10, 4 );

// ============================================================
// 3. Frontend Shortcode  [live_sale_grid]
// ============================================================
add_shortcode( 'live_sale_grid', function () {
    if ( ! is_user_logged_in() ) {
        return '<p>Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">login</a> to view products.</p>';
    }

    ob_start();
    ?>
    <div id="lsg-notifications" style="position:fixed;top:20px;right:20px;z-index:9999;max-width:340px;"></div>
    <div id="lsg-grid-wrap">
        <div id="lsg-grid"></div>
    </div>

    <style>
        /* Stretch grid to full content area */
        #lsg-grid-wrap {
            width: 100%;
            padding: 20px 0;
            box-sizing: border-box;
        }
        #lsg-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 24px;
        }
        @media (max-width: 600px) {
            #lsg-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
        }
        @media (max-width: 400px) {
            #lsg-grid { grid-template-columns: 1fr; }
        }
        .live-sale-card { border:1px solid #e0e0e0; border-radius:12px; overflow:hidden; background:#fff;
            box-shadow:0 2px 6px rgba(0,0,0,.06); transition:transform .2s,box-shadow .2s; position:relative; }
        .live-sale-card:hover { transform:translateY(-3px); box-shadow:0 6px 16px rgba(0,0,0,.12); }
        .live-sale-card.is-pinned { border-color:#f5a623; box-shadow:0 0 0 2px #f5a623, 0 4px 12px rgba(0,0,0,.08); }
        .lsg-pinned-badge { position:absolute; top:8px; left:8px; background:#f5a623; color:#fff;
            font-size:11px; font-weight:700; padding:3px 8px; border-radius:20px; z-index:1; }
        .lsg-card-img { width:100%; aspect-ratio:1; overflow:hidden; background:#f5f5f5; }
        .lsg-card-img img { width:100%; height:100%; object-fit:cover; display:block; }
        .lsg-card-body { padding:14px; }
        .lsg-card-title { margin:0 0 4px; font-size:16px; font-weight:700; color:#00483e; }
        .lsg-card-sku { font-size:11px; color:#999; background:#f5f5f5; display:inline-block;
            padding:2px 7px; border-radius:4px; margin-bottom:8px; font-family:monospace; }
        .lsg-card-price { font-size:22px; font-weight:700; color:#00483e; margin:6px 0; }
        .lsg-card-stock { font-size:13px; color:#555; margin-bottom:10px; }
        .lsg-btn { width:100%; padding:10px; border:none; border-radius:6px; font-size:14px;
            font-weight:600; cursor:pointer; transition:background .2s,transform .1s; }
        .lsg-btn:active { transform:scale(.98); }
        .lsg-btn-claim { background:#27ae60; color:#fff; }
        .lsg-btn-claim:hover { background:#1e8449; }
        .lsg-btn-claimed { background:#00483e; color:#fff; cursor:not-allowed; opacity:.85; }
        .lsg-btn-waitlist { background:#3498db; color:#fff; }
        .lsg-btn-waitlist:hover { background:#2176ae; }
        .lsg-btn-waitlist-joined { background:#7f8c8d; color:#fff; cursor:not-allowed; }
        .lsg-notif { padding:10px 14px; margin-bottom:8px; border-radius:6px; font-size:13px;
            box-shadow:0 2px 8px rgba(0,0,0,.15); animation:lsg-slide-in .3s ease; }
        .lsg-notif-success { background:#d4edda; color:#155724; }
        .lsg-notif-info    { background:#cce5ff; color:#004085; }
        .lsg-notif-error   { background:#f8d7da; color:#721c24; }
        @keyframes lsg-slide-in { from{transform:translateX(60px);opacity:0} to{transform:translateX(0);opacity:1} }

        .lsg-btn-giveaway { background:#8e44ad; color:#fff; }
        .lsg-btn-giveaway:hover { background:#7d3c98; }
        .lsg-giveaway-card { border-color:#8e44ad !important; }
        .lsg-giveaway-badge { position:absolute; top:8px; right:8px; background:#8e44ad; color:#fff;
            font-size:11px; font-weight:700; padding:3px 8px; border-radius:20px; z-index:1; }
        .lsg-giveaway-timer { font-size:13px; font-weight:600; color:#8e44ad; margin:6px 0 10px;
            background:#f5eef8; padding:6px 10px; border-radius:6px; text-align:center; }
        .lsg-timer-idle { color:#999; background:#f5f5f5; }
        .lsg-timer-count { font-size:20px; font-weight:800; display:inline-block; min-width:60px; letter-spacing:1px; }
        .lsg-giveaway-entrants { font-size:12px; color:#777; margin-bottom:4px; }
        .lsg-giveaway-winner-banner { font-size:13px; color:#b7950b; background:#fef9e7;
            border:1px solid #f9e79f; padding:6px 10px; border-radius:6px; margin:6px 0; text-align:center; }
        .lsg-winner-you { color:#1a5c38; background:#d4edda; border-color:#c3e6cb; }
        .lsg-btn-winner-cta { display:block; background:#27ae60; color:#fff !important; text-decoration:none !important;
            text-align:center; padding:10px 14px; border-radius:8px; font-weight:700; font-size:14px;
            animation:lsg-pulse 1.8s ease-in-out infinite; }
        .lsg-btn-winner-cta:hover { background:#1e8449; }
        @keyframes lsg-pulse { 0%,100%{box-shadow:0 0 0 0 rgba(39,174,96,.5)} 50%{box-shadow:0 0 0 8px rgba(39,174,96,0)} }

        /* Skeleton loader */
        .lsg-skeleton { border:1px solid #e8e8e8; border-radius:12px; overflow:hidden;
            background:#fff; box-shadow:0 2px 6px rgba(0,0,0,.04); }
        .lsg-skeleton-img { width:100%; aspect-ratio:1; background:#ececec; }
        .lsg-skeleton-body { padding:14px; }
        .lsg-skeleton-line { height:14px; border-radius:6px; background:#ececec; margin-bottom:10px; }
        .lsg-skeleton-line.short  { width:50%; }
        .lsg-skeleton-line.medium { width:70%; }
        .lsg-skeleton-line.full   { width:100%; }
        .lsg-skeleton-btn  { height:38px; border-radius:6px; background:#ececec; margin-top:4px; }
        @keyframes lsg-shimmer {
            0%   { background-position:-600px 0; }
            100% { background-position:600px 0; }
        }
        .lsg-skeleton-img,
        .lsg-skeleton-line,
        .lsg-skeleton-btn {
            background: linear-gradient(90deg, #ececec 25%, #f5f5f5 50%, #ececec 75%);
            background-size:1200px 100%;
            animation: lsg-shimmer 1.4s infinite linear;
        }
    </style>
    <?php
    // Enqueue the grid JS — completely outside shortcode HTML so wptexturize never touches it
    wp_enqueue_script(
        'lsg-grid',
        plugins_url( 'livesale-grid.js', __FILE__ ),
        [ 'jquery' ],
        filemtime( plugin_dir_path( __FILE__ ) . 'livesale-grid.js' ),
        true
    );
    wp_localize_script( 'lsg-grid', 'lsgGrid', [
        'ajax'         => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'lsg_actions' ),
        'ably_key'     => ABLY_API_KEY,
        'ably_channel' => ABLY_PRODUCT_CHANNEL,
        'username'     => ( function() {
            $u = wp_get_current_user();
            return $u->ID ? ( $u->display_name ?: $u->user_login ) : '';
        } )(),
    ] );

    return ob_get_clean();
} );

// ============================================================
// 4. Frontend AJAX – Get all products
// ============================================================
add_action( 'wp_ajax_lsg_get_products',        'lsg_ajax_get_products' );
add_action( 'wp_ajax_nopriv_lsg_get_products', 'lsg_ajax_get_products' );
function lsg_ajax_get_products() {
    check_ajax_referer( 'lsg_actions', '_ajax_nonce' );

    $cat_id = lsg_get_live_sale_category();
    $html   = '';

    if ( $cat_id ) {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ],
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        $posts = get_posts( $args );
        $cards = [];
        foreach ( $posts as $p ) {
            $d = lsg_get_product_data( $p->ID );
            if ( $d ) $cards[] = $d;
        }
        // Show pinned products first
        usort( $cards, fn( $a, $b ) => (int) $b['pinned'] - (int) $a['pinned'] );
        foreach ( $cards as $d ) {
            $html .= lsg_render_product_card( $d );
        }
    }

    if ( ! $html ) $html = '<p style="grid-column:1/-1;text-align:center;">No products yet.</p>';
    wp_send_json_success( [ 'html' => $html ] );
}

// ============================================================
// 5. Frontend AJAX – Get single product card
// ============================================================
add_action( 'wp_ajax_lsg_get_product_card',        'lsg_ajax_get_product_card' );
add_action( 'wp_ajax_nopriv_lsg_get_product_card', 'lsg_ajax_get_product_card' );
function lsg_ajax_get_product_card() {
    check_ajax_referer( 'lsg_actions', '_ajax_nonce' );
    $pid = absint( $_GET['product_id'] ?? 0 );
    if ( ! $pid ) wp_send_json_error( 'No product ID' );
    $d = lsg_get_product_data( $pid );
    if ( ! $d ) wp_send_json_error( 'Product not found' );
    wp_send_json_success( [ 'html' => lsg_render_product_card( $d ) ] );
}

// ============================================================
// 6. Frontend AJAX – Claim product
// ============================================================
add_action( 'wp_ajax_lsg_claim_product', 'lsg_ajax_claim_product' );
function lsg_ajax_claim_product() {
    check_ajax_referer( 'lsg_actions', '_ajax_nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Please log in first.' );

    $pid      = absint( $_POST['product_id'] ?? 0 );
    $user     = wp_get_current_user();
    $username = $user->display_name ?: $user->user_login;

    if ( ! $pid ) wp_send_json_error( 'Invalid product.' );

    $available = (int) get_post_meta( $pid, 'available_stock', true );
    $claimed   = get_post_meta( $pid, 'claimed_users', true ) ?: [];

    if ( in_array( $username, $claimed, true ) ) wp_send_json_error( 'You have already claimed this product.' );
    if ( $available <= 0 ) wp_send_json_error( 'No stock available.' );

    // Check product exists
    $product = wc_get_product( $pid );
    if ( ! $product ) wp_send_json_error( 'Product not found.' );

    $claimed[] = $username;
    update_post_meta( $pid, 'claimed_users',   $claimed );
    update_post_meta( $pid, 'available_stock', $available - 1 );
    $new_version = (int) get_post_meta( $pid, '_lsg_version', true ) + 1;
    update_post_meta( $pid, '_lsg_version', $new_version );

    // Remove from waitlist if present
    $waitlist = get_post_meta( $pid, 'lsg_waitlist', true ) ?: [];
    $waitlist = array_values( array_diff( $waitlist, [ $username ] ) );
    update_post_meta( $pid, 'lsg_waitlist', $waitlist );

    // Sync short description
    lsg_sync_short_description( $pid );

    lsg_increment_global_version();

    $notif = $username . ' has claimed ' . $product->get_name() . ' [' . $product->get_sku() . ']!';
    lsg_ably_publish( ABLY_PRODUCT_CHANNEL, 'product-updated', [
        'product_id'   => $pid,
        'version'      => $new_version,
        'notification' => $notif,
        'notif_type'   => 'success',
    ] );

    wp_send_json_success( [ 'notification' => $notif ] );
}

// ============================================================
// 7. Frontend AJAX – Join waitlist
// ============================================================
add_action( 'wp_ajax_lsg_join_waitlist', 'lsg_ajax_join_waitlist' );
function lsg_ajax_join_waitlist() {
    check_ajax_referer( 'lsg_actions', '_ajax_nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Please log in first.' );

    $pid      = absint( $_POST['product_id'] ?? 0 );
    $user     = wp_get_current_user();
    $username = $user->display_name ?: $user->user_login;

    if ( ! $pid ) wp_send_json_error( 'Invalid product.' );

    $product = wc_get_product( $pid );
    if ( ! $product ) wp_send_json_error( 'Product not found.' );

    $waitlist = get_post_meta( $pid, 'lsg_waitlist', true ) ?: [];
    if ( in_array( $username, $waitlist, true ) ) wp_send_json_error( 'You are already on the waitlist.' );

    $waitlist[] = $username;
    update_post_meta( $pid, 'lsg_waitlist', $waitlist );

    $notif = $username . ' added to waitlist for ' . $product->get_name() . ' [' . $product->get_sku() . ']!';
    lsg_ably_publish( ABLY_PRODUCT_CHANNEL, 'product-updated', [
        'product_id'   => $pid,
        'version'      => (int) get_post_meta( $pid, '_lsg_version', true ),
        'notification' => $notif,
        'notif_type'   => 'info',
    ] );

    wp_send_json_success( [ 'notification' => $notif ] );
}

// ============================================================
// 7b. Giveaway AJAX handlers
// ============================================================

// Enter giveaway (logged-in user)
add_action( 'wp_ajax_lsg_enter_giveaway', 'lsg_ajax_enter_giveaway' );
function lsg_ajax_enter_giveaway() {
    check_ajax_referer( 'lsg_actions', '_ajax_nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Please log in first.' );

    $pid      = absint( $_POST['product_id'] ?? 0 );
    $user     = wp_get_current_user();
    $username = $user->display_name ?: $user->user_login;
    if ( ! $pid ) wp_send_json_error( 'Invalid product.' );

    if ( ! get_post_meta( $pid, 'lsg_is_giveaway', true ) ) wp_send_json_error( 'Not a giveaway.' );
    $status   = get_post_meta( $pid, 'lsg_giveaway_status', true );
    $end_time = (int) get_post_meta( $pid, 'lsg_giveaway_end_time', true );
    if ( $status !== 'running' || time() >= $end_time ) wp_send_json_error( 'Giveaway is not active.' );

    $entrants = get_post_meta( $pid, 'lsg_giveaway_entrants', true ) ?: [];
    if ( in_array( $username, $entrants, true ) ) wp_send_json_error( 'You have already entered.' );

    $entrants[] = $username;
    update_post_meta( $pid, 'lsg_giveaway_entrants', $entrants );

    lsg_ably_publish( ABLY_PRODUCT_CHANNEL, 'product-updated', [
        'product_id' => $pid,
        'version'    => (int) get_post_meta( $pid, '_lsg_version', true ),
    ] );

    wp_send_json_success( [ 'count' => count( $entrants ) ] );
}

// Admin: start giveaway timer
add_action( 'wp_ajax_lsg_start_giveaway', function () {
    check_ajax_referer( 'live_sale_update', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized' );

    $pid      = absint( $_POST['product_id'] ?? 0 );
    $duration = max( 1, (int) ( $_POST['duration'] ?? 0 ) );
    if ( ! $pid ) wp_send_json_error( 'Invalid product.' );

    $end_time = time() + $duration * 60;
    update_post_meta( $pid, 'lsg_giveaway_status',   'running' );
    update_post_meta( $pid, 'lsg_giveaway_end_time', $end_time );
    update_post_meta( $pid, 'lsg_giveaway_entrants', [] );
    update_post_meta( $pid, 'lsg_giveaway_winner',   '' );

    $product = wc_get_product( $pid );
    lsg_ably_publish( ABLY_PRODUCT_CHANNEL, 'giveaway-started', [
        'product_id' => $pid,
        'name'       => $product ? $product->get_name() : '',
        'end_time'   => $end_time,
    ] );

    wp_send_json_success( [
        'end_time' => $end_time,
        'html'     => lsg_render_admin_row( $pid ),
    ] );
} );

/**
 * Post-win handler: runs exactly once per giveaway.
 * - Decrements available stock by 1.
 * - Creates a free WooCommerce order (status: processing) for the winner.
 * - Sends a congratulations email to the winner.
 *
 * Guarded by the 'lsg_win_processed' post meta flag to prevent double execution
 * regardless of which roll path (auto-advance / JS timer / admin manual) fires first.
 */
function lsg_process_giveaway_win( int $pid, string $winner_username ) : void {
    if ( ! $winner_username ) return;

    // Idempotency guard — only run once per giveaway
    if ( get_post_meta( $pid, 'lsg_win_processed', true ) ) return;
    update_post_meta( $pid, 'lsg_win_processed', 1 );

    $product = wc_get_product( $pid );
    if ( ! $product ) return;

    // 1. Decrement available stock
    $available = (int) get_post_meta( $pid, 'available_stock', true );
    if ( $available > 0 ) {
        update_post_meta( $pid, 'available_stock', $available - 1 );
    }

    // 2. Resolve the winner's WP user account by display_name then user_login
    $winner_user = get_user_by( 'login', $winner_username );
    if ( ! $winner_user ) {
        $candidates = get_users( [
            'search'         => $winner_username,
            'search_columns' => [ 'display_name' ],
            'number'         => 10,
        ] );
        foreach ( $candidates as $u ) {
            if ( $u->display_name === $winner_username ) {
                $winner_user = $u;
                break;
            }
        }
    }
    if ( ! $winner_user ) {
        // Unknown user — nothing more we can do
        error_log( '[LiveSale] Cannot resolve WP user for giveaway winner: ' . $winner_username );
        return;
    }

    // 3. Create a free WooCommerce order for the winner
    $order_id = null;
    try {
        $order = wc_create_order( [ 'customer_id' => $winner_user->ID ] );

        $item = new WC_Order_Item_Product();
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

        // Add a customer-visible note so the order page makes sense
        $order->add_order_note(
            '🎉 Congratulations! This order was automatically created because you won the Live Sale giveaway for “'
            . $product->get_name() . '”. '
            . 'Our team will be in touch to arrange delivery. No payment is required.',
            1  // 1 = visible to customer
        );
    } catch ( \Exception $e ) {
        error_log( '[LiveSale] Giveaway order creation failed for product ' . $pid . ': ' . $e->getMessage() );
    }

    // 4. Send congratulations email to the winner
    $site_name  = get_bloginfo( 'name' );
    $subject    = '🎉 You won the ' . $site_name . ' giveaway!';
    $order_line = $order_id
        ? 'We have automatically created order #' . $order_id . ' for you.' . "\n"
          . 'View it here: ' . wc_get_account_endpoint_url( 'orders' ) . "\n\n"
        : 'Our team will contact you shortly to arrange delivery.' . "\n\n";
    $message =
          'Hi ' . $winner_user->display_name . ",\n\n"
        . 'Congratulations! You have won the Live Sale giveaway for:' . "\n\n"
        . '  * ' . $product->get_name() . ' (SKU: ' . $product->get_sku() . ')' . "\n\n"
        . $order_line
        . 'Thank you for participating!' . "\n\n"
        . 'Best regards,' . "\n"
        . $site_name;
    wp_mail( $winner_user->user_email, $subject, $message );
}

// Shared: roll the winner
function lsg_do_roll_winner( int $pid ) : array {
    $status = get_post_meta( $pid, 'lsg_giveaway_status', true );
    if ( $status === 'ended' ) {
        return [ 'already_done' => true, 'winner' => get_post_meta( $pid, 'lsg_giveaway_winner', true ) ];
    }
    $end_time = (int) get_post_meta( $pid, 'lsg_giveaway_end_time', true );
    if ( $end_time > time() ) {
        return [ 'error' => 'Timer has not expired yet.' ];
    }
    // Transient lock prevents double-roll
    $lock_key = 'lsg_roll_lock_' . $pid;
    if ( get_transient( $lock_key ) ) {
        sleep( 1 );
        return [ 'already_done' => true, 'winner' => get_post_meta( $pid, 'lsg_giveaway_winner', true ) ];
    }
    set_transient( $lock_key, 1, 10 );

    $entrants = get_post_meta( $pid, 'lsg_giveaway_entrants', true ) ?: [];
    $winner   = '';
    if ( ! empty( $entrants ) ) {
        $winner  = $entrants[ array_rand( $entrants ) ];
        $claimed = get_post_meta( $pid, 'claimed_users', true ) ?: [];
        $claimed[] = $winner;
        update_post_meta( $pid, 'claimed_users', array_unique( $claimed ) );
        lsg_sync_short_description( $pid );
    }

    update_post_meta( $pid, 'lsg_giveaway_winner', $winner );
    update_post_meta( $pid, 'lsg_giveaway_status', 'ended' );
    delete_transient( $lock_key );

    // Post-win: stock, order, email
    if ( $winner ) {
        lsg_process_giveaway_win( $pid, $winner );
    }

    $product = wc_get_product( $pid );
    lsg_ably_publish( ABLY_PRODUCT_CHANNEL, 'giveaway-winner', [
        'product_id' => $pid,
        'name'       => $product ? $product->get_name() : '',
        'winner'     => $winner ?: 'No entrants',
    ] );

    return [ 'winner' => $winner ?: 'No entrants' ];
}

// Admin: manually roll winner
add_action( 'wp_ajax_lsg_roll_giveaway_winner', function () {
    check_ajax_referer( 'live_sale_update', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized' );
    $pid    = absint( $_POST['product_id'] ?? 0 );
    $result = lsg_do_roll_winner( $pid );
    if ( isset( $result['error'] ) ) wp_send_json_error( $result['error'] );
    wp_send_json_success( [ 'winner' => $result['winner'], 'html' => lsg_render_admin_row( $pid ) ] );
} );

// Public: auto-roll when timer expires
add_action( 'wp_ajax_lsg_auto_roll_winner',        'lsg_ajax_auto_roll_winner' );
add_action( 'wp_ajax_nopriv_lsg_auto_roll_winner', 'lsg_ajax_auto_roll_winner' );
function lsg_ajax_auto_roll_winner() {
    check_ajax_referer( 'lsg_actions', '_ajax_nonce' );
    $pid    = absint( $_POST['product_id'] ?? 0 );
    $result = lsg_do_roll_winner( $pid );
    if ( isset( $result['error'] ) ) wp_send_json_error( $result['error'] );
    wp_send_json_success( [ 'winner' => $result['winner'] ] );
}

// ============================================================
// 8. Admin AJAX – Refresh admin list
// ============================================================
add_action( 'wp_ajax_lsg_refresh_admin_list', function () {
    check_ajax_referer( 'lsg_refresh_admin_list', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized', 403 );
    $page = max( 1, (int) ( $_GET['page'] ?? 1 ) );
    lsg_render_admin_list( $page );
    wp_die();
} );

// ============================================================
// 9. Admin AJAX – Update price
// ============================================================
add_action( 'wp_ajax_lsg_update_price', function () {
    check_ajax_referer( 'live_sale_update', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();
    $pid   = absint( $_POST['product_id'] );
    $price = floatval( $_POST['value'] );
    $p     = wc_get_product( $pid );
    if ( $p && $price > 0 ) {
        $p->set_regular_price( $price );
        $p->set_price( $price );
        $p->save();
        $v = (int) get_post_meta( $pid, '_lsg_version', true ) + 1;
        update_post_meta( $pid, '_lsg_version', $v );
        lsg_increment_global_version();
        lsg_ably_publish( ABLY_PRODUCT_CHANNEL, 'product-updated', [ 'product_id' => $pid, 'version' => $v ] );
        wp_send_json_success( [ 'html' => lsg_render_admin_row( $pid ) ] );
    }
    wp_send_json_error();
} );

// ============================================================
// 10. Admin AJAX – Update total stock
// ============================================================
add_action( 'wp_ajax_lsg_update_total_stock', function () {
    check_ajax_referer( 'live_sale_update', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();
    $pid   = absint( $_POST['product_id'] );
    $stock = max( 0, (int) $_POST['value'] );
    $p     = wc_get_product( $pid );
    if ( $p ) {
        $p->set_stock_quantity( $stock );
        $p->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
        $p->save();
        $v = (int) get_post_meta( $pid, '_lsg_version', true ) + 1;
        update_post_meta( $pid, '_lsg_version', $v );
        lsg_increment_global_version();
        lsg_ably_publish( ABLY_PRODUCT_CHANNEL, 'product-updated', [ 'product_id' => $pid, 'version' => $v ] );
        wp_send_json_success( [ 'html' => lsg_render_admin_row( $pid ) ] );
    }
    wp_send_json_error();
} );

// ============================================================
// 11. Admin AJAX – Update available stock
// ============================================================
add_action( 'wp_ajax_lsg_update_available_stock', function () {
    check_ajax_referer( 'live_sale_update', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();
    $pid   = absint( $_POST['product_id'] );
    $avail = max( 0, (int) $_POST['value'] );
    update_post_meta( $pid, 'available_stock', $avail );
    $v = (int) get_post_meta( $pid, '_lsg_version', true ) + 1;
    update_post_meta( $pid, '_lsg_version', $v );
    lsg_increment_global_version();
    lsg_ably_publish( ABLY_PRODUCT_CHANNEL, 'product-updated', [ 'product_id' => $pid, 'version' => $v ] );
    wp_send_json_success( [ 'html' => lsg_render_admin_row( $pid ) ] );
} );

// ============================================================
// 12. Admin AJAX – Update claimed users (manual edit)
//     Removing a user restores stock + clears short desc
// ============================================================
add_action( 'wp_ajax_lsg_update_claimed_users', function () {
    check_ajax_referer( 'live_sale_update', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error();

    $pid       = absint( $_POST['product_id'] );
    $raw       = sanitize_text_field( $_POST['value'] ?? '' );
    $new_users = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
    $old_users = get_post_meta( $pid, 'claimed_users', true ) ?: [];

    $removed    = array_diff( $old_users, $new_users );
    $added      = array_diff( $new_users, $old_users );
    $available  = (int) get_post_meta( $pid, 'available_stock', true );
    $new_avail  = max( 0, $available + count( $removed ) - count( $added ) );

    update_post_meta( $pid, 'claimed_users',   $new_users );
    update_post_meta( $pid, 'available_stock', $new_avail );

    $short_desc = lsg_sync_short_description( $pid );

    $v = (int) get_post_meta( $pid, '_lsg_version', true ) + 1;
    update_post_meta( $pid, '_lsg_version', $v );
    lsg_increment_global_version();
    lsg_ably_publish( ABLY_PRODUCT_CHANNEL, 'product-updated', [ 'product_id' => $pid, 'version' => $v ] );
    wp_send_json_success( [ 'html' => lsg_render_admin_row( $pid ) ] );
} );

// ============================================================
// 13. Admin AJAX – Toggle pin
// ============================================================
add_action( 'wp_ajax_lsg_toggle_pin', function () {
    $pid = absint( $_POST['product_id'] ?? 0 );
    if ( ! check_ajax_referer( 'lsg_pin_product_' . $pid, '_ajax_nonce', false ) ) wp_send_json_error( 'Bad nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized' );

    $pinned = (bool) get_post_meta( $pid, 'lsg_pinned', true );
    update_post_meta( $pid, 'lsg_pinned', $pinned ? 0 : 1 );

    $v = (int) get_post_meta( $pid, '_lsg_version', true ) + 1;
    update_post_meta( $pid, '_lsg_version', $v );
    lsg_increment_global_version();
    lsg_ably_publish( ABLY_PRODUCT_CHANNEL, 'product-updated', [ 'product_id' => $pid, 'version' => $v ] );

    wp_send_json_success( [ 'html' => lsg_render_admin_row( $pid ), 'pinned' => ! $pinned ] );
} );

// ============================================================
// 14. Admin AJAX – Delete product
// ============================================================
add_action( 'wp_ajax_lsg_delete_product', function () {
    $pid = absint( $_POST['product_id'] ?? 0 );
    if ( ! check_ajax_referer( 'lsg_delete_product_' . $pid, '_ajax_nonce', false ) ) wp_send_json_error( 'Bad nonce' );
    if ( ! current_user_can( 'manage_woocommerce' ) ) wp_send_json_error( 'Unauthorized' );

    $result = wp_delete_post( $pid, true ); // true = force delete, skip trash
    if ( $result ) {
        lsg_increment_global_version();
        lsg_ably_publish( ABLY_PRODUCT_CHANNEL, 'product-deleted', [ 'product_id' => $pid ] );
        wp_send_json_success();
    } else {
        wp_send_json_error( 'Could not delete.' );
    }
} );

// ============================================================
// 15. WooCommerce Checkout Validation
// ============================================================
function lsg_validate_cart_claims() {
    if ( ! is_user_logged_in() ) return;

    $user     = wp_get_current_user();
    $username = $user->display_name ?: $user->user_login;
    $cat_id   = lsg_get_live_sale_category();

    if ( ! $cat_id || ! WC()->cart ) return;

    foreach ( WC()->cart->get_cart() as $item ) {
        $pid   = $item['product_id'];
        $terms = wp_get_post_terms( $pid, 'product_cat', [ 'fields' => 'ids' ] );
        if ( ! in_array( (int) $cat_id, $terms, true ) ) continue;

        $claimed_users = get_post_meta( $pid, 'claimed_users', true ) ?: [];
        if ( ! in_array( $username, $claimed_users, true ) ) {
            $product = wc_get_product( $pid );
            $name    = $product ? $product->get_name() : 'this product';
            wc_add_notice(
                sprintf(
                    __( 'You cannot check out "%s" because it has not been claimed by you.', 'live-sale' ),
                    esc_html( $name )
                ),
                'error'
            );
        }
    }
}

// Fires on cart page (shows notice)
add_action( 'woocommerce_check_cart_items', 'lsg_validate_cart_claims' );

// Fires when Place Order is submitted — hard-stops the order if invalid
add_action( 'woocommerce_checkout_process', 'lsg_validate_cart_claims' );

// ============================================================
// 16. Chat Shortcode  [live_sale_chat]
// ============================================================
add_shortcode( 'live_sale_chat', 'lsg_chat_shortcode' );
function lsg_chat_shortcode() : string {
    $is_admin = current_user_can( 'manage_options' );
    ob_start();
    ?>
    <div id="lsg-chat">
        <div id="lsg-chat-messages" style="border:1px solid #ccc;height:320px;overflow-y:auto;padding:12px;background:#f9f9f9;border-radius:6px;"></div>
        <div style="margin-top:10px;display:flex;gap:8px;">
            <input type="text" id="lsg-chat-input" style="flex:1;padding:8px;" placeholder="Type a message…">
            <button id="lsg-chat-send" class="button button-primary">Send</button>
            <?php if ( $is_admin ) : ?>
                <button id="lsg-chat-clear" class="button">Clear All</button>
            <?php endif; ?>
        </div>
    </div>
    <style>
        .lsg-chat-msg { display:flex; justify-content:space-between; align-items:flex-start;
            padding:6px 0; border-bottom:1px solid #eee; font-size:13px; }
        .lsg-chat-msg .lsg-del-btn { color:#c00; cursor:pointer; font-size:11px; white-space:nowrap; margin-left:10px; }
        .lsg-chat-msg .lsg-del-btn:hover { color:#f00; text-decoration:underline; }
    </style>
    <?php
    wp_enqueue_script(
        'lsg-chat',
        plugins_url( 'livesale-chat.js', __FILE__ ),
        [ 'jquery' ],
        filemtime( plugin_dir_path( __FILE__ ) . 'livesale-chat.js' ),
        true
    );
    wp_localize_script( 'lsg-chat', 'lsgChat', [
        'ajax'         => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'lsg_chat_nonce' ),
        'is_admin'     => $is_admin,
        'ably_key'     => ABLY_API_KEY,
        'ably_channel' => ABLY_CHAT_CHANNEL,
    ] );

    return ob_get_clean();
}

// ---- Chat AJAX ----
add_action( 'wp_ajax_lsg_fetch_chat',        'lsg_ajax_fetch_chat' );
add_action( 'wp_ajax_nopriv_lsg_fetch_chat', 'lsg_ajax_fetch_chat' );
function lsg_ajax_fetch_chat() {
    check_ajax_referer( 'lsg_chat_nonce', '_ajax_nonce' );
    wp_send_json_success( get_option( 'lsg_chat', [] ) );
}

add_action( 'wp_ajax_lsg_send_chat',        'lsg_ajax_send_chat' );
add_action( 'wp_ajax_nopriv_lsg_send_chat', 'lsg_ajax_send_chat' );
function lsg_ajax_send_chat() {
    check_ajax_referer( 'lsg_chat_nonce', '_ajax_nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'Login required.' );
    $user = wp_get_current_user();
    $name = $user->display_name ?: $user->user_login;
    $msg  = sanitize_text_field( $_POST['message'] ?? '' );
    if ( ! $msg ) wp_send_json_error( 'Empty message.' );
    $chat   = get_option( 'lsg_chat', [] );
    $chat[] = [ 'user' => $name, 'msg' => $msg ];
    $chat   = array_slice( $chat, -50 );
    update_option( 'lsg_chat', $chat );
    lsg_ably_publish( ABLY_CHAT_CHANNEL, 'new-message', [ 'user' => $name, 'message' => $msg, 'timestamp' => time() ] );
    wp_send_json_success();
}

add_action( 'wp_ajax_lsg_admin_send_chat', function () {
    check_ajax_referer( 'lsg_chat_nonce', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
    $msg  = sanitize_text_field( $_POST['message'] ?? '' );
    if ( ! $msg ) wp_send_json_error( 'Empty.' );
    $chat   = get_option( 'lsg_chat', [] );
    $chat[] = [ 'user' => 'Admin', 'msg' => $msg ];
    $chat   = array_slice( $chat, -50 );
    update_option( 'lsg_chat', $chat );
    lsg_ably_publish( ABLY_CHAT_CHANNEL, 'new-message', [ 'user' => 'Admin', 'message' => $msg, 'timestamp' => time() ] );
    wp_send_json_success();
} );

add_action( 'wp_ajax_lsg_admin_delete_msg', function () {
    check_ajax_referer( 'lsg_chat_nonce', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
    $idx  = (int) ( $_POST['index'] ?? -1 );
    $chat = get_option( 'lsg_chat', [] );
    if ( isset( $chat[ $idx ] ) ) {
        array_splice( $chat, $idx, 1 );
        update_option( 'lsg_chat', $chat );
        wp_send_json_success();
    }
    wp_send_json_error( 'Not found.' );
} );

add_action( 'wp_ajax_lsg_admin_clear_chat', function () {
    check_ajax_referer( 'lsg_chat_nonce', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
    update_option( 'lsg_chat', [] );
    wp_send_json_success();
} );

// ============================================================
// 17. Enqueue WP Media on admin page
// ============================================================
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'toplevel_page_live-sale-manager' ) return;
    wp_enqueue_media();
} );
