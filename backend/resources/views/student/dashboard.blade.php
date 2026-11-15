@extends('layouts.app')
@section('title', 'My monitoring - CertiTrack')
@section('content')
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card shadow-sm mb-3"><div class="card-header fw-semibold">Current monitoring status</div>
      <div class="card-body" id="active-box"></div></div>
    <div class="card shadow-sm mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span class="fw-semibold">My session history</span>
      <select id="f-status" class="form-select form-select-sm w-auto" aria-label="Filter by status">
        <option value="">All statuses</option><option value="active">Active</option><option value="interrupted">Interrupted</option><option value="completed">Completed</option><option value="abandoned">Abandoned</option></select></div>
      <div class="card-body p-0"><div id="history" class="table-responsive"></div><div id="history-pager" class="p-2"></div></div></div>
    <div class="card shadow-sm"><div class="card-header fw-semibold">My recorded events by type</div>
      <div class="card-body"><div class="chart-box"><canvas id="c-types" aria-label="Events by type" role="img"></canvas></div><div id="c-types-empty"></div></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card shadow-sm mb-3"><div class="card-header fw-semibold">1. Pair the Chrome extension</div><div class="card-body">
      <p class="small">Generate a one-time code (valid 10 minutes), then enter it in the CertiTrack extension popup.</p>
      <button id="btn-pair" class="btn btn-primary btn-sm">Generate pairing code</button>
      <div id="pair-out" class="mt-3" aria-live="polite"></div>
      <hr><h2 class="h6">Paired extensions</h2><div id="devices"></div></div></div>
    <div class="card shadow-sm mb-3"><div class="card-header fw-semibold">2. Start monitoring</div><div class="card-body small">
      <p>Open your certification platform page in Chrome, open the extension, choose the platform, tick the consent box and press <strong>Start monitoring</strong>. Press <strong>End monitoring</strong> in the popup when you finish.</p>
      <p class="mb-0">Monitoring only runs between Start and End. <a href="/student/privacy">See exactly what is collected</a>.</p></div></div>
    <div class="card shadow-sm"><div class="card-header fw-semibold">Supported platforms</div><div class="card-body" id="platforms"></div></div>
  </div>
</div>
@endsection
@push('scripts')
<script>
const $ = (id) => document.getElementById(id);
let page = 1;

async function loadActive() {
  try {
    const { data: s } = await CT.api('/sessions/active');
    if (!s) { $('active-box').innerHTML = '<p class="mb-0 text-muted">No monitoring session is active. Nothing is being recorded.</p>'; return; }
    const hb = s.last_heartbeat_at ? Math.round((Date.now() - new Date(s.last_heartbeat_at)) / 1000) : null;
    const connected = hb !== null && hb < 120 && s.status === 'active';
    $('active-box').innerHTML = `
      <div class="d-flex flex-wrap gap-3 align-items-center mb-2">
        <div>${CT.statusBadge(s.status)}</div>
        <div><strong>${CT.esc(s.platform?.name)}</strong></div>
        <div>Duration: <span class="mono">${CT.dur(s.duration_seconds)}</span></div>
        <div>Events recorded: <strong>${s.event_count}</strong></div>
        <div>Extension: ${connected ? '<span class="text-success">connected</span>' : '<span class="text-warning">no recent heartbeat</span>'}${hb !== null ? ` <small class="text-muted">(last heartbeat ${hb}s ago)</small>` : ''}</div>
      </div>
      <p class="small text-muted mb-2">Monitoring is ON for this session. End it from the extension popup, or below if the extension is unavailable.</p>
      <button class="btn btn-outline-danger btn-sm" id="btn-end">End this session now</button>
      <a class="btn btn-outline-secondary btn-sm" href="/sessions/${s.id}">View timeline</a>`;
    $('btn-end').onclick = async () => {
      if (!confirm('End this monitoring session? Events not yet synced by the extension may still arrive later and will be marked as late.')) return;
      try { await CT.api(`/sessions/${s.id}/end`, { method: 'POST', body: {} }); CT.notify('Session ended.'); refresh(); } catch (e) { CT.notify(CT.errText(e), 'danger'); }
    };
  } catch (e) { $('active-box').innerHTML = CT.failed(e); }
}

