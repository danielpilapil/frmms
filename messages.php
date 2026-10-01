<?php
/* FleetGo — Admin Messages (Socket.IO realtime) */
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
session_name('fleetgo_session_admin');
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/chat.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: login.php');
  exit;
}

chat_ensure_tables($conn);

$adminId = (int)$_SESSION['user_id'];
$adminName = $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Admin';
$socketUrl = chat_socket_url();

$preselectCustomerId = (int)($_GET['user_id'] ?? $_GET['customer_id'] ?? 0);
$preselectCustomer = null;
if ($preselectCustomerId > 0) {
  try {
    $stmt = $conn->prepare("SELECT id, full_name, email, profile_photo FROM users WHERE id=? AND role='user' LIMIT 1");
    $stmt->bind_param('i', $preselectCustomerId);
    $stmt->execute();
    $preselectCustomer = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$preselectCustomer) $preselectCustomerId = 0;
  } catch (Throwable $e) {
    $preselectCustomerId = 0;
    $preselectCustomer = null;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Messages • FleetGo Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.socket.io/4.8.1/socket.io.min.js"></script>
<style>
:root{
  --bg:#0b0d10;--card:#12161c;--text:#f2f6fa;--muted:#9aa6b3;
  --brand:#5dd0ff;--brand2:#7cffc7;--border:rgba(255,255,255,.08);
  --danger:#ff6b6b;--radius:16px;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif}
.wrap{max-width:1400px;margin:0 auto;padding:20px}
.head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:16px}
.head h1{margin:0;font-size:1.7rem;font-weight:800}
.head p{margin:6px 0 0;color:var(--muted);font-size:.92rem}
.status-pill{
  display:inline-flex;align-items:center;gap:8px;
  padding:8px 12px;border-radius:999px;border:1px solid var(--border);
  background:rgba(255,255,255,.03);font-size:.82rem;font-weight:700;color:var(--muted);
}
.status-pill .dot{width:8px;height:8px;border-radius:50%;background:#64748b}
.status-pill.online .dot{background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.2)}
.status-pill.offline .dot{background:#ef4444}

.chat-shell{
  display:grid;grid-template-columns:320px 1fr;gap:0;
  min-height:calc(100vh - 160px);
  background:linear-gradient(145deg,#101419,#151b24);
  border:1px solid var(--border);border-radius:20px;overflow:hidden;
}
.sidebar{border-right:1px solid var(--border);display:flex;flex-direction:column;min-height:0;background:rgba(0,0,0,.15)}
.side-head{padding:16px;border-bottom:1px solid var(--border)}
.side-head h2{margin:0;font-size:1rem;font-weight:800}
.side-list{overflow:auto;flex:1;padding:8px}
.conv{
  display:flex;gap:12px;align-items:center;padding:12px;border-radius:14px;
  cursor:pointer;border:1px solid transparent;transition:.15s ease;
}
.conv:hover{background:rgba(255,255,255,.04)}
.conv.active{background:rgba(93,208,255,.1);border-color:rgba(93,208,255,.25)}
.avatar{
  width:42px;height:42px;border-radius:50%;flex-shrink:0;
  background:linear-gradient(135deg,var(--brand),var(--brand2));
  color:#041b22;display:grid;place-items:center;font-weight:900;
  overflow:hidden;
}
.avatar img{width:100%;height:100%;object-fit:cover}
.conv-meta{min-width:0;flex:1}
.conv-top{display:flex;justify-content:space-between;gap:8px;align-items:center}
.conv-name{font-weight:800;font-size:.92rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.conv-time{color:var(--muted);font-size:.72rem;flex-shrink:0}
.conv-preview{color:var(--muted);font-size:.8rem;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.unread{
  min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#ef4444;color:#fff;
  font-size:11px;font-weight:900;display:inline-flex;align-items:center;justify-content:center;
}
.empty-side{padding:28px 16px;color:var(--muted);text-align:center;font-size:.9rem}

.thread{display:flex;flex-direction:column;min-height:0;min-width:0}
.thread-head{
  padding:14px 18px;border-bottom:1px solid var(--border);
  display:flex;justify-content:space-between;align-items:center;gap:12px;
}
.thread-head h3{margin:0;font-size:1.05rem;font-weight:800}
.thread-head .sub{color:var(--muted);font-size:.82rem;margin-top:2px}
.messages{
  flex:1;overflow:auto;padding:18px;display:flex;flex-direction:column;gap:10px;
  background:radial-gradient(ellipse at top, rgba(93,208,255,.04), transparent 50%);
}
.bubble-row{display:flex;width:100%}
.bubble-row.mine{justify-content:flex-end}
.bubble-row.theirs{justify-content:flex-start}
.bubble{
  max-width:min(70%,560px);padding:10px 14px;border-radius:16px;
  font-size:.92rem;line-height:1.45;word-break:break-word;
}
.bubble.mine{background:linear-gradient(135deg,#2563eb,#0891b2);color:#fff;border-bottom-right-radius:6px}
.bubble.theirs{background:rgba(255,255,255,.06);border:1px solid var(--border);border-bottom-left-radius:6px}
.bubble .meta{margin-top:6px;font-size:.7rem;opacity:.75}
.typing{color:var(--muted);font-size:.8rem;min-height:18px;padding:0 18px 6px}
.composer{
  border-top:1px solid var(--border);padding:14px 16px;display:flex;gap:10px;align-items:flex-end;
  background:rgba(0,0,0,.18);
}
.composer textarea{
  flex:1;min-height:46px;height:46px;max-height:46px;resize:none;
  border-radius:14px;border:1px solid var(--border);background:rgba(13,17,22,.9);
  color:var(--text);padding:12px 14px;font:inherit;line-height:1.35;overflow-y:auto;
}
.composer textarea:focus{outline:none;border-color:rgba(93,208,255,.45)}
.composer button{
  border:0;border-radius:14px;padding:12px 18px;font-weight:800;cursor:pointer;
  background:linear-gradient(135deg,var(--brand),var(--brand2));color:#041b22;
}
.composer button:disabled{opacity:.5;cursor:not-allowed}
.placeholder{
  flex:1;display:grid;place-items:center;color:var(--muted);text-align:center;padding:40px;
}
@media (max-width:900px){
  .chat-shell{grid-template-columns:1fr}
  .sidebar{max-height:240px;border-right:0;border-bottom:1px solid var(--border)}
}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/navbar.php'; ?>
<div class="wrap">
  <div class="head">
    <div>
      <h1>Messages</h1>
      <p>Realtime support chat with customers</p>
    </div>
    <div class="status-pill offline" id="connStatus"><span class="dot"></span><span id="connLabel">Connecting…</span></div>
  </div>

  <div class="chat-shell">
    <aside class="sidebar">
      <div class="side-head"><h2>Conversations</h2></div>
      <div class="side-list" id="convList"><div class="empty-side">No conversations yet.</div></div>
    </aside>
    <section class="thread">
      <div class="placeholder" id="emptyThread">Select a customer conversation to start messaging.</div>
      <div id="activeThread" style="display:none;flex:1;min-height:0;flex-direction:column;">
        <div class="thread-head">
          <div>
            <h3 id="threadTitle">Customer</h3>
            <div class="sub" id="threadSub"></div>
          </div>
        </div>
        <div class="messages" id="messages"></div>
        <div class="typing" id="typingHint"></div>
        <form class="composer" id="composer">
          <textarea id="msgInput" placeholder="Type a reply…" rows="1" required></textarea>
          <button type="submit" id="sendBtn">Send</button>
        </form>
      </div>
    </section>
  </div>
</div>

<script>
const SOCKET_URL = <?= json_encode($socketUrl) ?>;
const AUTH = {
  userId: <?= (int)$adminId ?>,
  role: 'admin',
  name: <?= json_encode($adminName) ?>
};
const PRESELECT = <?= json_encode($preselectCustomer ? [
  'customer_id' => (int)$preselectCustomer['id'],
  'customer_name' => (string)$preselectCustomer['full_name'],
  'customer_email' => (string)($preselectCustomer['email'] ?? ''),
  'profile_photo' => (string)($preselectCustomer['profile_photo'] ?? ''),
  'last_message' => '',
  'last_at' => null,
  'unread' => 0,
] : null) ?>;

const convList = document.getElementById('convList');
const messagesEl = document.getElementById('messages');
const emptyThread = document.getElementById('emptyThread');
const activeThread = document.getElementById('activeThread');
const threadTitle = document.getElementById('threadTitle');
const threadSub = document.getElementById('threadSub');
const typingHint = document.getElementById('typingHint');
const msgInput = document.getElementById('msgInput');
const composer = document.getElementById('composer');
const connStatus = document.getElementById('connStatus');
const connLabel = document.getElementById('connLabel');

let conversations = [];
let activeCustomerId = null;
let thread = [];
let typingTimer = null;
let preselectConsumed = false;

function esc(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function initials(name){
  const p = String(name||'U').trim().split(/\s+/);
  return ((p[0]?.[0]||'U') + (p[1]?.[0]||'')).toUpperCase();
}
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

function renderConvs(){
  if (!conversations.length) {
    convList.innerHTML = '<div class="empty-side">No conversations yet. Customers will appear here when they message support.</div>';
    return;
  }
  convList.innerHTML = conversations.map(c => `
    <div class="conv ${activeCustomerId===c.customer_id?'active':''}" data-id="${c.customer_id}">
      <div class="avatar">${c.profile_photo ? `<img src="${esc(c.profile_photo)}" alt="">` : esc(initials(c.customer_name))}</div>
      <div class="conv-meta">
        <div class="conv-top">
          <div class="conv-name">${esc(c.customer_name)}</div>
          <div class="conv-time">${esc(fmtTime(c.last_at))}</div>
        </div>
        <div class="conv-preview">${esc(c.last_message || 'No messages')}</div>
      </div>
      ${c.unread>0 ? `<span class="unread">${c.unread}</span>` : ''}
    </div>
  `).join('');
  convList.querySelectorAll('.conv').forEach(el => {
    el.addEventListener('click', () => openConversation(Number(el.dataset.id)));
  });
}

function renderMessages(){
  messagesEl.innerHTML = thread.map(m => {
    const mine = m.sender_role === 'admin';
    return `<div class="bubble-row ${mine?'mine':'theirs'}">
      <div class="bubble ${mine?'mine':'theirs'}">
        <div>${esc(m.body)}</div>
        <div class="meta">${esc(m.sender_name || '')} · ${esc(fmtTime(m.created_at))}</div>
      </div>
    </div>`;
  }).join('');
  messagesEl.scrollTop = messagesEl.scrollHeight;
}

function openConversation(customerId){
  customerId = Number(customerId);
  if (!customerId) return;
  // User manually switched chats — don't force URL preselect again
  if (PRESELECT && PRESELECT.customer_id && customerId !== PRESELECT.customer_id) {
    preselectConsumed = true;
  }
  activeCustomerId = customerId;
  let c = conversations.find(x => x.customer_id === customerId);
  if (!c && PRESELECT && PRESELECT.customer_id === customerId) {
    c = PRESELECT;
    if (!conversations.some(x => x.customer_id === customerId)) {
      conversations = [c, ...conversations];
    }
  }
  emptyThread.style.display = 'none';
  activeThread.style.display = 'flex';
  threadTitle.textContent = c?.customer_name || ('Customer #' + customerId);
  threadSub.textContent = c?.customer_email || '';
  typingHint.textContent = '';
  thread = [];
  renderMessages();
  renderConvs();
  if (socket && socket.connected) {
    socket.emit('messages:history', { customerId });
  }
}

function ensurePreselectInList(){
  if (!PRESELECT || !PRESELECT.customer_id) return;
  if (!conversations.some(c => c.customer_id === PRESELECT.customer_id)) {
    conversations = [PRESELECT, ...conversations];
  }
}

function applyPreselectOnce(){
  if (preselectConsumed || !PRESELECT || !PRESELECT.customer_id) return;
  preselectConsumed = true;
  // Drop ?user_id= from the URL so refresh/navigation won't keep re-locking the chat
  try {
    const url = new URL(window.location.href);
    if (url.searchParams.has('user_id') || url.searchParams.has('customer_id')) {
      url.searchParams.delete('user_id');
      url.searchParams.delete('customer_id');
      window.history.replaceState({}, '', url.pathname + (url.search || '') + url.hash);
    }
  } catch (e) {}
  openConversation(PRESELECT.customer_id);
}

const socket = io(SOCKET_URL, {
  auth: AUTH,
  transports: ['websocket', 'polling'],
  reconnection: true,
});

socket.on('connect', () => {
  setConn(true, 'Realtime connected');
  socket.emit('conversations:list');
  socket.emit('unread:get');
});
socket.on('disconnect', () => setConn(false, 'Disconnected — retrying…'));
socket.on('connect_error', () => setConn(false, 'Chat server offline (start realtime/server.js)'));

socket.on('conversations:list', (list) => {
  conversations = Array.isArray(list) ? list : [];
  ensurePreselectInList();
  renderConvs();
  applyPreselectOnce();
  const total = conversations.reduce((sum, c) => sum + (Number(c.unread) || 0), 0);
  if (typeof window.setAdminMessagesBadge === 'function') {
    window.setAdminMessagesBadge(total);
  }
});

socket.on('messages:history', (payload) => {
  if (Number(payload.customerId) !== activeCustomerId) return;
  thread = Array.isArray(payload.messages) ? payload.messages : [];
  renderMessages();
});

socket.on('unread:count', (payload) => {
  if (typeof window.setAdminMessagesBadge === 'function') {
    window.setAdminMessagesBadge(payload?.count ?? 0);
  }
});

socket.on('message:new', (payload) => {
  const cid = Number(payload.customerId);
  const msg = payload.message;
  if (cid === activeCustomerId) {
    if (!thread.some(m => m.id === msg.id)) {
      thread.push(msg);
      renderMessages();
    }
  }
  // Refresh sidebar quietly without re-opening a conversation
  socket.emit('conversations:list');
});

socket.on('typing', (payload) => {
  if (Number(payload.customerId) !== activeCustomerId) return;
  typingHint.textContent = payload.typing ? `${payload.name || 'Customer'} is typing…` : '';
});

socket.on('chat:error', (e) => {
  alert(e?.message || 'Chat error');
});

composer.addEventListener('submit', (e) => {
  e.preventDefault();
  const body = msgInput.value.trim();
  if (!body || !activeCustomerId) return;
  socket.emit('message:send', { customerId: activeCustomerId, body });
  msgInput.value = '';
  socket.emit('typing', { customerId: activeCustomerId, typing: false });
});

msgInput.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    composer.requestSubmit();
  }
});

msgInput.addEventListener('input', () => {
  if (!activeCustomerId) return;
  socket.emit('typing', { customerId: activeCustomerId, typing: true });
  clearTimeout(typingTimer);
  typingTimer = setTimeout(() => socket.emit('typing', { customerId: activeCustomerId, typing: false }), 1200);
});

// Show thread shell early for deep-links; actual open happens once via applyPreselectOnce()
if (PRESELECT && PRESELECT.customer_id) {
  ensurePreselectInList();
  emptyThread.style.display = 'none';
  activeThread.style.display = 'flex';
  threadTitle.textContent = PRESELECT.customer_name || ('Customer #' + PRESELECT.customer_id);
  threadSub.textContent = PRESELECT.customer_email || '';
  renderConvs();
}
</script>
</body>
</html>
<?php $conn->close(); ?>
