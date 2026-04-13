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

    function appendMsg(index, user, msg, ts) {
        var color   = avatarColor(user);
        var isAdmin = (user === 'Admin');
        var badge   = isAdmin ? ' <span class="lsg-admin-badge">Admin</span>' : '';
        $('#lsg-chat-messages').append(
            '<div class="lsg-chat-msg" data-index="' + index + '">' +
                '<span class="lsg-chat-username" style="color:' + color + '">' + esc(user) + '</span>' +
                badge +
                '<span class="lsg-chat-sep">：</span>' +
                '<span class="lsg-chat-text">' + esc(msg) + '</span>' +
            '</div>'
        );
    }

    function scrollBottom(){ var el = document.getElementById('lsg-chat-messages'); el.scrollTop = el.scrollHeight; }

    var lastMsgHash = '';
    var initialLoad = true;
    var seenUsers = {};

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
            if (initialLoad) {
                initialLoad = false;
                $('#lsg-chat-messages').empty();
                // Seed seenUsers from history — no join notifications on first load
                $.each(data, function(_, m){ if (m.user) seenUsers[m.user] = true; });
            }
            var hash = msgHash(data);
            if (hash === lastMsgHash) return; // nothing changed — no re-render, no flash
            lastMsgHash = hash;

            var existingCount = $('#lsg-chat-messages .lsg-chat-msg').length;

            if (data.length >= existingCount) {
                // Only new messages added — append only the new ones, never wipe
                var newUsersList = [];
                $.each(data.slice(existingCount), function(i, m){
                    if (m.user && !seenUsers[m.user]) { newUsersList.push(m.user); seenUsers[m.user] = true; }
                    appendMsg(existingCount + i, m.user, m.msg, m.ts);
                });
                $.each(newUsersList, function(_, u){ showJoinNotif(u); });
            } else {
                // Admin deleted a message — minimal re-render
                $('#lsg-chat-messages').empty();
                $.each(data, function(i, m){ appendMsg(i, m.user, m.msg, m.ts); });
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

    showSkeletons();
    var chatPollInterval = setInterval(loadChat, 4000); // fallback polling — cancelled when Socket.io connects
    loadChat();

    // Load Socket.io client non-blocking
    if (SOCKETIO_URL) {
        var socketioScript = document.createElement('script');
        socketioScript.src = SOCKETIO_URL + '/socket.io/socket.io.js';
        socketioScript.async = true;
        socketioScript.onload = function() {
            try {
                var socket = io(SOCKETIO_URL);
                socket.emit('join', SOCKETIO_CHANNEL);

                socket.on('new-message', function(){ loadChat(); });

                // Once Socket.io is connected, drop frequent polling to a slow heartbeat
                socket.on('connect', function() {
                    clearInterval(chatPollInterval);
                    chatPollInterval = setInterval(loadChat, 30000); // 30-second heartbeat fallback
                });

                // If Socket.io disconnects, restore fast polling so chat stays live
                socket.on('disconnect', function() {
                    clearInterval(chatPollInterval);
                    chatPollInterval = setInterval(loadChat, 4000);
                });
                socket.on('reconnect', function() {
                    clearInterval(chatPollInterval);
                    chatPollInterval = setInterval(loadChat, 30000);
                });
            } catch(e) { console.warn('[LiveSale Chat] Socket.io init failed:', e.message); }
        };
        document.head.appendChild(socketioScript);
    }
});
