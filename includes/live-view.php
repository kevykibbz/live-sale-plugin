<?php
/**
 * LSG Live View — [lsg_live_view] shortcode.
 *
 * Renders a video embed (YouTube / Vimeo / direct file) with the live chat
 * overlaid directly on top of the video — TikTok / Instagram Live style.
 *
 * Shortcode attributes:
 *   video_url    (required)  YouTube or Vimeo URL, or direct .mp4/.webm URL
 *   chat_side    "left" | "right"   (default: left)
 *   chat_width   integer 15–50      (default: 30)  — percent of video width
 *   aspect       CSS aspect-ratio string (default: "16 / 9")
 *
 * Usage in any page builder (Gutenberg, Elementor, Divi, etc.):
 *   [lsg_live_view video_url="https://www.youtube.com/watch?v=XXXX"]
 *   [lsg_live_view video_url="https://youtu.be/XXXX" chat_side="right" chat_width="28"]
 *
 * @package LiveSale
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// Video URL normalisation helpers
// ============================================================

/**
 * Detect if a URL is a direct video file (mp4, webm, ogg, mov).
 */
function lsg_is_direct_video_url( string $url ) : bool {
    return (bool) preg_match( '/\.(mp4|webm|ogg|mov)(\?.*)?$/i', wp_parse_url( $url, PHP_URL_PATH ) );
}

/**
 * Convert any recognised video URL to an embeddable src URL.
 * Falls back to the original URL for unknown formats.
 */
function lsg_normalize_video_url( string $url ) : string {
    $url = trim( $url );

    // Already an embed URL — return as-is
    if ( strpos( $url, 'youtube-nocookie.com/embed' ) !== false ) return $url;
    if ( strpos( $url, 'youtube.com/embed' ) !== false ) {
        // Upgrade to privacy-enhanced domain
        return str_replace( 'youtube.com/embed', 'youtube-nocookie.com/embed', $url );
    }
    if ( strpos( $url, 'player.vimeo.com/video' ) !== false ) return $url;

    // youtube.com/watch?v=ID  or  youtube.com/shorts/ID
    if ( preg_match( '/youtube\.com\/(?:watch\?v=|shorts\/)([a-zA-Z0-9_-]{11})/', $url, $m ) ) {
        return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0&modestbranding=1';
    }

    // youtu.be/ID
    if ( preg_match( '/youtu\.be\/([a-zA-Z0-9_-]{11})/', $url, $m ) ) {
        return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0&modestbranding=1';
    }

    // vimeo.com/ID
    if ( preg_match( '/vimeo\.com\/(\d+)/', $url, $m ) ) {
        return 'https://player.vimeo.com/video/' . $m[1] . '?byline=0&title=0';
    }

    return $url; // direct file or unknown — returned verbatim
}

// ============================================================
// Shortcode
// ============================================================

