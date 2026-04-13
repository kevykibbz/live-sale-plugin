<?php
/**
 * LSG Shortcodes — existing front-end shortcodes:
 *   [live_sale_grid]           — product grid
 *   [live_sale_chat]           — standalone TikTok-style chat
 *   [lsg_giveaway_timer]       — giveaway countdown widget
 *   [lsg_auction_widget]       — auction bid widget
 *
 * CSS is loaded via wp_enqueue_style() from the external .css files
 * (no more inline <style> output).
 *
 * @package LiveSale
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// No-texturise registration moved to main loader
// ============================================================

// ============================================================
// [live_sale_grid]
// ============================================================
add_shortcode( 'live_sale_grid', 'lsg_grid_shortcode' );
function lsg_grid_shortcode( $atts ) : string {
    if ( ! lsg_check_woocommerce() ) return '';

    // Enqueue CSS
    wp_enqueue_style(
        'lsg-grid',
        plugin_dir_url( dirname( __FILE__ ) ) . 'css/livesale-grid.css',
        [],
        '5.3'
    );

    // Enqueue JS
    wp_enqueue_script(
        'lsg-grid-js',
        plugin_dir_url( dirname( __FILE__ ) ) . 'js/livesale-grid.js',
        [ 'jquery' ],
        '5.5',
        true
    );
    wp_localize_script( 'lsg-grid-js', 'lsgGrid', [
        'ajax'         => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'lsg_actions' ),
        'username'     => ( function() {
            $u = wp_get_current_user();
            return $u && $u->exists() ? ( $u->display_name ?: $u->user_login ) : '';
        } )(),
        'socketio_url'     => LSG_SOCKETIO_URL,
        'socketio_channel' => LSG_SOCKETIO_PRODUCT_CHANNEL,
        'init_version' => (int) get_option( 'lsg_global_version', 0 ),
    ] );

    // Lightbox + auction countdown — injected once in footer
    add_action( 'wp_footer', 'lsg_enqueue_footer_scripts', 20 );

    ob_start(); ?>
    <div id="lsg-notifications" style="position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none;"></div>
    <div id="lsg-grid-wrap">
        <div id="lsg-grid">
            <?php
            $cat_id = lsg_get_live_sale_category();
            if ( $cat_id ) {
                $q = new WP_Query( [
                    'post_type'      => 'product',
                    'posts_per_page' => -1,
                    'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ],
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ] );
                if ( $q->have_posts() ) {
                    $ids = [];
                    while ( $q->have_posts() ) { $q->the_post(); $ids[] = get_the_ID(); }
                    wp_reset_postdata();
                    usort( $ids, fn( $a, $b ) => (int) get_post_meta( $b, 'lsg_pinned', true ) - (int) get_post_meta( $a, 'lsg_pinned', true ) );
                    foreach ( $ids as $pid ) {
                        $d = lsg_get_product_data( $pid );
                        if ( $d ) echo lsg_render_product_card( $d );
                    }
                } else {
                    echo '<p style="text-align:center;color:#999;grid-column:1/-1;">No live-sale products available yet.</p>';
                }
            }
            ?>
        </div>
    </div>
    <!-- Lightbox -->
    <div id="lsg-lightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:9998;align-items:center;justify-content:center;cursor:zoom-out;">
        <img id="lsg-lightbox-img" src="" alt="" style="max-width:90vw;max-height:90vh;border-radius:10px;box-shadow:0 8px 40px rgba(0,0,0,.6);">
    </div>
    <?php
    return ob_get_clean();
}

function lsg_enqueue_footer_scripts() {
    static $done = false;
    if ( $done ) return;
    $done = true;
    ?>
    <script>
    (function($){
        // Lightbox
        $(document).on('click', '.lsg-zoomable img', function(e){
            e.stopPropagation();
            var src = $(this).attr('src');
            $('#lsg-lightbox-img').attr('src', src);
            $('#lsg-lightbox').css('display','flex').hide().fadeIn(200);
        });
        $(document).on('click', '#lsg-lightbox', function(){ $(this).fadeOut(200); });

        // Auction countdowns — seconds only, urgent pulse under 10s
        function tickAuction() {
            var now = Math.floor(Date.now()/1000);
            $('.lsg-auction-timer[data-end]').each(function(){
                var el=$(this), end=parseInt(el.data('end'),10), left=end-now;
                if(!el.data('id')) return;
                var countdown=el.find('.lsg-auction-timer-count');
                if(left<=0){
                    countdown.text('EXPIRED');
                    el.addClass('lsg-timer-urgent');
                }else{
                    countdown.text(left + 's');
                    if(left<=10){ el.addClass('lsg-timer-urgent'); }
                    else        { el.removeClass('lsg-timer-urgent'); }
                }
            });
        }
        setInterval(tickAuction, 1000);
    })(jQuery);
    </script>
    <?php
}

// ============================================================
// [live_sale_chat]
// ============================================================
add_shortcode( 'live_sale_chat', 'lsg_chat_shortcode' );
function lsg_chat_shortcode( $atts ) : string {
    if ( ! lsg_check_woocommerce() ) return '';

    $atts = shortcode_atts( [ 'height' => '520px' ], $atts, 'live_sale_chat' );
    $height = sanitize_text_field( $atts['height'] );

    wp_enqueue_style(
        'lsg-chat',
        plugin_dir_url( dirname( __FILE__ ) ) . 'css/livesale-chat.css',
        [],
        '5.0'
    );
    wp_enqueue_script(
        'lsg-chat-js',
        plugin_dir_url( dirname( __FILE__ ) ) . 'js/livesale-chat.js',
        [ 'jquery' ],
        '5.4',
        true
    );

    $current_user = wp_get_current_user();
    $username     = $current_user->exists() ? ( $current_user->display_name ?: $current_user->user_login ) : '';
    wp_localize_script( 'lsg-chat-js', 'lsgChat', [
        'ajax'         => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'lsg_chat' ),
        'is_admin'     => current_user_can( 'manage_woocommerce' ) ? '1' : '0',
        'username'     => $username,
        'socketio_url'     => LSG_SOCKETIO_URL,
        'socketio_channel' => LSG_SOCKETIO_CHAT_CHANNEL,
    ] );

    ob_start();
    $inline_height = $height !== '520px' ? ' style="height:' . esc_attr( $height ) . ';"' : '';
    ?>
    <div id="lsg-chat"<?php echo $inline_height; ?>>
        <div id="lsg-live-bar">
            <span class="lsg-live-pill">● LIVE</span>
            <span id="lsg-viewer-count">– viewers</span>
        </div>
        <div id="lsg-chat-messages">
            <!-- skeleton loaders -->
            <?php for ( $i = 0; $i < 5; $i++ ) : ?>
            <div class="lsg-chat-msg lsg-skeleton">
                <span class="lsg-skel-name"></span>
                <span class="lsg-skel-text"></span>
            </div>
            <?php endfor; ?>
        </div>
        <?php if ( is_user_logged_in() ) : ?>
        <div id="lsg-chat-footer">
            <input id="lsg-chat-input" type="text" maxlength="200" placeholder="Say something…" autocomplete="off">
            <button id="lsg-chat-send">Send</button>
        </div>
        <?php else : ?>
        <div id="lsg-chat-footer" style="justify-content:center;">
            <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" style="color:#fff;font-size:13px;">Login to chat</a>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ============================================================
// [lsg_giveaway_overlay]
// Compact overlay widget — auto-finds the latest running giveaway.
// No product_id needed. Use as an overlay on top of the live sale screen.
// ============================================================
add_shortcode( 'lsg_giveaway_overlay', 'lsg_giveaway_overlay_shortcode' );
function lsg_giveaway_overlay_shortcode( $atts ) : string {

    wp_enqueue_style(
        'lsg-grid',
        plugin_dir_url( dirname( __FILE__ ) ) . 'css/livesale-grid.css',
        [],
        '5.3'
    );

    // Find the latest running giveaway in the live-sale category
    $active_pid      = 0;
    $active_end_time = 0;
    $active_name     = '';
    $cat_id          = lsg_get_live_sale_category();

    if ( $cat_id ) {
        $live_products = get_posts( [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ],
            'meta_query'     => [
                [ 'key' => 'lsg_is_giveaway',     'value' => '1' ],
                [ 'key' => 'lsg_giveaway_status', 'value' => 'running' ],
            ],
            'orderby' => 'date',
            'order'   => 'DESC',
            'fields'  => 'ids',
        ] );
        if ( ! empty( $live_products ) ) {
            $active_pid      = (int) $live_products[0];
            $active_end_time = (int) get_post_meta( $active_pid, 'lsg_giveaway_end_time', true );
            $active_name     = get_the_title( $active_pid );
        }
    }

    $is_logged_in = is_user_logged_in();
    $login_url    = wp_login_url( get_permalink() );
    $current_user = wp_get_current_user();
    $username     = $current_user->exists() ? ( $current_user->display_name ?: $current_user->user_login ) : '';
    $has_entered  = false;
    if ( $active_pid && $username ) {
        $entrants    = get_post_meta( $active_pid, 'lsg_giveaway_entrants', true ) ?: [];
        $has_entered = in_array( $username, (array) $entrants, true );
    }

    $nonce       = wp_create_nonce( 'lsg_actions' );
    $ajax_url    = admin_url( 'admin-ajax.php' );
    $socketio_url  = LSG_SOCKETIO_URL;
    $socketio_chan  = LSG_SOCKETIO_PRODUCT_CHANNEL;

    ob_start();
    ?>
    <div id="lsg-giveaway-overlay"
         class="lsg-giveaway-overlay<?php echo $active_pid ? ' lsg-gwo-active' : ' lsg-gwo-hidden'; ?>"
         data-pid="<?php echo esc_attr( $active_pid ); ?>"
         data-end="<?php echo esc_attr( $active_end_time ); ?>">

        <div class="lsg-gwo-badge">🎁 GIVEAWAY</div>

        <div class="lsg-gwo-timer" id="lsg-gwo-timer">
            <?php echo $active_pid ? '' : '–'; ?>
        </div>

        <div class="lsg-gwo-actions" id="lsg-gwo-actions">
            <?php if ( ! $is_logged_in ) : ?>
                <a href="<?php echo esc_url( $login_url ); ?>" class="lsg-gwo-btn lsg-gwo-btn-login">🔒 Login to Join</a>
            <?php elseif ( $active_pid && $has_entered ) : ?>
                <button class="lsg-gwo-btn lsg-gwo-btn-entered" disabled>✓ Entered</button>
            <?php elseif ( $active_pid ) : ?>
                <button class="lsg-gwo-btn lsg-gwo-btn-join" id="lsg-gwo-join-btn">🎟 Join Giveaway</button>
            <?php else : ?>
                <span class="lsg-gwo-idle">No active giveaway</span>
            <?php endif; ?>
        </div>

    </div>

    <script>
    (function(){
        var overlay  = document.getElementById('lsg-giveaway-overlay');
        if (!overlay) return;

        var timerEl  = document.getElementById('lsg-gwo-timer');
        var actionsEl= document.getElementById('lsg-gwo-actions');
        var pid      = parseInt(overlay.dataset.pid, 10) || 0;
        var endTime  = parseInt(overlay.dataset.end,  10) || 0;
        var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
        var ajax     = <?php echo wp_json_encode( $ajax_url ); ?>;
        var socketioUrl  = <?php echo wp_json_encode( $socketio_url ); ?>;
        var socketioChan = <?php echo wp_json_encode( $socketio_chan ); ?>;
        var isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
        var loginUrl   = <?php echo wp_json_encode( $login_url ); ?>;
        var tickTimer;

        // ---- Timer ----
        function tick() {
            if (!endTime || !pid) return;
            var left = endTime - Math.floor(Date.now() / 1000);
            if (left <= 0) {
                timerEl.textContent = 'EXPIRED';
                overlay.classList.add('lsg-gwo-expired');
                overlay.classList.remove('lsg-gwo-urgent');
            } else {
                timerEl.textContent = left + 's';
                overlay.classList.toggle('lsg-gwo-urgent', left <= 10);
            }
        }
        if (pid && endTime) {
            tick();
            tickTimer = setInterval(tick, 1000);
        }

        // ---- Join button (attached / re-attached dynamically) ----
        function attachJoin() {
            var btn = document.getElementById('lsg-gwo-join-btn');
            if (!btn) return;
            btn.addEventListener('click', function(){
                btn.disabled = true;
                btn.textContent = '…';
                var fd = new FormData();
                fd.append('action',      'lsg_enter_giveaway');
                fd.append('product_id',  pid);
                fd.append('_ajax_nonce', nonce);
                fetch(ajax, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(r){
                        if (r.success) {
                            actionsEl.innerHTML = '<button class="lsg-gwo-btn lsg-gwo-btn-entered" disabled>\u2713 Entered!</button>';
                        } else {
                            alert(r.data || 'Could not enter giveaway.');
                            btn.disabled = false;
                            btn.textContent = '\uD83C\uDFDF Join Giveaway';
                        }
                    })
                    .catch(function(){
                        btn.disabled = false;
                        btn.textContent = '\uD83C\uDFDF Join Giveaway';
                    });
            });
        }
        attachJoin();

        // ---- Socket.io real-time updates ----
        if (socketioUrl) {
            var s  = document.createElement('script');
            s.src  = socketioUrl + '/socket.io/socket.io.js';
            s.async = true;
            s.onload = function() {
                try {
                    var socket = io(socketioUrl);
                    socket.emit('join', socketioChan);

                    socket.on('giveaway-started', function(d){
                        if (!d || !d.product_id) return;
                        pid     = parseInt(d.product_id, 10);
                        endTime = parseInt(d.end_time,   10);
                        overlay.dataset.pid = pid;
                        overlay.dataset.end = endTime;
                        overlay.classList.add('lsg-gwo-active');
                        overlay.classList.remove('lsg-gwo-hidden', 'lsg-gwo-expired', 'lsg-gwo-urgent');
                        timerEl.textContent = endTime - Math.floor(Date.now() / 1000) + 's';
                        clearInterval(tickTimer);
                        tickTimer = setInterval(tick, 1000);
                        if (isLoggedIn) {
                            actionsEl.innerHTML = '<button class="lsg-gwo-btn lsg-gwo-btn-join" id="lsg-gwo-join-btn">\uD83C\uDFDF Join Giveaway</button>';
                            attachJoin();
                        } else {
                            actionsEl.innerHTML = '<a href="' + loginUrl + '" class="lsg-gwo-btn lsg-gwo-btn-login">\uD83D\uDD12 Login to Join</a>';
                        }
                    });

                    socket.on('giveaway-winner', hideOverlay);
                    // giveaway-entered: no-op on overlay (grid handles entrant count)

                    function hideOverlay() {
                        clearInterval(tickTimer);
                        pid = 0; endTime = 0;
                        overlay.classList.remove('lsg-gwo-active', 'lsg-gwo-urgent');
                        overlay.classList.add('lsg-gwo-hidden');
                        timerEl.textContent = '';
                        actionsEl.innerHTML = '<span class="lsg-gwo-idle">Giveaway ended</span>';
                    }

                } catch(e) { console.warn('[LSG Overlay] Socket.io init failed:', e.message); }
            };
            document.head.appendChild(s);
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}

// ============================================================
// [lsg_giveaway_timer product_id="X"]
// ============================================================
add_shortcode( 'lsg_giveaway_timer', 'lsg_giveaway_timer_shortcode' );
function lsg_giveaway_timer_shortcode( $atts ) : string {
    $atts = shortcode_atts( [ 'product_id' => 0 ], $atts );
    $pid  = absint( $atts['product_id'] );
    if ( ! $pid ) return '';

    $status   = (string) get_post_meta( $pid, 'lsg_giveaway_status',   true );
    $end_time = (int)    get_post_meta( $pid, 'lsg_giveaway_end_time', true );
    $winner   = (string) get_post_meta( $pid, 'lsg_giveaway_winner',   true );

    ob_start();
    if ( $status === 'ended' ) {
        echo '<div class="lsg-giveaway-timer-widget lsg-timer-ended">🏆 Winner: <strong>' . esc_html( $winner ?: 'No entrants' ) . '</strong></div>';
    } elseif ( $status === 'running' ) {
        ?>
        <div class="lsg-giveaway-timer-widget lsg-timer-running" data-end="<?php echo esc_attr( $end_time ); ?>">
            ⏱ Giveaway ends in <span class="lsg-timer-widget-count" style="font-weight:700;"></span>
        </div>
        <script>
        (function(){
            var el = document.querySelector('.lsg-giveaway-timer-widget[data-end="<?php echo esc_js( $end_time ); ?>"] .lsg-timer-widget-count');
            if(!el) return;
            var end = <?php echo (int) $end_time; ?>;
            function tick(){
                var left = end - Math.floor(Date.now()/1000);
                if(left<=0){el.textContent='EXPIRED';return;}
                el.textContent = left + 's';
                setTimeout(tick,1000);
            }
            tick();
        })();
        </script>
        <?php
    } else {
        echo '<div class="lsg-giveaway-timer-widget lsg-timer-idle">⏳ Giveaway Starting Soon</div>';
    }
    return ob_get_clean();
}

// ============================================================
// [lsg_auction_widget product_id="X"]
// ============================================================
add_shortcode( 'lsg_auction_widget', 'lsg_auction_widget_shortcode' );

/* ============================================================
   Hero shortcode [lsg_hero]
   ============================================================ */
