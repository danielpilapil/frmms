<?php
$geminiRole = ($geminiRole ?? '') === 'user' ? 'user' : 'admin';
$geminiAction = $geminiRole === 'user' ? 'customer_chat' : 'admin_chat';
$geminiHint = $geminiRole === 'user'
    ? 'Ask about vehicles, rates, policies, or your bookings.'
    : 'Ask about the fleet, rentals, earnings, or maintenance.';
$geminiFaqs = $geminiRole === 'user'
    ? [
        'Which cars are discounted right now?',
        'How do I book a car?',
        'Where are you located?',
        'What is the late fee?',
    ]
    : [
        'Which vehicles are free today?',
        'What rentals are active?',
        'What maintenance is coming up?',
        'How were earnings in the last 30 days?',
    ];
?>
<style>
#fgGeminiBtn{position:fixed;right:22px;bottom:22px;z-index:1200;border:0;border-radius:999px;padding:12px 16px;font-weight:800;cursor:pointer;color:#04121b;background:linear-gradient(135deg,#5dd0ff,#7cffc7);box-shadow:0 10px 28px rgba(0,0,0,.35);font-family:Inter,system-ui,sans-serif}
#fgGeminiPanel{position:fixed;right:22px;bottom:76px;z-index:1200;width:min(400px,calc(100vw - 28px));height:min(580px,calc(100vh - 110px));display:none;flex-direction:column;background:#101419;color:#f2f6fa;border:1px solid rgba(93,208,255,.25);border-radius:18px;box-shadow:0 18px 50px rgba(0,0,0,.45);overflow:hidden;font-family:Inter,system-ui,sans-serif}
#fgGeminiPanel.open{display:flex}
#fgGeminiHead{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.08)}
#fgGeminiHead strong{font-size:.95rem}
#fgGeminiHead span{display:block;color:#9aa6b3;font-size:.75rem;font-weight:600;margin-top:2px}
#fgGeminiTools{display:flex;align-items:center;gap:8px}
#fgGeminiEnd,#fgGeminiClose{background:transparent;border:0;color:#f2f6fa;cursor:pointer}
#fgGeminiEnd{font-size:.75rem;font-weight:700;color:#9aa6b3}
#fgGeminiClose{font-size:1.3rem}
#fgGeminiFaqs{display:flex;gap:6px;flex-wrap:wrap;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.08)}
#fgGeminiFaqs[hidden]{display:none}
.fg-g-faq{display:inline-flex;align-items:center;gap:2px;border:1px solid rgba(93,208,255,.35);background:rgba(93,208,255,.08);color:#d7f6ff;border-radius:999px;padding:4px 4px 4px 10px;font-size:.75rem;font-weight:700}
.fg-g-faq-q,.fg-g-faq-x{border:0;background:transparent;color:inherit;font:inherit;font-weight:700;cursor:pointer}
.fg-g-faq-q{padding:2px 0;text-align:left}
.fg-g-faq-x{width:22px;height:22px;border-radius:999px;color:#9aa6b3;font-size:1rem;line-height:1}
.fg-g-faq-x:hover{background:rgba(255,255,255,.12);color:#fff}
.fg-g-cars{display:flex;flex-direction:column;gap:8px;margin-top:8px;max-width:100%}
.fg-g-car{display:flex;gap:10px;align-items:center;text-decoration:none;color:inherit;background:rgba(255,255,255,.05);border:1px solid rgba(93,208,255,.2);border-radius:12px;padding:8px}
.fg-g-car img{width:84px;height:60px;object-fit:cover;border-radius:8px;background:#0b0d10;flex:0 0 auto}
.fg-g-car strong{display:block;font-size:.86rem}
.fg-g-car span{display:block;color:#9aa6b3;font-size:.75rem;margin-top:2px}
.fg-g-view{display:inline-block;margin-top:6px;background:#7cffc7;color:#04121b;font-weight:800;font-size:.75rem;border-radius:999px;padding:4px 8px}
.fg-g-faq:hover{background:rgba(93,208,255,.18)}
#fgGeminiLog{flex:1;overflow:auto;padding:14px;display:flex;flex-direction:column;gap:12px}
.fg-g-turn{display:flex;flex-direction:column;gap:4px;max-width:88%}
.fg-g-turn.user{align-self:flex-end;align-items:flex-end}
.fg-g-turn.model{align-self:stretch;align-items:flex-start;max-width:100%}
.fg-g-who{font-size:.68rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase}
.fg-g-turn.user .fg-g-who{color:#7ec8ea}
.fg-g-turn.model .fg-g-who{color:#7cffc7}
.fg-g-msg{padding:10px 12px;border-radius:12px;line-height:1.45;font-size:.9rem;white-space:pre-wrap}
.fg-g-turn.model .fg-g-msg{max-width:88%}
.fg-g-msg.user{background:rgba(93,208,255,.16)}
.fg-g-msg.model{background:rgba(255,255,255,.06)}
.fg-g-turn.model .fg-g-cars{width:100%}
#fgGeminiForm{display:flex;gap:8px;padding:12px;border-top:1px solid rgba(255,255,255,.08)}
#fgGeminiInput{flex:1;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:#0b0d10;color:#f2f6fa;padding:10px 12px;font:inherit}
#fgGeminiForm button{border:0;border-radius:12px;padding:0 14px;font-weight:800;cursor:pointer;color:#04121b;background:#7cffc7}
</style>
<button type="button" id="fgGeminiBtn">Ask FleetGo</button>
<section id="fgGeminiPanel" aria-label="FleetGo assistant">
  <div id="fgGeminiHead">
    <div>
      <strong>FleetGo assistant</strong>
      <span><?= htmlspecialchars($geminiHint, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div id="fgGeminiTools">
      <button type="button" id="fgGeminiEnd">End chat</button>
      <button type="button" id="fgGeminiClose" aria-label="Close">&times;</button>
    </div>
  </div>
  <div id="fgGeminiFaqs"></div>
  <div id="fgGeminiLog"></div>
  <form id="fgGeminiForm">
    <input id="fgGeminiInput" type="text" maxlength="2000" placeholder="Type a question" autocomplete="off">
    <button type="submit">Send</button>
  </form>
</section>
<script>
(function () {
  const action = <?= json_encode($geminiAction) ?>;
  const faqs = <?= json_encode(array_values($geminiFaqs), JSON_UNESCAPED_UNICODE) ?>;
  const storageKey = 'fgGeminiThread:' + action;
  const hiddenKey = 'fgGeminiHiddenFaqs:' + action;
  const panel = document.getElementById('fgGeminiPanel');
  const log = document.getElementById('fgGeminiLog');
  const form = document.getElementById('fgGeminiForm');
  const input = document.getElementById('fgGeminiInput');
  const history = [];
  let sending = false;

  function saveThread() {
    try {
      sessionStorage.setItem(storageKey, JSON.stringify(history));
    } catch (err) {}
  }

  function buildCarList(cars) {
    const list = document.createElement('div');
    list.className = 'fg-g-cars';
    cars.forEach(function (car) {
      if (!car || !car.name || !/^vehiclepage\.php\?book=\d+$/.test(String(car.href || ''))) return;
      const link = document.createElement('a');
      link.className = 'fg-g-car';
      link.href = String(car.href);
      const img = document.createElement('img');
      const photo = String(car.photo || '');
      img.src = photo.indexOf('assets/vehicles/') === 0 ? photo : 'assets/vehicles/images.jpeg';
      img.alt = car.name;
      img.addEventListener('error', function () { img.src = 'assets/vehicles/images.jpeg'; });
      const info = document.createElement('div');
      const name = document.createElement('strong');
      name.textContent = car.name;
      const price = document.createElement('span');
      price.textContent = car.discount ? (car.price + ' · ' + car.discount) : (car.price || '');
      const view = document.createElement('span');
      view.className = 'fg-g-view';
      view.textContent = 'View Car';
      info.appendChild(name);
      info.appendChild(price);
      info.appendChild(view);
      link.appendChild(img);
      link.appendChild(info);
      list.appendChild(link);
    });
    return list;
  }

  function addMsg(role, text, cars) {
    const mine = role === 'user';
    const wrap = document.createElement('div');
    wrap.className = 'fg-g-turn ' + (mine ? 'user' : 'model');
    const who = document.createElement('div');
    who.className = 'fg-g-who';
    who.textContent = mine ? 'You' : 'FleetGo';
    const el = document.createElement('div');
    el.className = 'fg-g-msg ' + (mine ? 'user' : 'model');
    el.textContent = text;
    wrap.appendChild(who);
    wrap.appendChild(el);
    if (role === 'model' && Array.isArray(cars) && cars.length) {
      wrap.appendChild(buildCarList(cars));
    }
    log.appendChild(wrap);
    log.scrollTop = log.scrollHeight;
    return el;
  }

  function hiddenFaqs() {
    try {
      const saved = JSON.parse(sessionStorage.getItem(hiddenKey) || '[]');
      return Array.isArray(saved) ? saved : [];
    } catch (err) {
      return [];
    }
  }

  function dismissFaq(question, chip) {
    const next = hiddenFaqs();
    if (next.indexOf(question) === -1) next.push(question);
    try { sessionStorage.setItem(hiddenKey, JSON.stringify(next)); } catch (err) {}
    chip.remove();
    const box = document.getElementById('fgGeminiFaqs');
    if (!box.children.length) box.hidden = true;
  }

  function renderFaqs() {
    const box = document.getElementById('fgGeminiFaqs');
    const hidden = hiddenFaqs();
    box.innerHTML = '';
    faqs.forEach(function (question) {
      if (hidden.indexOf(question) !== -1) return;
      const chip = document.createElement('div');
      chip.className = 'fg-g-faq';
      const ask = document.createElement('button');
      ask.type = 'button';
      ask.className = 'fg-g-faq-q';
      ask.textContent = question;
      ask.addEventListener('click', function () {
        dismissFaq(question, chip);
        panel.classList.add('open');
        sendMessage(question);
      });
      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'fg-g-faq-x';
      close.setAttribute('aria-label', 'Dismiss ' + question);
      close.textContent = '\u00d7';
      close.addEventListener('click', function () {
        dismissFaq(question, chip);
      });
      chip.appendChild(ask);
      chip.appendChild(close);
      box.appendChild(chip);
    });
    box.hidden = box.children.length === 0;
  }

  function restoreThread() {
    let saved = [];
    try {
      saved = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
    } catch (err) {
      saved = [];
    }
    if (!Array.isArray(saved)) return;
    saved.forEach(function (turn) {
      if (!turn || !turn.text) return;
      const role = turn.role === 'model' ? 'model' : 'user';
      history.push({ role: role, text: String(turn.text), cars: Array.isArray(turn.cars) ? turn.cars : [] });
      addMsg(role, String(turn.text), turn.cars);
    });
  }

  function endThread() {
    history.splice(0, history.length);
    log.textContent = '';
    try {
      sessionStorage.removeItem(storageKey);
      sessionStorage.removeItem(hiddenKey);
    } catch (err) {}
    renderFaqs();
  }

  async function sendMessage(message) {
    message = String(message || '').trim();
    if (!message || sending) return;
    sending = true;
    input.value = '';
    addMsg('user', message);
    const pending = addMsg('model', 'Thinking…');
    try {
      const res = await fetch('includes/ajax_gemini.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          action: action,
          message: message,
          history: history.slice(-12).map(function (turn) {
            return { role: turn.role, text: turn.text };
          })
        })
      });
      const data = await res.json();
      const text = data && data.ok ? data.text : ((data && data.error) || 'Could not get an answer.');
      pending.textContent = text;
      if (data && data.ok) {
        const cars = Array.isArray(data.cars) ? data.cars : [];
        history.push({ role: 'user', text: message });
        history.push({ role: 'model', text: text, cars: cars });
        if (cars.length && pending.parentElement) {
          pending.parentElement.appendChild(buildCarList(cars));
          log.scrollTop = log.scrollHeight;
        }
        saveThread();
      }
    } catch (err) {
      pending.textContent = 'Could not reach the assistant.';
    } finally {
      sending = false;
    }
  }

  renderFaqs();
  restoreThread();

  document.getElementById('fgGeminiBtn').addEventListener('click', function () {
    panel.classList.add('open');
    input.focus();
  });
  document.getElementById('fgGeminiClose').addEventListener('click', function () {
    panel.classList.remove('open');
  });
  document.getElementById('fgGeminiEnd').addEventListener('click', function () {
    if (history.length && !window.confirm('End this chat? The conversation will be cleared.')) return;
    endThread();
  });
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    sendMessage(input.value);
  });
})();
</script>
