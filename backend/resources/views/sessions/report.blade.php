@extends('layouts.app')
@section('title', 'Session report #' . $id . ' - CertiTrack')
@section('content')
<div class="d-flex gap-2 mb-3 no-print">
  <a href="/sessions/{{ (int) $id }}" class="btn btn-outline-secondary btn-sm">&larr; Back to session</a>
  <button class="btn btn-primary btn-sm" onclick="window.print()">Print / save as PDF</button>
  <button class="btn btn-outline-primary btn-sm" id="btn-csv">Download events (CSV)</button>
</div>
<div id="report"><div class="text-muted">Generating report&hellip;</div></div>
@endsection
@push('scripts')
<script>
const SID = {{ (int) $id }}; let R = null;
(async () => {
  const box = document.getElementById('report');
  try {
    R = (await CT.api(`/instructor/sessions/${SID}/report`)).data;
    const s = R.session, sum = R.summary;
    const kv = (o) => Object.keys(o).length ? '<ul class="mb-0">' + Object.entries(o).map(([k, v]) => `<li>${CT.esc(CT.pretty(k))}: <strong>${v}</strong></li>`).join('') + '</ul>' : '<span class="text-muted">None</span>';
    box.innerHTML = `<div class="card"><div class="card-body">
      <h1 class="h4">CertiTrack session report #${s.id}</h1>
      <p class="small text-muted">Generated ${CT.fmt(R.generated_at)} by ${CT.esc(R.generated_by.name)}. Times are shown in the viewer's local timezone (stored in UTC).</p>
      <div class="alert alert-secondary small">${CT.esc(R.disclaimer)}</div>
      <dl class="row small"><dt class="col-sm-3">Student</dt><dd class="col-sm-9">${CT.esc(s.student?.name)} (${CT.esc(s.student?.email)})</dd>
        <dt class="col-sm-3">Platform</dt><dd class="col-sm-9">${CT.esc(s.platform?.name)}</dd>
        <dt class="col-sm-3">Status</dt><dd class="col-sm-9">${CT.statusBadge(s.status)} &middot; review: ${CT.reviewBadge(s.review_status)}</dd>
        <dt class="col-sm-3">Started / ended</dt><dd class="col-sm-9">${CT.fmt(s.started_at)} &rarr; ${CT.fmt(s.ended_at)} (${CT.dur(s.duration_seconds)})</dd>
        <dt class="col-sm-3">Events received</dt><dd class="col-sm-9">${s.event_count} extension events${s.possibly_missing_events ? `; <strong>extension reports ${s.possibly_missing_events} more that never reached the server</strong>` : ''}; ${sum.late_events} synced after the session ended; ${sum.flagged} flagged</dd></dl>
      <div class="row"><div class="col-md-6"><h2 class="h6">By severity</h2>${kv(sum.by_severity)}</div><div class="col-md-6"><h2 class="h6">By type</h2>${kv(sum.by_type)}</div></div>
      <h2 class="h6 mt-4">Timeline</h2>
      ${R.events.length ? `<table class="table table-sm"><thead><tr><th>Time</th><th>Event</th><th>Severity</th><th>Details</th></tr></thead><tbody>${R.events.map((e) => `<tr><td class="text-nowrap">${CT.fmt(e.occurred_at)}</td><td>${CT.esc(e.label)}${e.flagged ? ' (flagged)' : ''}${e.received_after_end ? ' (late sync)' : ''}</td><td>${CT.esc(CT.pretty(e.severity))}</td><td>${CT.esc(CT.detailText(e.details))}</td></tr>`).join('')}</tbody></table>` : '<p class="text-muted">No events recorded.</p>'}
      <h2 class="h6 mt-4">Instructor reviews</h2>
      ${R.reviews.length ? R.reviews.map((r) => `<p class="small mb-2">${CT.reviewBadge(r.status)} <strong>${CT.esc(r.reviewer?.name)}</strong>, ${CT.fmt(r.created_at)}${r.event_id ? ' (event #' + r.event_id + ')' : ''}<br>${CT.esc(r.remarks || '')}</p>`).join('') : '<p class="text-muted small">No review recorded.</p>'}
    </div></div>`;
  } catch (e) { box.innerHTML = CT.failed(e); }
})();
document.getElementById('btn-csv').onclick = () => {
  if (!R) return;
  const q = (v) => '"' + String(v ?? '').replace(/"/g, '""') + '"';
  const csv = ['occurred_at_utc,received_at_utc,type,severity,source,flagged,late_sync,details'].concat(R.events.map((e) => [e.occurred_at, e.received_at, e.type, e.severity, e.source, e.flagged, e.received_after_end, JSON.stringify(e.details || {})].map(q).join(','))).join('\n');
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = `certitrack-session-${SID}-events.csv`; a.click();
};
</script>
@endpush
