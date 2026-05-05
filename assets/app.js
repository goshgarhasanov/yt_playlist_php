'use strict';

const $ = (id) => document.getElementById(id);

const state = {
  currentJobId: null,
  pollTimer: null,
  view: 'new',
};

const PHASES = ['queued', 'fetching', 'downloading', 'converting', 'finalizing'];

document.addEventListener('DOMContentLoaded', init);

function init() {
  $('form').addEventListener('submit', onStart);
  $('cancelBtn').addEventListener('click', onCancel);

  document.querySelectorAll('.nav-btn').forEach(btn => {
    btn.addEventListener('click', () => switchView(btn.dataset.view));
  });

  loadHistory();
  setInterval(loadHistory, 5000);

  const params = new URLSearchParams(location.search);
  const resumeId = params.get('job');
  if (resumeId) {
    showJobCard();
    poll(resumeId);
  }
  if (params.get('view') === 'history' || location.hash === '#history') {
    switchView('history');
  }
}

function switchView(view) {
  state.view = view;
  document.querySelectorAll('.nav-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.view === view);
  });
  document.querySelectorAll('.view').forEach(v => v.classList.add('hidden'));
  $('view-' + view).classList.remove('hidden');
  if (view === 'history') loadHistory();
}

async function onStart(e) {
  e.preventDefault();
  const btn = $('startBtn');
  btn.disabled = true;
  $('jobCard').classList.remove('hidden');
  $('filesCard').classList.add('hidden');
  $('fileList').innerHTML = '';
  resetJobUI();

  try {
    const fd = new FormData($('form'));
    const res = await fetch('api/start.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'xəta');
    state.currentJobId = data.job_id;
    history.replaceState(null, '', '?job=' + data.job_id);
    poll(data.job_id);
    toast('Yükləmə başladı', 'success');
  } catch (err) {
    toast(err.message, 'error');
    btn.disabled = false;
    $('jobCard').classList.add('hidden');
  }
}

async function onCancel() {
  if (!state.currentJobId) return;
  if (!confirm('Yükləməni dayandırmaq istədiyinizə əminsiniz?')) return;

  $('cancelBtn').disabled = true;
  try {
    const fd = new FormData();
    fd.append('id', state.currentJobId);
    const res = await fetch('api/cancel.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'xəta');
    toast('Yükləmə dayandırıldı', 'success');
  } catch (err) {
    toast(err.message, 'error');
  } finally {
    $('cancelBtn').disabled = false;
  }
}

function poll(jobId) {
  if (state.pollTimer) clearInterval(state.pollTimer);
  showJobCard();
  tick(jobId);
  state.pollTimer = setInterval(() => tick(jobId), 350);
}

async function tick(jobId) {
  try {
    const res = await fetch('api/status.php?id=' + encodeURIComponent(jobId));
    const s = await res.json();
    if (!s.ok) throw new Error(s.error || 'status alınmadı');
    renderJob(s);
    if (['done', 'error', 'cancelled'].includes(s.state)) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
      $('startBtn').disabled = false;
      $('cancelBtn').classList.add('hidden');
      if (s.files_count > 0) loadFiles(jobId);
      loadHistory();
    }
  } catch (err) {
    clearInterval(state.pollTimer);
    state.pollTimer = null;
    toast(err.message, 'error');
    $('startBtn').disabled = false;
  }
}

function showJobCard() {
  $('jobCard').classList.remove('hidden');
  $('cancelBtn').classList.remove('hidden');
}

function resetJobUI() {
  $('jobTitle').textContent = 'Hazırlanır...';
  $('jobMeta').innerHTML = '';
  $('bar').style.width = '0%';
  $('bar').classList.remove('done', 'error');
  $('bar').classList.add('indeterminate');
  $('percent').textContent = '0%';
  $('speed').textContent = '—';
  $('eta').textContent = 'ETA —';
  $('log').textContent = '';
  document.querySelectorAll('.phase').forEach(p => {
    p.classList.remove('active', 'done', 'error');
  });
  $('cancelBtn').classList.remove('hidden');
  $('cancelBtn').disabled = false;
}

function renderJob(s) {
  if (s.title) {
    $('jobTitle').textContent = s.title;
  } else if (s.config && s.config.url) {
    $('jobTitle').textContent = shortUrl(s.config.url);
  }

  const meta = $('jobMeta');
  meta.innerHTML = '';
  if (s.config) {
    if (s.config.format) meta.appendChild(chip(s.config.format.toUpperCase()));
    if (s.config.quality) meta.appendChild(chip(qualityLabel(s.config.quality)));
  }
  if (s.total_items > 1) {
    const span = document.createElement('span');
    span.textContent = `Element ${s.current_item || 0} / ${s.total_items}`;
    meta.appendChild(span);
  }
  if (s.files_count > 0) {
    const span = document.createElement('span');
    span.textContent = `${s.files_count} fayl · ${formatSize(s.files_size)}`;
    meta.appendChild(span);
  }

  renderPhases(s);

  const bar = $('bar');
  const pct = Math.max(0, Math.min(100, Number(s.percent) || 0));

  if (s.state === 'queued' || (s.phase === 'fetching' && pct === 0)) {
    bar.classList.add('indeterminate');
    bar.style.width = '';
  } else {
    bar.classList.remove('indeterminate');
    bar.style.width = pct + '%';
  }

  if (s.state === 'done') {
    bar.classList.add('done');
    bar.classList.remove('indeterminate');
    bar.style.width = '100%';
    $('cancelBtn').classList.add('hidden');
  } else if (s.state === 'error' || s.state === 'cancelled') {
    bar.classList.add('error');
    bar.classList.remove('indeterminate');
    $('cancelBtn').classList.add('hidden');
  }

  const errorBox = $('errorBox');
  if (s.error && (s.state === 'error' || s.state === 'cancelled')) {
    errorBox.classList.remove('hidden');
    $('errorMsg').textContent = s.error;
  } else {
    errorBox.classList.add('hidden');
  }

  $('percent').textContent = pct.toFixed(1) + '%';
  $('speed').textContent = s.speed || '—';
  $('eta').textContent = s.eta ? 'ETA ' + s.eta : 'ETA —';

  if (s.log) $('log').textContent = s.log;
}

function renderPhases(s) {
  const phaseEls = document.querySelectorAll('.phase');
  let activeIdx = PHASES.indexOf(s.phase);
  if (activeIdx < 0) activeIdx = PHASES.indexOf(s.state);

  phaseEls.forEach((el, idx) => {
    el.classList.remove('active', 'done', 'error');
    if (s.state === 'error' || s.state === 'cancelled') {
      if (idx === activeIdx) el.classList.add('error');
      else if (idx < activeIdx) el.classList.add('done');
    } else if (s.state === 'done') {
      el.classList.add('done');
    } else {
      if (idx < activeIdx) el.classList.add('done');
      else if (idx === activeIdx) el.classList.add('active');
    }
  });
}

function chip(text) {
  const span = document.createElement('span');
  span.className = 'chip';
  span.textContent = text;
  return span;
}

function qualityLabel(q) {
  return { best: 'Ən yaxşı', medium: 'Orta', low: 'Aşağı' }[q] || q;
}

function shortUrl(url) {
  try {
    const u = new URL(url);
    return u.hostname + u.pathname.slice(0, 30) + (u.pathname.length > 30 ? '...' : '');
  } catch { return url; }
}

async function loadFiles(jobId) {
  try {
    const res = await fetch('api/files.php?id=' + encodeURIComponent(jobId));
    const data = await res.json();
    if (!data.ok || !data.files.length) return;
    $('filesCard').classList.remove('hidden');
    const total = data.files.reduce((s, f) => s + f.size, 0);
    $('filesSummary').textContent = `${data.files.length} fayl · ${formatSize(total)}`;
    const list = $('fileList');
    list.innerHTML = '';
    for (const f of data.files) {
      const li = document.createElement('li');
      li.innerHTML = `
        <span class="ico">${fileIcon(f.name)}</span>
        <span class="name" title="${escapeHtml(f.name)}">${escapeHtml(f.name)}</span>
        <span class="size">${formatSize(f.size)}</span>
        <a class="dl-btn" href="api/files.php?id=${encodeURIComponent(jobId)}&download=${encodeURIComponent(f.name)}">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Yüklə
        </a>`;
      list.appendChild(li);
    }
  } catch (err) {
    console.error(err);
  }
}

function fileIcon(name) {
  const ext = (name.split('.').pop() || '').toLowerCase();
  if (['mp3', 'm4a', 'opus', 'wav', 'flac'].includes(ext)) {
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>';
  }
  return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>';
}

async function loadHistory() {
  try {
    const res = await fetch('api/jobs.php');
    const data = await res.json();
    if (!data.ok) return;
    const jobs = data.jobs || [];

    $('historyCount').textContent = jobs.length || '';

    const list = $('historyList');
    const empty = $('historyEmpty');
    if (!jobs.length) {
      list.innerHTML = '';
      empty.classList.remove('hidden');
      return;
    }
    empty.classList.add('hidden');
    list.innerHTML = '';
    for (const j of jobs) {
      list.appendChild(historyRow(j));
    }
  } catch {}
}

function historyRow(j) {
  const div = document.createElement('div');
  div.className = 'history-item';

  const fmt = (j.format || '').toLowerCase();
  const iconHtml = fmt === 'mp4'
    ? '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>'
    : '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>';

  const title = j.title || shortUrl(j.url);
  const date = j.started_at ? new Date(j.started_at).toLocaleString('az-AZ') : '';
  const sizeStr = j.files_size ? formatSize(j.files_size) : '';
  const filesStr = j.files_count ? `${j.files_count} fayl` : '';

  div.innerHTML = `
    <div class="history-icon ${fmt}">${iconHtml}</div>
    <div class="history-body">
      <div class="history-title">${escapeHtml(title)}</div>
      <div class="history-meta">
        <span>${escapeHtml(fmt.toUpperCase())} · ${escapeHtml(qualityLabel(j.quality))}</span>
        ${filesStr ? `<span>${filesStr}</span>` : ''}
        ${sizeStr ? `<span>${sizeStr}</span>` : ''}
        ${date ? `<span>${escapeHtml(date)}</span>` : ''}
      </div>
    </div>
    <span class="history-status ${j.state}">${stateLabel(j.state)}</span>
    <div class="history-actions">
      <button class="icon-btn" title="Aç" data-action="open">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
      </button>
      <button class="icon-btn danger" title="Sil" data-action="delete">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
      </button>
    </div>`;

  div.querySelector('[data-action="open"]').addEventListener('click', (e) => {
    e.stopPropagation();
    openJob(j.job_id);
  });
  div.querySelector('[data-action="delete"]').addEventListener('click', async (e) => {
    e.stopPropagation();
    if (!confirm('Bu yükləməni və bütün fayllarını silmək istədiyinizə əminsiniz?')) return;
    const fd = new FormData();
    fd.append('id', j.job_id);
    const res = await fetch('api/delete.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      toast('Silindi', 'success');
      loadHistory();
    } else {
      toast(data.error || 'silinmədi', 'error');
    }
  });
  div.addEventListener('click', () => openJob(j.job_id));

  return div;
}

function openJob(jobId) {
  state.currentJobId = jobId;
  history.replaceState(null, '', '?job=' + jobId);
  switchView('new');
  poll(jobId);
}

function stateLabel(s) {
  return {
    queued: 'gözləyir',
    fetching: 'məlumat',
    downloading: 'yüklənir',
    converting: 'çevrilir',
    finalizing: 'bitir',
    running: 'işləyir',
    done: 'tamam',
    error: 'xəta',
    cancelled: 'dayandırıldı',
    unknown: '—',
  }[s] || s;
}

function formatSize(bytes) {
  if (!bytes) return '0 B';
  const u = ['B','KB','MB','GB','TB'];
  let i = 0, n = bytes;
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return n.toFixed(i === 0 ? 0 : 1) + ' ' + u[i];
}

function escapeHtml(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

let toastTimer = null;
function toast(msg, kind) {
  const t = $('toast');
  t.textContent = msg;
  t.className = 'toast show' + (kind ? ' ' + kind : '');
  if (toastTimer) clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 3500);
}
