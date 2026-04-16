jQuery(function($){
    var AJAX         = lsgChat.ajax;
    var IS_ADMIN     = lsgChat.is_admin;
    var chatNonce    = lsgChat.nonce;
    var SOCKETIO_URL     = lsgChat.socketio_url;
    var SOCKETIO_CHANNEL = lsgChat.socketio_channel;

    function esc(s){ return $('<span>').text(s).html(); }

    var AVATAR_COLORS = ['#e74c3c','#3b82f6','#2ecc71','#f39c12','#9b59b6','#1abc9c','#e67e22','#ec4899','#06b6d4','#8b5cf6'];
    function avatarColor(name) {
        var hash = 0;
        for (var i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
        return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
    }

    function formatTime(ts) {
        if (!ts) return '';
        var now  = Math.floor(Date.now() / 1000);
        var diff = now - ts;
        if (diff < 60)  return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) {
            var d = new Date(ts * 1000);
            var h = d.getHours(), m = d.getMinutes();
            var ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            return h + ':' + (m < 10 ? '0' + m : m) + ' ' + ampm;
        }
        var d = new Date(ts * 1000);
        return (d.getMonth()+1) + '/' + d.getDate() + ' ' + d.getHours() + ':' + (d.getMinutes()<10?'0':'') + d.getMinutes();
    }

    function appendMsg(index, user, msg, ts, autoRemove) {
        var color   = avatarColor(user);
        var isAdmin = (user === 'Admin');
        var badge   = isAdmin ? ' <span class="lsg-admin-badge">Admin</span>' : '';
        var $msg = $(
            '<div class="lsg-chat-msg" data-index="' + index + '">' +
                '<span class="lsg-chat-username" style="color:' + color + '">' + esc(user) + '</span>' +
                badge +
                '<span class="lsg-chat-sep">：</span>' +
                '<span class="lsg-chat-text">' + esc(msg) + '</span>' +
            '</div>'
        );
        $('#lsg-chat-messages').append($msg);
        renderedMsgCount++;

        // Enforce max visible cap — drop the oldest bubble if over limit
        var $all = $('#lsg-chat-messages .lsg-chat-msg');
        if ($all.length > MAX_OVERLAY_MSGS) { $all.first().remove(); }

        // Auto-fade and self-remove — only for live new messages, not history
        if (autoRemove) {
            var $el = $msg;
            setTimeout(function() {
                $el.css({ opacity: '0', transform: 'translateY(-8px)' });
                setTimeout(function() { $el.remove(); }, 560);
            }, MSG_LIFETIME_MS);
        }
    }

    function scrollBottom(){ var el = document.getElementById('lsg-chat-messages'); el.scrollTop = el.scrollHeight; }

    var lastMsgHash = '';
    var initialLoad = true;
    var seenUsers = {};
    var renderedMsgCount = 0;    // tracks server-fetched messages appended to overlay
    var MAX_OVERLAY_MSGS = 8;    // max bubbles visible at once
    var MSG_LIFETIME_MS  = 7000; // ms before a bubble fades out and self-removes

    function showJoinNotif(user) {
        var $n = $('<div class="lsg-join-notif">👋 ' + esc(user) + ' joined</div>');
        $('#lsg-chat-messages').append($n);
        scrollBottom();
        setTimeout(function(){ $n.fadeOut(600, function(){ $(this).remove(); }); }, 4000);
    }

    function updateViewerCount(data) {
        var unique = {};
        $.each(data, function(_, m){ if (m.user) unique[m.user] = true; });
        var c = Object.keys(unique).length;
        $('#lsg-viewer-num').text(c || '—');
    }

    function msgHash(data) {
        return data.map(function(m){ return m.user + '\x01' + m.msg + '\x01' + (m.ts||0); }).join('\x02');
    }

    function showSkeletons() {
        var s = '';
        for (var i = 0; i < 6; i++) {
            s += '<div class="lsg-skel-row">' +
                '<div class="lsg-skel-name"></div>' +
                '<div class="lsg-skel-' + (i % 2 === 0 ? 'wide' : 'short') + '"></div>' +
                '</div>';
        }
        $('#lsg-chat-messages').html(s);
    }

    function loadChat() {
        $.post(AJAX, { action: 'lsg_fetch_chat', _ajax_nonce: chatNonce }, function(r){
            if (!r.success) return;
            var data = r.data;
            var wasInitialLoad = initialLoad; // capture before mutating
            if (initialLoad) {
                initialLoad = false;
                $('#lsg-chat-messages').empty();
                // Seed seenUsers from history — no join notifications on first load
                $.each(data, function(_, m){ if (m.user) seenUsers[m.user] = true; });
            }
            var hash = msgHash(data);
            if (hash === lastMsgHash) return; // nothing changed — no re-render, no flash
            lastMsgHash = hash;

            var existingCount = renderedMsgCount;

            if (data.length >= existingCount) {
                // Only new messages added — append only the new ones, never wipe
                var newUsersList = [];
                $.each(data.slice(existingCount), function(i, m){
                    if (m.user && !seenUsers[m.user]) { newUsersList.push(m.user); seenUsers[m.user] = true; }
                    // History (first load): keep permanently. Live new messages: auto-remove.
                    appendMsg(existingCount + i, m.user, m.msg, m.ts, !wasInitialLoad);
                });
                $.each(newUsersList, function(_, u){ showJoinNotif(u); });
            } else {
                // Admin deleted a message — full re-render (history, no auto-remove)
                $('#lsg-chat-messages').empty();
                renderedMsgCount = 0;
                $.each(data, function(i, m){ appendMsg(i, m.user, m.msg, m.ts, false); });
            }
            scrollBottom();
            updateViewerCount(data);
        });
    }

    function sendMsg() {
        var msg = $('#lsg-chat-input').val().trim();
        if (!msg) return;

        // 1. Clear input and lock send button immediately
        $('#lsg-chat-input').val('').focus();
        $('#lsg-chat-send').prop('disabled', true);

        // 2. Append pending bubble optimistically
        var pendingId   = 'lsg-pending-' + Date.now();
        var nowTs       = Math.floor(Date.now() / 1000);
        var currentUser = lsgChat.username || (IS_ADMIN ? 'Admin' : 'You');
        var color       = avatarColor(currentUser);
        var badge       = IS_ADMIN ? ' <span class="lsg-admin-badge">Admin</span>' : '';
        $('#lsg-chat-messages').append(
            '<div class="lsg-chat-msg lsg-chat-pending" id="' + pendingId + '" style="opacity:0.55">' +
                '<span class="lsg-chat-username" style="color:' + color + '">' + esc(currentUser) + '</span>' +
                badge +
                '<span class="lsg-chat-sep">：</span>' +
                '<span class="lsg-chat-text">' + esc(msg) + '</span>' +
                '<span class="lsg-sending-tick"> ⋯</span>' +
            '</div>'
        );
        // Count the pending bubble so loadChat’s slice stays correct
        renderedMsgCount++;
        // Enforce cap — drop oldest if over limit
        var $allPre = $('#lsg-chat-messages .lsg-chat-msg');
        if ($allPre.length > MAX_OVERLAY_MSGS) { $allPre.first().remove(); }
        // TikTok-style: pending bubble also fades out after lifetime
        (function(pid) {
            setTimeout(function() {
                var $p = $('#' + pid);
                if ($p.length) {
                    $p.css({ opacity: '0', transform: 'translateY(-8px)' });
                    setTimeout(function() { $p.remove(); }, 560);
                }
            }, MSG_LIFETIME_MS);
        })(pendingId);
        scrollBottom();

        var action = IS_ADMIN ? 'lsg_admin_send_chat' : 'lsg_send_chat';
        $.post(AJAX, { action: action, message: msg, _ajax_nonce: chatNonce }, function(r){
            $('#lsg-chat-send').prop('disabled', false);
            if (r.success) {
                // 3. Confirm the bubble in-place — no remove, no reload, no second append
                var $p = $('#' + pendingId);
                $p.removeClass('lsg-chat-pending').css('opacity', '1');
                $p.find('.lsg-sending-tick').remove();
                // Sync hash so next poll sees this message as already rendered
                lastMsgHash = lastMsgHash + (lastMsgHash ? '\x02' : '') + currentUser + '\x01' + msg + '\x01' + nowTs;
            } else {
                markFailed(pendingId);
            }
        }).fail(function(){
            $('#lsg-chat-send').prop('disabled', false);
            markFailed(pendingId);
        });
    }

    function markFailed(pendingId) {
        var $p = $('#' + pendingId);
        $p.css('opacity', '1').addClass('lsg-chat-failed');
        $p.find('.lsg-sending-tick').text(' ⚠️').attr('title', 'Failed to send');
    }

    $('#lsg-chat-send').on('click', sendMsg);
    $('#lsg-chat-input').on('keypress', function(e){ if (e.which===13) sendMsg(); });

    // ── WebSocket status indicator (in live bar) ─────────────
    function setChatWsStatus(state) {
        var el = document.getElementById('lsg-chat-ws-status');
        if (!el) return;
        var styles = {
            connecting: 'background:#fef9e7;color:#7d6608;border-color:#f1c40f',
            connected:  'background:#eafaf1;color:#1a5c38;border-color:#27ae60',
            polling:    'background:#fdf2e9;color:#784212;border-color:#e67e22',
            error:      'background:#fdf2f2;color:#7b1e1e;border-color:#c0392b'
        };
        var labels = {
            connecting: '&#9679; WS&hellip;',
            connected:  '&#9679; WebSocket',
            polling:    '&#9679; Polling',
            error:      '&#9679; WS Error'
        };
        el.style.cssText = 'margin-left:auto;font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px;border:1px solid;' + (styles[state] || '');
        el.innerHTML = labels[state] || state;
        console.log('[LiveSale Chat] WS status:', state);
    }

    showSkeletons();
    var chatPollInterval = setInterval(loadChat, 4000); // fallback polling — cancelled when Socket.io connects
    loadChat();

    // Load Socket.io client non-blocking
    if (SOCKETIO_URL) {
        console.log('[LiveSale Chat] Socket.io URL:', SOCKETIO_URL);
        setChatWsStatus('connecting');
        var socketioScript = document.createElement('script');
        socketioScript.src = SOCKETIO_URL + '/socket.io/socket.io.js';
        socketioScript.async = true;
        socketioScript.onload = function() {
            try {
                var socket = io(SOCKETIO_URL, { transports: ['websocket', 'polling'] });
                socket.emit('join', SOCKETIO_CHANNEL);

                socket.on('new-message', function(){ loadChat(); });

                socket.on('connect', function() {
                    var transport = socket.io.engine.transport.name;
                    console.log('[LiveSale Chat] Socket.io connected via', transport, '| id:', socket.id);
                    setChatWsStatus(transport === 'websocket' ? 'connected' : 'polling');
                    clearInterval(chatPollInterval);
                    chatPollInterval = setInterval(loadChat, 30000);
                });

                socket.on('upgrade', function() {
                    var transport = socket.io.engine.transport.name;
                    console.log('[LiveSale Chat] Socket.io upgraded to', transport);
                    setChatWsStatus(transport === 'websocket' ? 'connected' : 'polling');
                });

                // If Socket.io disconnects, restore fast polling so chat stays live
                socket.on('disconnect', function(reason) {
                    console.warn('[LiveSale Chat] Socket.io disconnected:', reason);
                    setChatWsStatus('polling');
                    clearInterval(chatPollInterval);
                    chatPollInterval = setInterval(loadChat, 4000);
                });

                socket.on('connect_error', function(err) {
                    console.error('[LiveSale Chat] Socket.io connection error:', err.message);
                    setChatWsStatus('error');
                });

                socket.on('reconnect', function() {
                    clearInterval(chatPollInterval);
                    chatPollInterval = setInterval(loadChat, 30000);
                });
            } catch(e) { console.warn('[LiveSale Chat] Socket.io init failed:', e.message); setChatWsStatus('error'); }
        };
        socketioScript.onerror = function() {
            console.error('[LiveSale Chat] Failed to load socket.io.js from', SOCKETIO_URL);
            setChatWsStatus('error');
        };
        document.head.appendChild(socketioScript);
    } else {
        console.warn('[LiveSale Chat] No SOCKETIO_URL configured — polling only.');
    }
});