add_shortcode( 'lsg_hero', 'lsg_hero_shortcode' );
function lsg_hero_shortcode( $atts ) : string {

    wp_enqueue_style(
        'lsg-hero',
        plugin_dir_url( dirname( __FILE__ ) ) . 'css/livesale-hero.css',
        [],
        '1.0'
    );

    $base = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/';

    $slides = [
        [
            'bg'     => '#eef4fd',
            'accent' => '#046bd2',
            'dark'   => '#033d78',
            'img'    => $base . 'beautiful-woman-elegant-dress-holding-shopping-bags-showing-okay-sign-recommend-store-with-di.jpg',
            'alt'    => 'Woman with shopping bags',
            'eyebrow'=> '&#128717; Exclusive Live Shopping',
            'title'  => 'Discover Deals <em>Live.</em>',
            'desc'   => 'Shop our exclusive live sales — fresh finds, unbeatable prices, straight to your door.',
            'cta1'   => [ 'label' => 'Watch Live Now', 'url' => '/live-sales/' ],
            'cta2'   => [ 'label' => 'Browse Shop',    'url' => '/shop/'       ],
        ],
        [
            'bg'     => '#fdf6f0',
            'accent' => '#c0392b',
            'dark'   => '#922b21',
            'img'    => $base . 'shopping-concept-close-up-portrait-young-beautiful-attractive-redhair-girl-smiling-looking-camera-with-white-shopping-bag-blue-pastel-background-copy-space.jpg',
            'alt'    => 'Woman with shopping bag',
            'eyebrow'=> '&#127909; Live Sales Events',
            'title'  => 'Shop Live, <em>Save More</em>',
            'desc'   => 'Join our real-time live sales — exciting auctions, giveaways, and members-only deals await.',
            'cta1'   => [ 'label' => 'Join Live Sales', 'url' => '/live-sales/'  ],
            'cta2'   => [ 'label' => 'My Account',      'url' => '/my-account/' ],
        ],
        [
            'bg'     => '#fffbf0',
            'accent' => '#d4ac0d',
            'dark'   => '#9a7d0a',
            'img'    => $base . 'african-american-man-with-colorful-paper-bags-isolated-yellow-background.jpg',
            'alt'    => 'Man with colorful shopping bags',
            'eyebrow'=> '&#127873; Members Get More',
            'title'  => 'Exclusive Deals <em>For You</em>',
            'desc'   => 'Sign up free and unlock member-only pricing, early access, and special giveaways.',
            'cta1'   => [ 'label' => 'Create Account', 'url' => '/my-account/' ],
            'cta2'   => [ 'label' => 'Learn More',     'url' => '/faq/'        ],
        ],
    ];

    ob_start();
    ?>
    <section class="lsg-hero" aria-label="Featured promotions">

        <div class="lsg-hero__slider">
            <?php foreach ( $slides as $i => $s ) :
                $is_active = ( $i === 0 ) ? ' is-active' : '';
            ?>
            <div class="lsg-hero__slide<?php echo esc_attr( $is_active ); ?>"
                 style="background:<?php echo esc_attr( $s['bg'] ); ?>;--lsg-slide-accent:<?php echo esc_attr( $s['accent'] ); ?>;--lsg-slide-accent-dark:<?php echo esc_attr( $s['dark'] ); ?>">
                <div class="lsg-hero__inner">

                    <div class="lsg-hero__content">
                        <span class="lsg-hero__eyebrow"><?php echo wp_kses_post( $s['eyebrow'] ); ?></span>
                        <h2 class="lsg-hero__title"><?php echo wp_kses_post( $s['title'] ); ?></h2>
                        <p class="lsg-hero__desc"><?php echo esc_html( $s['desc'] ); ?></p>
                        <div class="lsg-hero__ctas">
                            <a href="<?php echo esc_url( $s['cta1']['url'] ); ?>"
                               class="lsg-hero__btn lsg-hero__btn--primary"><?php echo esc_html( $s['cta1']['label'] ); ?></a>
                            <a href="<?php echo esc_url( $s['cta2']['url'] ); ?>"
                               class="lsg-hero__btn lsg-hero__btn--outline"><?php echo esc_html( $s['cta2']['label'] ); ?></a>
                        </div>
                        <div class="lsg-hero__controls" role="tablist" aria-label="Hero slides">
                            <?php foreach ( $slides as $j => $_ ) :
                                $active_class = ( $j === 0 ) ? ' is-active' : '';
                            ?>
                            <button class="lsg-hero__dot<?php echo esc_attr( $active_class ); ?>"
                                    role="tab"
                                    aria-label="Slide <?php echo ( $j + 1 ); ?>"
                                    aria-selected="<?php echo ( $j === 0 ) ? 'true' : 'false'; ?>"
                                    data-slide="<?php echo esc_attr( $j ); ?>"></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="lsg-hero__image" aria-hidden="true">
                        <img src="<?php echo esc_url( $s['img'] ); ?>"
                             alt="<?php echo esc_attr( $s['alt'] ); ?>"
                             <?php echo ( $i === 0 ) ? 'loading="eager" fetchpriority="high"' : 'loading="lazy"'; ?>>
                    </div>

                </div>
            </div>
            <?php endforeach; ?>
        </div><!-- .lsg-hero__slider -->

        <div class="lsg-hero__badges" aria-label="Shopping guarantees">
            <div class="lsg-hero__badge">
                <span class="lsg-hero__badge-icon" aria-hidden="true">&#128666;</span>
                <div class="lsg-hero__badge-text">
                    <strong>Free Shipping</strong>
                    <span>On orders $50+</span>
                </div>
            </div>
            <div class="lsg-hero__badge">
                <span class="lsg-hero__badge-icon" aria-hidden="true">&#128274;</span>
                <div class="lsg-hero__badge-text">
                    <strong>Secure Checkout</strong>
                    <span>256-bit SSL encryption</span>
                </div>
            </div>
            <div class="lsg-hero__badge">
                <span class="lsg-hero__badge-icon" aria-hidden="true">&#8617;</span>
                <div class="lsg-hero__badge-text">
                    <strong>Easy Returns</strong>
                    <span>Hassle-free policy</span>
                </div>
            </div>
            <div class="lsg-hero__badge">
                <span class="lsg-hero__badge-icon" aria-hidden="true">&#127873;</span>
                <div class="lsg-hero__badge-text">
                    <strong>Member Deals</strong>
                    <span>Sign up, save more</span>
                </div>
            </div>
        </div><!-- .lsg-hero__badges -->

        <div class="lsg-features" aria-label="Site highlights">
            <div class="lsg-features__inner">
                <a href="<?php echo esc_url( home_url( '/live-sales/' ) ); ?>" class="lsg-feature">
                    <span class="lsg-feature__icon" aria-hidden="true">&#128247;</span>
                    <div>
                        <div class="lsg-feature__title">Live Sales Daily</div>
                        <div class="lsg-feature__text">Fresh inventory, every day</div>
                    </div>
                </a>
                <a href="<?php echo esc_url( home_url( '/live-sales/' ) ); ?>" class="lsg-feature">
                    <span class="lsg-feature__icon" aria-hidden="true">&#128172;</span>
                    <div>
                        <div class="lsg-feature__title">Chat Live With Us</div>
                        <div class="lsg-feature__text">Real-time shopping support</div>
                    </div>
                </a>
                <a href="<?php echo esc_url( home_url( '/my-account/' ) ); ?>" class="lsg-feature">
                    <span class="lsg-feature__icon" aria-hidden="true">&#11088;</span>
                    <div>
                        <div class="lsg-feature__title">Member-Only Pricing</div>
                        <div class="lsg-feature__text">Exclusive deals for members</div>
                    </div>
                </a>
            </div>
        </div><!-- .lsg-features -->

    </section><!-- .lsg-hero -->

    <script>
    (function () {
        'use strict';
        var hero = document.querySelector('.lsg-hero');
        if (!hero) return;
        var slides = hero.querySelectorAll('.lsg-hero__slide');
        var dotGroups = hero.querySelectorAll('.lsg-hero__controls');
        var current = 0;
        var timer;

        function goTo(n) {
            var prev = current;
            current = (n + slides.length) % slides.length;
            if (prev === current) return;

            // Outgoing: add leaving class (plays exit animation), remove active
            var leaving = slides[prev];
            leaving.classList.remove('is-active');
            leaving.classList.add('is-leaving');

            // Incoming: add active class (plays enter animation with CSS delay)
            slides[current].classList.add('is-active');

            // Clean up leaving class once exit animation finishes
            setTimeout(function () {
                leaving.classList.remove('is-leaving');
            }, 600);

            dotGroups.forEach(function (group) {
                var dots = group.querySelectorAll('.lsg-hero__dot');
                dots[prev].classList.remove('is-active');
                dots[prev].setAttribute('aria-selected', 'false');
                dots[current].classList.add('is-active');
                dots[current].setAttribute('aria-selected', 'true');
            });
        }

        function resetTimer() {
            clearInterval(timer);
            timer = setInterval(function () { goTo(current + 1); }, 6000);
        }

        dotGroups.forEach(function (group) {
            group.querySelectorAll('.lsg-hero__dot').forEach(function (dot) {
                dot.addEventListener('click', function () {
                    goTo(parseInt(this.dataset.slide, 10));
                    resetTimer();
                });
            });
        });

        resetTimer();
    }());
    </script>
    <?php
    return ob_get_clean();
}

