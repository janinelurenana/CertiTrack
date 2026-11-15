const $ = (id) => document.getElementById(id);
const send = (type, extra = {}) => chrome.runtime.sendMessage({ type, ...extra });
let status = null, busy = false;

const show = (id, on) => { $(id).hidden = !on; };
function msg(text, ok = false) { const m = $('msg'); m.textContent = text || ''; m.className = 'msg' + (ok ? ' ok' : ''); }
const dur = (s) => { s = Math.max(0, Math.floor(s)); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60; return [h, m, x].map((n) => String(n).padStart(2, '0')).join(':'); };

async function call(type, extra, okText) {
  if (busy) return null; busy = true; msg('');
  try {
    const r = await send(type, extra);
    if (!r || !r.ok) { msg(r?.error || 'Unexpected error'); return r; }
    if (okText) msg(okText, true);
    return r;
  } finally { busy = false; await refresh(); }
}

async function refresh() {
  const r = await send('GET_STATUS');
  if (!r || !r.ok) return;
  status = r.data; render();
}

function render() {
  const s = status, sess = s.session;
  const configured = !!s.server.baseUrl;
  show('v-server', false);
  show('v-signin', !s.server.signedIn);
  show('v-idle', s.server.signedIn && !sess);
  show('v-active', !!sess);
  show('foot', s.server.signedIn);
  $('server-url').value = $('server-url').value || s.server.baseUrl;
  if (!s.server.signedIn && !configured) show('v-server', true);
  $('notice').hidden = !s.notice; $('notice').textContent = s.notice || '';
  $('who').textContent = s.server.user ? `${s.server.user.name}` : '';

  const pill = $('pill');
  if (sess && sess.active) { pill.textContent = 'Monitoring ON'; pill.className = 'pill on'; }
  else if (sess && sess.pendingEnd) { pill.textContent = 'Finishing\u2026'; pill.className = 'pill wait'; }
  else { pill.textContent = 'Monitoring OFF'; pill.className = 'pill off'; }

  if (s.server.signedIn && !sess) {
    const sel = $('platform'); const cur = sel.value;
    sel.innerHTML = s.platforms.map((p) => `<option value="${p.id}">${p.name.replace(/</g, '&lt;')}</option>`).join('');
    sel.value = s.detectedPlatformId ? String(s.detectedPlatformId) : (cur || (s.platforms[0] && String(s.platforms[0].id)) || '');
    $('detect').textContent = s.detectedPlatformId ? 'This tab matches the selected platform.' : 'This tab does not match any approved domain. Open the platform page in this window, then reopen this popup.';
    $('btn-start').disabled = !$('consent').checked || !sel.value;
  }
  if (sess) renderActive(sess);
}

function renderActive(sess) {
  $('a-title').textContent = sess.active ? 'Monitoring is ON' : 'Ending session\u2026';
  $('a-platform').textContent = sess.platformName;
  $('a-duration').textContent = dur((Date.now() - new Date(sess.startedAt)) / 1000);
  const c = status.connection;
  $('a-conn').innerHTML = c.online ? '<span class="good">Online</span>' : `<span class="bad">Offline since ${new Date(c.offlineSince).toLocaleTimeString()}</span> (events are queued)`;
  $('a-events').textContent = `${sess.eventCount} recorded, ${sess.queued} waiting to sync` + (sess.dropped ? `, ${sess.dropped} dropped (queue full)` : '') + (sess.rejected ? `, ${sess.rejected} rejected by server` : '');
  $('a-tab').innerHTML = sess.hasExamTab ? '<span class="good">tracked</span>' : '<span class="bad">closed (reopen the platform page)</span>';
  $('btn-end').hidden = !sess.active;
  const p = $('pending');
  p.hidden = !sess.pendingEnd;
  if (sess.pendingEnd) {
    p.innerHTML = `Waiting to sync ${sess.queued} event(s) before closing the session. <button id="b-retry" class="secondary">Retry now</button> <button id="b-force" class="danger">End anyway (discard unsynced)</button>`;
    $('b-retry').onclick = () => call('SYNC_NOW');
    $('b-force').onclick = () => confirm('Discard events that have not reached the server?') && call('END', { force: true });
  }
}

$('btn-server').onclick = async () => {
  const url = $('server-url').value.trim();
  try {
    const origin = new URL(url).origin;
    const granted = await chrome.permissions.request({ origins: [origin + '/*'] }); // needs this user click
    if (!granted) return msg('Permission to contact that server was not granted.');
  } catch { return msg('Enter a full address such as http://127.0.0.1:8000'); }
  await call('SET_SERVER', { baseUrl: url }, 'Server saved.');
};
$('change-server').onclick = (e) => { e.preventDefault(); show('v-server', true); };
$('btn-pair').onclick = () => call('PAIR', { code: $('pair-code').value.trim() }, 'Paired.');
$('btn-login').onclick = () => call('LOGIN', { email: $('email').value.trim(), password: $('password').value });
$('btn-logout').onclick = (e) => { e.preventDefault(); call('LOGOUT'); };
$('consent').onchange = () => { $('btn-start').disabled = !$('consent').checked || !$('platform').value; };
$('btn-start').onclick = async () => {
  const r = await call('START', { platformId: Number($('platform').value), consent: $('consent').checked });
  if (r && !r.ok && r.existing) {
    const box = $('existing'); box.hidden = false;
    box.innerHTML = `A session (#${r.existing.id}) is already open. <button id="b-adopt" class="secondary">Resume it on this tab</button>`;
    $('b-adopt').onclick = () => call('START', { platformId: Number($('platform').value), consent: true, adoptSessionId: r.existing.id });
  }
};
$('btn-end').onclick = async () => {
  const r = await call('END', {});
  if (r?.ok && r.data.status === 'pending') msg('Offline: the session will close automatically once events are synced.');
};
$('open-dash').onclick = (e) => { e.preventDefault(); if (status) chrome.tabs.create({ url: status.server.baseUrl + '/student' }); };

refresh();
setInterval(() => { if (!busy) refresh(); }, 1000);
