jQuery(function($){
    var AJAX       = lsgChat.ajax;
    var IS_ADMIN   = lsgChat.is_admin;
    var chatNonce  = lsgChat.nonce;
    var ABLY_KEY     = lsgChat.ably_key;
    var ABLY_CHANNEL = lsgChat.ably_channel;

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
        var initial = user.charAt(0).toUpperCase();
        var isAdmin = (user === 'Admin');
        var badge   = isAdmin ? '<span class="lsg-admin-badge">Admin</span>' : '';
        var bubbleCls = isAdmin ? 'lsg-chat-bubble lsg-chat-bubble-admin' : 'lsg-chat-bubble';
        var delBtn  = IS_ADMIN
            ? '<button class="lsg-del-btn" data-index="' + index + '" title="Delete message">×</button>'
            : '';
        var timeStr = ts ? '<span class="lsg-chat-time">' + formatTime(ts) + '</span>' : '';
        $('#lsg-chat-messages').append(
            '<div class="lsg-chat-msg" data-index="' + index + '">' +
                '<div class="lsg-chat-avatar" style="background:' + color + '">' + initial + '</div>' +
                '<div class="' + bubbleCls + '">' +
                    '<div class="lsg-chat-meta">' +
                        '<span class="lsg-chat-username">' + esc(user) + '</span>' + badge + timeStr +
                    '</div>' +
                    '<div class="lsg-chat-text">' + esc(msg) + '</div>' +
                '</div>' +
                delBtn +
            '</div>'
        );
    }

    function scrollBottom(){ var el = document.getElementById('lsg-chat-messages'); el.scrollTop = el.scrollHeight; }

    var lastMsgHash = '';
    var initialLoad = true;

    function msgHash(data) {
        return data.map(function(m){ return m.user + '\x01' + m.msg + '\x01' + (m.ts||0); }).join('\x02');
    }

    function showSkeletons() {
        var skeletons = '';
        for (var i = 0; i < 5; i++) {
            var wide = i % 2 === 0;
            skeletons +=
                '<div class="lsg-skel-row">' +
                    '<div class="lsg-skel-avatar"></div>' +
                    '<div class="lsg-skel-bubble">' +
                        '<div class="lsg-skel-line lsg-skel-name"></div>' +
                        '<div class="lsg-skel-line' + (wide ? ' lsg-skel-wide' : ' lsg-skel-short') + '"></div>' +
                    '</div>' +
                '</div>';
        }
        $('#lsg-chat-messages').html(skeletons);
    }

    function loadChat() {
        $.post(AJAX, { action: 'lsg_fetch_chat', _ajax_nonce: chatNonce }, function(r){
            if (!r.success) return;
            if (initialLoad) {
                initialLoad = false;
                $('#lsg-chat-messages').empty();
            }
            var data = r.data;
            var hash = msgHash(data);
            if (hash === lastMsgHash) return; // nothing changed — no re-render, no flash
            lastMsgHash = hash;

            var existingCount = $('#lsg-chat-messages .lsg-chat-msg').length;

            if (data.length >= existingCount) {
                // Only new messages added — append only the new ones, never wipe
                $.each(data.slice(existingCount), function(i, m){
                    appendMsg(existingCount + i, m.user, m.msg, m.ts);
                });
            } else {
                // Admin deleted a message — minimal re-render
                $('#lsg-chat-messages').empty();
                $.each(data, function(i, m){ appendMsg(i, m.user, m.msg, m.ts); });
            }
            scrollBottom();
        });
    }

    function sendMsg() {
        var msg = $('#lsg-chat-input').val().trim();
        if (!msg) return;

        // 1. Clear input and lock send button immediately
        $('#lsg-chat-input').val('').focus();
        $('#lsg-chat-send').prop('disabled', true);

        // 2. Append pending bubble optimistically
        var pendingId  = 'lsg-pending-' + Date.now();
        var nowTs      = Math.floor(Date.now() / 1000);
        var currentUser = lsgChat.username || (IS_ADMIN ? 'Admin' : 'You');
        var color     = avatarColor(currentUser);
        var initial   = currentUser.charAt(0).toUpperCase();
        var badge     = IS_ADMIN ? '<span class="lsg-admin-badge">Admin</span>' : '';
        var bubbleCls = IS_ADMIN ? 'lsg-chat-bubble lsg-chat-bubble-admin' : 'lsg-chat-bubble';
        $('#lsg-chat-messages').append(
            '<div class="lsg-chat-msg lsg-chat-pending" id="' + pendingId + '">' +
                '<div class="lsg-chat-avatar" style="background:' + color + ';opacity:0.55">' + initial + '</div>' +
                '<div class="' + bubbleCls + '" style="opacity:0.55">' +
                    '<div class="lsg-chat-meta">' +
                        '<span class="lsg-chat-username">' + esc(currentUser) + '</span>' + badge +
                        '<span class="lsg-chat-time lsg-sending-tick">🕐</span>' +
                    '</div>' +
                    '<div class="lsg-chat-text">' + esc(msg) + '</div>' +
                '</div>' +
            '</div>'
        );
        scrollBottom();

        var action = IS_ADMIN ? 'lsg_admin_send_chat' : 'lsg_send_chat';
        $.post(AJAX, { action: action, message: msg, _ajax_nonce: chatNonce }, function(r){
            $('#lsg-chat-send').prop('disabled', false);
            if (r.success) {
                // 3. Confirm the bubble in-place — no remove, no reload, no second append
                var $p = $('#' + pendingId);
                $p.removeClass('lsg-chat-pending');
                $p.find('.lsg-chat-avatar').css('opacity', '1');
                $p.find('.lsg-chat-bubble').css('opacity', '1');
                $p.find('.lsg-sending-tick').text(formatTime(nowTs)).removeClass('lsg-sending-tick').addClass('lsg-chat-time');
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
        $p.find('.lsg-sending-tick').text('⚠️').attr('title', 'Failed — tap to retry');
        $p.find('.lsg-chat-avatar, .lsg-chat-bubble').css('opacity', '1');
        $p.addClass('lsg-chat-failed');
    }

    $('#lsg-chat-send').on('click', sendMsg);
    $('#lsg-chat-input').on('keypress', function(e){ if (e.which===13) sendMsg(); });

    if (IS_ADMIN) {
        $('#lsg-chat-clear').on('click', function(){
            if (!confirm('Delete all messages?')) return;
            $.post(AJAX, { action: 'lsg_admin_clear_chat', _ajax_nonce: chatNonce }, function(r){
                if (r.success) $('#lsg-chat-messages').empty();
            });
        });
        $(document).on('click', '.lsg-del-btn', function(){
            var idx = $(this).data('index');
            $.post(AJAX, { action: 'lsg_admin_delete_msg', index: idx, _ajax_nonce: chatNonce }, function(r){
                if (r.success) loadChat(); else alert('Could not delete.');
            });
        });
    }

    showSkeletons();
    setInterval(loadChat, 4000);
    loadChat();

    var ablyScript = document.createElement('script');
    ablyScript.src = 'https://cdn.ably.com/lib/ably.min-1.js';
    ablyScript.async = true;
    ablyScript.onload = function() {
        try {
            var ably = new Ably.Realtime(ABLY_KEY);
            ably.channels.get(ABLY_CHANNEL).subscribe('new-message', function(){ loadChat(); });
        } catch(e) { console.warn('[LiveSale Chat] Ably init failed:', e.message); }
    };
    document.head.appendChild(ablyScript);
});
