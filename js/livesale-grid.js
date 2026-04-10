(function($){
    var AJAX     = lsgGrid.ajax;
    var NONCE    = lsgGrid.nonce;
    var USERNAME = lsgGrid.username || '';
    var ABLY_KEY     = lsgGrid.ably_key;
    var ABLY_CHANNEL = lsgGrid.ably_channel;

    function notify(msg, type) {
        type = type || 'info';
        var el = $('<div class="lsg-notif lsg-notif-' + type + '">' + $('<span>').text(msg).html() + '</div>');
        $('#lsg-notifications').append(el);
        setTimeout(function(){ el.fadeOut(400, function(){ $(this).remove(); }); }, 4000);
    }

    function showSkeletons(count) {
        var skeletons = '';
        for (var i = 0; i < count; i++) {
            skeletons += '<div class="lsg-skeleton">'
                + '<div class="lsg-skeleton-img"></div>'
                + '<div class="lsg-skeleton-body">'
                + '<div class="lsg-skeleton-line medium"></div>'
                + '<div class="lsg-skeleton-line short"></div>'
                + '<div class="lsg-skeleton-line short"></div>'
                + '<div class="lsg-skeleton-line full"></div>'
                + '<div class="lsg-skeleton-btn"></div>'
                + '</div></div>';
        }
        $('#lsg-grid').html(skeletons);
    }

    function loadGrid() {
        showSkeletons(4);
        $.get(AJAX, { action: 'lsg_get_products', _ajax_nonce: NONCE }, function(r){
            if (r.success) {
                $('#lsg-grid').html(r.data.html);
                updateTimers();
            } else {
                $('#lsg-grid').html('<p>Could not load products.</p>');
            }
        });
    }

    function refreshCard(pid) {
        $.get(AJAX, { action: 'lsg_get_product_card', product_id: pid, _ajax_nonce: NONCE }, function(r){
            if (r.success && r.data.html) {
                var existing = $('#product-' + pid);
                if (existing.length) {
                    existing.replaceWith(r.data.html);
                    updateTimers(); // Initialize timer on refreshed card
                } else {
                    loadGrid();
                }
            } else loadGrid();
        });
    }

    // Claim Now
    $(document).on('click', '.claim-now', function(){
        var btn = $(this);
        if (btn.prop('disabled')) return;
        var pid = btn.data('id');
        btn.prop('disabled', true).text('Claiming\u2026');
        $.post(AJAX, { action: 'lsg_claim_product', product_id: pid, _ajax_nonce: NONCE }, function(r){
            if (r.success) {
                notify(r.data.notification || 'Claimed!', 'success');
                refreshCard(pid);
            } else {
                notify(r.data || 'Could not claim.', 'error');
                btn.prop('disabled', false).text('Claim Now');
            }
        }).fail(function(){
            notify('Network error.', 'error');
            btn.prop('disabled', false).text('Claim Now');
        });
    });

    // Join Waitlist
    $(document).on('click', '.join-waitlist', function(){
        var btn = $(this);
        if (btn.prop('disabled')) return;
        var pid = btn.data('id');
        btn.prop('disabled', true).text('Adding\u2026');
        $.post(AJAX, { action: 'lsg_join_waitlist', product_id: pid, _ajax_nonce: NONCE }, function(r){
            if (r.success) {
                notify(r.data.notification || 'Added to waitlist!', 'info');
                refreshCard(pid);
            } else {
                notify(r.data || 'Could not join waitlist.', 'error');
                btn.prop('disabled', false).text('Join Waitlist');
            }
        }).fail(function(){
            notify('Network error.', 'error');
            btn.prop('disabled', false).text('Join Waitlist');
        });
    });

    // Enter Giveaway
    $(document).on('click', '.enter-giveaway', function(){
        var btn = $(this);
        if (btn.prop('disabled')) return;
        var pid = btn.data('id');
        btn.prop('disabled', true).text('Entering\u2026');
        $.post(AJAX, { action: 'lsg_enter_giveaway', product_id: pid, _ajax_nonce: NONCE }, function(r){
            if (r.success) {
                notify('\uD83C\uDF9F You\'re in the giveaway! Good luck!', 'success');
                refreshCard(pid);
            } else {
                notify(r.data || 'Could not enter giveaway.', 'error');
                btn.prop('disabled', false).text('\uD83C\uDF9F Enter Giveaway');
            }
        }).fail(function(){
            notify('Network error.', 'error');
            btn.prop('disabled', false).text('\uD83C\uDF9F Enter Giveaway');
        });
    });

    // Place Bid (Auction)
    $(document).on('click', '.place-bid', function(){
        var btn = $(this);
        if (btn.prop('disabled')) return;
        var pid = btn.data('id');
        var input = $('.lsg-bid-input[data-id="' + pid + '"]');
        var bidAmount = parseFloat(input.val());
        
        if (!bidAmount || bidAmount <= 0) {
            notify('Please enter a valid bid amount.', 'error');
            return;
        }
        
        var originalText = btn.text();
        btn.prop('disabled', true).text('Placing Bid\u2026');
        
        $.post(AJAX, { 
            action: 'lsg_place_bid', 
            product_id: pid, 
            bid: bidAmount,
            _ajax_nonce: NONCE 
        }, function(r){
            if (r.success) {
                notify('\uD83D\uDD28 Bid placed successfully!', 'success');
                input.val(''); // Clear input
                refreshCard(pid);
            } else {
                notify(r.data || 'Could not place bid.', 'error');
                btn.prop('disabled', false).text(originalText);
            }
        }).fail(function(){
            notify('Network error.', 'error');
            btn.prop('disabled', false).text(originalText);
        });
    });

    // Giveaway & Auction countdown timers
    function updateTimers() {
        var now = Math.floor(Date.now() / 1000);
        $('.lsg-giveaway-timer[data-end], .lsg-auction-timer[data-end]').each(function(){
            var el   = $(this);
            var end  = parseInt(el.data('end'), 10);
            var pid  = el.data('id');
            var left = end - now;
            
            if (left <= 0) {
                el.find('.lsg-timer-count').text('00:00');
                if (!el.data('rolled')) {
                    el.data('rolled', true);
                    $.post(AJAX, { action: 'lsg_auto_roll_winner', product_id: pid, _ajax_nonce: NONCE }, function(r){
                        if (r.success && r.data && r.data.winner) {
                            if (USERNAME && r.data.winner === USERNAME) {
                                notify('\uD83C\uDF89 You Won the giveaway!', 'success');
                            } else {
                                notify('\uD83C\uDFC6 Giveaway winner: ' + r.data.winner + '!', 'info');
                            }
                        }
                        refreshCard(pid);
                    });
                }
            } else {
                var mins = Math.floor(left / 60);
                var secs = left % 60;
                el.find('.lsg-timer-count').text(
                    (mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs
                );
            }
        });
    }
    updateTimers(); // Initialize timers immediately
    setInterval(updateTimers, 1000);

    // Load grid immediately
    loadGrid();

    // Version polling fallback — catches any real-time events Ably may have missed.
    // Polls a lightweight version endpoint every 8 seconds; reloads grid only when version advances.
    var knownVersion = lsgGrid.init_version || 0;
    setInterval(function(){
        $.get(AJAX, { action: 'lsg_get_global_version', _ajax_nonce: NONCE }, function(r){
            if (r.success && r.data.version > knownVersion) {
                knownVersion = r.data.version;
                loadGrid();
            }
        });
    }, 8000);

    // Load Ably non-blocking
    var ablyScript = document.createElement('script');
    ablyScript.src = 'https://cdn.ably.com/lib/ably.min-1.js';
    ablyScript.async = true;
    ablyScript.onload = function() {
        try {
            var ably = new Ably.Realtime(ABLY_KEY);
            var ch   = ably.channels.get(ABLY_CHANNEL);
            ch.subscribe('product-updated', function(msg){
                if (msg.data && msg.data.product_id) {
                    refreshCard(msg.data.product_id);
                    // Skip notification if this user triggered the event (they already got an AJAX toast)
                    if (msg.data.notification && msg.data.claimer !== USERNAME) {
                        notify(msg.data.notification, msg.data.notif_type || 'info');
                    }
                }
            });
            ch.subscribe('new_product', function(){ loadGrid(); });
            ch.subscribe('giveaway-started', function(msg){
                if (msg.data && msg.data.product_id) {
                    notify('\uD83C\uDF81 Giveaway started for ' + (msg.data.name || 'a product') + '!', 'info');
                    refreshCard(msg.data.product_id);
                }
            });
            ch.subscribe('giveaway-restarted', function(msg){
                if (msg.data && msg.data.product_id) {
                    var count = msg.data.restart_count || 1;
                    notify('\uD83D\uDD04 Giveaway extended ×' + count + ' — No entries yet!', 'warning');
                    refreshCard(msg.data.product_id);
                }
            });
            ch.subscribe('giveaway-winner', function(msg){
                if (msg.data && msg.data.product_id) {
                    if (USERNAME && msg.data.winner === USERNAME) {
                        notify('\uD83C\uDF89 You Won the giveaway!', 'success');
                    } else {
                        notify('\uD83C\uDFC6 Winner: ' + msg.data.winner + '!', 'info');
                    }
                    refreshCard(msg.data.product_id);
                }
            });
            ch.subscribe('auction-started', function(msg){
                if (msg.data && msg.data.product_id) {
                    notify('\uD83D\uDD28 Auction started for ' + (msg.data.name || 'a product') + '!', 'info');
                    refreshCard(msg.data.product_id);
                }
            });
            ch.subscribe('auction-bid', function(msg){
                if (msg.data && msg.data.product_id) {
                    var bidMsg = '\uD83D\uDD28 ' + msg.data.bidder + ' bid ' + msg.data.bid;
                    if (USERNAME && msg.data.bidder === USERNAME) {
                        notify('Your bid placed: ' + msg.data.bid, 'success');
                    } else {
                        notify(bidMsg, 'info');
                    }
                    refreshCard(msg.data.product_id);
                }
            });
        } catch(e) { console.warn('[LiveSale] Ably init failed:', e.message); }
    };
    document.head.appendChild(ablyScript);

})(jQuery);
