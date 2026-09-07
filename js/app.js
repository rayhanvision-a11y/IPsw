/* ==========================================================================
   IPSW Master - Main Dashboard Application JS
   ========================================================================== */

// All API paths are RELATIVE so they work in any subfolder on cPanel
const API = {
  releases   : 'api/releases/index.php',
  history    : 'api/history/',
  settings   : 'api/settings/',
  devices    : 'api/devices/',
  authStatus : 'api/auth/status.php',
  authLogout : 'api/auth/logout.php',
  idmDownload: 'api/download/idm.php',
  emailSend  : 'api/email/send.php',
};

// ── State ──────────────────────────────────────────────────────────────────
let allReleases      = [];
let importedReleases = [];   // releases loaded from an imported .txt file
let allHistory       = [];
let filteredHistory  = [];
let allDevices       = [];
let currentFilter    = 'all';
let releaseSourceFilter = 'official'; // 'official' | 'beta'
let relDateFrom      = null;
let relDateTo        = null;
let relSingleDate    = null;
let relMultiDates    = new Set();
let multiDateMode    = false;
let histDateFrom     = null;
let histDateTo       = null;
let autoCheckTimer   = null;
let settings         = {};

// Store release objects by index to avoid JSON-in-onclick issues
const releaseStore = new Map();
// Store device firmware data by identifier
const deviceStore  = new Map();

// ── Utility ────────────────────────────────────────────────────────────────
function showToast(msg, type = 'info', duration = 4000) {
  const container = document.getElementById('toast-container');
  const icons  = { success: 'fa-circle-check', error: 'fa-circle-exclamation', info: 'fa-circle-info', warn: 'fa-triangle-exclamation' };
  const colors = { success: '#30d158', error: '#ff453a', info: '#00f2fe', warn: '#ffd60a' };
  const toast  = document.createElement('div');
  toast.className = 'toast';
  toast.style.borderLeftColor = colors[type] || colors.info;
  toast.innerHTML = `<i class="fa-solid ${icons[type] || icons.info}" style="color:${colors[type]};"></i><span>${msg}</span>`;
  container.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(100%)';
    toast.style.transition = '0.3s';
    setTimeout(() => toast.remove(), 350);
  }, duration);
}

function formatBytes(bytes) {
  if (!bytes || bytes === 0) return '0 B';
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + sizes[i];
}

function formatDate(iso) {
  if (!iso) return '—';
  try { return new Date(iso).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }); }
  catch { return iso; }
}

function formatDateTime(iso) {
  if (!iso) return '—';
  try {
    const d = new Date(iso);
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const day   = d.getDate();
    const mon   = months[d.getMonth()];
    const year  = d.getFullYear();
    const hh    = String(d.getHours()).padStart(2,'0');
    const mm    = String(d.getMinutes()).padStart(2,'0');
    return `${day} ${mon} ${year} (${hh}:${mm})`;
  } catch { return iso; }
}

