@extends('layouts.app')
@section('title', 'Session #' . $id . ' - CertiTrack')
@section('content')
<div class="mb-3"><a href="javascript:history.back()" class="small">&larr; Back</a></div>
<div id="head"></div>
<div class="alert alert-secondary small" role="note">Events show <strong>technical browser activity only</strong>. A tab switch or loss of focus can have legitimate explanations and is not proof of misconduct. Events are reported by a browser extension and may be incomplete or altered.</div>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card shadow-sm"><div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"><span class="fw-semibold">Event timeline</span>
      <div class="d-flex gap-2"><select id="f-sev" class="form-select form-select-sm" aria-label="Filter by severity"><option value="">All severities</option><option value="info">Info</option><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select>
      <select id="f-type" class="form-select form-select-sm" aria-label="Filter by type"><option value="">All types</option></select></div></div>
      <div class="card-body p-0"><div id="timeline" class="table-responsive"></div></div></div>
  </div>
  <div class="col-lg-4">
    <div class="card shadow-sm mb-3"><div class="card-header fw-semibold">Events by severity</div><div class="card-body"><div class="chart-box" style="height:200px"><canvas id="c-sev" role="img" aria-label="Events by severity"></canvas></div></div></div>
    <div id="review-panel"></div>
  </div>
</div>
@endsection
@push('scripts')
<script>
const SID = {{ (int) $id }};
const ROLE = CT_USER.role;
const $ = (id) => document.getElementById(id);
let session = null, events = [], reviews = [], flagTarget = null;

async function load() {
  CT.loading($('timeline'));
  try {
    if (ROLE === 'student') {
      const [s, e] = await Promise.all([CT.api('/sessions/' + SID), CT.api('/sessions/' + SID + '/events')]);
      session = s.data; events = e.data; reviews = [];
    } else {
      const [s, e] = await Promise.all([CT.api('/sessions/' + SID), CT.api('/instructor/sessions/' + SID + '/events')]);
      session = s.data; events = e.data; reviews = e.meta.reviews;
    }
    renderHead(); renderTypes(); renderTimeline(); renderChart(); renderReview();
  } catch (e) {
    $('head').innerHTML = CT.failed(e); $('timeline').innerHTML = '';
  }
}

function renderHead() {
  const s = session;
  $('head').innerHTML = `<div class="card shadow-sm mb-3"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between gap-2"><h1 class="h4 mb-0">Session #${s.id} &middot; ${CT.esc(s.platform?.name)}</h1>
      <div>${CT.statusBadge(s.status)} ${ROLE !== 'student' ? CT.reviewBadge(s.review_status) : ''}
      ${ROLE !== 'student' ? `<a class="btn btn-outline-secondary btn-sm ms-2" href="/reports/sessions/${s.id}">Generate report</a>` : ''}</div></div>
    <dl class="row mb-0 mt-3 small">
      ${ROLE !== 'student' ? `<dt class="col-sm-3">Student</dt><dd class="col-sm-9">${CT.esc(s.student?.name)} (${CT.esc(s.student?.email)})</dd>` : ''}
      <dt class="col-sm-3">Started</dt><dd class="col-sm-9">${CT.fmt(s.started_at)}</dd>
      <dt class="col-sm-3">Ended</dt><dd class="col-sm-9">${CT.fmt(s.ended_at)}</dd>
      <dt class="col-sm-3">Duration</dt><dd class="col-sm-9">${CT.dur(s.duration_seconds)}</dd>
      <dt class="col-sm-3">Last heartbeat</dt><dd class="col-sm-9">${CT.fmt(s.last_heartbeat_at)}</dd>
      <dt class="col-sm-3">Extension events received</dt><dd class="col-sm-9">${s.event_count} ${s.possibly_missing_events ? `<span class="badge bg-warning text-dark">Extension reports ${s.possibly_missing_events} more event(s) that never reached the server</span>` : ''}</dd>
      <dt class="col-sm-3">Consent</dt><dd class="col-sm-9">version ${CT.esc(s.consent_version)} at ${CT.fmt(s.consented_at)}</dd>
    </dl></div></div>`;
}

function renderTypes() {
  const sel = $('f-type'), cur = sel.value;
  const types = [...new Set(events.map((e) => e.type))].sort();
  sel.innerHTML = '<option value="">All types</option>' + types.map((t) => `<option value="${t}">${CT.esc(CT.pretty(t))}</option>`).join('');
  sel.value = types.includes(cur) ? cur : '';
}

