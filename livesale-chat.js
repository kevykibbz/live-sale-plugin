jQuery(function($){
    var AJAX       = lsgChat.ajax;
    var IS_ADMIN   = lsgChat.is_admin;
    var chatNonce  = lsgChat.nonce;
    var ABLY_KEY     = lsgChat.ably_key;
    var ABLY_CHANNEL = lsgChat.ably_channel;

    function esc(s){ return $('<span>').text(s).html(); }

    function appendMsg(index, user, msg) {
        var delBtn = IS_ADMIN
            ? '<span class="lsg-del-btn" data-index="' + index + '">Delete</span>'
            : '';
        $('#lsg-chat-messages').append(
            '<div class="lsg-chat-msg" data-index="' + index + '">'
            + '<span><strong>' + esc(user) + ':</strong> ' + esc(msg) + '</span>'
            + delBtn + '</div>'
        );
    }

    function scrollBottom(){ var el = document.getElementById('lsg-chat-messages'); el.scrollTop = el.scrollHeight; }

    function loadChat() {
        $.post(AJAX, { action: 'lsg_fetch_chat', _ajax_nonce: chatNonce }, function(r){
            if (r.success) {
                $('#lsg-chat-messages').empty();
                $.each(r.data, function(i, m){ appendMsg(i, m.user, m.msg); });
                scrollBottom();
            }
        });
    }

    function sendMsg() {
        var msg = $('#lsg-chat-input').val().trim();
        if (!msg) return;
        var action = IS_ADMIN ? 'lsg_admin_send_chat' : 'lsg_send_chat';
        var btn = $('#lsg-chat-send').prop('disabled', true);
        $.post(AJAX, { action: action, message: msg, _ajax_nonce: chatNonce }, function(r){
            btn.prop('disabled', false);
            if (r.success) {
                $('#lsg-chat-input').val('');
                loadChat();
            } else alert('Failed to send.');
        }).fail(function(){
            btn.prop('disabled', false);
        });
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
