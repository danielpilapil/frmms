<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>FleetGo — Browse Vehicles</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{ --bg:#0b0d10; --card:#101419; --muted:#9aa6b3; --text:#f2f6fa; --brand:#5dd0ff; --brand-2:#7cffc7; --radius:14px; --shadow:0 6px 18px rgba(0,0,0,.35); --container:1280px; }
  html,body{height:100%;margin:0}
  body{font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--text);line-height:1.6;overflow-x:hidden}
  a{color:inherit;text-decoration:none}
  .container{max-width:var(--container);margin:0 auto;padding:0 2rem}
  *,*:before,*:after{box-sizing:border-box}

  /* Navbar */
  header{position:sticky;top:0;z-index:50;background:#0b0d10e8;backdrop-filter:blur(8px);border-bottom:1px solid rgba(255,255,255,.08)}
  .nav{display:flex;align-items:center;justify-content:space-between;padding:1rem 0}
  .brand{display:flex;align-items:center;gap:.6rem;font-weight:700}
  .logo{width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,var(--brand),var(--brand-2));display:grid;place-items:center;color:#04121b;font-weight:800}
  .nav-links{display:flex;align-items:center;gap:.6rem}
  .nav-links a{padding:.55rem .85rem;border-radius:8px;color:var(--muted);transition:.25s;white-space:nowrap}
  .nav-links a:hover{background:rgba(255,255,255,.08);color:var(--text)}
  .btn{min-width:120px;cursor:pointer;padding:12px 18px;border:0;border-radius:10px;font-weight:600;color:#04121b;background:linear-gradient(90deg,var(--brand),var(--brand-2));transition:.25s;display:inline-flex;align-items:center;justify-content:center}
  .btn:hover{transform:translateY(-2px);box-shadow:0 0 14px rgba(93,208,255,.35)}
  .ghost{background:#0d1116;border:1px solid rgba(255,255,255,.16);color:#eaf3ff;border-radius:10px;padding:.6rem .9rem;cursor:pointer}

  .hero{padding:28px 0 4px}
  .hero h1{margin:0;font-size:clamp(1.8rem,4.5vw,2.4rem)}
  .sub{margin:6px 0 0;color:#bed0e0}

  .card{background:var(--card);border:1px solid rgba(255,255,255,.10);border-radius:16px;padding:18px}
  .filters{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}
  @media (max-width:1100px){ .filters{grid-template-columns:repeat(3,1fr)} }
  @media (max-width:680px){ .filters{grid-template-columns:1fr} }
  label{color:#cfe0f5;font-size:.9rem}
  input,select{width:100%;padding:.75rem .8rem;border-radius:10px;border:1px solid rgba(255,255,255,.16);background:#0d1116;color:var(--text);outline:none}
  input:focus,select:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(93,208,255,.16)}

  .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:16px 0 8px}
  @media (max-width:1100px){ .grid{grid-template-columns:repeat(2,1fr)} }
  @media (max-width:680px){ .grid{grid-template-columns:1fr} }

  .vcard{display:flex;flex-direction:column;gap:10px}
  .vimg{width:100%;aspect-ratio:16/9;border-radius:12px;background:#0d1116;border:1px solid rgba(255,255,255,.08);object-fit:cover}
  .row{display:flex;justify-content:space-between;align-items:center;gap:8px}
  .muted{color:#9aa6b3}
  .pill{font-size:.82rem;padding:.25rem .6rem;border-radius:999px;border:1px solid rgba(255,255,255,.16);background:#0d1116}

  .pagination{display:flex;gap:8px;justify-content:center;margin:18px 0 30px}
  .pagination a,.pagination span{padding:.55rem .8rem;border-radius:10px;border:1px solid rgba(255,255,255,.16);background:#0d1116;cursor:pointer;user-select:none}
  .pagination .active{background:rgba(124,255,199,.12);border-color:rgba(124,255,199,.35)}

  .backdrop{position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:1000;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .25s,visibility .25s}
  .backdrop.show{opacity:1;visibility:visible;pointer-events:auto}
  .modal{width:min(760px,92vw);background:var(--card);border:1px solid rgba(255,255,255,.12);border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.5);padding:16px}

  /* Pricing modal bits */
  .price-table{width:100%;border-collapse:collapse;margin:8px 0 12px}
  .price-table th,.price-table td{padding:.6rem .75rem;border-bottom:1px solid rgba(255,255,255,.12)}
  .price-table th{color:#cfe0f5;text-align:left}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media (max-width:680px){ .grid-2{grid-template-columns:1fr} }
  .addon{display:flex;justify-content:space-between;align-items:center;border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:.6rem .75rem;background:#0d1116}
</style>
</head>
<body>

<header>
  <div class="container nav">
    <a href="#" class="brand"><span class="logo">FG</span>FleetGo</a>
    <nav class="nav-links">
      <!-- Vehicles removed -->
      <a href="#" class="ghost" id="openPricing">Pricing</a>
      <a href="http://localhost/FRMMS/userprofile.php" class="ghost">Profile</a>
      <a href="#" class="btn" id="openQuickBlank">+ New Booking</a>
    </nav>
  </div>
</header>

<main class="container">
  <section class="hero">
    <h1>Find your vehicle</h1>
    <p class="sub">Filter by type, seats, budget, and availability, then start a booking.</p>
  </section>

  <!-- Filters -->
  <form class="card" id="filterForm">
    <div class="filters">
      <div>
        <label>Type</label>
        <select name="type" id="fType">
          <option value="all">All types</option>
          <option>Sedan</option><option>SUV</option><option>Van</option>
          <option>Pickup</option><option>Motorcycle</option>
        </select>
      </div>
      <div>
        <label>Transmission</label>
        <select name="transmission" id="fTrans">
          <option value="all">Any</option>
          <option value="AT">Automatic</option>
          <option value="MT">Manual</option>
        </select>
      </div>
      <div>
        <label>Seats (min)</label>
        <input type="number" id="fSeats" min="0" value="0" />
      </div>
      <div>
        <label>Min ₱/day</label>
        <input type="number" step="0.01" id="fMin" />
      </div>
      <div>
        <label>Max ₱/day</label>
        <input type="number" step="0.01" id="fMax" />
      </div>
      <div>
        <label>Search</label>
        <input type="text" id="fQ" placeholder="Model or type…" />
      </div>

      <div>
        <label>Start</label>
        <input type="datetime-local" id="fStart" />
      </div>
      <div>
        <label>End</label>
        <input type="datetime-local" id="fEnd" />
      </div>

      <div style="display:flex;align-items:end;gap:8px">
        <button class="btn" type="submit">Apply Filters</button>
        <button class="ghost" type="button" id="resetBtn">Reset</button>
      </div>
    </div>
  </form>

  <!-- Results -->
  <section class="grid" id="results" aria-live="polite"></section>

  <!-- Pagination -->
  <nav class="pagination" id="pagi" aria-label="Pagination" hidden></nav>
</main>

<!-- PRICING MODAL -->
<div class="backdrop" id="pricingBackdrop" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="pricingTitle">
    <header style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid rgba(255,255,255,.10);padding-bottom:.6rem;margin-bottom:.8rem">
      <h3 id="pricingTitle" style="margin:0">Pricing & Add-ons</h3>
      <button class="ghost" type="button" onclick="closePricing()">✕</button>
    </header>

    <section>
      <h4 style="margin:.3rem 0 .4rem">Daily Rates (by type)</h4>
      <table class="price-table" id="ratesTable"><!-- filled by JS --></table>
    </section>

    <section class="grid-2">
      <div>
        <h4 style="margin:.3rem 0 .4rem">Add-ons (per day)</h4>
        <div id="addonsList"><!-- filled by JS --></div>
        <p class="muted" style="margin-top:.5rem">Prices shown are estimates for reference only.</p>
      </div>

      <div>
        <h4 style="margin:.3rem 0 .4rem">Quick Estimator</h4>
        <div class="card" style="padding:12px">
          <div class="row" style="margin:.35rem 0">
            <label for="estType">Vehicle type</label>
            <select id="estType"></select>
          </div>
          <div class="row" style="margin:.35rem 0">
            <label for="estDays">Days</label>
            <input id="estDays" type="number" min="1" value="1" />
          </div>
          <div class="row" style="margin:.35rem 0">
            <label>Selected add-ons</label>
            <div id="estAddons" class="muted">(none)</div>
          </div>
          <div class="row" style="margin:.35rem 0">
            <strong>Estimated total:</strong>
            <div id="estTotal" style="font-size:1.15rem;font-weight:700">₱0.00</div>
          </div>
        </div>
      </div>
    </section>

    <div class="actions" style="display:flex;justify-content:flex-end;margin-top:12px">
      <button class="ghost" onclick="closePricing()">Close</button>
    </div>
  </div>
</div>

<!-- QUICK BOOK MODAL (with renter details) -->
<div class="backdrop" id="qbBackdrop" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="qbTitle">
    <header style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid rgba(255,255,255,.10);padding-bottom:.6rem;margin-bottom:.8rem">
      <h3 id="qbTitle" style="margin:0">Quick Book</h3>
      <button class="ghost" type="button" onclick="closeQuickBook()">✕</button>
    </header>

    <form id="qbForm">
      <input type="hidden" id="qbVehicleId">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div>
          <label>Vehicle</label>
          <input id="qbVehicleName" disabled>
        </div>
        <div>
          <label>Rate</label>
          <input id="qbRateInfo" disabled>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
        <div>
          <label>Start</label>
          <input type="datetime-local" id="qbStart" required>
        </div>
        <div>
          <label>End</label>
          <input type="datetime-local" id="qbEnd" required>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px">
        <div>
          <label>Pickup City</label>
          <input type="text" id="qbPickup" placeholder="e.g., Makati" required>
        </div>
        <div>
          <label>Return City</label>
          <input type="text" id="qbReturn" placeholder="e.g., Makati" required>
        </div>
      </div>

      <div style="margin-top:14px">
        <h4 style="margin:0 0 .35rem">Renter Details</h4>
        <div class="grid-2">
          <div>
            <label for="qbName">Full name</label>
            <input id="qbName" type="text" placeholder="Juan Dela Cruz" required>
          </div>
          <div>
            <label for="qbPhone">Phone</label>
            <input id="qbPhone" type="tel" placeholder="+63 9XX XXX XXXX" required>
          </div>
        </div>
        <div class="row" style="margin-top:8px">
          <label for="qbEmail">Email</label>
          <input id="qbEmail" type="email" placeholder="you@example.com" required>
        </div>
      </div>

      <div style="margin-top:10px">
        <label>Notes (optional)</label>
        <input type="text" id="qbNotes" placeholder="Child seat / special request">
      </div>

      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
        <button class="ghost" type="button" onclick="closeQuickBook()">Cancel</button>
        <button class="btn" type="submit">Create Booking</button>
      </div>
    </form>
  </div>
</div>

<script>
/* ========= Mock data ========= */
const MOCK_VEHICLES = [
  { id:1, make_model:"Toyota Vios 1.3 XE", type:"Sedan", seats:5, transmission:"AT", daily_rate:1800, status:"available", image_url:"" },
  { id:2, make_model:"Honda City RS", type:"Sedan", seats:5, transmission:"AT", daily_rate:2200, status:"available", image_url:"" },
  { id:3, make_model:"Mitsubishi Xpander", type:"Van", seats:7, transmission:"AT", daily_rate:2500, status:"available", image_url:"" },
  { id:4, make_model:"Toyota Hiace Commuter", type:"Van", seats:12, transmission:"MT", daily_rate:3200, status:"available", image_url:"" },
  { id:5, make_model:"Ford Ranger 2.0", type:"Pickup", seats:5, transmission:"AT", daily_rate:3000, status:"available", image_url:"" },
  { id:6, make_model:"Toyota Fortuner G", type:"SUV", seats:7, transmission:"AT", daily_rate:3500, status:"available", image_url:"" },
  { id:7, make_model:"Honda CR-V", type:"SUV", seats:7, transmission:"AT", daily_rate:3600, status:"available", image_url:"" },
  { id:8, make_model:"Suzuki S-Presso", type:"Sedan", seats:5, transmission:"MT", daily_rate:1500, status:"available", image_url:"" },
  { id:9, make_model:"Kawasaki CT125", type:"Motorcycle", seats:2, transmission:"MT", daily_rate:700, status:"available", image_url:"" },
  { id:10, make_model:"Nissan Terra VE", type:"SUV", seats:7, transmission:"AT", daily_rate:3800, status:"available", image_url:"" },
  { id:11, make_model:"Isuzu mu-X", type:"SUV", seats:7, transmission:"AT", daily_rate:3700, status:"available", image_url:"" },
  { id:12, make_model:"Hyundai Staria", type:"Van", seats:11, transmission:"AT", daily_rate:4000, status:"available", image_url:"" },
];

const PAGE_SIZE = 9;
const ADDONS = [
  { key:'child_seat', label:'Child seat', per_day:150 },
  { key:'additional_driver', label:'Additional driver', per_day:200 },
  { key:'full_insurance', label:'Full insurance', per_day:600 },
  { key:'gps', label:'GPS unit', per_day:120 },
];

/* ========= Elements ========= */
const resultsEl = document.getElementById('results');
const pagiEl    = document.getElementById('pagi');

const fType  = document.getElementById('fType');
const fTrans = document.getElementById('fTrans');
const fSeats = document.getElementById('fSeats');
const fMin   = document.getElementById('fMin');
const fMax   = document.getElementById('fMax');
const fQ     = document.getElementById('fQ');
const fStart = document.getElementById('fStart');
const fEnd   = document.getElementById('fEnd');

let currentPage = 1;

/* ========= Utils ========= */
const placeholderImg = 'data:image/svg+xml;utf8,' + encodeURIComponent(`
  <svg xmlns="http://www.w3.org/2000/svg" width="800" height="450">
    <defs><linearGradient id="g" x1="0" x2="1"><stop stop-color="#0d1116"/><stop offset="1" stop-color="#18202a"/></linearGradient></defs>
    <rect width="100%" height="100%" fill="url(#g)"/>
    <text x="50%" y="50%" fill="#9aa6b3" font-family="Inter, sans-serif" font-size="28" dominant-baseline="middle" text-anchor="middle">Vehicle Image</text>
  </svg>
`);

function currency(n){ return '₱' + Number(n).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}); }
function validRange(start, end){
  if(!start || !end) return false;
  const s = new Date(start), e = new Date(end);
  return e > s;
}

/* ========= Filtering + Rendering ========= */
function getFilters(){
  return {
    type: fType.value || 'all',
    transmission: fTrans.value || 'all',
    seats: Number(fSeats.value || 0),
    min: fMin.value === '' ? null : Number(fMin.value),
    max: fMax.value === '' ? null : Number(fMax.value),
    q: (fQ.value || '').trim().toLowerCase(),
    start: fStart.value,
    end: fEnd.value
  };
}

function applyFilters(data, filters){
  let out = data.filter(v => String(v.status).toLowerCase() === 'available');

  if(filters.type !== 'all'){
    out = out.filter(v => v.type.toLowerCase() === filters.type.toLowerCase());
  }
  if(filters.transmission !== 'all'){
    out = out.filter(v => v.transmission.toLowerCase() === filters.transmission.toLowerCase());
  }
  if(filters.seats > 0){
    out = out.filter(v => Number(v.seats) >= filters.seats);
  }
  if(filters.min !== null){
    out = out.filter(v => Number(v.daily_rate) >= filters.min);
  }
  if(filters.max !== null){
    out = out.filter(v => Number(v.daily_rate) <= filters.max);
  }
  if(filters.q){
    out = out.filter(v =>
      v.make_model.toLowerCase().includes(filters.q) ||
      v.type.toLowerCase().includes(filters.q)
    );
  }
  if(filters.start && filters.end && !validRange(filters.start, filters.end)){
    out = [];
  }
  return out;
}

function renderPage(items, page){
  const start = (page-1) * PAGE_SIZE;
  return items.slice(start, start + PAGE_SIZE);
}

function renderResults(list){
  resultsEl.innerHTML = '';
  if(list.length === 0){
    resultsEl.innerHTML = `
      <div class="card" style="grid-column:1/-1">
        <strong>No vehicles match your filters.</strong>
        <div class="muted">Try widening your dates or removing some filters.</div>
      </div>
    `;
    pagiEl.hidden = true;
    return;
  }

  const frag = document.createDocumentFragment();
  list.forEach(v => {
    const art = document.createElement('article');
    art.className = 'card vcard';
    art.innerHTML = `
      <img class="vimg" src="${v.image_url || placeholderImg}" alt="${escapeHtml(v.make_model)}" onerror="this.src='${placeholderImg}'">
      <div class="row">
        <div>
          <div style="font-weight:700">${escapeHtml(v.make_model)}</div>
          <div class="muted">${escapeHtml(v.type)} • ${Number(v.seats)} seats • ${escapeHtml(v.transmission)}</div>
        </div>
        <div style="text-align:right">
          <div style="font-weight:700">${currency(v.daily_rate)}</div>
          <div class="muted">per day</div>
        </div>
      </div>
      <div class="row">
        <span class="pill">${String(v.status).toUpperCase()}</span>
        <button class="btn" type="button" data-qb="${v.id}">Quick Book</button>
      </div>
    `;
    frag.appendChild(art);   // ✅ fixed: no stray parenthesis
  });
  resultsEl.appendChild(frag);

  document.querySelectorAll('[data-qb]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = Number(btn.getAttribute('data-qb'));
      const item = MOCK_VEHICLES.find(x=>x.id===id);
      if(item) openQuickBook(item);
    });
  });
}

function renderPagination(totalCount){
  const pages = Math.max(1, Math.ceil(totalCount / PAGE_SIZE));
  if(pages <= 1){ pagiEl.hidden = true; pagiEl.innerHTML = ''; return; }
  pagiEl.hidden = false;

  const makeBtn = (p, label = null, active = false) => {
    const el = document.createElement('a');
    el.textContent = label || String(p);
    if(active) el.classList.add('active');
    el.addEventListener('click', ()=> {
      currentPage = p;
      run();
      window.scrollTo({top:0, behavior:'smooth'});
    });
    return el;
  };

  const frag = document.createDocumentFragment();
  frag.appendChild(makeBtn(Math.max(1, currentPage-1), '« Prev', false));
  for(let i=Math.max(1,currentPage-2); i<=Math.min(pages,currentPage+2); i++){
    frag.appendChild(makeBtn(i, null, i===currentPage));
  }
  frag.appendChild(makeBtn(Math.min(pages, currentPage+1), 'Next »', false));

  pagiEl.innerHTML = '';
  pagiEl.appendChild(frag);
}

/* ========= Quick Book modal ========= */
const qbBd   = document.getElementById('qbBackdrop');
const qbForm = document.getElementById('qbForm');

function showBackdrop(el){ el.classList.add('show'); el.setAttribute('aria-hidden','false'); }
function hideBackdrop(el){ el.classList.remove('show'); el.setAttribute('aria-hidden','true'); }
function toLocalValue(dt){
  const d = dt instanceof Date ? dt : new Date(dt);
  const pad = n=> String(n).padStart(2,'0');
  return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())+'T'+pad(d.getHours())+':'+pad(d.getMinutes());
}

function openQuickBook(item){
  document.getElementById('qbVehicleId').value = item.id;
  document.getElementById('qbVehicleName').value = item.make_model || '(Select vehicle from list)';
  document.getElementById('qbRateInfo').value = item.daily_rate ? currency(item.daily_rate) : '—';

  const now = new Date();
  const twoHrs = new Date(now.getTime()+2*60*60*1000);
  const nextDay = new Date(twoHrs.getTime()+24*60*60*1000);
  document.getElementById('qbStart').value = toLocalValue(twoHrs);
  document.getElementById('qbEnd').value   = toLocalValue(nextDay);

  document.getElementById('qbPickup').value = '';
  document.getElementById('qbReturn').value = '';
  document.getElementById('qbNotes').value  = '';
  document.getElementById('qbName').value   = '';
  document.getElementById('qbPhone').value  = '';
  document.getElementById('qbEmail').value  = '';

  showBackdrop(qbBd);
  setTimeout(()=> document.getElementById('qbStart').focus(), 50);
}

function closeQuickBook(){ hideBackdrop(qbBd); }
qbBd.addEventListener('click', (e)=>{ if(e.target===qbBd) closeQuickBook(); });

qbForm.addEventListener('submit', (e)=>{
  e.preventDefault();
  const s = new Date(document.getElementById('qbStart').value);
  const en = new Date(document.getElementById('qbEnd').value);
  if(!(en > s)){ alert('End date/time must be after start.'); return; }

  const payload = {
    vehicle_id: document.getElementById('qbVehicleId').value || null,
    start_at: document.getElementById('qbStart').value,
    end_at: document.getElementById('qbEnd').value,
    pickup_city: document.getElementById('qbPickup').value,
    return_city: document.getElementById('qbReturn').value,
    notes: document.getElementById('qbNotes').value,
    name: document.getElementById('qbName').value,
    phone: document.getElementById('qbPhone').value,
    email: document.getElementById('qbEmail').value
  };

  console.log('BOOKING DEMO:', payload);
  alert('Booking created (demo only). Wire this to your backend next.');
  closeQuickBook();
});

/* Navbar buttons */
document.getElementById('openQuickBlank').addEventListener('click', (e)=>{
  e.preventDefault();
  openQuickBook({ id: 0, make_model: '(Select vehicle from list)', daily_rate: 0 });
});

/* ========= Pricing modal ========= */
const prBd = document.getElementById('pricingBackdrop');
const ratesTable = document.getElementById('ratesTable');
const addonsList = document.getElementById('addonsList');
const estTypeSel = document.getElementById('estType');
const estDaysEl  = document.getElementById('estDays');
const estAddons  = document.getElementById('estAddons');
const estTotal   = document.getElementById('estTotal');

function openPricing(){
  buildRatesAndEstimator();
  showBackdrop(prBd);
}
function closePricing(){ hideBackdrop(prBd); }
document.getElementById('openPricing').addEventListener('click', (e)=>{ e.preventDefault(); openPricing(); });
prBd.addEventListener('click', (e)=>{ if(e.target===prBd) closePricing(); });

function buildRatesAndEstimator(){
  const byType = {};
  MOCK_VEHICLES.forEach(v=>{
    const t = v.type;
    byType[t] = Math.min(byType[t] ?? Infinity, v.daily_rate);
  });

  // Rates table
  const rows = Object.keys(byType).sort().map(t=>{
    return `<tr><th>${escapeHtml(t)}</th><td>${currency(byType[t])} / day</td></tr>`;
  }).join('');
  ratesTable.innerHTML = `<thead><tr><th>Type</th><th>Rate</th></tr></thead><tbody>${rows}</tbody>`;

  // Estimator type select
  estTypeSel.innerHTML = Object.keys(byType).sort().map(t=>`<option value="${escapeHtml(t)}">${escapeHtml(t)}</option>`).join('');
  estTypeSel.value = Object.keys(byType)[0] || '';

  // Add-ons
  addonsList.innerHTML = ADDONS.map(a => `
    <label class="addon">
      <span>${escapeHtml(a.label)}</span>
      <span>
        <input type="checkbox" data-addon="${a.key}"> <span class="muted" style="margin-left:.4rem">${currency(a.per_day)}/day</span>
      </span>
    </label>
  `).join('');

  // Estimator wiring
  const update = ()=>{
    const t = estTypeSel.value;
    const days = Math.max(1, Number(estDaysEl.value||1));
    const base = (byType[t] || 0) * days;

    const chosen = [...document.querySelectorAll('[data-addon]:checked')].map(ch=>{
      return ADDONS.find(a=>a.key===ch.getAttribute('data-addon'));
    }).filter(Boolean);

    const addonsTotal = chosen.reduce((sum,a)=> sum + a.per_day*days, 0);
    estAddons.textContent = chosen.length ? chosen.map(a=>a.label).join(', ') : '(none)';
    estTotal.textContent = currency(base + addonsTotal);
  };

  estTypeSel.onchange = update;
  estDaysEl.oninput = update;
  document.querySelectorAll('[data-addon]').forEach(cb=> cb.addEventListener('change', update));
  update();
}

/* ========= Form behavior ========= */
document.getElementById('filterForm').addEventListener('submit', (e)=>{
  e.preventDefault();
  currentPage = 1;
  run();
});

document.getElementById('resetBtn').addEventListener('click', ()=>{
  fType.value = 'all';
  fTrans.value = 'all';
  fSeats.value = 0;
  fMin.value = '';
  fMax.value = '';
  fQ.value = '';
  fStart.value = '';
  fEnd.value = '';
  currentPage = 1;
  run();
});

/* ========= Escape to close modals ========= */
window.addEventListener('keydown', (e)=>{
  if(e.key==='Escape'){
    if(qbBd.classList.contains('show')) closeQuickBook();
    if(prBd.classList.contains('show')) closePricing();
  }
});

/* ========= Render pipeline ========= */
function run(){
  const filters = getFilters();
  const filtered = applyFilters(MOCK_VEHICLES, filters);
  const pageItems = renderPage(filtered, currentPage);
  renderResults(pageItems);
  renderPagination(filtered.length);
}

/* ========= Helpers ========= */
function escapeHtml(s){
  return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
}

/* Initial paint */
run();
</script>
</body>
</html>
