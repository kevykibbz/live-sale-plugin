<?php
/**
 * LSG Admin — admin menu, tabs, product creation form, and all admin UI.
 *
 * @package LiveSale
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================
// Auto-init meta when a product is assigned to the Live Sale category
// ============================================================
add_action( 'set_object_terms', function( $object_id, $terms, $tt_ids, $taxonomy ) {
    if ( $taxonomy !== 'product_cat' ) return;
    $cat_id = lsg_get_live_sale_category();
    if ( ! $cat_id || ! in_array( (int) $cat_id, array_map( 'intval', $tt_ids ), true ) ) return;
    if ( get_post_meta( $object_id, '_lsg_version', true ) ) return;
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
// Admin menu
// ============================================================
add_action( 'admin_menu', function () {
    if ( ! lsg_check_woocommerce() ) return;
    add_menu_page(
        'Live Sale Manager', 'Live Sale', 'manage_woocommerce',
        'live-sale-manager', 'lsg_admin_page',
        'dashicons-cart', 56
    );
} );

// Enqueue WP Media uploader on the admin page
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'toplevel_page_live-sale-manager' ) return;
    wp_enqueue_media();
} );

// ============================================================
// Admin page router
// ============================================================
function lsg_admin_page() {
    $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'products';
    ?>
    <div class="wrap">
        <h1>Live Sale Manager</h1>
        <h2 class="nav-tab-wrapper">
            <a href="?page=live-sale-manager&tab=products"  class="nav-tab <?php echo $tab === 'products'  ? 'nav-tab-active' : ''; ?>">Products</a>
            <a href="?page=live-sale-manager&tab=claimed"   class="nav-tab <?php echo $tab === 'claimed'   ? 'nav-tab-active' : ''; ?>">Claimed Users</a>
            <a href="?page=live-sale-manager&tab=giveaway"  class="nav-tab <?php echo $tab === 'giveaway'  ? 'nav-tab-active' : ''; ?>">🎁 Giveaways</a>
            <a href="?page=live-sale-manager&tab=waitlist"  class="nav-tab <?php echo $tab === 'waitlist'  ? 'nav-tab-active' : ''; ?>">Waitlists</a>
            <a href="?page=live-sale-manager&tab=chat"      class="nav-tab <?php echo $tab === 'chat'      ? 'nav-tab-active' : ''; ?>">Chat</a>
            <a href="?page=live-sale-manager&tab=setup"     class="nav-tab <?php echo $tab === 'setup'     ? 'nav-tab-active' : ''; ?>">&#9881; Setup</a>
        </h2>
        <?php
        if      ( $tab === 'products' ) lsg_admin_products_tab();
        elseif  ( $tab === 'claimed'  ) lsg_admin_claimed_tab();
        elseif  ( $tab === 'giveaway' ) lsg_admin_giveaway_tab();
        elseif  ( $tab === 'waitlist' ) lsg_admin_waitlist_tab();
        elseif  ( $tab === 'chat'     ) lsg_admin_chat_tab();
        elseif  ( $tab === 'setup'    ) lsg_admin_setup_tab();
        ?>
    </div>
    <?php
}

// ============================================================
// Chat tab
// ============================================================
function lsg_admin_chat_tab() {
    $messages = get_option( 'lsg_chat_messages', [] );
    if ( ! is_array( $messages ) ) $messages = [];
    $messages = array_reverse( $messages ); // newest first
    $nonce    = wp_create_nonce( 'lsg_chat_nonce' );
    ?>
    <h2 style="margin-top:20px;">&#x1F4AC; Live Chat Messages</h2>

    <!-- Send message form -->
    <div style="margin-bottom:16px;display:flex;gap:8px;align-items:center;">
        <input type="text" id="lsg-admin-chat-input"
               placeholder="Type a message as admin…"
               maxlength="500"
               style="flex:1;padding:6px 10px;border-radius:4px;border:1px solid #ddd;font-size:14px;">
        <button id="lsg-admin-chat-send" class="button button-primary">&#x2709;&#xFE0F; Send</button>
        <button id="lsg-admin-chat-clear" class="button" style="color:#c00;border-color:#c00;"
                onclick="return confirm('Clear all chat messages?');">&#x1F5D1; Clear All</button>
    </div>
    <p id="lsg-chat-admin-msg" style="display:none;font-weight:600;"></p>

    <!-- Messages table -->
    <table class="widefat striped" id="lsg-chat-admin-table">
        <thead>
            <tr>
                <th style="width:140px;">Time</th>
                <th style="width:160px;">User</th>
                <th>Message</th>
                <th style="width:80px;">Role</th>
                <th style="width:70px;">Delete</th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $messages ) ) : ?>
            <tr id="lsg-chat-empty-row">
                <td colspan="5" style="text-align:center;color:#999;">No chat messages yet.</td>
            </tr>
        <?php else : ?>
            <?php foreach ( $messages as $i => $msg ) :
                $real_index = count( $messages ) - 1 - $i; // original array index (for delete)
                $time_str   = gmdate( 'Y-m-d H:i', (int)( $msg['timestamp'] ?? 0 ) );
                $is_admin   = ! empty( $msg['is_admin'] );
            ?>
            <tr id="lsg-chat-row-<?php echo esc_attr( $real_index ); ?>">
                <td style="color:#888;font-size:12px;"><?php echo esc_html( $time_str ); ?></td>
                <td><strong><?php echo esc_html( $msg['user'] ?? '–' ); ?></strong></td>
                <td><?php echo esc_html( $msg['text'] ?? '' ); ?></td>
                <td>
                    <?php if ( $is_admin ) : ?>
                        <span style="background:#fff3cd;color:#856404;padding:2px 7px;border-radius:10px;font-size:11px;">Admin</span>
                    <?php else : ?>
                        <span style="background:#f0f0f0;color:#555;padding:2px 7px;border-radius:10px;font-size:11px;">User</span>
                    <?php endif; ?>
                </td>
                <td>
                    <button class="button button-small lsg-admin-del-msg"
                            data-index="<?php echo esc_attr( $real_index ); ?>"
                            style="color:#c00;border-color:#c00;">&#x2715;</button>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <script>
    jQuery(function($){
        var nonce      = <?php echo wp_json_encode( $nonce ); ?>;
        var ajax       = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        var ablyKey    = <?php echo wp_json_encode( ABLY_API_KEY ); ?>;
        var ablyChan   = <?php echo wp_json_encode( ABLY_CHAT_CHANNEL ); ?>;
        var adminName  = <?php echo wp_json_encode( wp_get_current_user()->display_name ?: wp_get_current_user()->user_login ); ?>;

        // Track how many messages are already in the table (original count before reverse for display)
        var renderedCount = <?php echo (int) count( $messages ); ?>;

        // ---- Helpers ----
        function esc(s){ return $('<span>').text(s).html(); }

        function fmtTime(ts) {
            if (!ts) return '—';
            var d = new Date(ts * 1000);
            var pad = function(n){ return n < 10 ? '0'+n : n; };
            return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())
                   +' '+pad(d.getHours())+':'+pad(d.getMinutes());
        }

        function buildRow(msg, index) {
            var isAdmin = !!msg.is_admin;
            var badge   = isAdmin
                ? '<span style="background:#fff3cd;color:#856404;padding:2px 7px;border-radius:10px;font-size:11px;">Admin</span>'
                : '<span style="background:#f0f0f0;color:#555;padding:2px 7px;border-radius:10px;font-size:11px;">User</span>';
            return '<tr id="lsg-chat-row-'+index+'">'
                + '<td style="color:#888;font-size:12px;">'+esc(fmtTime(msg.timestamp||0))+'</td>'
                + '<td><strong>'+esc(msg.user||'—')+'</strong></td>'
                + '<td>'+esc(msg.text||'')+'</td>'
                + '<td>'+badge+'</td>'
                + '<td><button class="button button-small lsg-admin-del-msg"'
                +     ' data-index="'+index+'" style="color:#c00;border-color:#c00;">&#x2715;</button></td>'
                + '</tr>';
        }

        function removeEmptyRow() {
            $('#lsg-chat-empty-row').remove();
        }

        function maybeShowEmptyRow() {
            if ( $('#lsg-chat-admin-table tbody tr').length === 0 ) {
                $('#lsg-chat-admin-table tbody').html(
                    '<tr id="lsg-chat-empty-row"><td colspan="5" style="text-align:center;color:#999;">No chat messages yet.</td></tr>'
                );
            }
        }

        // ---- Load new messages from server (called by Ably + fallback poll) ----
        function loadNewMessages() {
            $.post(ajax, { action:'lsg_fetch_chat', _ajax_nonce:nonce }, function(res){
                if ( !res.success ) return;
                var msgs = res.data.messages || [];
                if ( msgs.length <= renderedCount ) return; // nothing new

                // New messages are at the end of the chronological array
                var newOnes = msgs.slice( renderedCount );
                var tbody   = $('#lsg-chat-admin-table tbody');
                removeEmptyRow();
                $.each( newOnes, function(i, msg){
                    var realIndex = renderedCount + i;
                    tbody.prepend( buildRow( msg, realIndex ) );
                });
                renderedCount = msgs.length;
            });
        }

        // ---- Send message ----
        function doSend() {
            var text = $('#lsg-admin-chat-input').val().trim();
            if ( !text ) return;
            $('#lsg-admin-chat-send').prop('disabled', true);
            $('#lsg-admin-chat-input').val('').focus();

            // Optimistic row — shown immediately
            var tempId  = 'lsg-chat-temp-' + Date.now();
            var fakeMsg = { user: adminName, text: text, timestamp: Math.floor(Date.now()/1000), is_admin: true };
            removeEmptyRow();
            $('#lsg-chat-admin-table tbody').prepend(
                '<tr id="'+tempId+'" style="opacity:.5;">'
                + '<td style="color:#888;font-size:12px;">'+esc(fmtTime(fakeMsg.timestamp))+'</td>'
                + '<td><strong>'+esc(adminName)+'</strong></td>'
                + '<td>'+esc(text)+'&nbsp;<em style="color:#999;font-size:11px;">sending…</em></td>'
                + '<td><span style="background:#fff3cd;color:#856404;padding:2px 7px;border-radius:10px;font-size:11px;">Admin</span></td>'
                + '<td>—</td>'
                + '</tr>'
            );

            $.post(ajax, { action:'lsg_admin_send_chat', message:text, _ajax_nonce:nonce }, function(res){
                $('#lsg-admin-chat-send').prop('disabled', false);
                if ( res.success ) {
                    // Replace temp row with proper confirmed row
                    var confirmedIndex = renderedCount;
                    renderedCount++;
                    $('#'+tempId).replaceWith( buildRow( fakeMsg, confirmedIndex ) );
                    showMsg('Sent ✓', '#1e8449');
                } else {
                    $('#'+tempId).css('opacity','1').find('td:nth-child(3) em').text('⚠ Failed').css('color','#c00');
                    showMsg('Error: '+(res.data||'unknown'), '#c00');
                }
            }).fail(function(){
                $('#lsg-admin-chat-send').prop('disabled', false);
                $('#'+tempId).css('opacity','1').find('td:nth-child(3) em').text('⚠ Failed').css('color','#c00');
            });
        }

        $('#lsg-admin-chat-send').on('click', doSend);
        $('#lsg-admin-chat-input').on('keydown', function(e){ if (e.key==='Enter') doSend(); });

        // ---- Delete single message ----
        $(document).on('click', '.lsg-admin-del-msg', function(){
            if ( !confirm('Delete this message?') ) return;
            var btn = $(this), idx = btn.data('index');

            // Loading state
            btn.prop('disabled', true).text('⏳ Deleting…').css({ 'color':'#888', 'border-color':'#ccc', 'width':'90px' });

            $.post(ajax, { action:'lsg_admin_delete_msg', index:idx, _ajax_nonce:nonce }, function(res){
                if ( res.success ) {
                    btn.closest('tr').fadeOut(300, function(){
                        $(this).remove();
                        renderedCount = Math.max(0, renderedCount - 1);
                        maybeShowEmptyRow();
                    });
                } else {
                    // Restore button on failure
                    btn.prop('disabled', false).html('&#x2715;').css({ 'color':'#c00', 'border-color':'#c00', 'width':'' });
                    showMsg('Delete failed.', '#c00');
                }
            }).fail(function(){
                btn.prop('disabled', false).html('&#x2715;').css({ 'color':'#c00', 'border-color':'#c00', 'width':'' });
            });
        });

        // ---- Clear all ----
        $('#lsg-admin-chat-clear').on('click', function(){
            $.post(ajax, { action:'lsg_admin_clear_chat', _ajax_nonce:nonce }, function(res){
                if ( res.success ) {
                    renderedCount = 0;
                    $('#lsg-chat-admin-table tbody').html(
                        '<tr id="lsg-chat-empty-row"><td colspan="5" style="text-align:center;color:#999;">No chat messages yet.</td></tr>'
                    );
                    showMsg('All messages cleared.', '#1e8449');
                }
            });
        });

        function showMsg(text, color){
            var el = $('#lsg-chat-admin-msg');
            el.text(text).css('color', color||'#333').show();
            setTimeout(function(){ el.fadeOut(); }, 3000);
        }

        // ---- Ably real-time subscription ----
        if ( ablyKey ) {
            var ablyScript = document.createElement('script');
            ablyScript.src = 'https://cdn.ably.com/lib/ably.min-1.js';
            ablyScript.async = true;
            ablyScript.onload = function(){
                try {
                    var ably = new Ably.Realtime(ablyKey);
                    ably.channels.get(ablyChan).subscribe('chat-message', function(){
                        loadNewMessages();
                    });
                } catch(e){ console.warn('[LSG Admin Chat] Ably error:', e.message); }
            };
            document.head.appendChild(ablyScript);
        }

        // ---- Fallback poll every 8s (catches messages when Ably is unavailable) ----
        setInterval(loadNewMessages, 8000);
    });
    </script>
    <?php
}

// ============================================================
// Products tab
// ============================================================
function lsg_admin_products_tab() {
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! empty( $_POST['create_live_sale_nonce'] )
         && wp_verify_nonce( $_POST['create_live_sale_nonce'], 'create_live_sale_action' ) ) {
        if ( lsg_handle_create_product() ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Product created.</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>❌ Error – check SKU is unique and all fields are valid.</p></div>';
        }
    }

    lsg_render_admin_summary();
    ?>
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
                <div id="lsg_giveaway_duration_wrap" style="display:none;background:#f9f2ff;border-radius:8px;padding:12px;margin-top:-5px;">
                    <label>Timer Duration (minutes) *<br><input type="number" name="giveaway_duration" min="1" value="5" style="width:100px;"></label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-top:10px;">
                        <input type="checkbox" name="giveaway_claimed_only" value="1" style="width:auto;">
                        <span style="font-size:13px;">Claimed users only can enter</span>
                    </label>
                </div>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_auction" id="lsg_is_auction" value="1" style="width:auto;">
                    <span>🔨 This is an Auction</span>
                </label>
                <div id="lsg_auction_wrap" style="display:none;background:#fef5e7;border-radius:8px;padding:12px;margin-top:-5px;">
                    <label>Base Price *<br><input type="number" name="auction_base_price" step="0.01" min="0" value="0" style="width:120px;"></label>
                    <label style="margin-top:10px;display:block;">Auction Duration (minutes) *<br><input type="number" name="auction_duration" min="1" value="5" style="width:100px;"></label>
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
        .lsg-row-pinned   { background:#fff9e6 !important; }
        .lsg-row-giveaway { background:#fdf2ff !important; }
        .lsg-saving { font-size:12px; animation:lsg-pulse 1s infinite; }
        .lsg-saved  { font-size:12px; animation:lsg-fade  1s ease-out forwards; }
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
                $.post(ajaxurl, { action: action, product_id: pid, value: value, _ajax_nonce: saveNonce }, function(r){
                    showIndicator(input, r.success);
                    if (r.success && r.data && r.data.html) {
                        $('#product-row-' + pid).replaceWith(r.data.html);
                    }
                }).fail(function(){ showIndicator(input, false); });
            }, 500);
        }

        $(document).on('blur', '.name_admin',      function(){ sendUpdate($(this), 'lsg_update_product_name',      $(this).val()); });
        $(document).on('blur', '.price_admin',     function(){ sendUpdate($(this), 'lsg_update_price',             parseFloat($(this).val())); });
        $(document).on('blur', '.stock_admin',     function(){ sendUpdate($(this), 'lsg_update_total_stock',       parseInt($(this).val())); });
        $(document).on('blur', '.available_admin', function(){ sendUpdate($(this), 'lsg_update_available_stock',   parseInt($(this).val())); });
        $(document).on('blur', '.claimed_admin',   function(){ sendUpdate($(this), 'lsg_update_claimed_users',     $(this).val()); });

        // Pin / Unpin
        $(document).on('click', '.lsg-pin-btn', function(){
            var btn = $(this), pid = btn.data('id'), n = btn.data('nonce');
            btn.prop('disabled', true);
            $.post(ajaxurl, { action: 'lsg_toggle_pin', product_id: pid, _ajax_nonce: n }, function(r){
                btn.prop('disabled', false);
                if (r.success && r.data && r.data.html) { $('#product-row-' + pid).replaceWith(r.data.html); }
                else { alert('Failed to pin/unpin.'); }
            });
        });

        // Delete
        $(document).on('click', '.lsg-delete-btn', function(){
            if (!confirm('Delete this product permanently?')) return;
            var btn = $(this);
            if (btn.prop('disabled')) return;
            var pid = btn.data('id'), n = btn.data('nonce');
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
            $.get(ajaxurl, { action: 'lsg_refresh_admin_list', page: 1, _ajax_nonce: '<?php echo wp_create_nonce( 'lsg_refresh_admin_list' ); ?>' }, function(html){
                $('#lsg-admin-list').html(html);
                btn.prop('disabled',false).text('🔄 Refresh');
            });
        });

        // Pagination
        $(document).on('click', '.admin-pagination-link', function(){
            var page = $(this).data('page');
            $.get(ajaxurl, { action: 'lsg_refresh_admin_list', page: page, _ajax_nonce: '<?php echo wp_create_nonce( 'lsg_refresh_admin_list' ); ?>' }, function(html){
                $('#lsg-admin-list').html(html);
            });
        });

        // Toggle giveaway duration field
        $('#lsg_is_giveaway').on('change', function(){
            $('#lsg_giveaway_duration_wrap').toggle(this.checked);
            if (this.checked) $('#lsg_is_auction').prop('checked', false).trigger('change');
        });

        // Toggle auction fields
        $('#lsg_is_auction').on('change', function(){
            $('#lsg_auction_wrap').toggle(this.checked);
            if (this.checked) $('#lsg_is_giveaway').prop('checked', false).trigger('change');
        });

        // Admin: Start Auction
        $(document).on('click', '.lsg-start-auction-btn', function(){
            var btn = $(this), pid = btn.data('id'), dur = parseInt(btn.data('duration'), 10);
            if (!confirm('Start ' + dur + '-minute auction for this product?')) return;
            btn.prop('disabled', true).text('Starting…');
            $.post(ajaxurl, { action: 'lsg_start_auction', product_id: pid, duration: dur, _ajax_nonce: saveNonce }, function(r){
                if (r.success && r.data.html) { $('#product-row-' + pid).replaceWith(r.data.html); }
                else { alert('Could not start auction.'); btn.prop('disabled', false).text('▶ Start Auction'); }
            });
        });

        // Admin: End Auction
        $(document).on('click', '.lsg-end-auction-btn', function(){
            var btn = $(this), pid = btn.data('id');
            if (!confirm('End auction and declare winner now?')) return;
            btn.prop('disabled', true).text('Ending…');
            $.post(ajaxurl, { action: 'lsg_end_auction', product_id: pid, _ajax_nonce: saveNonce }, function(r){
                if (r.success) {
                    alert('🔨 Winner: ' + r.data.winner);
                    if (r.data.html) $('#product-row-' + pid).replaceWith(r.data.html);
                } else {
                    alert(r.data || 'Could not end auction.');
                    btn.prop('disabled', false).text('🔨 End Auction');
                }
            });
        });

        // Auction admin countdown timers
        function updateAuctionAdminTimers() {
            var now = Math.floor(Date.now() / 1000);
            $('.lsg-admin-auction-timer[data-end]').each(function(){
                var el = $(this), end = parseInt(el.data('end'), 10), left = end - now;
                if (left <= 0) { el.text('⏰ EXPIRED'); }
                else { var m=Math.floor(left/60),s=left%60; el.text('⏱ '+(m<10?'0':'')+m+':'+(s<10?'0':'')+s+' remaining'); }
            });
        }
        setInterval(updateAuctionAdminTimers, 1000);

        // Admin: Start Giveaway
        $(document).on('click', '.lsg-start-giveaway-btn', function(){
            var btn = $(this), pid = btn.data('id'), dur = parseInt(btn.data('duration'), 10);
            if (!confirm('Start ' + dur + '-minute giveaway for this product?')) return;
            btn.prop('disabled', true).text('Starting…');
            $.post(ajaxurl, { action: 'lsg_start_giveaway', product_id: pid, duration: dur, _ajax_nonce: saveNonce }, function(r){
                if (r.success && r.data.html) { $('#product-row-' + pid).replaceWith(r.data.html); }
                else { alert('Could not start giveaway.'); btn.prop('disabled', false).text('▶ Start Giveaway'); }
            });
        });

        // Admin: Roll Winner
        $(document).on('click', '.lsg-roll-winner-btn', function(){
            var btn = $(this), pid = btn.data('id');
            if (!confirm('Roll the winner now?')) return;
            btn.prop('disabled', true).text('Rolling…');
            $.post(ajaxurl, { action: 'lsg_roll_giveaway_winner', product_id: pid, _ajax_nonce: saveNonce }, function(r){
                if (r.success) {
                    alert('🏆 Winner: ' + r.data.winner);
                    if (r.data.html) $('#product-row-' + pid).replaceWith(r.data.html);
                } else {
                    alert(r.data || 'Could not roll winner.');
                    btn.prop('disabled', false).text('🎲 Roll Winner');
                }
            });
        });

        // Giveaway admin countdown timers
        function updateAdminTimers() {
            var now = Math.floor(Date.now() / 1000);
            $('.lsg-admin-timer[data-end]').each(function(){
                var el = $(this), end = parseInt(el.data('end'), 10), left = end - now;
                if (left <= 0) { el.text('⏰ Expired — Roll Winner'); }
                else { var m=Math.floor(left/60),s=left%60; el.text('⏱ '+(m<10?'0':'')+m+':'+(s<10?'0':'')+s+' remaining'); }
            });
        }
        setInterval(updateAdminTimers, 1000);

        // Real-time admin row updates via Ably
        (function(){
            var ablyKey     = '<?php echo esc_js( ABLY_API_KEY ); ?>';
            var ablyChannel = '<?php echo esc_js( ABLY_PRODUCT_CHANNEL ); ?>';
            var rowNonce    = '<?php echo wp_create_nonce( 'live_sale_update' ); ?>';
            if (!ablyKey) return;
            function refreshAdminRow(pid) {
                $.post(ajaxurl, { action: 'lsg_refresh_admin_row', product_id: pid, _ajax_nonce: rowNonce }, function(r){
                    if (r.success && r.data && r.data.html) $('#product-row-' + pid).replaceWith(r.data.html);
                });
            }
            var s = document.createElement('script');
            s.src = 'https://cdn.ably.com/lib/ably.min-1.js';
            s.async = true;
            s.onload = function() {
                try {
                    var ably = new Ably.Realtime(ablyKey);
                    var ch   = ably.channels.get(ablyChannel);
                    ch.subscribe('product-updated',  function(m){ if (m.data && m.data.product_id) refreshAdminRow(m.data.product_id); });
                    ch.subscribe('giveaway-started', function(m){ if (m.data && m.data.product_id) refreshAdminRow(m.data.product_id); });
                    ch.subscribe('giveaway-winner',  function(m){ if (m.data && m.data.product_id) refreshAdminRow(m.data.product_id); });
                } catch(e) { console.warn('[LiveSale Admin] Ably init failed:', e.message); }
            };
            document.head.appendChild(s);
        })();

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

// ============================================================
// Claimed Users tab
// ============================================================
function lsg_admin_claimed_tab() {
    $cat_id = lsg_get_live_sale_category();
    if ( ! $cat_id ) { echo '<p>No live-sale category.</p>'; return; }

    $products = get_posts( [
        'post_type' => 'product', 'posts_per_page' => -1,
        'tax_query' => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ],
        'orderby' => 'date', 'order' => 'DESC',
    ] );

    echo '<h2 style="margin-top:20px;">Claimed Users</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Product</th><th>SKU</th><th>Price</th><th>Available</th><th>Claims</th><th>Claimed By</th></tr></thead><tbody>';

    $found = false;
    foreach ( $products as $p ) {
        $claimed = get_post_meta( $p->ID, 'claimed_users', true ) ?: [];
        $product = wc_get_product( $p->ID );
        if ( ! $product ) continue;
        $found     = true;
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

// ============================================================
// Giveaway tab
// ============================================================
function lsg_admin_giveaway_tab() {
    $cat_id = lsg_get_live_sale_category();
    if ( ! $cat_id ) { echo '<p>No live-sale category.</p>'; return; }

    $products = get_posts( [
        'post_type' => 'product', 'posts_per_page' => -1,
        'tax_query' => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ],
        'orderby' => 'date', 'order' => 'DESC',
        'meta_query' => [ [ 'key' => 'lsg_is_giveaway', 'value' => '1' ] ],
    ] );

    echo '<h2 style="margin-top:20px;">🎁 Giveaway Products</h2>';
    if ( empty( $products ) ) {
        echo '<p style="color:#999;">No giveaway products yet.</p>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr><th>Product</th><th>Status</th><th>Duration</th><th>Timer</th><th>Entrants</th><th>Winner</th><th>Actions</th></tr></thead><tbody>';
    $save_nonce = wp_create_nonce( 'live_sale_update' );

    foreach ( $products as $p ) {
        $product  = wc_get_product( $p->ID );
        if ( ! $product ) continue;
        $status   = (string) get_post_meta( $p->ID, 'lsg_giveaway_status',   true ) ?: 'idle';
        $duration = (int)    get_post_meta( $p->ID, 'lsg_giveaway_duration', true );
        $end_time = (int)    get_post_meta( $p->ID, 'lsg_giveaway_end_time', true );
        $entrants = get_post_meta( $p->ID, 'lsg_giveaway_entrants', true ) ?: [];
        $winner   = (string) get_post_meta( $p->ID, 'lsg_giveaway_winner',   true );

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
        function updateGATimers() {
            var now = Math.floor(Date.now()/1000);
            $('.lsg-admin-timer[data-end]').each(function(){
                var el=$(this), end=parseInt(el.data('end'),10), left=end-now;
                if(left<=0){el.text('⏰ Expired');}
                else{var m=Math.floor(left/60),s=left%60;el.text((m<10?'0':'')+m+':'+(s<10?'0':'')+s+' left');}
            });
        }
        setInterval(updateGATimers, 1000);
    });
    </script>
    <?php
}

// ============================================================
// Waitlist tab
// ============================================================
function lsg_admin_waitlist_tab() {
    $cat_id = lsg_get_live_sale_category();
    if ( ! $cat_id ) { echo '<p>No live-sale category.</p>'; return; }

    $products = get_posts( [
        'post_type' => 'product', 'posts_per_page' => -1,
        'tax_query' => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ],
    ] );

    echo '<h2>Waitlists</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Product</th><th>SKU</th><th>Available Stock</th><th>Waitlist Users</th></tr></thead><tbody>';

    $found = false;
    foreach ( $products as $p ) {
        $waitlist = get_post_meta( $p->ID, 'lsg_waitlist', true ) ?: [];
        if ( empty( $waitlist ) ) continue;
        $found    = true;
        $product  = wc_get_product( $p->ID );
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

// ============================================================
// Admin summary (dashboard stats cards)
// ============================================================
function lsg_render_admin_summary() : void {
    $cat_id = lsg_get_live_sale_category();
    $total_products = $total_stock = $available_stock_sum = $total_claims = $total_waitlisted = 0;
    $total_claimed_value = 0.0;

    if ( $cat_id ) {
        $ids = get_posts( [
            'post_type' => 'product', 'posts_per_page' => -1,
            'tax_query' => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ],
            'fields' => 'ids',
        ] );
        $total_products = count( $ids );
        foreach ( $ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) continue;
            $total_stock         += max( 0, (int) $product->get_stock_quantity() );
            $available_stock_sum += max( 0, (int) get_post_meta( $pid, 'available_stock', true ) );
            $claimed              = get_post_meta( $pid, 'claimed_users', true ) ?: [];
            $claim_count          = count( $claimed );
            $total_claims        += $claim_count;
            $total_waitlisted    += count( get_post_meta( $pid, 'lsg_waitlist', true ) ?: [] );
            $total_claimed_value += (float) $product->get_price() * $claim_count;
        }
    }
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin:20px 0;">
        <?php foreach ( [
            [ '📦', $total_products,                                   'Products' ],
            [ '📊', $total_stock,                                      'Total Stock' ],
            [ '✅', $available_stock_sum,                              'Available' ],
            [ '👥', $total_claims,                                     'Claims' ],
            [ '⏳', $total_waitlisted,                                  'Waitlisted' ],
            [ '💰', strip_tags( wc_price( $total_claimed_value ) ),    'Total Claimed Value' ],
        ] as $card ) : ?>
        <div style="background:#fff;border-radius:10px;padding:18px;box-shadow:0 2px 6px rgba(0,0,0,.07);display:flex;align-items:center;gap:12px;">
            <span style="font-size:28px;"><?php echo $card[0]; ?></span>
            <div>
                <div style="font-size:<?php echo $card[2] === 'Total Claimed Value' ? '16px' : '22px'; ?>;font-weight:700;color:#00483e;"><?php echo esc_html( $card[1] ); ?></div>
                <div style="color:#777;font-size:12px;"><?php echo esc_html( $card[2] ); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}

// ============================================================
// Product creation handler
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

        $is_giveaway           = ! empty( $_POST['is_giveaway'] ) ? 1 : 0;
        $giveaway_duration     = max( 1, (int) ( $_POST['giveaway_duration'] ?? 5 ) );
        $giveaway_claimed_only = ! empty( $_POST['giveaway_claimed_only'] ) ? 1 : 0;
        update_post_meta( $pid, 'lsg_is_giveaway',           $is_giveaway );
        update_post_meta( $pid, 'lsg_giveaway_duration',     $giveaway_duration );
        update_post_meta( $pid, 'lsg_giveaway_claimed_only', $giveaway_claimed_only );
        update_post_meta( $pid, 'lsg_giveaway_status',       'idle' );
        update_post_meta( $pid, 'lsg_giveaway_end_time',     0 );
        update_post_meta( $pid, 'lsg_giveaway_entrants',     [] );
        update_post_meta( $pid, 'lsg_giveaway_winner',       '' );

        $is_auction       = ! empty( $_POST['is_auction'] ) ? 1 : 0;
        $auction_base     = max( 0, (float) ( $_POST['auction_base_price'] ?? 0 ) );
        $auction_duration = max( 1, (int) ( $_POST['auction_duration'] ?? 5 ) );
        update_post_meta( $pid, 'lsg_is_auction',          $is_auction );
        update_post_meta( $pid, 'lsg_auction_base_price',  $auction_base );
        update_post_meta( $pid, 'lsg_auction_duration',    $auction_duration );
        update_post_meta( $pid, 'lsg_auction_status',      'idle' );
        update_post_meta( $pid, 'lsg_auction_end_time',    0 );
        update_post_meta( $pid, 'lsg_auction_current_bid', 0 );
        update_post_meta( $pid, 'lsg_auction_current_bidder', '' );
        update_post_meta( $pid, 'lsg_auction_bids',        [] );

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
// Setup tab — create pages + navigation menu
// ============================================================
function lsg_admin_setup_tab() {
    $nonce_action = 'lsg_setup_site';
    $message      = '';
    $message_type = 'info';

    if ( isset( $_POST['lsg_run_setup'] ) ) {
        if ( ! check_admin_referer( $nonce_action ) ) {
            wp_die( 'Security check failed.' );
        }
        [ $message, $message_type ] = lsg_run_site_setup();
    }

    // Determine if setup is already complete
    $all_pages_exist = true;
    foreach ( lsg_setup_page_definitions() as $def ) {
        if ( ! get_page_by_path( $def['slug'] ) ) {
            $all_pages_exist = false;
            break;
        }
    }
    $menu_exists     = (bool) get_term_by( 'name', 'Main Menu', 'nav_menu' );
    $front_page_set  = get_option( 'show_on_front' ) === 'page' && (int) get_option( 'page_on_front' ) > 0;
    $setup_done      = $all_pages_exist && $menu_exists && $front_page_set;

    ?>
    <div style="max-width:700px;margin-top:24px;">
        <h2>&#9881; Site Setup</h2>
        <p style="color:#555;">
            Creates all necessary pages and builds the primary navigation menu to match the original EM Garden site layout.
            Safe to run multiple times — existing pages are left untouched.
        </p>

        <?php if ( $message ) : ?>
            <div class="notice notice-<?php echo esc_attr( $message_type ); ?> is-dismissible" style="padding:10px 14px;">
                <p><?php echo wp_kses_post( $message ); ?></p>
            </div>
        <?php endif; ?>

        <table class="widefat" style="margin-bottom:16px;">
            <thead>
                <tr>
                    <th>Page</th><th>Slug</th><th>Content</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( lsg_setup_page_definitions() as $def ) :
                    $exists = get_page_by_path( $def['slug'] );
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $def['title'] ); ?></strong></td>
                    <td><code>/<?php echo esc_html( $def['slug'] ); ?>/</code></td>
                    <td><?php echo $exists ? '<span style="color:green;">&#10003; Exists</span>' : '<span style="color:#888;">Will be created</span>'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post">
            <?php wp_nonce_field( $nonce_action ); ?>
            <?php if ( $setup_done ) : ?>
            <p style="color:green;font-weight:600;">&#10003; Setup is already complete — all pages and the navigation menu exist.</p>
            <input type="submit" name="lsg_run_setup" class="button button-primary button-large"
                   value="&#9881; Re-run Setup (Force Update Page Content)">
            <?php else : ?>
            <input type="submit" name="lsg_run_setup" class="button button-primary button-large"
                   value="&#9881; Run Setup — Create Pages &amp; Navigation Menu">
            <?php endif; ?>
        </form>

        <hr style="margin:28px 0;">
        <h3>Navigation Structure</h3>
        <ul style="list-style:disc;margin-left:20px;color:#333;line-height:2;">
            <li>Home</li>
            <li>About <em style="color:#888;">(dropdown)</em>
                <ul style="list-style:circle;margin-left:20px;">
                    <li>FAQ</li>
                    <li>Terms and Conditions</li>
                    <li>Shipping Program</li>
                    <li>Live Sales 101</li>
                    <li>Acclimating Guide 101</li>
                </ul>
            </li>
            <li>Live Sales</li>
            <li>Shop</li>
            <li>My Account</li>
        </ul>
    </div>
    <?php
}

/**
 * Returns the page definitions used by the setup routine.
 */