add_shortcode( 'lsg_live_view', 'lsg_live_view_shortcode' );
function lsg_live_view_shortcode( $atts ) : string {
    if ( ! lsg_check_woocommerce() ) return '';

    $atts = shortcode_atts( [
        'video_url'  => '',
        'chat_side'  => 'left',
        'chat_width' => '30',
        'aspect'     => '16 / 9',
    ], $atts, 'lsg_live_view' );

    $video_url  = esc_url_raw( $atts['video_url'] );
    $chat_side  = in_array( $atts['chat_side'], [ 'left', 'right' ], true ) ? $atts['chat_side'] : 'left';
    $chat_width = max( 15, min( 50, (int) $atts['chat_width'] ) );
    $aspect     = sanitize_text_field( $atts['aspect'] );

    if ( ! $video_url ) {
        return '<p style="color:#c00;">[lsg_live_view] shortcode requires a <code>video_url</code> attribute.</p>';
    }

    // Enqueue chat CSS + JS (same as standalone chat shortcode)
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
        '5.0',
        true
    );

    $current_user = wp_get_current_user();
    $username     = $current_user->exists() ? ( $current_user->display_name ?: $current_user->user_login ) : '';
    wp_localize_script( 'lsg-chat-js', 'lsgChat', [
        'ajax'         => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'lsg_chat_nonce' ),
        'is_admin'     => current_user_can( 'manage_woocommerce' ),  // Boolean, not string
        'username'     => $username,
        'ably_key'     => ABLY_API_KEY,
        'ably_channel' => ABLY_CHAT_CHANNEL,
    ] );

    $embed_url     = lsg_normalize_video_url( $video_url );
    $is_direct     = lsg_is_direct_video_url( $video_url );

    // Unique wrapper ID so multiple shortcodes on one page work independently
    static $instance = 0;
    $instance++;
    $wrap_id = 'lsg-lv-' . $instance;

    // Enqueue product grid CSS + JS (claim / waitlist / giveaway / auction buttons)
    wp_enqueue_style(
        'lsg-grid-css',
        plugin_dir_url( dirname( __FILE__ ) ) . 'css/livesale-grid.css',
        [],
        '5.2'
    );
    wp_enqueue_script(
        'lsg-grid',
        plugin_dir_url( dirname( __FILE__ ) ) . 'js/livesale-grid.js',
        [ 'jquery' ],
        '5.2', // Updated version to force cache refresh
        true
    );
    wp_localize_script( 'lsg-grid', 'lsgGrid', [
        'ajax'         => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'lsg_actions' ),
        'ably_key'     => ABLY_API_KEY,
        'ably_channel' => ABLY_PRODUCT_CHANNEL,
        'username'     => $username,
        'init_version' => (int) get_option( 'lsg_global_version', 0 ),
    ] );
    // Lightbox JS — added once per page even if shortcode appears multiple times
    add_action( 'wp_footer', function () {
        static $lv_lb_done = false;
        if ( $lv_lb_done ) return;
        $lv_lb_done = true;
        ?>
        <script>
        jQuery(function($){
            $(document).on('click','.lsg-zoomable',function(e){
                if($(e.target).is('button,a,input')) return;
                var src=$(this).find('img').attr('src');
                if(!src) return;
                $('#lsg-lightbox-img').attr('src',src);
                $('#lsg-lightbox').addClass('active');
            });
            $(document).on('click','#lsg-lightbox-close, #lsg-lightbox',function(e){
                if(e.target===this) $('#lsg-lightbox').removeClass('active');
            });
            $(document).on('keydown',function(e){ if(e.key==='Escape') $('#lsg-lightbox').removeClass('active'); });
        });
        </script>
        <?php
    }, 20 );

    // Viewer count + live badge are handled by lsg-chat-js already.
    // Fetch product grid (latest 8 published products)
    $grid_products = class_exists( 'WooCommerce' ) ? wc_get_products( [
        'status'     => 'publish',
        'limit'      => 8,
        'orderby'    => 'date',
        'order'      => 'DESC',
        'visibility' => 'catalog',
    ] ) : [];

    $is_logged_in = is_user_logged_in();
    $account_page = get_page_by_path( 'my-account' );
    $login_url    = $account_page ? get_permalink( $account_page->ID ) : wp_login_url( get_permalink() );
    $shop_url     = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' );

    ob_start();
    ?>

    <!-- ===== Live Sales page: full-width breakout ===== -->
    <div id="<?php echo esc_attr( $wrap_id ); ?>" class="lsg-lv-wrap">

        <!-- ── Top strip: Live badge + viewer count ── -->
        <div class="lsg-lv-topbar">
            <span class="lsg-live-pill">&#11044; LIVE</span>
            <span id="lsg-viewer-count" class="lsg-lv-viewers">– viewers</span>
        </div>

        <!-- ── Two-column player area (video | chat) ── -->
        <div class="lsg-lv-columns">

            <!-- LEFT: video -->
            <div class="lsg-lv-video-col">
                <div class="lsg-lv-stage">
                    <?php if ( $is_direct ) : ?>
                    <video class="lsg-lv-video" loop playsinline>
                        <source src="<?php echo esc_url( $video_url ); ?>">
                        Your browser does not support HTML5 video.
                    </video>
                    <?php else : ?>
                    <iframe class="lsg-lv-video"
                        src="<?php echo esc_url( $embed_url ); ?>"
                        frameborder="0"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowfullscreen
                        title="Live Sale Video"
                    ></iframe>
                    <?php endif; ?>

                    <!-- Pulsating play overlay — shown by default, hidden on click -->
                    <div class="lsg-lv-play-overlay" role="button" aria-label="Play video" tabindex="0">
                        <div class="lsg-play-ring lsg-play-ring--1"></div>
                        <div class="lsg-play-ring lsg-play-ring--2"></div>
                        <div class="lsg-play-ring lsg-play-ring--3"></div>
                        <button class="lsg-lv-play-btn" aria-hidden="true" tabindex="-1">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <polygon points="6,3 21,12 6,21" fill="#fff"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div><!-- .lsg-lv-video-col -->

            <!-- RIGHT: chat -->
            <div class="lsg-lv-chat-col">
                <div id="lsg-chat">
                    <!-- Chat header -->
                    <div id="lsg-live-bar">
                        <span class="lsg-lv-chat-title">Live Chat</span>
                    </div>

                    <!-- Messages -->
                    <div id="lsg-chat-messages">
                        <?php for ( $i = 0; $i < 5; $i++ ) : ?>
                        <div class="lsg-skel-row">
                            <span class="lsg-skel-name"></span>
                            <span class="lsg-skel-wide"></span>
                        </div>
                        <?php endfor; ?>
                    </div>

                    <!-- Input -->
                    <?php if ( $is_logged_in ) : ?>
                    <div id="lsg-chat-footer">
                        <div id="lsg-chat-input-row">
                            <input id="lsg-chat-input" type="text" maxlength="200"
                                   placeholder="Say something nice…" autocomplete="off">
                            <button id="lsg-chat-send" aria-label="Send message">
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M2 21l21-9L2 3v7l15 2-15 2v7z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <?php else : ?>
                    <div id="lsg-chat-footer">
                        <p id="lsg-chat-login-prompt">
                            <a href="<?php echo esc_url( $login_url ); ?>">Log in</a> to join the conversation
                        </p>
                    </div>
                    <?php endif; ?>
                </div><!-- #lsg-chat -->
            </div><!-- .lsg-lv-chat-col -->

        </div><!-- .lsg-lv-columns -->

        <!-- ── Product grid below the player ── -->
        <!-- Notifications + lightbox needed by livesale-grid.js -->
        <div id="lsg-notifications" style="position:fixed;top:20px;right:20px;z-index:9999;max-width:340px;pointer-events:none;"></div>
        <div id="lsg-lightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:99999;justify-content:center;align-items:center;">
            <button id="lsg-lightbox-close" style="position:absolute;top:20px;right:28px;color:#fff;font-size:36px;cursor:pointer;background:none;border:none;line-height:1;">&#10005;</button>
            <img id="lsg-lightbox-img" src="" alt="">
        </div>

        <!-- ── Product grid below the player ── -->
        <section class="lsg-lv-products">
            <div class="lsg-products__header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                <h2 class="lsg-products__title" style="margin:0;font-size:1.2rem;">Products from This Sale</h2>
                <a href="<?php echo esc_url( $shop_url ); ?>" style="font-size:13px;color:#1557b0;text-decoration:none;font-weight:600;">Browse All &rarr;</a>
            </div>
            <!-- lsg-grid is filled immediately by livesale-grid.js via AJAX (claim/waitlist/giveaway/auction cards) -->
            <div id="lsg-grid"></div>
        </section>

    </div><!-- .lsg-lv-wrap -->

    <script>
    (function () {
        var wrap    = document.getElementById( <?php echo wp_json_encode( $wrap_id ); ?> );
        if ( ! wrap ) return;
        var overlay = wrap.querySelector( '.lsg-lv-play-overlay' );
        if ( ! overlay ) return;

        function dismissOverlay() {
            overlay.style.opacity = '0';
            overlay.style.pointerEvents = 'none';
            <?php if ( $is_direct ) : ?>
            var video = wrap.querySelector( '.lsg-lv-video' );
            if ( video ) video.play();
            <?php endif; ?>
        }

        overlay.addEventListener( 'click', dismissOverlay );
        overlay.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); dismissOverlay(); }
        } );
    }());
    </script>

    <?php
    return ob_get_clean();
}