async function loadHistory() {
  CT.loading($('history'));
  try {
    const r = await CT.api('/sessions', { query: { page, status: $('f-status').value } });
    if (!r.data.length) { $('history').innerHTML = CT.empty('No sessions yet. They will appear here after you start monitoring from the extension.'); $('history-pager').innerHTML = ''; return; }
    $('history').innerHTML = `<table class="table table-hover mb-0"><caption class="visually-hidden">Session history</caption>
      <thead><tr><th>Started</th><th>Platform</th><th>Status</th><th>Duration</th><th>Events</th><th></th></tr></thead><tbody>` +
      r.data.map((s) => `<tr><td>${CT.fmt(s.started_at)}</td><td>${CT.esc(s.platform?.name)}</td><td>${CT.statusBadge(s.status)}</td>
        <td>${CT.dur(s.duration_seconds)}</td><td>${s.event_count}</td><td><a href="/sessions/${s.id}">Details</a></td></tr>`).join('') + '</tbody></table>';
    CT.pager($('history-pager'), r, (p) => { page = p; loadHistory(); });
  } catch (e) { $('history').innerHTML = CT.failed(e); }
}

async function loadStats() {
  try {
    const { data } = await CT.api('/stats');
    const labels = Object.keys(data.events_by_type);
    $('c-types-empty').innerHTML = labels.length ? '' : CT.empty('No events recorded yet.');
    CT.chart('c-types', { type: 'bar', data: { labels: labels.map(CT.pretty), datasets: [{ label: 'Events', data: Object.values(data.events_by_type), backgroundColor: CT.PALETTE }] }, options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
  } catch (e) { $('c-types-empty').innerHTML = CT.failed(e); }
}

async function loadPlatforms() {
  try {
    const { data } = await CT.api('/platforms');
    $('platforms').innerHTML = data.length ? '<ul class="list-unstyled mb-0">' + data.map((p) => `<li class="mb-2"><strong>${CT.esc(p.name)}</strong><br><small class="text-muted">${p.domains.map((d) => CT.esc(d.host + (d.path_prefix || ''))).join(', ')}</small></li>`).join('') + '</ul>' : CT.empty('No platforms configured.');
  } catch (e) { $('platforms').innerHTML = CT.failed(e); }
}

async function loadDevices() {
  try {
    const { data } = await CT.api('/extension/devices');
    $('devices').innerHTML = data.length ? '<ul class="list-group list-group-flush">' + data.map((d) => `<li class="list-group-item px-0 d-flex justify-content-between align-items-center">
      <span class="small">${CT.esc(d.name.replace('extension:', ''))}<br><span class="text-muted">paired ${CT.fmt(d.created_at)}; last used ${CT.fmt(d.last_used_at)}</span></span>
      <button class="btn btn-sm btn-outline-danger" data-id="${d.id}">Revoke</button></li>`).join('') + '</ul>' : '<p class="small text-muted mb-0">No extension paired yet.</p>';
    $('devices').querySelectorAll('button[data-id]').forEach((b) => b.onclick = async () => {
      if (!confirm('Revoke this extension? It will stop working until paired again.')) return;
      try { await CT.api('/extension/devices/' + b.dataset.id, { method: 'DELETE' }); CT.notify('Extension revoked.'); loadDevices(); } catch (e) { CT.notify(CT.errText(e), 'danger'); }
    });
  } catch (e) { $('devices').innerHTML = CT.failed(e); }
}

$('btn-pair').onclick = async () => {
  try {
    const { data } = await CT.api('/pairing-codes', { method: 'POST', body: {} });
    $('pair-out').innerHTML = `<div class="alert alert-info mb-0"><div class="pair-code mono">${CT.esc(data.code)}</div><small>Expires ${CT.fmt(data.expires_at)}. One use only.</small></div>`;
  } catch (e) { $('pair-out').innerHTML = CT.failed(e); }
};
$('f-status').onchange = () => { page = 1; loadHistory(); };

function refresh() { loadActive(); loadHistory(); loadStats(); loadDevices(); }
loadPlatforms(); refresh();
setInterval(loadActive, 10000);
</script>
@endpush
