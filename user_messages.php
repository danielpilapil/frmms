<?php
/* FleetGo — Customer Messages (Socket.IO realtime support chat) */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_user');
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/chat.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
  header('Location: login.php');
  exit;
}

chat_ensure_tables($conn);

$userId = (int)$_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'Customer';
$socketUrl = chat_socket_url();

// Mark admin replies as read when opening page (best-effort)
try {
  $stmt = $conn->prepare("UPDATE chat_messages SET is_read=1 WHERE sender_role='admin' AND recipient_id=? AND is_read=0");
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $stmt->close();
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Messages • FleetGo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>
<style>
:root{
  --bg:#0b0d10;--text:#f2f6fa;--muted:#9aa6b3;--brand:#5dd0ff;--brand2:#7cffc7;
  --border:rgba(255,255,255,.08);
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif}
.wrap{max-width:920px;margin:0 auto;padding:20px}
.head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:16px}
.head h1{margin:0;font-size:1.6rem;font-weight:800}
.head p{margin:6px 0 0;color:var(--muted)}
.status-pill{
  display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;
  border:1px solid var(--border);background:rgba(255,255,255,.03);font-size:.82rem;font-weight:700;color:var(--muted);
}
.status-pill .dot{width:8px;height:8px;border-radius:50%;background:#64748b}
.status-pill.online .dot{background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.2)}
.status-pill.offline .dot{background:#ef4444}
.chat-card{
  min-height:calc(100vh - 180px);display:flex;flex-direction:column;
  background:linear-gradient(145deg,#101419,#151b24);border:1px solid var(--border);border-radius:20px;overflow:hidden;
}
.thread-head{padding:16px 18px;border-bottom:1px solid var(--border)}
.thread-head h2{margin:0;font-size:1.05rem;font-weight:800}
.thread-head .sub{color:var(--muted);font-size:.85rem;margin-top:4px}
.messages{flex:1;overflow:auto;padding:18px;display:flex;flex-direction:column;gap:10px}
.bubble-row{display:flex;width:100%}
.bubble-row.mine{justify-content:flex-end}
.bubble-row.theirs{justify-content:flex-start}
.bubble{max-width:min(78%,520px);padding:10px 14px;border-radius:16px;font-size:.92rem;line-height:1.45;word-break:break-word}
.bubble.mine{background:linear-gradient(135deg,#2563eb,#0891b2);color:#fff;border-bottom-right-radius:6px}
.bubble.theirs{background:rgba(255,255,255,.06);border:1px solid var(--border);border-bottom-left-radius:6px}
.bubble .meta{margin-top:6px;font-size:.7rem;opacity:.75}
.typing{color:var(--muted);font-size:.8rem;min-height:18px;padding:0 18px 6px}
.composer{border-top:1px solid var(--border);padding:14px 16px;display:flex;gap:10px;align-items:flex-end;background:rgba(0,0,0,.18)}
.composer textarea{
  flex:1;min-height:46px;height:46px;max-height:46px;resize:none;
  border-radius:14px;border:1px solid var(--border);
  background:rgba(13,17,22,.9);color:var(--text);padding:12px 14px;font:inherit;line-height:1.35;overflow-y:auto;
}
.composer textarea:focus{outline:none;border-color:rgba(93,208,255,.45)}
.composer button{
  border:0;border-radius:14px;padding:12px 18px;font-weight:800;cursor:pointer;
  background:linear-gradient(135deg,var(--brand),var(--brand2));color:#041b22;
}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/user_navbar.php'; ?>
<div class="wrap">
  <div class="head">
    <div>
      <h1>Messages</h1>
      <p>Chat with FleetGo support in realtime</p>
    </div>
    <div class="status-pill offline" id="connStatus"><span class="dot"></span><span id="connLabel">Connecting…</span></div>
  </div>

  <div class="chat-card">
    <div class="thread-head">
      <h2>FleetGo Support</h2>
      <div class="sub">Ask about bookings, payments, or vehicle issues</div>
    </div>
    <div class="messages" id="messages"></div>
    <div class="typing" id="typingHint"></div>
    <form class="composer" id="composer">
      <textarea id="msgInput" placeholder="Write a message…" rows="1" required></textarea>
      <button type="submit">Send</button>
    </form>
  </div>
</div>

<script>
const SOCKET_URL = <?= json_encode($socketUrl) ?>;
const AUTH = {
  userId: <?= (int)$userId ?>,
  role: 'user',
  name: <?= json_encode($userName) ?>
};
const messagesEl = document.getElementById('messages');
const typingHint = document.getElementById('typingHint');
const msgInput = document.getElementById('msgInput');
const composer = document.getElementById('composer');
const connStatus = document.getElementById('connStatus');
const connLabel = document.getElementById('connLabel');
let thread = [];
let typingTimer = null;

function esc(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function fmtTime(v){
  if (!v) return '';
  const d = new Date(String(v).replace(' ', 'T'));
  if (isNaN(d)) return '';
  return d.toLocaleString([], { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
}
function setConn(online, text){
  connStatus.classList.toggle('online', !!online);
  connStatus.classList.toggle('offline', !online);
  connLabel.textContent = text;
}
function renderMessages(){
  if (!thread.length) {
    messagesEl.innerHTML = '<div style="margin:auto;color:var(--muted);text-align:center;padding:24px;">Say hello to FleetGo support — messages appear here in realtime.</div>';
    return;
  }
  messagesEl.innerHTML = thread.map(m => {
    const mine = m.sender_role === 'user';
    return `<div class="bubble-row ${mine?'mine':'theirs'}">
      <div class="bubble ${mine?'mine':'theirs'}">
        <div>${esc(m.body)}</div>
        <div class="meta">${esc(mine ? 'You' : (m.sender_name || 'Support'))} · ${esc(fmtTime(m.created_at))}</div>
      </div>
    </div>`;
  }).join('');
  messagesEl.scrollTop = messagesEl.scrollHeight;
}

const socket = io(SOCKET_URL, {
  auth: AUTH,
  transports: ['websocket', 'polling'],
  reconnection: true,
});

socket.on('connect', () => {
  setConn(true, 'Realtime connected');
  socket.emit('messages:history', {});
  socket.emit('unread:get');
});
socket.on('disconnect', () => setConn(false, 'Disconnected — retrying…'));
socket.on('connect_error', () => setConn(false, 'Chat server offline'));

socket.on('messages:history', (payload) => {
  thread = Array.isArray(payload.messages) ? payload.messages : [];
  renderMessages();
  if (typeof window.setUserMessagesBadge === 'function') {
    window.setUserMessagesBadge(0);
  }
});

socket.on('unread:count', (payload) => {
  if (typeof window.setUserMessagesBadge === 'function') {
    window.setUserMessagesBadge(payload?.count ?? 0);
  }
});

socket.on('message:new', (payload) => {
  if (Number(payload.customerId) !== AUTH.userId) return;
  thread.push(payload.message);
  renderMessages();
  // Viewing the chat — mark admin reply as read
  if (payload.message?.sender_role === 'admin') {
    socket.emit('messages:history', {});
  }
});

socket.on('typing', (payload) => {
  typingHint.textContent = payload.typing ? `${payload.name || 'Support'} is typing…` : '';
});

socket.on('chat:error', (e) => alert(e?.message || 'Chat error'));

composer.addEventListener('submit', (e) => {
  e.preventDefault();
  const body = msgInput.value.trim();
  if (!body) return;
  socket.emit('message:send', { body });
  msgInput.value = '';
  socket.emit('typing', { typing: false });
});

msgInput.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    composer.requestSubmit();
  }
});

msgInput.addEventListener('input', () => {
  socket.emit('typing', { typing: true });
  clearTimeout(typingTimer);
  typingTimer = setTimeout(() => socket.emit('typing', { typing: false }), 1200);
});

renderMessages();
</script>
</body>
</html>
<?php $conn->close(); ?>
