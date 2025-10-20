<?php
// chat_widget.php
// Updated chat widget: shows current user's name, per-message online dot, and an online roster list.
if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'guest_' . session_id();
$username = isset($_SESSION['username']) ? $_SESSION['username'] : (isset($_SESSION['fullName']) ? $_SESSION['fullName'] : 'Guest');
?>
<style>
#chat-box { position: fixed; right: 18px; bottom: 18px; width: 360px; max-height: 620px; z-index:9999; font-size:14px; box-shadow:0 6px 18px rgba(0,0,0,0.15);}
#chat-header { background:#0d6efd; color:#fff; padding:8px 10px; border-radius:8px 8px 0 0; display:flex; align-items:center; justify-content:space-between;}
#chat-messages { background:#fff; border:1px solid #ddd; height:300px; overflow:auto; padding:10px;}
#chat-input { display:flex; gap:8px; padding:8px; background:#f8f9fa; border-radius:0 0 8px 8px;}
#chat-input input { flex:1; padding:6px 8px; border:1px solid #ccc; border-radius:6px;}
.chat-msg { margin-bottom:10px; text-align:left;}
.chat-msg .meta { font-size:12px; color:#666; display:flex; align-items:center; gap:8px; }
.chat-msg .text { margin-top:4px; white-space:pre-wrap; }
#chat-online { font-size:12px; color:#fff; opacity:0.95; margin-left:8px; }
.badge-notif { background:#dc3545; color:#fff; border-radius:50%; padding:2px 7px; font-size:12px; margin-left:8px; display:inline-block;}
.online-dot { display:inline-block; width:8px; height:8px; background:#28a745; border-radius:50%; margin-right:6px; vertical-align:middle; box-shadow:0 0 4px rgba(0,0,0,0.15);} 
.offline-dot { display:inline-block; width:8px; height:8px; background:#6c757d; border-radius:50%; margin-right:6px; vertical-align:middle; opacity:0.7;} 
.sender-name { font-weight:600; }
#chat-roster { background:#f1f3f5; border-top:1px solid #e9ecef; padding:8px; max-height:120px; overflow:auto; }
.roster-item { display:flex; align-items:center; gap:8px; padding:6px 4px; border-radius:6px; }
.roster-item:hover { background:rgba(13,110,253,0.03); cursor:pointer; }
.current-user { font-weight:700; color:#0d6efd; }
</style>

<div id="chat-box" aria-live="polite">
    <div id="chat-header">
        <div>Admin Chat <span id="chat-online">(…)</span></div>
        <div style="display:flex;align-items:center;gap:8px;">
            <div class="current-user" title="You"><?php echo htmlspecialchars($username); ?></div>
            <button id="chat-toggle" class="btn btn-sm btn-light">▼</button>
            <span id="chat-notif" class="badge-notif" style="display:none">0</span>
        </div>
    </div>

    <div id="chat-body" style="display:block; border-radius:0 0 8px 8px; overflow:hidden;">
        <!-- Roster / online users -->
        <div id="chat-roster" aria-label="Online users">
            <!-- populated by JS -->
            <div style="font-size:12px;color:#495057;margin-bottom:6px;">Online users</div>
            <div id="roster-list"></div>
        </div>

        <div id="chat-messages" role="log" aria-atomic="false" aria-relevant="additions"></div>

        <div id="chat-input">
            <input id="chat-message-input" placeholder="Type a message..." autocomplete="off" />
            <button id="chat-send" class="btn btn-primary btn-sm">Send</button>
        </div>
    </div>
</div>

<script>
(function(){
    const fetchUrl = 'fetch_messages.php';
    const postUrl = 'post_message.php';
    const presenceUrl = 'presence.php';
    let lastId = 0;
    const pollInterval = 2000;
    const presenceInterval = 5000;
    let onlineMap = {}; // user_key => { user_name, page, last_seen }

    const messagesEl = document.getElementById('chat-messages');
    const rosterEl = document.getElementById('roster-list');
    const inputEl = document.getElementById('chat-message-input');
    const sendBtn = document.getElementById('chat-send');
    const notifEl = document.getElementById('chat-notif');
    const onlineEl = document.getElementById('chat-online');
    const chatBody = document.getElementById('chat-body');
    const toggleBtn = document.getElementById('chat-toggle');

    toggleBtn.addEventListener('click', () => {
        if (chatBody.style.display === 'none') {
            chatBody.style.display = 'block';
            toggleBtn.textContent = '▼';
            notifEl.style.display = 'none';
            notifEl.textContent = '0';
            lastId = 0; // force fresh load
            fetchMessages();
        } else {
            chatBody.style.display = 'none';
            toggleBtn.textContent = '▲';
        }
    });

    function sendMessage() {
        const message = inputEl.value.trim();
        if (!message) return;
        sendBtn.disabled = true;
        fetch(postUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message })
        }).then(r => r.json()).then(data => {
            if (data && data.success) {
                inputEl.value = '';
                fetchMessages();
            } else {
                console.error('Send error', data && data.error);
                alert('Send error: ' + (data && data.error ? data.error : 'unknown'));
            }
        }).catch(err => console.error(err)).finally(() => sendBtn.disabled = false);
    }

    sendBtn.addEventListener('click', sendMessage);
    inputEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); sendMessage(); } });

    function createMetaElement(m) {
        const meta = document.createElement('div');
        meta.className = 'meta';
        const dot = document.createElement('span');
        dot.className = isUserOnline(m.sender_id) ? 'online-dot' : 'offline-dot';
        dot.dataset.senderId = m.sender_id;
        const nameSpan = document.createElement('span');
        nameSpan.className = 'sender-name';
        nameSpan.textContent = m.sender_name;
        const dateSpan = document.createElement('span');
        const d = new Date(m.created_at);
        dateSpan.textContent = '• ' + d.toLocaleString();
        meta.appendChild(dot);
        meta.appendChild(nameSpan);
        meta.appendChild(dateSpan);
        return meta;
    }

    function appendMessage(m) {
        const div = document.createElement('div');
        div.className = 'chat-msg';
        div.dataset.senderId = m.sender_id;
        const meta = createMetaElement(m);
        const text = document.createElement('div');
        text.className = 'text';
        text.textContent = m.message;
        div.appendChild(meta);
        div.appendChild(text);
        messagesEl.appendChild(div);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function fetchMessages() {
        const url = fetchUrl + '?since_id=' + lastId;
        fetch(url).then(r => r.json()).then(data => {
            if (!data || !data.success) return;
            const msgs = data.messages || [];
            if (msgs.length) {
                msgs.forEach(m => {
                    appendMessage(m);
                    lastId = Math.max(lastId, parseInt(m.id, 10));
                });
                if (chatBody.style.display === 'none') {
                    const cur = parseInt(notifEl.textContent || '0', 10);
                    notifEl.textContent = cur + msgs.length;
                    notifEl.style.display = 'inline-block';
                }
            }
        }).catch(err => console.error(err));
    }

    function updatePresence() {
        const form = new FormData();
        form.append('page', window.location.pathname);
        fetch(presenceUrl, { method:'POST', body: form })
            .then(r => r.json())
            .then(data => {
                if (!data || !data.success) return;
                const online = data.online || [];
                onlineMap = {};
                online.forEach(u => { onlineMap[String(u.user_key)] = u; });
                onlineEl.textContent = '(' + online.length + ' online)';
                renderRoster(online);
                refreshRenderedMessageDots();
            }).catch(err => console.error(err));
    }

    function renderRoster(list) {
        rosterEl.innerHTML = '';
        if (!list.length) {
            rosterEl.textContent = '—';
            return;
        }
        list.forEach(u => {
            const item = document.createElement('div');
            item.className = 'roster-item';
            item.dataset.userKey = u.user_key;
            const dot = document.createElement('span');
            dot.className = 'online-dot';
            const name = document.createElement('span');
            name.textContent = u.user_name || u.user_key;
            item.appendChild(dot);
            item.appendChild(name);
            // clicking a roster item inserts an @mention into the input
            item.addEventListener('click', () => {
                inputEl.value = inputEl.value + (inputEl.value && !inputEl.value.endsWith(' ') ? ' ' : '') + '@' + (u.user_name || u.user_key) + ' ';
                inputEl.focus();
            });
            rosterEl.appendChild(item);
        });
    }

    function isUserOnline(senderId) {
        if (senderId === null || senderId === undefined) return false;
        return !!onlineMap[String(senderId)];
    }

    function refreshRenderedMessageDots() {
        // update dots for each rendered message using the senderId attribute
        const msgEls = messagesEl.querySelectorAll('.chat-msg');
        msgEls.forEach(el => {
            const senderId = el.dataset.senderId;
            const dot = el.querySelector('.online-dot, .offline-dot');
            if (!dot) return;
            dot.className = isUserOnline(senderId) ? 'online-dot' : 'offline-dot';
        });
    }

    setInterval(fetchMessages, pollInterval);
    setInterval(updatePresence, presenceInterval);

    fetchMessages();
    updatePresence();

    messagesEl.addEventListener('click', () => {
        notifEl.style.display = 'none';
        notifEl.textContent = '0';
    });

    window.addEventListener('focus', () => {
        lastId = 0;
        fetchMessages();
        updatePresence();
    });
})();
</script>