function escapeHtml(str) {
  if (str == null) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function getOsType(name = '', type = '') {
  // Use the API's type field first when available
  if (type) {
    const t = type.toLowerCase();
    if (t.includes('visionos') || t.includes('xros'))   return 'visionOS';
    if (t.includes('ipad'))                              return 'iPadOS';
    if (t.includes('ios'))                               return 'iOS';
    if (t.includes('macos') || t.includes('mac os'))    return 'macOS';
    if (t.includes('watchos'))                           return 'watchOS';
    if (t.includes('tvos'))                              return 'tvOS';
    if (t.includes('audioos') || t.includes('homepod')) return 'audioOS';
  }
  // Fallback: derive from name
  const n = name.toLowerCase();
  if (n.includes('visionos') || n.includes('vision pro') || n.includes('xros')) return 'visionOS';
  if (n.includes('ipad'))                              return 'iPadOS';
  if (n.includes('iphone') || n.includes('ios'))      return 'iOS';
  if (n.includes('macos') || n.includes('mac os') || n.includes('osx')) return 'macOS';
  if (n.includes('watchos') || n.includes('watch'))   return 'watchOS';
  if (n.includes('tvos') || n.includes('apple tv'))   return 'tvOS';
  if (n.includes('audioos') || n.includes('homepod')) return 'audioOS';
  return 'Other';
}

// ── Tab Navigation ─────────────────────────────────────────────────────────
function initTabs() {
  document.getElementById('main-nav')?.addEventListener('click', e => {
    const btn = e.target.closest('[data-tab]');
    if (!btn) return;
    const tab    = btn.dataset.tab;
    const source = btn.dataset.source || null;

    document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    const tabPane = document.getElementById('tab-' + tab);
    if (tabPane) tabPane.classList.add('active');

    if (source !== null) {
      releaseSourceFilter = source;
      apply2MonthsFilter();
      renderReleases();
    }
    if (tab === 'settings') loadSettings();
  });
}

// ── Auth ───────────────────────────────────────────────────────────────────
async function checkAuth() {
  try {
    const res  = await fetch(API.authStatus);
    const data = await res.json();
    if (!data.loggedIn) window.location.href = 'login.php';
  } catch {
    window.location.href = 'login.php';
  }
}

function isBetaReleaseName(name = '', buildid = '') {
  const n = (name || '').toLowerCase();
  if (n.includes('beta') || n.includes(' rc') || n.includes('release candidate') || n.includes('developer') || n.includes('seed')) return true;
  const b = (buildid || '').trim();
  if (b && /[a-z]$/.test(b)) return true;
  const m = n.match(/\(([a-z0-9]+)\)\s*$/);
  if (m && /[a-z]$/.test(m[1])) return true;
  return false;
}

// ── Live Releases ──────────────────────────────────────────────────────────
async function loadReleases() {
  const grid = document.getElementById('releases-grid');
  if (grid) {
    grid.innerHTML = '<div class="loading-spinner"><i class="fa-solid fa-spinner fa-spin"></i><p>Fetching latest firmware releases...</p></div>';
  }

  try {
    let data = null;
    try {
      const res = await fetch(API.releases, { credentials: 'same-origin' });
      if (res.ok) {
        data = await res.json();
      }
    } catch (e) {
      console.warn('Primary API fetch failed, trying static fallback...', e);
    }

    // Static fallback if primary API failed or returned empty
    if (!Array.isArray(data) || data.length === 0) {
      try {
        const fallbackRes = await fetch('data/releases_cache.json');
        if (fallbackRes.ok) {
          const raw = await fallbackRes.json();
          if (Array.isArray(raw)) {
            data = raw.map(rel => {
              if (!rel.source) rel.source = isBetaReleaseName(rel.name || '', rel.buildid || '') ? 'beta' : 'official';
              return rel;
            });
          }
        }
      } catch (e) {
        console.warn('Static cache fallback failed:', e);
      }
    }

    allReleases = Array.isArray(data) ? data : [];
    releaseStore.clear();
    allReleases.forEach((rel, i) => releaseStore.set(i, rel));

    const elChecked = document.getElementById('last-checked');
    if (elChecked) elChecked.textContent = 'Last checked: ' + new Date().toLocaleTimeString();

    populateDateDropdown();
    apply2MonthsFilter();
    updatePresetActive('2months');
    renderReleases();

    // Deep Link check from Gmail URL (e.g. ?buildid=17S5433b)
    const urlParams = new URLSearchParams(window.location.search);
    const targetBuild = urlParams.get('buildid') || urlParams.get('rel');
    if (targetBuild) {
      const matchedRel = allReleases.find(r => 
        (r.buildid && r.buildid.toLowerCase() === targetBuild.toLowerCase()) || 
        (r.name && r.name.toLowerCase().includes(targetBuild.toLowerCase()))
      );
      if (matchedRel) {
        const src = (matchedRel.source === 'beta' || isBetaReleaseName(matchedRel.name || '', matchedRel.buildid || '')) ? 'beta' : 'official';
        releaseSourceFilter = src;
        document.querySelectorAll('.nav-btn').forEach(b => {
          if (b.dataset.source === src) b.classList.add('active');
          else if (b.dataset.source) b.classList.remove('active');
        });
        apply2MonthsFilter();
        renderReleases();
        setTimeout(() => openReleaseFirmwares(matchedRel), 300);
      }
    }

  } catch (err) {
    if (grid) {
      grid.innerHTML = `<div class="empty-state">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <p>Failed to load releases. Click Retry to try again.</p>
        <button class="btn btn-primary" id="btn-retry-releases" style="margin-top:1rem;">
          <i class="fa-solid fa-rotate-right"></i> Retry
        </button></div>`;
      document.getElementById('btn-retry-releases')?.addEventListener('click', loadReleases);
    }
  }
}

function populateDateDropdown() {
  const select = document.getElementById('rel-date-select');
  if (!select) return;
  const dates  = [...new Set(allReleases.map(r => r.date ? r.date.split('T')[0] : null).filter(Boolean))].sort().reverse();

  select.innerHTML = `<option value="all">All Release Dates (${allReleases.length} updates)</option>`;
  dates.forEach(d => {
    const opt = document.createElement('option');
    opt.value = d;
    opt.textContent = formatDate(d);
    select.appendChild(opt);
  });

  // Also populate multi-date panel if element exists
  const list = document.getElementById('multi-date-list');
  if (list) {
    list.innerHTML = dates.map(d => `
      <label class="multi-date-item">
        <input type="checkbox" value="${d}" class="multi-date-cb"> ${formatDate(d)}
      </label>`).join('');
  }
}

function renderReleases() {
  const grid = document.getElementById('releases-grid');
  const source = importedReleases.length > 0 ? importedReleases : allReleases;

  let filtered = source;

  // Source filter (official/beta/all)
  if (releaseSourceFilter !== 'all') {
    filtered = filtered.filter(r => (r.source || 'official') === releaseSourceFilter);
  }

  // OS filter
  if (currentFilter !== 'all') {
    filtered = filtered.filter(r => getOsType(r.name || '', r.type || '') === currentFilter);
  }

  // Date range
  if (relDateFrom || relDateTo) {
    filtered = filtered.filter(r => {
      if (!r.date) return false;
      const dStr = r.date.substring(0, 10);
      if (relDateFrom && dStr < relDateFrom) return false;
      if (relDateTo   && dStr > relDateTo)   return false;
      return true;
    });
  }

  // Single date
  if (relSingleDate) {
    filtered = filtered.filter(r => r.date && r.date.startsWith(relSingleDate));
  }

  // Multi-date
  if (multiDateMode && relMultiDates.size > 0) {
    filtered = filtered.filter(r => r.date && relMultiDates.has(r.date.split('T')[0]));
  }

  // Update status bar
  const badge = document.getElementById('feed-status-badge');
  const text  = document.getElementById('feed-status-text');
  if (relSingleDate) {
    badge.textContent = 'DATE: ' + formatDate(relSingleDate);
    text.textContent  = `Showing ${filtered.length} release(s) for ${formatDate(relSingleDate)}`;
  } else if (relDateFrom || relDateTo) {
    badge.textContent = 'DATE RANGE';
    text.textContent  = `Showing ${filtered.length} release(s) in date range`;
  } else if (multiDateMode) {
    badge.textContent = 'MULTI-DATE';
    text.textContent  = `Showing ${filtered.length} release(s) across ${relMultiDates.size} selected date(s)`;
  } else if (releaseSourceFilter !== 'all') {
    badge.textContent = 'SOURCE: ' + releaseSourceFilter.toUpperCase();
    text.textContent  = `Showing ${filtered.length} ${releaseSourceFilter} release(s)`;
  } else {
    badge.textContent = 'SHOWING ALL DATES';
    text.textContent  = `Showing ALL ${filtered.length} release(s) across all dates`;
  }

  if (filtered.length === 0) {
    grid.innerHTML = `<div class="empty-state"><i class="fa-brands fa-apple"></i><p>No releases found for this filter.</p></div>`;
    updateHeaderReleaseInfo(filtered);
    updateRealtimeCheckPanel(filtered);
    return;
  }

  // Re-map filtered to their original indices in releaseStore
  const filteredWithIdx = filtered.map(rel => {
    for (const [idx, r] of releaseStore) { if (r === rel) return { rel, idx }; }
    return { rel, idx: -1 };
  });

  const osToTxtButtonMap = {
    'iOS': `<button class="btn-txt ios" data-os="ios"><i class="fa-solid fa-mobile-screen"></i> iOS .txt</button>`,
    'iPadOS': `<button class="btn-txt ipados" data-os="ipados"><i class="fa-solid fa-tablet-screen-button"></i> iPadOS .txt</button>`,
    'macOS': `<button class="btn-txt macos" data-os="macos"><i class="fa-solid fa-laptop"></i> macOS .txt</button>`,
    'watchOS': `<button class="btn-txt watchos" data-os="watchos"><i class="fa-solid fa-watch-smart"></i> watchOS .txt</button>`,
    'tvOS': `<button class="btn-txt tvos" data-os="tvos"><i class="fa-solid fa-tv"></i> tvOS .txt</button>`,
    'visionOS': `<button class="btn-txt visionos" data-os="visionos"><i class="fa-solid fa-glasses"></i> visionOS .txt</button>`,
  };

  grid.innerHTML = filteredWithIdx.map(({ rel, idx }) => {
    const osType     = getOsType(rel.name || '', rel.type || '');
    const isOfficial = rel.source === 'official';
    const sourceBadge = isOfficial
      ? '<span class="card-source-badge official"><i class="fa-brands fa-apple"></i> OFFICIAL (IPSW.ME)</span>'
      : '<span class="card-source-badge beta"><i class="fa-solid fa-flask"></i> BETA</span>';
    const osLabel = rel.type || osType;
    const osBadge = `<span class="card-os-badge os-${osType.toLowerCase().replace(/\s/g,'')}">${escapeHtml(osLabel)}</span>`;
    const count   = rel.count || (rel.firmwares ? rel.firmwares.length : '?');
    const dateStr = rel.date ? formatDateTime(rel.date) : '';

    let txtBtnsHtml = '';
    if (osType === 'macOS') {
      txtBtnsHtml = `
        <button class="btn-txt macos" data-rel-idx="${idx}" data-os="macos" title="Export Mac IPSW URLs">
          <i class="fa-solid fa-laptop"></i> Mac .txt
        </button>`;
    } else if (osType === 'iOS') {
      txtBtnsHtml = `
        <button class="btn-txt ios" data-rel-idx="${idx}" data-os="ios" title="Export iPhone IPSW URLs">
          <i class="fa-solid fa-mobile-screen"></i> iPhone .txt
        </button>`;
    } else if (osType === 'iPadOS') {
      txtBtnsHtml = `
        <button class="btn-txt ipados" data-rel-idx="${idx}" data-os="ipados" title="Export iPad IPSW URLs">
          <i class="fa-solid fa-tablet-screen-button"></i> iPad .txt
        </button>`;
    } else if (osType === 'watchOS') {
      txtBtnsHtml = `
        <button class="btn-txt watchos" data-rel-idx="${idx}" data-os="watchos" title="Export watchOS URLs">
          <i class="fa-solid fa-clock"></i> watchOS .txt
        </button>`;
    } else if (osType === 'tvOS') {
      txtBtnsHtml = `
        <button class="btn-txt tvos" data-rel-idx="${idx}" data-os="tvos" title="Export tvOS URLs">
          <i class="fa-solid fa-tv"></i> tvOS .txt
        </button>`;
    } else if (osType === 'visionOS') {
      txtBtnsHtml = `
        <button class="btn-txt visionos" data-rel-idx="${idx}" data-os="visionos" title="Export visionOS URLs">
          <i class="fa-solid fa-vr-cardboard"></i> visionOS .txt
        </button>`;
    } else if (osType === 'audioOS') {
      txtBtnsHtml = `
        <button class="btn-txt audioos" data-rel-idx="${idx}" data-os="audioos" title="Export audioOS URLs">
          <i class="fa-solid fa-headphones"></i> audioOS .txt
        </button>`;
    } else {
      txtBtnsHtml = `
        <button class="btn-txt all" data-rel-idx="${idx}" data-os="all" title="Export All Devices IPSW URLs">
          <i class="fa-solid fa-layer-group"></i> All Devices .txt
        </button>`;
    }

    return `<div class="release-card-v2">
  <div class="card-v2-top">
    <div class="card-v2-badges">${sourceBadge}${osBadge}</div>
    <div class="card-v2-date"><i class="fa-solid fa-clock"></i> ${escapeHtml(dateStr)}</div>
  </div>
  <div class="card-v2-name">${escapeHtml(rel.name || 'Unknown Release')}</div>
  <div class="card-v2-count">${count} Device Model(s)</div>

  <!-- Dynamic Quick .txt Export Buttons -->
  <div class="card-v2-txt-btns">
    ${txtBtnsHtml}
  </div>

  <!-- Action Row (3 Columns) -->
  <div class="card-v2-action-row">
    <button class="btn-card-idm" data-rel-idx="${idx}"><i class="fa-solid fa-bolt"></i> Send to IDM</button>
    <button class="btn-card-devices" data-rel-idx="${idx}"><i class="fa-solid fa-list"></i> View Devices</button>
    <button class="btn-card-email-alert" data-rel-idx="${idx}" style="background:rgba(168,85,247,0.15); border:1px solid rgba(168,85,247,0.4); color:#c084fc; border-radius:8px; padding:0.45rem 0.4rem; font-size:0.78rem; font-weight:700; cursor:pointer;">
      <i class="fa-solid fa-envelope"></i> Email Alert
    </button>
  </div>
</div>`;
  }).join('');

  // Event delegation
  grid.querySelectorAll('[data-rel-idx]').forEach(btn => {
    const idx = parseInt(btn.dataset.relIdx);
    const rel = releaseStore.get(idx);
    if (!rel) return;
    if (btn.classList.contains('btn-txt') || btn.classList.contains('btn-card-export')) {
      btn.addEventListener('click', () => downloadReleaseTxt(rel, btn.dataset.os));
    } else if (btn.classList.contains('btn-card-idm')) {
      btn.addEventListener('click', () => sendReleaseToIdm(rel));
    } else if (btn.classList.contains('btn-card-devices')) {
      btn.addEventListener('click', () => openReleaseFirmwares(rel));
    } else if (btn.classList.contains('btn-card-email-alert')) {
      btn.addEventListener('click', () => sendReleaseEmailAlert(rel));
    }
  });

  updateHeaderReleaseInfo(filtered);
  updateRealtimeCheckPanel(filtered);
}

function initCategoryFilter() {
  document.getElementById('category-pills')?.addEventListener('click', (e) => {
    const pill = e.target.closest('[data-filter]');
    if (!pill) return;
    document.querySelectorAll('#category-pills .pill').forEach(p => p.classList.remove('active'));
    pill.classList.add('active');
    currentFilter = pill.dataset.filter;
    renderReleases();
  });
  document.getElementById('btn-refresh-releases')?.addEventListener('click', loadReleases);
}

function apply2MonthsFilter() {
  const source = importedReleases.length > 0 ? importedReleases : allReleases;
  if (!source || source.length === 0) {
    relDateFrom = relDateTo = null;
    return;
  }

  const pool = releaseSourceFilter === 'all'
    ? source
    : source.filter(r => (r.source || 'official') === releaseSourceFilter);

  const targetPool = (pool && pool.length > 0) ? pool : source;
  const latestItem = targetPool.find(r => r.date);
  const latestDateStr = latestItem && latestItem.date ? latestItem.date.split('T')[0] : null;

  if (latestDateStr) {
    const latest = new Date(latestDateStr);
    const twoMonthsAgo = new Date(latest);
    twoMonthsAgo.setMonth(latest.getMonth() - 2);

    const fmt = d => d.toISOString().split('T')[0];
    relDateFrom = fmt(twoMonthsAgo);
    relDateTo   = latestDateStr;
  } else {
    relDateFrom = null;
    relDateTo   = null;
  }

  relSingleDate = null; relMultiDates.clear(); multiDateMode = false;
  const elFrom = document.getElementById('rel-date-from');
  const elTo   = document.getElementById('rel-date-to');
  if (elFrom) elFrom.value = relDateFrom || '';
  if (elTo)   elTo.value   = relDateTo || '';
  const elSelect = document.getElementById('rel-date-select');
  if (elSelect) elSelect.value = 'all';
  updatePresetActive('2months');
}

function updatePresetActive(preset) {
  document.querySelectorAll('.preset-tag').forEach(b => b.classList.remove('active'));
  if (preset === '2months') document.getElementById('preset-2months')?.classList.add('active');
  else if (preset === 'thismonth') document.getElementById('preset-thismonth')?.classList.add('active');
  else if (preset === 'latest') document.getElementById('preset-latest')?.classList.add('active');
  else if (preset === 'showall') document.getElementById('preset-showall')?.classList.add('active');
}

function initReleasesControls() {
  // Click anywhere on date input opens the calendar picker
  document.getElementById('rel-date-from')?.addEventListener('click', function() { try { this.showPicker(); } catch(e) {} });
  document.getElementById('rel-date-to')?.addEventListener('click', function() { try { this.showPicker(); } catch(e) {} });

  // Date range inputs
  document.getElementById('rel-date-from')?.addEventListener('change', e => {
    relDateFrom   = e.target.value || null;
    relSingleDate = null;
    relMultiDates.clear(); multiDateMode = false;
    const elSel = document.getElementById('rel-date-select');
    if (elSel) elSel.value = 'all';
    updatePresetActive(null);
    renderReleases();
  });
  document.getElementById('rel-date-to')?.addEventListener('change', e => {
    relDateTo     = e.target.value || null;
    relSingleDate = null;
    relMultiDates.clear(); multiDateMode = false;
    updatePresetActive(null);
    renderReleases();
  });

  // Presets
  document.getElementById('preset-2months')?.addEventListener('click', () => {
    apply2MonthsFilter();
    renderReleases();
  });
  document.getElementById('preset-thismonth')?.addEventListener('click', () => {
    const today = new Date();
    const fmt = d => d.toISOString().split('T')[0];
    relDateFrom = fmt(new Date(today.getFullYear(), today.getMonth(), 1));
    relDateTo   = fmt(today);
    relSingleDate = null; relMultiDates.clear(); multiDateMode = false;
    const elF = document.getElementById('rel-date-from');
    const elT = document.getElementById('rel-date-to');
    const elS = document.getElementById('rel-date-select');
    if (elF) elF.value = relDateFrom;
    if (elT) elT.value = relDateTo;
    if (elS) elS.value = 'all';
    updatePresetActive('thismonth');
    renderReleases();
  });
  document.getElementById('preset-latest')?.addEventListener('click', () => {
    const latest = allReleases.find(r => r.date)?.date?.split('T')[0];
    if (!latest) return;
    relDateFrom = relDateTo = latest;
    relSingleDate = null; relMultiDates.clear(); multiDateMode = false;
    const elF = document.getElementById('rel-date-from');
    const elT = document.getElementById('rel-date-to');
    const elS = document.getElementById('rel-date-select');
    if (elF) elF.value = latest;
    if (elT) elT.value = latest;
    if (elS) elS.value = 'all';
    updatePresetActive('latest');
    renderReleases();
  });
  document.getElementById('preset-showall')?.addEventListener('click', () => {
    relDateFrom = relDateTo = relSingleDate = null;
    relMultiDates.clear(); multiDateMode = false;
    const elF = document.getElementById('rel-date-from');
    const elT = document.getElementById('rel-date-to');
    const elS = document.getElementById('rel-date-select');
    if (elF) elF.value = '';
    if (elT) elT.value = '';
    if (elS) elS.value = 'all';
    updatePresetActive('showall');
    renderReleases();
  });

  // Single date select
  document.getElementById('rel-date-select')?.addEventListener('change', e => {
    relSingleDate = e.target.value === 'all' ? null : e.target.value;
    relDateFrom = relDateTo = null;
    relMultiDates.clear(); multiDateMode = false;
    const elF = document.getElementById('rel-date-from');
    const elT = document.getElementById('rel-date-to');
    if (elF) elF.value = '';
    if (elT) elT.value = '';
    updatePresetActive(null);
    renderReleases();
  });

  // Multi-date toggle
  document.getElementById('btn-multi-date')?.addEventListener('click', () => {
    document.getElementById('multi-date-panel')?.classList.toggle('hidden');
  });
  document.getElementById('btn-multi-select-all')?.addEventListener('click', () => {
    document.querySelectorAll('.multi-date-cb').forEach(cb => cb.checked = true);
  });
  document.getElementById('btn-multi-clear-all')?.addEventListener('click', () => {
    document.querySelectorAll('.multi-date-cb').forEach(cb => cb.checked = false);
  });
  document.getElementById('btn-multi-apply')?.addEventListener('click', () => {
    relMultiDates = new Set([...document.querySelectorAll('.multi-date-cb:checked')].map(cb => cb.value));
    multiDateMode = relMultiDates.size > 0;
    relDateFrom = relDateTo = relSingleDate = null;
    const elF = document.getElementById('rel-date-from');
    const elT = document.getElementById('rel-date-to');
    const elS = document.getElementById('rel-date-select');
    if (elF) elF.value = '';
    if (elT) elT.value = '';
    if (elS) elS.value = 'all';
    document.getElementById('multi-date-panel')?.classList.add('hidden');
    renderReleases();
  });

  // Export all releases URLs
  document.getElementById('btn-export-all-releases')?.addEventListener('click', () => {
    const src = importedReleases.length > 0 ? importedReleases : allReleases;
    if (!src || !src.length) { showToast('No releases available to export.', 'warn'); return; }
    const firstRel = src.find(r => r.buildid);
    if (!firstRel) { showToast('No build ID found.', 'warn'); return; }
    showToast('Downloading combined .txt export file...', 'info', 2500);
    const url = `api/releases/export.php?buildid=${encodeURIComponent(firstRel.buildid)}&os=all&name=${encodeURIComponent(firstRel.name || '')}&format=txt`;
    const iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.src = url;
    document.body.appendChild(iframe);
    setTimeout(() => document.body.removeChild(iframe), 6000);
  });

  // 1-Click Send All to IDM
  document.getElementById('btn-send-all-idm')?.addEventListener('click', () => {
    const src   = importedReleases.length > 0 ? importedReleases : allReleases;
    const items = src.flatMap(r => (r.firmwares || []).filter(f => f.url));
    if (!items.length) { showToast('No firmware URLs loaded yet. Open individual releases first.', 'warn'); return; }
    queueIdmDownload(items);
  });
}

function extractBuildId(rel) {
  if (rel.buildid) return rel.buildid;
  // Extract from name: "macOS 26.6.1 (25G76)" → "25G76"
  const m = (rel.name || '').match(/\(([A-Za-z0-9]+)\)\s*$/);
  return m ? m[1] : '';
}

function downloadReleaseTxt(rel, os) {
  const buildid = extractBuildId(rel);
  if (!buildid) { showToast('No build ID found for this release.', 'warn'); return; }
  showToast(`Downloading ${os === 'all' ? 'All Devices' : os.toUpperCase()} .txt list...`, 'info', 2500);
  const url = `api/releases/export.php?buildid=${encodeURIComponent(buildid)}&os=${encodeURIComponent(os)}&name=${encodeURIComponent(rel.name || '')}&format=txt`;
  const iframe = document.createElement('iframe');
  iframe.style.display = 'none';
  iframe.src = url;
  document.body.appendChild(iframe);
  setTimeout(() => document.body.removeChild(iframe), 6000);
}

async function sendReleaseEmailAlert(rel) {
  showToast(`Sending Gmail alert with direct URLs for ${rel.name || 'Release'}...`, 'info', 3000);
  try {
    const res = await fetch(API.emailSend, {
      method : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body   : JSON.stringify({ mode: 'notify', release: rel })
    });
    const data = await res.json();
    if (data.success) {
      showToast(`Gmail alert sent successfully to ${data.sent_to ? data.sent_to.length : 0} recipient(s)!`, 'success');
    } else {
      showToast('Failed to send email: ' + (data.error || 'Check Gmail Settings'), 'error', 5000);
    }
  } catch (err) {
    showToast('Network error sending email alert', 'error');
  }
}

async function sendReleaseToIdm(rel) {
  let firmwares = rel.firmwares || [];
  if (firmwares.length === 0) {
    showToast('No firmware data available. Use View Devices first.', 'warn');
    return;
  }
  await queueIdmDownload(firmwares.filter(fw => fw.url));
}

// ── Open Release Modal ─────────────────────────────────────────────────────
// ── Open Release Modal with Checkboxes & Right-Click Context Menu ────────────
async function openReleaseFirmwares(rel) {
  const modal = document.getElementById('modal-firmware');
  document.getElementById('modal-title').textContent = rel.name || 'Firmware Files';
  const modalBody = document.getElementById('modal-body');

  // If firmwares list is not attached to rel object, fetch directly via export.php format=json
  if (!rel.firmwares || rel.firmwares.length === 0) {
    modalBody.innerHTML = `
      <div class="empty-state" style="padding:2.5rem 1rem; text-align:center;">
        <i class="fa-solid fa-circle-notch fa-spin" style="font-size:2.4rem; color:#00f2fe; margin-bottom:1rem;"></i>
        <p style="font-size:0.95rem; color:#cbd5e1;">Fetching direct Apple CDN firmware links for <b>${escapeHtml(rel.name || '')}</b>...</p>
      </div>`;
    modal.classList.remove('hidden');

    try {
      const fetchUrl = `api/releases/export.php?buildid=${encodeURIComponent(rel.buildid || '')}&name=${encodeURIComponent(rel.name || '')}&os=${encodeURIComponent(rel.type || 'all')}&format=json`;
      const res = await fetch(fetchUrl);
      const data = await res.json();
      if (Array.isArray(data) && data.length > 0) {
        rel.firmwares = data.map(f => ({
          identifier: f.identifier || 'Device',
          deviceName: f.identifier || 'Device',
          url       : f.url,
          signed    : f.signed ?? true,
          filesize  : f.size || f.filesize || 0,
          version   : rel.name || '',
          buildid   : rel.buildid || '',
        }));
      }
    } catch (e) {
      console.error('Failed to fetch firmwares:', e);
    }
  }

  const firmwares = rel.firmwares || [];
  if (firmwares.length === 0) {
    modalBody.innerHTML = '<div class="empty-state"><i class="fa-solid fa-circle-info"></i><p>No direct download URLs available for this release. This build may not be indexed by ipsw.me yet.</p></div>';
  } else {
    const batchItems = firmwares.filter(fw => fw.url);

    modalBody.innerHTML = `
      <!-- Modal Tabs -->
      <div class="modal-tabs">
        <button class="modal-tab-btn active" id="btn-modal-view-table">
          <i class="fa-solid fa-table-list"></i> Table View
        </button>
        <button class="modal-tab-btn" id="btn-modal-view-txt">
          <i class="fa-solid fa-file-lines"></i> Plain Text View
        </button>
      </div>

      <!-- VIEW 1: TABLE VIEW -->
      <div id="modal-view-table-wrapper">
        <div class="modal-action-banner">
          <label class="modal-select-all-label">
            <input type="checkbox" id="modal-select-all-cb" checked>
            <span>Select All (<span id="modal-checked-count">${batchItems.length}</span> / ${batchItems.length})</span>
          </label>
          <div class="modal-banner-buttons">
            <button class="btn btn-primary btn-sm btn-banner-idm" id="modal-send-checked-idm">
              <i class="fa-solid fa-bolt"></i> Send Checked to IDM
            </button>
            <button class="btn btn-secondary btn-sm btn-banner-copy" id="modal-copy-checked-urls">
              <i class="fa-solid fa-copy"></i> Copy Checked URLs
            </button>
            <button class="btn btn-success btn-sm btn-banner-download-txt" id="modal-download-checked-txt-table">
              <i class="fa-solid fa-file-arrow-down"></i> Download .txt
            </button>
          </div>
        </div>

        <!-- Right Click Tip -->
        <div class="modal-tip-container">
          <i class="fa-solid fa-mouse-pointer"></i>
          <span>Tip: Right-click anywhere on the list below to quickly send selected/checked URLs to IDM!</span>
        </div>

        <div class="modal-firmware-table-container" id="fw-table-container">
          <table class="modal-firmware-table">
            <thead>
              <tr>
                <th>Device / Version</th>
                <th>Build</th>
                <th>Size / Status</th>
                <th style="text-align:right;">Action</th>
              </tr>
            </thead>
            <tbody>
              ${firmwares.map((fw, i) => {
                const isSigned = fw.signed ?? true;
                const statusBadge = isSigned
                  ? `<span class="modal-status-badge signed"><i class="fa-solid fa-check"></i> Signed</span>`
                  : `<span class="modal-status-badge unsigned"><i class="fa-solid fa-xmark"></i> Unsigned</span>`;
                return `
                  <tr>
                    <td>
                      <div class="modal-device-name-container">
                        ${fw.url ? `<input type="checkbox" class="fw-url-cb modal-device-cb" data-fw-idx="${i}" data-url="${escapeHtml(fw.url)}" checked>` : ''}
                        <span class="modal-device-name">${escapeHtml(fw.identifier || fw.deviceName || rel.name || 'Device')}</span>
                      </div>
                    </td>
                    <td>
                      <span class="build-text-pink">${escapeHtml(fw.buildid || rel.buildid || '—')}</span>
                    </td>
                    <td>
                      <div class="size-status-container">
                        <span class="size-text">${formatBytes(fw.filesize)}</span>
                        ${statusBadge}
                      </div>
                    </td>
                    <td style="text-align:right;">
                      <div style="display:inline-flex; gap:0.4rem; justify-content:flex-end;">
                        ${fw.url ? `
                          <button class="btn btn-secondary btn-sm btn-row-copy data-fw-copy" data-url="${escapeHtml(fw.url)}">
                            <i class="fa-solid fa-copy"></i> Copy
                          </button>
                          <button class="btn btn-primary btn-sm btn-row-download" data-fw-idx="${i}">
                            <i class="fa-solid fa-download"></i> Download
                          </button>` : '—'}
                      </div>
                    </td>
                  </tr>
                `;
              }).join('')}
            </tbody>
          </table>
        </div>
      </div>

      <!-- VIEW 2: PLAIN TEXT VIEW -->
      <div id="modal-view-txt-wrapper" class="hidden">
        <div class="plain-text-container">
          <p style="font-size:0.85rem; color:#cbd5e1; margin-bottom:4px;">
            Here are the direct download links for the checked devices. You can copy them or download them directly as a plain list file.
          </p>
          <textarea class="modal-textarea" id="modal-textarea-links" readonly></textarea>
          <div style="display:flex; gap:0.5rem; justify-content:flex-end;">
            <button class="btn btn-secondary btn-sm btn-banner-copy" id="modal-copy-textarea-btn">
              <i class="fa-solid fa-copy"></i> Copy Plain Links
            </button>
            <button class="btn btn-success btn-sm btn-banner-download-txt" id="modal-download-checked-txt-plain">
              <i class="fa-solid fa-file-arrow-down"></i> Download .txt File
            </button>
          </div>
        </div>
      </div>
    `;

    // Helpers to get checked firmwares
    const getCheckedFirmwares = () => {
      const checkedBoxes = Array.from(modalBody.querySelectorAll('.fw-url-cb:checked'));
      const idxs = checkedBoxes.map(cb => parseInt(cb.dataset.fwIdx));
      return idxs.map(i => firmwares[i]).filter(fw => fw && fw.url);
    };

    const updatePlainTextView = () => {
      const selected = getCheckedFirmwares();
      const textarea = modalBody.querySelector('#modal-textarea-links');
      if (textarea) {
        textarea.value = selected.map(fw => fw.url).join('\n');
      }
      const countEl = modalBody.querySelector('#modal-checked-count');
      if (countEl) countEl.textContent = selected.length;
    };

    const downloadCheckedTxt = () => {
      const selected = getCheckedFirmwares();
      if (selected.length === 0) {
        showToast('Please check at least one URL.', 'warn');
        return;
      }
      const text = selected.map(fw => fw.url).join('\n');
      const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
      const blobUrl = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = blobUrl;
      a.download = `${(rel.name || 'firmware').replace(/[^a-zA-Z0-9]/g, '_')}_links.txt`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(blobUrl);
      showToast('Download started for checked URLs .txt file!', 'success');
    };

    // Tab Switches
    const btnViewTable = modalBody.querySelector('#btn-modal-view-table');
    const btnViewTxt = modalBody.querySelector('#btn-modal-view-txt');
    const viewTableWrapper = modalBody.querySelector('#modal-view-table-wrapper');
    const viewTxtWrapper = modalBody.querySelector('#modal-view-txt-wrapper');

    btnViewTable?.addEventListener('click', () => {
      btnViewTable.classList.add('active');
      btnViewTxt.classList.remove('active');
      viewTableWrapper.classList.remove('hidden');
      viewTxtWrapper.classList.add('hidden');
    });

    btnViewTxt?.addEventListener('click', () => {
      btnViewTxt.classList.add('active');
      btnViewTable.classList.remove('active');
      viewTxtWrapper.classList.remove('hidden');
      viewTableWrapper.classList.add('hidden');
      updatePlainTextView();
    });

    // Checkbox change events
    modalBody.querySelectorAll('.fw-url-cb').forEach(cb => {
      cb.addEventListener('change', updatePlainTextView);
    });

    // Select All Checkbox
    const selectAllCb = modalBody.querySelector('#modal-select-all-cb');
    if (selectAllCb) {
      selectAllCb.addEventListener('change', (e) => {
        const isChecked = e.target.checked;
        modalBody.querySelectorAll('.fw-url-cb').forEach(cb => cb.checked = isChecked);
        updatePlainTextView();
      });
    }

    // Send Checked to IDM
    modalBody.querySelector('#modal-send-checked-idm')?.addEventListener('click', () => {
      const selected = getCheckedFirmwares();
      if (selected.length === 0) {
        showToast('Please check at least one URL.', 'warn');
        return;
      }
      queueIdmDownload(selected);
    });

    // Copy Checked URLs
    modalBody.querySelector('#modal-copy-checked-urls')?.addEventListener('click', () => {
      const selected = getCheckedFirmwares();
      if (selected.length === 0) {
        showToast('Please check at least one URL.', 'warn');
        return;
      }
      const text = selected.map(fw => fw.url).join('\n');
      navigator.clipboard.writeText(text).then(() => showToast(`Copied ${selected.length} checked IPSW URL(s) to clipboard!`, 'success'));
    });

    // Download Checked TXT button triggers
    modalBody.querySelector('#modal-download-checked-txt-table')?.addEventListener('click', downloadCheckedTxt);
    modalBody.querySelector('#modal-download-checked-txt-plain')?.addEventListener('click', downloadCheckedTxt);

    // Copy Plain text view textarea
    modalBody.querySelector('#modal-copy-textarea-btn')?.addEventListener('click', () => {
      const textarea = modalBody.querySelector('#modal-textarea-links');
      if (textarea && textarea.value) {
        navigator.clipboard.writeText(textarea.value).then(() => showToast('All plain links copied to clipboard!', 'success'));
      } else {
        showToast('No links to copy.', 'warn');
      }
    });

    // Individual download buttons
    modalBody.querySelectorAll('.btn-row-download').forEach(btn => {
      btn.addEventListener('click', () => {
        const fw = firmwares[parseInt(btn.dataset.fwIdx)];
        if (fw) queueIdmDownload([fw]);
      });
    });

    // Individual Copy URL buttons
    modalBody.querySelectorAll('.data-fw-copy').forEach(btn => {
      btn.addEventListener('click', () => {
        const url = btn.dataset.url;
        if (url) navigator.clipboard.writeText(url).then(() => showToast('Direct IPSW URL copied to clipboard!', 'success'));
      });
    });

    // Initialize textarea contents
    updatePlainTextView();

    // ── Custom Right-Click Context Menu ──────────────────────────────────────
    let existingMenu = document.getElementById('custom-idm-ctx-menu');
    if (existingMenu) existingMenu.remove();

    const ctxMenu = document.createElement('div');
    ctxMenu.id = 'custom-idm-ctx-menu';
    ctxMenu.style.cssText = 'position:fixed; z-index:99999; display:none; background:#0f172a; border:1px solid #00f2fe; border-radius:8px; padding:0.4rem 0; box-shadow:0 10px 30px rgba(0,0,0,0.8); width:220px; font-family:sans-serif;';
    ctxMenu.innerHTML = `
      <div id="ctx-send-idm" style="padding:0.5rem 1rem; color:#00f2fe; font-weight:700; font-size:0.82rem; cursor:pointer; display:flex; align-items:center; gap:0.5rem;">
        <i class="fa-solid fa-bolt"></i> Send Checked to IDM
      </div>
      <div id="ctx-copy-urls" style="padding:0.5rem 1rem; color:#cbd5e1; font-weight:600; font-size:0.82rem; cursor:pointer; display:flex; align-items:center; gap:0.5rem;">
        <i class="fa-solid fa-copy"></i> Copy Checked URLs
      </div>
      <div id="ctx-download-txt" style="padding:0.5rem 1rem; color:#10b981; font-weight:600; font-size:0.82rem; cursor:pointer; display:flex; align-items:center; gap:0.5rem;">
        <i class="fa-solid fa-file-arrow-down"></i> Download .txt File
      </div>
    `;
    document.body.appendChild(ctxMenu);

    ctxMenu.querySelector('#ctx-send-idm').addEventListener('click', () => {
      ctxMenu.style.display = 'none';
      const selected = getCheckedFirmwares();
      if (selected.length === 0) {
        showToast('Please check at least one URL.', 'warn');
        return;
      }
      queueIdmDownload(selected);
    });

    ctxMenu.querySelector('#ctx-copy-urls').addEventListener('click', () => {
      ctxMenu.style.display = 'none';
      const selected = getCheckedFirmwares();
      if (selected.length === 0) {
        showToast('Please check at least one URL.', 'warn');
        return;
      }
      const text = selected.map(fw => fw.url).join('\n');
      navigator.clipboard.writeText(text).then(() => showToast(`Copied ${selected.length} checked URL(s) to clipboard!`, 'success'));
    });

    ctxMenu.querySelector('#ctx-download-txt').addEventListener('click', () => {
      ctxMenu.style.display = 'none';
      downloadCheckedTxt();
    });

    const fwContainer = modalBody.querySelector('#fw-table-container');
    if (fwContainer) {
      fwContainer.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        ctxMenu.style.left = e.clientX + 'px';
        ctxMenu.style.top  = e.clientY + 'px';
        ctxMenu.style.display = 'block';
      });
    }

    document.addEventListener('click', () => { ctxMenu.style.display = 'none'; });
  }

  modal.classList.remove('hidden');
}