function lsg_auction_widget_shortcode( $atts ) : string {
    $atts = shortcode_atts( [ 'product_id' => 0 ], $atts );
    $pid  = absint( $atts['product_id'] );
    if ( ! $pid ) return '';

    wp_enqueue_style(
        'lsg-grid',
        plugin_dir_url( dirname( __FILE__ ) ) . 'css/livesale-grid.css',
        [],
        '5.2'
    );
    wp_enqueue_script(
        'lsg-grid-js',
        plugin_dir_url( dirname( __FILE__ ) ) . 'js/livesale-grid.js',
        [ 'jquery' ],
        '5.5',
        true
    );
    wp_localize_script( 'lsg-grid-js', 'lsgGrid', [
        'ajax'         => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'lsg_actions' ),
        'username'     => ( function() {
            $u = wp_get_current_user();
            return $u && $u->exists() ? ( $u->display_name ?: $u->user_login ) : '';
        } )(),
        'socketio_url'     => LSG_SOCKETIO_URL,
        'socketio_channel' => LSG_SOCKETIO_PRODUCT_CHANNEL,
        'init_version' => (int) get_option( 'lsg_global_version', 0 ),
    ] );

    $d = lsg_get_product_data( $pid );
    if ( ! $d ) return '<p>Product not found.</p>';
    return '<div id="lsg-auction-widget-wrap">' . lsg_render_product_card( $d ) . '</div>';
}