function lsg_setup_page_definitions() {
    return [
        [
            'title'   => 'Home',
            'slug'    => 'home',
            'content' => '[lsg_hero][lsg_home_products][lsg_home_cta]',
        ],
        [
            'title'   => 'Live Sales',
            'slug'    => 'live-sales',
            'content' => '[lsg_live_view video_url="' . plugins_url( 'livesale/assets/videos/233382_medium.mp4' ) . '"]',
        ],
        [
            'title'   => 'FAQ',
            'slug'    => 'faq',
            'content' => '<!-- Add your FAQ content here -->',
        ],
        [
            'title'   => 'Terms and Conditions',
            'slug'    => 'terms-and-conditions',
            'content' => '<!-- Add your Terms and Conditions here -->',
        ],
        [
            'title'   => 'Shipping Program',
            'slug'    => 'shipping-program',
            'content' => '<!-- Add your Shipping Program details here -->',
        ],
        [
            'title'   => 'Live Sales 101',
            'slug'    => 'live-sales-101',
            'content' => '<!-- Add your Live Sales 101 guide here -->',
        ],
        [
            'title'   => 'Acclimating Guide 101',
            'slug'    => 'acclimating-guide-101',
            'content' => '<!-- Add your Acclimating Guide here -->',
        ],
    ];
}