function initModal() {
  document.getElementById('modal-close')?.addEventListener('click', () => {
    document.getElementById('modal-firmware')?.classList.add('hidden');
  });
  document.getElementById('modal-backdrop')?.addEventListener('click', () => {
    document.getElementById('modal-firmware')?.classList.add('hidden');
  });
}

// ── Devices & Cache Server Exporter Modal ──────────────────────────────────
async function loadDevicesList() {
  try {
    const res = await fetch(API.devices);
    allDevices = await res.json();
    if (!Array.isArray(allDevices)) allDevices = [];
    renderCacheDeviceList();
  } catch (err) {
    console.warn('Failed to load devices list', err);
  }
}

function renderCacheDeviceList(filterText = '') {
  const container = document.getElementById('cache-device-list');
  if (!container) return;

  const query = filterText.toLowerCase().trim();
  const matched = allDevices.filter(d => {
    const name = (d.name || d.identifier || '').toLowerCase();
    const id   = (d.identifier || '').toLowerCase();
    return !query || name.includes(query) || id.includes(query);
  });

  if (matched.length === 0) {
    container.innerHTML = '<div style="color:#64748b; font-size:0.85rem; grid-column:1/-1; text-align:center;">No matching devices found</div>';
    return;
  }

  container.innerHTML = matched.map(d => {
    const id   = escapeHtml(d.identifier || '');
    const name = escapeHtml(d.name || id);
    return `
      <label class="device-cb-label" title="${id}">
        <input type="checkbox" value="${id}" class="cache-dev-cb" checked>
        <span>${name}</span>
      </label>`;
  }).join('');
}