// ============================================================
// [lsg_home_products]  Featured product grid for the home page
// ============================================================
add_shortcode( 'lsg_home_products', 'lsg_home_products_shortcode' );
function lsg_home_products_shortcode( $atts ) : string {
    if ( ! class_exists( 'WooCommerce' ) ) return '';

    $atts = shortcode_atts( [
        'count'   => 8,
        'columns' => 4,
        'orderby' => 'date',
        'order'   => 'DESC',
    ], $atts );

    wp_enqueue_style(
        'lsg-home',
        plugin_dir_url( dirname( __FILE__ ) ) . 'css/livesale-home.css',
        [],
        '1.0'
    );
    wp_enqueue_script( 'ionicons-esm' );

    $products = wc_get_products( [
        'status'     => 'publish',
        'limit'      => (int) $atts['count'],
        'orderby'    => sanitize_key( $atts['orderby'] ),
        'order'      => strtoupper( $atts['order'] ) === 'ASC' ? 'ASC' : 'DESC',
        'visibility' => 'catalog',
    ] );

    if ( empty( $products ) ) {
        return '<p class="lsg-products__empty">No products found yet. <a href="' . esc_url( admin_url( 'post-new.php?post_type=product' ) ) . '">Add some products</a>.</p>';
    }

    $is_logged_in = is_user_logged_in();
    $account_page = get_page_by_path( 'my-account' );
    $login_url    = $account_page ? get_permalink( $account_page->ID ) : wp_login_url( get_permalink() );
    $shop_url     = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );
    $cols         = max( 1, min( 4, (int) $atts['columns'] ) );

    ob_start(); ?>
    <section class="lsg-products">
        <div class="lsg-products__header">
            <div class="lsg-products__header-left">
                <h2 class="lsg-products__title">Featured Products</h2>
                <p class="lsg-products__subtitle">Handpicked favorites — updated weekly.</p>
            </div>
            <a href="<?php echo esc_url( $shop_url ); ?>" class="lsg-products__more">
                Browse All <ion-icon name="arrow-forward-outline"></ion-icon>
            </a>
        </div>

        <div class="lsg-products__grid lsg-products__grid--<?php echo esc_attr( $cols ); ?>col">
        <?php foreach ( $products as $product ) :
            /** @var WC_Product $product */
            $pid       = $product->get_id();
            $permalink = get_permalink( $pid );
            $img_url   = get_the_post_thumbnail_url( $pid, 'woocommerce_thumbnail' ) ?: wc_placeholder_img_src();
            $title     = $product->get_name();
            $on_sale   = $product->is_on_sale();
            $featured  = $product->is_featured();
            $cats      = get_the_terms( $pid, 'product_cat' );
            $cat_name  = ( $cats && ! is_wp_error( $cats ) ) ? $cats[0]->name : '';
            $cart_url  = $product->is_type( 'simple' )
                ? add_query_arg( 'add-to-cart', $pid, $permalink )
                : $permalink;
        ?>
            <div class="lsg-product-card">
                <!-- Thumbnail -->
                <a href="<?php echo esc_url( $permalink ); ?>" class="lsg-product-card__thumb-link" aria-label="<?php echo esc_attr( $title ); ?>">
                    <div class="lsg-product-card__img">
                        <img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy">
                        <?php if ( $on_sale || $featured ) : ?>
                        <div class="lsg-product-card__badges">
                            <?php if ( $on_sale ) : ?><span class="lsg-badge lsg-badge--sale">Sale</span><?php endif; ?>
                            <?php if ( $featured ) : ?><span class="lsg-badge lsg-badge--hot"><ion-icon name="flame-outline"></ion-icon></span><?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="lsg-product-card__overlay">
                            <span class="lsg-product-card__quick-view"><ion-icon name="eye-outline"></ion-icon></span>
                        </div>
                    </div>
                </a>

                <!-- Body -->
                <div class="lsg-product-card__body">
                    <?php if ( $cat_name ) : ?>
                        <span class="lsg-product-card__cat"><?php echo esc_html( $cat_name ); ?></span>
                    <?php endif; ?>
                    <h3 class="lsg-product-card__title">
                        <a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
                    </h3>
                    <div class="lsg-product-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>

                    <div class="lsg-product-card__actions">
                        <?php if ( $is_logged_in ) : ?>
                        <a href="<?php echo esc_url( $cart_url ); ?>" class="lsg-product-card__btn lsg-product-card__btn--cart">
                            <ion-icon name="cart-outline"></ion-icon>
                            <span>Add to Cart</span>
                        </a>
                        <?php else : ?>
                        <a href="<?php echo esc_url( $login_url ); ?>" class="lsg-product-card__btn lsg-product-card__btn--login">
                            <ion-icon name="lock-closed-outline"></ion-icon>
                            <span>Login to Buy</span>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