function renderTimeline() {
  const sev = $('f-sev').value, type = $('f-type').value;
  const rows = events.filter((e) => (!sev || e.severity === sev) && (!type || e.type === type));
  if (!rows.length) { $('timeline').innerHTML = CT.empty(events.length ? 'No events match these filters.' : 'No events were recorded for this session.'); return; }
  $('timeline').innerHTML = `<table class="table table-sm align-middle mb-0"><caption class="visually-hidden">Event timeline (times shown in your local timezone)</caption>
    <thead><tr><th>Time (local)</th><th>Event</th><th>Severity</th><th>Details</th>${ROLE === 'instructor' ? '<th></th>' : ''}</tr></thead><tbody>` +
    rows.map((e) => `<tr class="timeline-row ${e.flagged ? 'flagged' : ''}"><td class="text-nowrap">${CT.fmt(e.occurred_at)}</td>
      <td>${CT.esc(e.label)} ${e.flagged ? '<span class="badge bg-warning text-dark">flagged</span>' : ''} ${e.received_after_end ? '<span class="badge bg-light text-dark border" title="Synced after the session ended">late sync</span>' : ''}
        ${e.source === 'server' ? '<span class="badge bg-light text-dark border" title="Generated by the server">server</span>' : ''}</td>
      <td>${CT.sevBadge(e.severity)}</td><td class="small text-muted">${CT.esc(CT.detailText(e.details))}</td>
      ${ROLE === 'instructor' ? `<td><button class="btn btn-sm btn-outline-secondary" data-flag="${e.id}">Flag</button></td>` : ''}</tr>`).join('') + '</tbody></table>';
  $('timeline').querySelectorAll('button[data-flag]').forEach((b) => b.onclick = () => { flagTarget = +b.dataset.flag; renderReview(); $('rv-remarks')?.focus(); });
}

function renderChart() {
  const c = { info: 0, low: 0, medium: 0, high: 0 }; events.forEach((e) => c[e.severity]++);
  CT.chart('c-sev', { type: 'bar', data: { labels: ['Info', 'Low', 'Medium', 'High'], datasets: [{ data: Object.values(c), backgroundColor: ['#6c757d', '#0d6efd', '#fd7e14', '#dc3545'] }] }, options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
}

function renderReview() {
  if (ROLE === 'student') { $('review-panel').innerHTML = ''; return; }
  const past = reviews.length ? '<ul class="list-group list-group-flush mt-3">' + reviews.map((r) => `<li class="list-group-item px-0 small">${CT.reviewBadge(r.status)} <strong>${CT.esc(r.reviewer?.name)}</strong> <span class="text-muted">${CT.fmt(r.created_at)}${r.event_id ? ' &middot; event #' + r.event_id : ''}</span><br>${CT.esc(r.remarks || '')}</li>`).join('') + '</ul>' : '<p class="small text-muted mt-3 mb-0">No review recorded yet.</p>';
  const form = ROLE === 'instructor' ? `<form id="rv-form">
      ${flagTarget ? `<div class="alert alert-warning py-1 small">Flagging event #${flagTarget} <button type="button" class="btn-close btn-sm float-end" id="rv-unflag" aria-label="Cancel flag"></button></div>` : ''}
      <label class="form-label small" for="rv-status">Review outcome</label>
      <select id="rv-status" class="form-select form-select-sm mb-2"><option value="reviewed">Reviewed</option><option value="no_concern">No concern</option><option value="requires_investigation">Requires further investigation</option><option value="pending_review">Pending review</option></select>
      <label class="form-label small" for="rv-remarks">Remarks</label>
      <textarea id="rv-remarks" class="form-control form-control-sm mb-2" rows="3" maxlength="2000"></textarea>
      <div id="rv-err" class="text-danger small mb-2" role="alert"></div>
      <button class="btn btn-primary btn-sm">Save review</button></form>` : '<p class="small text-muted">Administrators have read-only access to session reviews.</p>';
  $('review-panel').innerHTML = `<div class="card shadow-sm"><div class="card-header fw-semibold">Review</div><div class="card-body">${form}${past}</div></div>`;
  if (ROLE !== 'instructor') return;
  $('rv-unflag')?.addEventListener('click', () => { flagTarget = null; renderReview(); });
  $('rv-form').onsubmit = async (ev) => {
    ev.preventDefault();
    try {
      await CT.api(`/instructor/sessions/${SID}/review`, { method: 'POST', body: { status: $('rv-status').value, remarks: $('rv-remarks').value || null, event_id: flagTarget } });
      flagTarget = null; CT.notify('Review saved.'); load();
    } catch (e) { $('rv-err').textContent = CT.errText(e); }
  };
}

$('f-sev').onchange = renderTimeline; $('f-type').onchange = renderTimeline;
load();
setInterval(() => { if (!document.hidden && session && ['active', 'interrupted'].includes(session.status) && !document.activeElement?.closest('#rv-form')) load(); }, 15000);
</script>
@endpush