function openCacheExporterModal() {
  const modal = document.getElementById('modal-cache-exporter');
  if (!modal) return;

  // Populate releases dropdown in cache modal
  const relSelect = document.getElementById('cache-rel-select');
  const src = importedReleases.length > 0 ? importedReleases : allReleases;
  
  if (src.length === 0) {
    relSelect.innerHTML = '<option value="">No releases loaded yet</option>';
  } else {
    relSelect.innerHTML = src.map((rel, idx) => {
      const buildid = extractBuildId(rel);
      let rawName = rel.name || 'Unknown';
      let cleanName = rawName;
      if (buildid && cleanName.trim().endsWith(`(${buildid})`)) {
        cleanName = cleanName.substring(0, cleanName.lastIndexOf(`(${buildid})`)).trim();
      }
      const date  = rel.date ? rel.date.split('T')[0] : '';
      const label = buildid ? `${cleanName} (${buildid})` : cleanName;
      return `<option value="${idx}" data-buildid="${escapeHtml(buildid)}" data-name="${escapeHtml(rawName)}">${escapeHtml(label)} — ${date}</option>`;
    }).join('');
  }

  if (allDevices.length === 0) loadDevicesList();
  modal.classList.remove('hidden');
}

function getSelectedCacheDevices() {
  const checkboxes = document.querySelectorAll('.cache-dev-cb:checked');
  const allCheckboxes = document.querySelectorAll('.cache-dev-cb');
  if (checkboxes.length === allCheckboxes.length || checkboxes.length === 0) {
    return 'all';
  }
  return [...checkboxes].map(cb => cb.value).join(',');
}

