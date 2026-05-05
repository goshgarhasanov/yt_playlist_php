const form = document.getElementById('form');
const startBtn = document.getElementById('startBtn');
const progressCard = document.getElementById('progressCard');
const filesCard = document.getElementById('filesCard');
const stateEl = document.getElementById('state');
const itemInfo = document.getElementById('itemInfo');
const bar = document.getElementById('bar');
const percentEl = document.getElementById('percent');
const speedEl = document.getElementById('speed');
const etaEl = document.getElementById('eta');
const logEl = document.getElementById('log');
const fileList = document.getElementById('fileList');

let pollTimer = null;

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  startBtn.disabled = true;
  filesCard.classList.add('hidden');
  fileList.innerHTML = '';
  progressCard.classList.remove('hidden');
  setState('gözləyir', '');
  bar.style.width = '0%';
  percentEl.textContent = '0%';
  speedEl.textContent = '';
  etaEl.textContent = '';
  itemInfo.textContent = '';
  logEl.textContent = '';

  const fd = new FormData(form);
  try {
    const res = await fetch('api/start.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Naməlum xəta');
    poll(data.job_id);
  } catch (err) {
    setState('xəta', err.message, 'error');
    startBtn.disabled = false;
  }
});

function poll(jobId) {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(async () => {
    try {
      const res = await fetch('api/status.php?id=' + encodeURIComponent(jobId));
      const s = await res.json();
      if (!s.ok) throw new Error(s.error || 'status alınmadı');
      render(s);
      if (s.state === 'done' || s.state === 'error') {
        clearInterval(pollTimer);
        pollTimer = null;
        startBtn.disabled = false;
        if (s.state === 'done') loadFiles(jobId);
      }
    } catch (err) {
      clearInterval(pollTimer);
      pollTimer = null;
      setState('xəta', err.message, 'error');
      startBtn.disabled = false;
    }
  }, 1200);
}

function render(s) {
  const cls = s.state === 'running' ? 'running' : s.state === 'done' ? 'done' : s.state === 'error' ? 'error' : '';
  setState(stateLabel(s.state), '', cls);

  if (s.total_items > 0) {
    itemInfo.textContent = `Element ${s.current_item || 0} / ${s.total_items}`;
  }
  const pct = Math.max(0, Math.min(100, Number(s.percent) || 0));
  bar.style.width = pct + '%';
  percentEl.textContent = pct.toFixed(1) + '%';
  speedEl.textContent = s.speed ? 'Sürət: ' + s.speed : '';
  etaEl.textContent = s.eta ? 'ETA: ' + s.eta : '';
  if (s.log) logEl.textContent = s.log;
}

function stateLabel(state) {
  switch (state) {
    case 'running': return 'yüklənir';
    case 'done': return 'tamam';
    case 'error': return 'xəta';
    default: return state || 'gözləyir';
  }
}

function setState(text, extra, cls) {
  stateEl.className = 'badge' + (cls ? ' ' + cls : '');
  stateEl.textContent = text;
  if (extra) itemInfo.textContent = extra;
}

async function loadFiles(jobId) {
  try {
    const res = await fetch('api/files.php?id=' + encodeURIComponent(jobId));
    const data = await res.json();
    if (!data.ok) return;
    if (!data.files.length) return;
    filesCard.classList.remove('hidden');
    fileList.innerHTML = '';
    for (const f of data.files) {
      const li = document.createElement('li');
      const name = document.createElement('span');
      name.className = 'name';
      name.textContent = f.name;
      const size = document.createElement('span');
      size.className = 'size';
      size.textContent = formatSize(f.size);
      const a = document.createElement('a');
      a.href = 'api/files.php?id=' + encodeURIComponent(jobId) + '&download=' + encodeURIComponent(f.name);
      a.textContent = 'Yüklə';
      li.append(name, size, a);
      fileList.appendChild(li);
    }
  } catch {}
}

function formatSize(bytes) {
  if (!bytes) return '';
  const u = ['B','KB','MB','GB'];
  let i = 0, n = bytes;
  while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
  return n.toFixed(1) + ' ' + u[i];
}