// ============================================================
// [lsg_home_cta]  Call-to-action banner for the home page
// ============================================================
add_shortcode( 'lsg_home_cta', 'lsg_home_cta_shortcode' );
function lsg_home_cta_shortcode( $atts ) : string {
    wp_enqueue_style(
        'lsg-home',
        plugin_dir_url( dirname( __FILE__ ) ) . 'css/livesale-home.css',
        [],
        '1.0'
    );
    wp_enqueue_script( 'ionicons-esm' );

    $is_logged_in = is_user_logged_in();

    $account_page = get_page_by_path( 'my-account' );
    $account_url  = $account_page ? get_permalink( $account_page->ID ) : home_url( '/my-account/' );

    $live_page    = get_page_by_path( 'live-sales' );
    $live_url     = $live_page ? get_permalink( $live_page->ID ) : home_url( '/live-sales/' );

    ob_start(); ?>
    <section class="lsg-home-cta">
        <div class="lsg-home-cta__inner">
            <div class="lsg-home-cta__content">
                <div class="lsg-home-cta__icon-wrap" aria-hidden="true">
                    <ion-icon name="videocam-outline"></ion-icon>
                </div>
                <div class="lsg-home-cta__text">
                    <h2 class="lsg-home-cta__title">Join Our Live Sales Events</h2>
                    <p class="lsg-home-cta__desc">Real-time auctions, giveaways &amp; exclusive member-only pricing — every week, live.</p>
                </div>
            </div>
            <div class="lsg-home-cta__actions">
                <a href="<?php echo esc_url( $live_url ); ?>" class="lsg-home-cta__btn lsg-home-cta__btn--primary">
                    <ion-icon name="play-circle-outline"></ion-icon>
                    Watch Live Now
                </a>
                <?php if ( $is_logged_in ) : ?>
                <a href="<?php echo esc_url( $account_url ); ?>" class="lsg-home-cta__btn lsg-home-cta__btn--outline">
                    <ion-icon name="person-outline"></ion-icon>
                    My Account
                </a>
                <?php else : ?>
                <a href="<?php echo esc_url( $account_url ); ?>" class="lsg-home-cta__btn lsg-home-cta__btn--outline">
                    <ion-icon name="person-add-outline"></ion-icon>
                    Create Free Account
                </a>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
    return ob_get_clean();
}