function initCacheExporterModal() {
  // Modal toggle buttons
  document.getElementById('btn-open-cache-exporter')?.addEventListener('click', openCacheExporterModal);
  document.getElementById('btn-device-filter-quick')?.addEventListener('click', openCacheExporterModal);

  // Close handlers
  document.getElementById('modal-cache-close')?.addEventListener('click', () => {
    document.getElementById('modal-cache-exporter').classList.add('hidden');
  });
  document.getElementById('modal-cache-backdrop')?.addEventListener('click', () => {
    document.getElementById('modal-cache-exporter').classList.add('hidden');
  });

  // Device search input
  document.getElementById('cache-dev-search')?.addEventListener('input', e => {
    renderCacheDeviceList(e.target.value);
  });

  // Select all / Clear all
  document.getElementById('btn-cache-dev-all')?.addEventListener('click', () => {
    document.querySelectorAll('.cache-dev-cb').forEach(cb => cb.checked = true);
  });
  document.getElementById('btn-cache-dev-none')?.addEventListener('click', () => {
    document.querySelectorAll('.cache-dev-cb').forEach(cb => cb.checked = false);
  });

  // Export Trigger Handlers
  const triggerExport = (format) => {
    const relSelect = document.getElementById('cache-rel-select');
    const selectedOpt = relSelect.options[relSelect.selectedIndex];
    if (!selectedOpt || !selectedOpt.dataset.buildid) {
      showToast('Please select a valid release build first.', 'warn');
      return;
    }
    const buildid    = selectedOpt.dataset.buildid;
    const name       = selectedOpt.dataset.name;
    const devices    = getSelectedCacheDevices();
    const signedOnly = document.getElementById('cache-signed-only')?.checked ? 1 : 0;

    showToast(`Generating Cache ${format.toUpperCase()} Package...`, 'info', 2500);
    const url = `api/releases/export.php?buildid=${encodeURIComponent(buildid)}&name=${encodeURIComponent(name)}&devices=${encodeURIComponent(devices)}&format=${encodeURIComponent(format)}&signed_only=${signedOnly}`;
    
    const a = document.createElement('a');
    a.href = url;
    a.target = '_blank';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  };

  document.getElementById('btn-cache-download-txt')?.addEventListener('click', () => triggerExport('txt'));
  document.getElementById('btn-cache-download-sh')?.addEventListener('click', () => triggerExport('sh'));
  document.getElementById('btn-cache-download-ef2')?.addEventListener('click', () => triggerExport('ef2'));

  // 1-Click Send Cache Items to IDM
  document.getElementById('btn-cache-send-idm')?.addEventListener('click', async () => {
    const relSelect = document.getElementById('cache-rel-select');
    const selectedOpt = relSelect.options[relSelect.selectedIndex];
    if (!selectedOpt || !selectedOpt.dataset.buildid) {
      showToast('Please select a valid release build first.', 'warn');
      return;
    }
    const idx = parseInt(selectedOpt.value);
    const rel = releaseStore.get(idx);
    if (rel && rel.firmwares && rel.firmwares.length > 0) {
      await queueIdmDownload(rel.firmwares.filter(f => f.url));
      document.getElementById('modal-cache-exporter').classList.add('hidden');
    } else {
      // Trigger export via server API stream
      triggerExport('ef2');
    }
  });
}