/**
 * Creates pages and the primary navigation menu.
 *
 * @return array [ string $message, string $notice_type ]
 */
function lsg_run_site_setup() {
    $log   = [];
    $pages = lsg_setup_page_definitions();

    // --- 1. Create pages ---
    $page_ids = [];
    foreach ( $pages as $def ) {
        $existing = get_page_by_path( $def['slug'] );
        if ( $existing ) {
            $page_ids[ $def['slug'] ] = $existing->ID;
            // Update content in case it changed (e.g. video_url added)
            wp_update_post( [
                'ID'           => $existing->ID,
                'post_content' => $def['content'],
            ] );
            $log[] = 'Updated page: <strong>' . esc_html( $def['title'] ) . '</strong>';
        } else {
            $id = wp_insert_post( [
                'post_title'   => $def['title'],
                'post_name'    => $def['slug'],
                'post_content' => $def['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ] );
            if ( is_wp_error( $id ) ) {
                $log[] = 'Error creating page <strong>' . esc_html( $def['title'] ) . '</strong>: ' . esc_html( $id->get_error_message() );
            } else {
                $page_ids[ $def['slug'] ] = $id;
                $log[] = 'Created page: <strong>' . esc_html( $def['title'] ) . '</strong>';
            }
        }
    }

    // --- Set Home page as static front page ---
    if ( isset( $page_ids['home'] ) ) {
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', $page_ids['home'] );
        $log[] = 'Set <strong>Home</strong> page as the static front page.';
    }

    // WooCommerce pages (Shop, My Account)
    $wc_pages = [
        'shop'       => (int) get_option( 'woocommerce_shop_page_id' ),
        'my-account' => (int) get_option( 'woocommerce_myaccount_page_id' ),
    ];
    foreach ( $wc_pages as $slug => $pid ) {
        if ( $pid > 0 ) {
            $page_ids[ $slug ] = $pid;
        }
    }

    // --- 2. Create / update the nav menu ---
    $menu_name = 'Main Menu';
    $menu_id   = 0;

    $existing_menus = get_terms( [ 'taxonomy' => 'nav_menu', 'hide_empty' => false ] );
    foreach ( $existing_menus as $menu ) {
        if ( $menu->name === $menu_name ) {
            $menu_id = (int) $menu->term_id;
            break;
        }
    }

    if ( ! $menu_id ) {
        $result = wp_create_nav_menu( $menu_name );
        if ( is_wp_error( $result ) ) {
            return [ 'Failed to create navigation menu: ' . esc_html( $result->get_error_message() ), 'error' ];
        }
        $menu_id = (int) $result;
        $log[]   = 'Created navigation menu: <strong>' . esc_html( $menu_name ) . '</strong>';
    } else {
        // Clear existing items so we start fresh
        $existing_items = wp_get_nav_menu_items( $menu_id );
        if ( $existing_items ) {
            foreach ( $existing_items as $item ) {
                wp_delete_post( $item->ID, true );
            }
        }
        $log[] = 'Rebuilt navigation menu: <strong>' . esc_html( $menu_name ) . '</strong>';
    }

    // Helper: add a page item to the menu
    $add_item = function( $page_slug, $label, $parent_id = 0, $position = 0 ) use ( $menu_id, &$page_ids ) {
        $object_id = isset( $page_ids[ $page_slug ] ) ? $page_ids[ $page_slug ] : 0;
        $args = [
            'menu-item-title'     => $label,
            'menu-item-status'    => 'publish',
            'menu-item-position'  => $position,
            'menu-item-parent-id' => $parent_id,
        ];
        if ( $object_id ) {
            $args['menu-item-object']    = 'page';
            $args['menu-item-object-id'] = $object_id;
            $args['menu-item-type']      = 'post_type';
        } else {
            // Fallback to home URL if page not found (e.g., Home)
            $args['menu-item-type'] = 'custom';
            $args['menu-item-url']  = home_url( '/' . $page_slug . '/' );
        }
        return (int) wp_update_nav_menu_item( $menu_id, 0, $args );
    };

    // Top-level item: Home first
    $add_item( 'home', 'Home', 0, 1 );

    // About (parent — custom # link so dropdown opener doesn't navigate)
    $about_id = (int) wp_update_nav_menu_item( $menu_id, 0, [
        'menu-item-title'    => 'About',
        'menu-item-type'     => 'custom',
        'menu-item-url'      => '#',
        'menu-item-status'   => 'publish',
        'menu-item-position' => 2,
    ] );

    // About sub-items
    $add_item( 'faq',                  'FAQ',                   $about_id, 1 );
    $add_item( 'terms-and-conditions', 'Terms and Conditions',  $about_id, 2 );
    $add_item( 'shipping-program',     'Shipping Program',      $about_id, 3 );
    $add_item( 'live-sales-101',       'Live Sales 101',        $about_id, 4 );
    $add_item( 'acclimating-guide-101','Acclimating Guide 101', $about_id, 5 );

    // Remaining top-level items
    $add_item( 'live-sales',  'Live Sales', 0, 3 );
    $add_item( 'shop',        'Shop',       0, 4 );
    $add_item( 'my-account',  'My Account', 0, 5 );

    // --- 3. Assign menu to Botiga 'primary' location ---
    $locations = get_theme_mod( 'nav_menu_locations', [] );
    $locations['primary'] = $menu_id;
    set_theme_mod( 'nav_menu_locations', $locations );
    $log[] = 'Assigned menu to Botiga <strong>Primary</strong> location.';

    $summary = '<ul style="margin:.5em 0 0 1.2em;list-style:disc;">'
        . implode( '', array_map( fn( $l ) => '<li>' . $l . '</li>', $log ) )
        . '</ul>';

    return [ '&#10003; Setup complete!<br>' . $summary, 'success' ];
}
