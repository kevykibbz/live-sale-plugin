(function($){
    var AJAX         = lsgGrid.ajax;
    var NONCE        = lsgGrid.nonce;
    var USERNAME     = lsgGrid.username || '';
    var SOCKETIO_URL     = lsgGrid.socketio_url;
    var SOCKETIO_CHANNEL = lsgGrid.socketio_channel;

    // ── Auto nonce refresh on 403 ─────────────────────────────
    var _nonceRefreshing = false;
    $(document).ajaxError(function(event, jqXHR, settings, err) {
        if (jqXHR.status === 403 && !_nonceRefreshing) {
            _nonceRefreshing = true;
            console.warn('[LiveSale Grid] 403 detected — refreshing nonce…');
            $.get(AJAX, { action: 'lsg_refresh_nonce' }, function(r) {
                if (r && r.success) {
                    NONCE = r.data.nonce;
                    console.log('[LiveSale Grid] Nonce refreshed.');
                }
                _nonceRefreshing = false;
            }).fail(function() { _nonceRefreshing = false; });
        }
    });

    // Track which product IDs have already had winner rolled this session.
    // Stored outside DOM so the flag survives refreshCard() replacing the element.
    var rolledProductIds = {};

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

    var gridInitialised = false;

    function loadGrid() {
        if (!gridInitialised) {
            showSkeletons(4); // only on first load — never wipe existing cards with skeletons
        }
        $.get(AJAX, { action: 'lsg_get_products', _ajax_nonce: NONCE }, function(r){
            if (r.success) {
                $('#lsg-grid').html(r.data.html);
                gridInitialised = true;
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
                if (!rolledProductIds[pid]) {
                    rolledProductIds[pid] = true;
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

    // Track when Socket.io last handled an update to avoid double-rendering
    var lastSocketUpdate = 0;

    // ── WebSocket status indicator ────────────────────────────
    function setWsStatus(state) {
        var el = document.getElementById('lsg-ws-status');
        if (!el) return;
        el.className = 'lsg-ws-' + state;
        var labels = {
            connecting: '&#9679; WebSocket connecting&hellip;',
            connected:  '&#9679; WebSocket Live &#10003;',
            polling:    '&#9679; Polling mode (WebSocket offline)',
            error:      '&#9679; WebSocket error &mdash; using polling'
        };
        el.innerHTML = labels[state] || state;
        console.log('[LiveSale Grid] WS status:', state);
    }

    // Version polling fallback — catches any real-time events Socket.io may have missed.
    // Polls a lightweight version endpoint every 8 seconds; reloads grid only when version advances.
    var knownVersion = lsgGrid.init_version || 0;
    setInterval(function(){
        $.get(AJAX, { action: 'lsg_get_global_version', _ajax_nonce: NONCE }, function(r){
            if (r.success && r.data.version > knownVersion) {
                knownVersion = r.data.version;
                // Skip reload if Socket.io already handled an update in the last 10 seconds
                if (Date.now() - lastSocketUpdate > 10000) {
                    loadGrid();
                }
            }
        });
    }, 8000);

    // Load Socket.io client non-blocking
    if (SOCKETIO_URL) {
        console.log('[LiveSale Grid] Socket.io URL:', SOCKETIO_URL);
        setWsStatus('connecting');
        var socketioScript = document.createElement('script');
        socketioScript.src = SOCKETIO_URL + '/socket.io/socket.io.js';
        socketioScript.async = true;
        socketioScript.onload = function() {
            try {
                var socket = io(SOCKETIO_URL, { transports: ['websocket', 'polling'] });
                socket.emit('join', SOCKETIO_CHANNEL);

                socket.on('connect', function() {
                    var transport = socket.io.engine.transport.name;
                    console.log('[LiveSale Grid] Socket.io connected via', transport, '| id:', socket.id);
                    setWsStatus(transport === 'websocket' ? 'connected' : 'polling');
                });

                socket.on('disconnect', function(reason) {
                    console.warn('[LiveSale Grid] Socket.io disconnected:', reason);
                    setWsStatus('polling');
                });

                socket.on('connect_error', function(err) {
                    console.error('[LiveSale Grid] Socket.io connection error:', err.message);
                    setWsStatus('error');
                });

                socket.on('upgrade', function() {
                    var transport = socket.io.engine.transport.name;
                    console.log('[LiveSale Grid] Socket.io upgraded to', transport);
                    setWsStatus(transport === 'websocket' ? 'connected' : 'polling');
                });

                socket.on('product-updated', function(data){
                    lastSocketUpdate = Date.now();
                    if (data && data.product_id) {
                        var transport = socket.io.engine.transport.name;
                        if (data.claimed_by) {
                            console.log(
                                '%c[LiveSale] ⚡ WebSocket (' + transport + ') → product-updated | product #' + data.product_id +
                                ' claimed by "' + data.claimed_by + '" | stock left: ' + data.available,
                                'color:#27ae60;font-weight:bold'
                            );
                        } else {
                            console.log('[LiveSale] ⚡ WebSocket (' + transport + ') → product-updated | product #' + data.product_id);
                        }
                        refreshCard(data.product_id);
                        // Skip notification if this user triggered the event (they already got an AJAX toast)
                        if (data.notification && data.claimer !== USERNAME) {
                            notify(data.notification, data.notif_type || 'info');
                        }
                    }
                });
                socket.on('new_product', function(){
                    lastSocketUpdate = Date.now();
                    loadGrid();
                });
                socket.on('giveaway-started', function(data){
                    lastSocketUpdate = Date.now();
                    if (data && data.product_id) {
                        notify('\uD83C\uDF81 Giveaway started for ' + (data.name || 'a product') + '!', 'info');
                        refreshCard(data.product_id);
                    }
                });
                socket.on('giveaway-restarted', function(data){
                    lastSocketUpdate = Date.now();
                    if (data && data.product_id) {
                        var count = data.restart_count || 1;
                        notify('\uD83D\uDD04 Giveaway extended \xD7' + count + ' \u2014 No entries yet!', 'warning');
                        refreshCard(data.product_id);
                    }
                });
                socket.on('giveaway-winner', function(data){
                    lastSocketUpdate = Date.now();
                    if (data && data.product_id) {
                        if (USERNAME && data.winner === USERNAME) {
                            notify('\uD83C\uDF89 You Won the giveaway!', 'success');
                        } else {
                            notify('\uD83C\uDFC6 Winner: ' + data.winner + '!', 'info');
                        }
                        refreshCard(data.product_id);
                    }
                });
                socket.on('auction-started', function(data){
                    lastSocketUpdate = Date.now();
                    if (data && data.product_id) {
                        notify('\uD83D\uDD28 Auction started for ' + (data.name || 'a product') + '!', 'info');
                        refreshCard(data.product_id);
                    }
                });
                socket.on('auction-bid', function(data){
                    lastSocketUpdate = Date.now();
                    if (data && data.product_id) {
                        if (USERNAME && data.bidder === USERNAME) {
                            notify('Your bid placed: ' + data.bid, 'success');
                        } else {
                            notify('\uD83D\uDD28 ' + data.bidder + ' bid ' + data.bid, 'info');
                        }
                        refreshCard(data.product_id);
                    }
                });
            } catch(e) { console.warn('[LiveSale] Socket.io init failed:', e.message); setWsStatus('error'); }
        };
        socketioScript.onerror = function() {
            console.error('[LiveSale Grid] Failed to load socket.io.js from', SOCKETIO_URL);
            setWsStatus('error');
        };
        document.head.appendChild(socketioScript);
    } else {
        console.warn('[LiveSale Grid] No SOCKETIO_URL configured — running in polling-only mode.');
        setWsStatus('polling');
    }

})(jQuery);