// ── IDM Download ───────────────────────────────────────────────────────────
async function queueIdmDownload(items) {
  document.getElementById('modal-firmware').classList.add('hidden');
  try {
    const res  = await fetch(API.idmDownload, {
      method : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body   : JSON.stringify({ items })
    });
    const data = await res.json();
    if (data.success) {
      showToast(`${data.queued} file(s) queued in IDM successfully!`, 'success');
      allHistory = [...(data.items || []), ...allHistory];
      filteredHistory = allHistory;
    } else {
      showToast(data.error || 'Failed to queue download', 'error');
    }
  } catch {
    showToast('Failed to communicate with server', 'error');
  }
}

// ── History ────────────────────────────────────────────────────────────────
async function loadHistory() {
  try {
    // This feature seems to be deprecated per UI comments.
    // If the history tab is removed, this entire function can be removed.
    const res  = await fetch(API.history);
    allHistory = await res.json();
    if (!Array.isArray(allHistory)) allHistory = [];
    filteredHistory = allHistory;
  } catch {
  }
}

function applyHistoryFilter() {
  if (!histDateFrom && !histDateTo) {
    filteredHistory = allHistory;
  } else {
    const from = histDateFrom ? new Date(histDateFrom + 'T00:00:00') : null;
    const to   = histDateTo   ? new Date(histDateTo   + 'T23:59:59') : null;
    filteredHistory = allHistory.filter(h => {
      const d = new Date(h.startTime || h.endTime);
      if (from && d < from) return false;
      if (to   && d > to)   return false;
      return true;
    });
  }
}

// ── Settings ───────────────────────────────────────────────────────────────
async function loadSettings() {
  try {
    const res = await fetch(API.settings);
    settings  = await res.json();

    document.getElementById('set-download-dir').value      = settings.downloadDir || '';
    document.getElementById('set-idm-path').value          = settings.idmPath || '';
    document.getElementById('set-auto-check').value        = settings.autoCheckMinutes || 1440;
    document.getElementById('set-email-alerts').checked    = !!settings.emailAlertsEnabled;

    renderGmailAccounts(settings.gmailAccounts || []);

    // Update gmail count in header
    const gmailCount = (settings.gmailAccounts || []).filter(a => a.enabled).length;
    document.getElementById('gmail-connected-count').textContent = gmailCount;
  } catch {
    showToast('Failed to load settings.', 'error');
  }
}

function renderGmailAccounts(accounts) {
  const list = document.getElementById('gmail-accounts-list');
  if (!list) return;
  if (accounts.length === 0) {
    list.innerHTML = '<div style="color:#64748b; font-size:0.9rem;">No Gmail accounts configured.</div>';
    return;
  }
  list.innerHTML = accounts.map((acc, i) => `
    <div class="gmail-account-card" data-index="${i}">
      <div class="gmail-account-info">
        <i class="fa-brands fa-google" style="color:#00f2fe; font-size:1.2rem;"></i>
        <div style="flex:1;">
          <input type="email" class="form-control" style="margin-bottom:0.4rem; padding:0.4rem 0.6rem; font-size:0.85rem;"
            value="${escapeHtml(acc.email || '')}" placeholder="Gmail address" data-field="email">
          <input type="password" class="form-control" style="padding:0.4rem 0.6rem; font-size:0.85rem;"
            value="${escapeHtml(acc.appPassword || '')}" placeholder="App Password" data-field="appPassword">
        </div>
        <div style="display:flex; flex-direction:column; gap:0.5rem; align-items:flex-start; min-width:90px;">
          <label style="font-size:0.75rem; color:#94a3b8; display:flex; align-items:center; gap:0.4rem; cursor:pointer;">
            <input type="checkbox" ${acc.isSender ? 'checked' : ''} data-field="isSender" style="accent-color:#00f2fe;"> Sender
          </label>
          <label style="font-size:0.75rem; color:#94a3b8; display:flex; align-items:center; gap:0.4rem; cursor:pointer;">
            <input type="checkbox" ${acc.enabled ? 'checked' : ''} data-field="enabled" style="accent-color:#00f2fe;"> Enabled
          </label>
        </div>
      </div>
      <div class="gmail-account-actions">
        <button class="btn btn-danger-outline btn-sm" data-remove-idx="${i}">
          <i class="fa-solid fa-trash"></i>
        </button>
      </div>
    </div>`).join('');

  list.querySelectorAll('[data-remove-idx]').forEach(btn => {
    btn.addEventListener('click', () => removeGmailAccount(parseInt(btn.dataset.removeIdx)));
  });
}

function removeGmailAccount(index) {
  const accounts = collectGmailAccounts();
  accounts.splice(index, 1);
  settings.gmailAccounts = accounts;
  renderGmailAccounts(accounts);
}

function collectGmailAccounts() {
  const cards = document.querySelectorAll('.gmail-account-card');
  return Array.from(cards).map((card, i) => {
    const origIdx = parseInt(card.dataset.index);
    const orig    = (settings.gmailAccounts || [])[origIdx] || {};
    return {
      id         : orig.id || ('gmail-' + Date.now() + i),
      email      : card.querySelector('[data-field="email"]')?.value.trim() || '',
      appPassword: card.querySelector('[data-field="appPassword"]')?.value || '',
      isSender   : card.querySelector('[data-field="isSender"]')?.checked ?? false,
      enabled    : card.querySelector('[data-field="enabled"]')?.checked ?? true,
    };
  });
}

function initSettings() {
  document.getElementById('btn-add-gmail')?.addEventListener('click', () => {
    const accounts = collectGmailAccounts();
    accounts.push({ id: 'gmail-' + Date.now(), email: '', appPassword: '', isSender: false, enabled: true });
    settings.gmailAccounts = accounts;
    renderGmailAccounts(accounts);
  });

  document.getElementById('btn-save-system-settings')?.addEventListener('click', async () => {
    const minutes = parseInt(document.getElementById('set-auto-check')?.value) || 1440;
    const payload = {
      downloadDir      : document.getElementById('set-download-dir')?.value?.trim() || '',
      idmPath          : document.getElementById('set-idm-path')?.value?.trim() || '',
      autoCheckMinutes : minutes,
      emailAlertsEnabled: document.getElementById('set-email-alerts')?.checked || false,
    };
    await saveSettings(payload);
    startAutoCheck(minutes);
  });

  document.getElementById('btn-save-credentials')?.addEventListener('click', async () => {
    const username = document.getElementById('set-username')?.value?.trim() || '';
    const password = document.getElementById('set-password')?.value?.trim() || '';
    if (!username && !password) { showToast('Enter a new username or password.', 'warn'); return; }
    const payload = {};
    if (username) payload.username = username;
    if (password) payload.password = password;
    await saveSettings(payload);
    const elU = document.getElementById('set-username');
    const elP = document.getElementById('set-password');
    if (elU) elU.value = '';
    if (elP) elP.value = '';
  });

  document.getElementById('btn-save-gmail')?.addEventListener('click', async () => {
    await saveSettings({ gmailAccounts: collectGmailAccounts() });
  });

  document.getElementById('btn-test-email')?.addEventListener('click', sendTestEmail);
}

async function saveSettings(payload) {
  try {
    const res  = await fetch(API.settings, {
      method : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body   : JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      showToast('Settings saved successfully.', 'success');
      await loadSettings();
    } else {
      showToast(data.error || 'Failed to save settings.', 'error');
    }
  } catch {
    showToast('Failed to save settings.', 'error');
  }
}

// ── Auto-check interval ────────────────────────────────────────────────────
function startAutoCheck(minutes) {
  if (autoCheckTimer) clearInterval(autoCheckTimer);
  if (!minutes || minutes < 1) return;
  autoCheckTimer = setInterval(loadReleases, minutes * 60 * 1000);
}

// ── Gmail Test Email ────────────────────────────────────────────────────────
async function sendTestEmail() {
  const btn = document.getElementById('btn-test-email');
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending...';
  try {
    const res  = await fetch(API.emailSend, {
      method : 'POST',
      headers: { 'Content-Type': 'application/json' },
      body   : JSON.stringify({ mode: 'test' })
    });
    const data = await res.json();
    if (data.success) {
      showToast('Test email sent to: ' + data.sent_to.join(', '), 'success', 6000);
    } else {
      showToast('Email failed: ' + (data.error || 'Unknown error'), 'error', 8000);
    }
  } catch {
    showToast('Failed to reach email API.', 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = orig;
  }
}

// ── Graphical Analytics ─────────────────────────────────────────────────────
let chartOs = null, chartSource = null, chartOsBar = null, chartTimeline = null;

const CHART_COLORS = {
  iOS      : '#38bdf8',
  iPadOS   : '#818cf8',
  macOS    : '#34c759',
  watchOS  : '#fb923c',
  tvOS     : '#f87171',
  audioOS  : '#4ade80',
  visionOS : '#fde047',
  Other    : '#64748b',
};

function renderCharts() {
  const dataset = (importedReleases.length > 0 ? importedReleases : allReleases);
  if (!dataset || !dataset.length) return;

  const timeframeVal = document.getElementById('analytics-timeframe')?.value || 'all';
  const sourceVal    = document.getElementById('analytics-source')?.value || 'all';

  // Filter dataset by timeframe and source
  let filtered = dataset;
  if (sourceVal !== 'all') {
    filtered = filtered.filter(r => (r.source === sourceVal));
  }

  if (timeframeVal !== 'all') {
    const daysLimit = parseInt(timeframeVal);
    if (!isNaN(daysLimit)) {
      const cutoff = new Date();
      cutoff.setDate(cutoff.getDate() - daysLimit);
      filtered = filtered.filter(r => {
        if (!r.date) return false;
        return new Date(r.date) >= cutoff;
      });
    }
  }

  // Update KPI Cards
  const totalCount = filtered.length;
  let officialCount = 0;
  let betaCount = 0;
  const osCounts = {};
  const dayCounts = {};

  filtered.forEach(r => {
    const isBeta = (r.source === 'beta');
    if (isBeta) betaCount++; else officialCount++;

    const os = getOsType(r.name || '', r.type || '');
    osCounts[os] = (osCounts[os] || 0) + 1;

    if (r.date) {
      const day = r.date.split('T')[0];
      dayCounts[day] = (dayCounts[day] || 0) + 1;
    }
  });

  const totalEl = document.getElementById('kpi-total-releases');
  const officialEl = document.getElementById('kpi-official-releases');
  const betaEl = document.getElementById('kpi-beta-releases');
  const topOsEl = document.getElementById('kpi-top-os');

  if (totalEl) totalEl.textContent = totalCount.toLocaleString();
  if (officialEl) officialEl.textContent = officialCount.toLocaleString();
  if (betaEl) betaEl.textContent = betaCount.toLocaleString();

  const officialPct = totalCount ? Math.round((officialCount / totalCount) * 100) : 0;
  const betaPct = totalCount ? Math.round((betaCount / totalCount) * 100) : 0;

  const officialSub = document.getElementById('kpi-official-sub');
  const betaSub = document.getElementById('kpi-beta-sub');
  if (officialSub) officialSub.textContent = `${officialPct}% of analyzed dataset`;
  if (betaSub) betaSub.textContent = `${betaPct}% of analyzed dataset`;

  // Find Top OS
  let topOs = '—';
  let topOsMax = 0;
  Object.keys(osCounts).forEach(os => {
    if (osCounts[os] > topOsMax) {
      topOsMax = osCounts[os];
      topOs = os;
    }
  });
  if (topOsEl) topOsEl.textContent = topOs;
  const topOsSub = document.getElementById('kpi-top-os-sub');
  if (topOsSub) topOsSub.textContent = topOsMax ? `${topOsMax} builds (${Math.round((topOsMax/totalCount)*100)}%)` : 'No data';

  // Render Smart Insights Text
  const ins1 = document.getElementById('insight-text-1');
  const ins2 = document.getElementById('insight-text-2');
  const ins3 = document.getElementById('insight-text-3');

  if (ins1) ins1.innerHTML = `<strong>Dominant OS:</strong> ${topOs} accounts for ${Math.round((topOsMax/(totalCount||1))*100)}% of total releases with ${topOsMax} cataloged builds.`;
  if (ins2) ins2.innerHTML = `<strong>Channel Split:</strong> ${officialPct}% Official Public builds vs ${betaPct}% Developer/Public Beta test builds.`;

  const sortedDays = Object.keys(dayCounts).sort();
  let peakDay = '—';
  let peakCount = 0;
  sortedDays.forEach(d => {
    if (dayCounts[d] > peakCount) {
      peakCount = dayCounts[d];
      peakDay = d;
    }
  });
  if (ins3) ins3.innerHTML = `<strong>Peak Activity Day:</strong> Highest single-day activity was on <strong>${peakDay}</strong> with ${peakCount} updates released.`;

  // Common chart defaults
  const chartDefaults = {
    responsive: true,
    maintainAspectRatio: false,
    color: '#94a3b8',
    plugins: {
      legend: {
        position: 'bottom',
        labels: {
          color: '#cbd5e1',
          font: { family: 'Inter', size: 12 },
          padding: 14,
          usePointStyle: true,
        }
      },
      tooltip: {
        backgroundColor: '#0f172a',
        titleColor: '#f8fafc',
        bodyColor: '#cbd5e1',
        borderColor: 'rgba(255,255,255,0.1)',
        borderWidth: 1,
        padding: 10,
        cornerRadius: 8,
      }
    }
  };

  // Chart 1: OS Donut
  const osLabels = Object.keys(osCounts);
  const osData   = Object.values(osCounts);
  const osColors = osLabels.map(l => CHART_COLORS[l] || CHART_COLORS.Other);

  if (chartOs) chartOs.destroy();
  const ctxOs = document.getElementById('chart-os');
  if (ctxOs) {
    chartOs = new Chart(ctxOs, {
      type: 'doughnut',
      data: {
        labels: osLabels,
        datasets: [{ data: osData, backgroundColor: osColors, borderWidth: 2, borderColor: '#0b1223' }]
      },
      options: { ...chartDefaults, cutout: '65%' }
    });
  }

  // Chart 2: Official vs Beta Pie
  if (chartSource) chartSource.destroy();
  const ctxSource = document.getElementById('chart-source');
  if (ctxSource) {
    chartSource = new Chart(ctxSource, {
      type: 'pie',
      data: {
        labels: ['Official Public', 'Beta Channels'],
        datasets: [{ data: [officialCount, betaCount], backgroundColor: ['#30d158', '#fb923c'], borderWidth: 2, borderColor: '#0b1223' }]
      },
      options: { ...chartDefaults }
    });
  }

  // Chart 3: Platform Build Counts Horizontal Bar
  if (chartOsBar) chartOsBar.destroy();
  const ctxOsBar = document.getElementById('chart-os-bar');
  if (ctxOsBar) {
    chartOsBar = new Chart(ctxOsBar, {
      type: 'bar',
      data: {
        labels: osLabels,
        datasets: [{
          label: 'Build Count',
          data: osData,
          backgroundColor: osColors,
          borderRadius: 6,
        }]
      },
      options: {
        ...chartDefaults,
        indexAxis: 'y',
        scales: {
          x: { ticks: { color: '#64748b' }, grid: { color: 'rgba(255,255,255,0.05)' }, beginAtZero: true },
          y: { ticks: { color: '#cbd5e1' }, grid: { display: false } }
        },
        plugins: { ...chartDefaults.plugins, legend: { display: false } }
      }
    });
  }

  // Chart 4: Timeline Bar Chart
  const days   = sortedDays;
  const counts = days.map(d => dayCounts[d]);

  const tagEl = document.getElementById('timeline-chart-tag');
  if (tagEl) tagEl.textContent = `${days.length} Active Days`;

  if (chartTimeline) chartTimeline.destroy();
  const ctxTimeline = document.getElementById('chart-timeline');
  if (ctxTimeline) {
    chartTimeline = new Chart(ctxTimeline, {
      type: 'bar',
      data: {
        labels: days,
        datasets: [{
          label: 'Releases',
          data: counts,
          backgroundColor: 'rgba(51, 112, 179, 0.45)',
          borderColor: '#3370b3',
          borderWidth: 1.5,
          borderRadius: 4,
        }]
      },
      options: {
        ...chartDefaults,
        scales: {
          x: { ticks: { color: '#64748b', maxRotation: 45 }, grid: { color: 'rgba(255,255,255,0.05)' } },
          y: { ticks: { color: '#64748b' }, grid: { color: 'rgba(255,255,255,0.05)' }, beginAtZero: true }
        },
        plugins: { ...chartDefaults.plugins, legend: { display: false } }
      }
    });
  }
}

function exportAnalyticsCsv() {
  const dataset = (importedReleases.length > 0 ? importedReleases : allReleases);
  if (!dataset || !dataset.length) {
    showToast('No analytics data available to export.', 'warn');
    return;
  }

  let csvContent = 'data:text/csv;charset=utf-8,Name,Type,BuildID,Date,Source,Count\n';
  dataset.forEach(r => {
    const row = [
      `"${(r.name || '').replace(/"/g, '""')}"`,
      `"${(r.type || '').replace(/"/g, '""')}"`,
      `"${(r.buildid || '').replace(/"/g, '""')}"`,
      `"${(r.date || '').replace(/"/g, '""')}"`,
      `"${(r.source || '').replace(/"/g, '""')}"`,
      r.count || 0
    ].join(',');
    csvContent += row + '\n';
  });

  const encodedUri = encodeURI(csvContent);
  const link = document.createElement('a');
  link.setAttribute('href', encodedUri);
  link.setAttribute('download', `ipsw_analytics_export_${Date.now()}.csv`);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  showToast('Analytics CSV exported successfully!', 'success');
}

function initAnalytics() {
  document.getElementById('btn-refresh-charts')?.addEventListener('click', renderCharts);
  document.getElementById('analytics-timeframe')?.addEventListener('change', renderCharts);
  document.getElementById('analytics-source')?.addEventListener('change', renderCharts);
  document.getElementById('btn-export-analytics-csv')?.addEventListener('click', exportAnalyticsCsv);

  // Render whenever the analytics tab is opened
  document.getElementById('main-nav')?.addEventListener('click', e => {
    if (e.target.closest('[data-tab="analytics"]')) setTimeout(renderCharts, 50);
  });
}

// ── Header Release Filter ──────────────────────────────────────────────────
function initHeaderReleaseFilter() {
  const checkbox = document.getElementById('chk-header-release-info');
  const badge    = document.getElementById('hdr-release-badge');
  const popover  = document.getElementById('hdr-release-popover');
  const closeBtn = document.getElementById('hdr-popover-close');

  if (!checkbox) return;

  checkbox.addEventListener('change', () => {
    if (checkbox.checked) {
      badge?.classList.remove('hidden');
      popover?.classList.remove('hidden');
      updateHeaderReleaseInfo();
    } else {
      badge?.classList.add('hidden');
      popover?.classList.add('hidden');
    }
  });

  badge?.addEventListener('click', (e) => {
    e.stopPropagation();
    popover?.classList.toggle('hidden');
  });

  closeBtn?.addEventListener('click', () => {
    popover?.classList.add('hidden');
  });

  document.addEventListener('click', (e) => {
    const wrap = document.getElementById('header-release-filter-wrap');
    if (wrap && !wrap.contains(e.target) && popover && !popover.classList.contains('hidden')) {
      popover.classList.add('hidden');
    }
  });
}

function updateHeaderReleaseInfo(filteredList) {
  const checkbox = document.getElementById('chk-header-release-info');
  if (!checkbox) return;

  // Use currently displayed filteredList, or fallback to allReleases/importedReleases
  const list = filteredList || (importedReleases.length > 0 ? importedReleases : allReleases);
  
  const lastDateText = document.getElementById('hdr-last-date-text');
  const infoLastDate = document.getElementById('hdr-info-last-date');
  const datesCountEl = document.getElementById('hdr-info-dates-count');
  const datesListEl  = document.getElementById('hdr-info-dates-list');

  if (!list || list.length === 0) {
    if (lastDateText) lastDateText.textContent = 'Last: None';
    if (infoLastDate) infoLastDate.textContent = 'No releases found';
    if (datesCountEl) datesCountEl.textContent = '0';
    if (datesListEl)  datesListEl.innerHTML  = '<div class="hdr-empty-dates">No release dates available</div>';
    return;
  }

  // Sort items by date descending
  const datedItems = list.filter(r => r.date).sort((a, b) => new Date(b.date) - new Date(a.date));

  if (datedItems.length > 0) {
    const latestItem = datedItems[0];
    const formattedLatest = formatDateTime(latestItem.date);
    const shortLatest     = formatDate(latestItem.date);

    if (lastDateText) lastDateText.textContent = 'Last: ' + shortLatest;
    if (infoLastDate) {
      infoLastDate.innerHTML = `<span style="color:#00f2fe;">${formattedLatest}</span> <span style="font-size:0.78rem; font-weight:normal; color:#94a3b8;">(${escapeHtml(latestItem.name || 'Release')})</span>`;
    }
  } else {
    if (lastDateText) lastDateText.textContent = 'Last: —';
    if (infoLastDate) infoLastDate.textContent = 'Date not specified';
  }

  // Count releases per date string (YYYY-MM-DD)
  const dateMap = new Map();
  datedItems.forEach(r => {
    const dStr = r.date.split('T')[0];
    dateMap.set(dStr, (dateMap.get(dStr) || 0) + 1);
  });

  const sortedDates = Array.from(dateMap.entries()).sort((a, b) => b[0].localeCompare(a[0]));

  if (datesCountEl) datesCountEl.textContent = sortedDates.length;

  if (datesListEl) {
    if (sortedDates.length === 0) {
      datesListEl.innerHTML = '<div class="hdr-empty-dates">No dates found</div>';
    } else {
      datesListEl.innerHTML = sortedDates.map(([dStr, count]) => `
        <div class="hdr-date-item" data-date="${dStr}" title="Click to filter by date ${formatDate(dStr)}">
          <span><i class="fa-solid fa-calendar-day" style="color:#64748b; font-size:0.75rem;"></i> ${formatDate(dStr)}</span>
          <span class="count-tag">${count} update${count > 1 ? 's' : ''}</span>
        </div>
      `).join('');

      // Add click handler to filter by clicked date
      datesListEl.querySelectorAll('.hdr-date-item').forEach(item => {
        item.addEventListener('click', () => {
          const clickedDate = item.dataset.date;
          const select = document.getElementById('rel-date-select');
          if (select) {
            select.value = clickedDate;
            relSingleDate = clickedDate;
            updatePresetActive('none');
            renderReleases();
          }
        });
      });
    }
  }
}

// ── Real-Time Release Check Controls ──────────────────────────────────────
async function runRealtimeCheck() {
  const panel = document.getElementById('realtime-check-panel');
  const btn1  = document.getElementById('btn-feed-realtime-check');
  const btn2  = document.getElementById('btn-feed-header-realtime');

  const setButtonLoading = (isLoading) => {
    [btn1, btn2].forEach(b => {
      if (!b) return;
      if (isLoading) {
        b.disabled = true;
        if (!b.dataset.origHtml) b.dataset.origHtml = b.innerHTML;
        b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking...';
      } else if (b.dataset.origHtml) {
        b.disabled = false;
        b.innerHTML = b.dataset.origHtml;
      }
    });
  };

  setButtonLoading(true);
  showToast('Running real-time release date check...', 'info', 2000);

  try {
    await loadReleases();
  } catch (e) {
    console.warn('Realtime check load warning:', e);
  } finally {
    setButtonLoading(false);
  }

  // Open panel
  panel?.classList.remove('hidden');

  // Update status timestamp
  const timeEl = document.getElementById('rt-status-time');
  if (timeEl) timeEl.textContent = 'Checked at ' + new Date().toLocaleTimeString();

  updateRealtimeCheckPanel();
  showToast('Real-time check finished! Date results updated below.', 'success', 3000);
}

function updateRealtimeCheckPanel(filteredList) {
  const panel = document.getElementById('realtime-check-panel');
  if (!panel || panel.classList.contains('hidden')) return;

  const list = filteredList || (importedReleases.length > 0 ? importedReleases : allReleases);

  const lastDateEl  = document.getElementById('rt-last-release-date');
  const countEl     = document.getElementById('rt-dates-count');
  const datesGridEl = document.getElementById('rt-dates-grid');

  const datedItems = (list || []).filter(r => r.date).sort((a, b) => new Date(b.date) - new Date(a.date));

  if (datedItems.length > 0) {
    const latest = datedItems[0];
    if (lastDateEl) {
      lastDateEl.innerHTML = `<span style="color:#00f2fe;">${formatDateTime(latest.date)}</span> <div style="font-size:0.75rem; color:#94a3b8; font-weight:normal; margin-top:2px;">(${escapeHtml(latest.name || '')})</div>`;
    }
  } else {
    if (lastDateEl) lastDateEl.textContent = 'No releases found for selected filter';
  }

  const dateMap = new Map();
  datedItems.forEach(r => {
    const dStr = r.date.split('T')[0];
    dateMap.set(dStr, (dateMap.get(dStr) || 0) + 1);
  });

  const sortedDates = Array.from(dateMap.entries()).sort((a, b) => b[0].localeCompare(a[0]));

  if (countEl) countEl.textContent = sortedDates.length;

  if (datesGridEl) {
    if (sortedDates.length === 0) {
      datesGridEl.innerHTML = '<div class="hdr-empty-dates">No dates found</div>';
    } else {
      datesGridEl.innerHTML = sortedDates.map(([dStr, count]) => `
        <div class="rt-date-pill" data-date="${dStr}" title="Click to filter feed by date ${formatDate(dStr)}">
          <i class="fa-solid fa-calendar-day" style="color:#00f2fe;"></i>
          <span>${formatDate(dStr)}</span>
          <span class="pill-count">${count} update${count > 1 ? 's' : ''}</span>
        </div>
      `).join('');

      datesGridEl.querySelectorAll('.rt-date-pill').forEach(pill => {
        pill.addEventListener('click', () => {
          const clickedDate = pill.dataset.date;
          const select = document.getElementById('rel-date-select');
          if (select) {
            select.value = clickedDate;
            relSingleDate = clickedDate;
            updatePresetActive('none');
            renderReleases();
          }
        });
      });
    }
  }
}

function initRealtimeCheckControls() {
  document.getElementById('btn-feed-realtime-check')?.addEventListener('click', runRealtimeCheck);
  document.getElementById('btn-feed-header-realtime')?.addEventListener('click', runRealtimeCheck);
  document.getElementById('btn-rt-panel-close')?.addEventListener('click', () => {
    document.getElementById('realtime-check-panel')?.classList.add('hidden');
  });
}

// ── Boot ───────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  // Each init wrapped in try-catch so a crash in one NEVER blocks loadReleases
  try { initTabs(); }              catch (e) { console.error('initTabs error:', e); }
  try { initModal(); }             catch (e) { console.error('initModal error:', e); }
  try { initCacheExporterModal(); } catch (e) { console.error('initCacheExporterModal error:', e); }
  try { initCategoryFilter(); }    catch (e) { console.error('initCategoryFilter error:', e); }
  try { initReleasesControls(); }  catch (e) { console.error('initReleasesControls error:', e); }
  try { initSettings(); }          catch (e) { console.error('initSettings error:', e); }
  try { initAnalytics(); }         catch (e) { console.error('initAnalytics error:', e); }
  try { initHeaderReleaseFilter(); } catch (e) { console.error('initHeaderReleaseFilter error:', e); }
  try { initRealtimeCheckControls(); } catch (e) { console.error('initRealtimeCheckControls error:', e); }

  try { await loadSettings(); } catch (e) { console.error('loadSettings error:', e); }
  try { await loadReleases(); } catch (e) { console.error('loadReleases error:', e); }

  const minutes = parseInt(settings.autoCheckMinutes) || 1440;
  startAutoCheck(minutes);
});
