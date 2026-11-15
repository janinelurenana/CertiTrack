@extends('layouts.app')
@section('title', 'Settings - CertiTrack')
@section('content')
<div class="row g-3"><div class="col-lg-7"><div class="card shadow-sm"><div class="card-header fw-semibold">Monitoring rules and system settings</div><div class="card-body">
<form id="s-form" novalidate>
  <div class="row g-2">
    <div class="col-md-6"><label class="form-label small" for="heartbeat_interval_sec">Heartbeat interval (s, 30-300)</label><input type="number" class="form-control form-control-sm" id="heartbeat_interval_sec" min="30" max="300"></div>
    <div class="col-md-6"><label class="form-label small" for="heartbeat_timeout_sec">Heartbeat timeout &rarr; "Interrupted" (s)</label><input type="number" class="form-control form-control-sm" id="heartbeat_timeout_sec" min="60" max="1800"></div>
    <div class="col-md-6"><label class="form-label small" for="disconnect_after_sec">"Extension disconnected" after silence (s)</label><input type="number" class="form-control form-control-sm" id="disconnect_after_sec" min="90" max="3600"></div>
    <div class="col-md-6"><label class="form-label small" for="abandon_after_sec">"Abandoned" after silence (s)</label><input type="number" class="form-control form-control-sm" id="abandon_after_sec" min="300" max="86400"></div>
    <div class="col-md-6"><label class="form-label small" for="retention_days">Data retention (days)</label><input type="number" class="form-control form-control-sm" id="retention_days" min="1" max="3650"></div>
    <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input type="checkbox" class="form-check-input" id="require_assignment"><label class="form-check-label small" for="require_assignment">Students must be assigned to an instructor before starting a session</label></div></div>
  </div>
  <fieldset class="mt-3"><legend class="h6">Event types the extension may report</legend><div id="events" class="small"></div></fieldset>
  <div id="s-err" class="text-danger small my-2" role="alert"></div>
  <button class="btn btn-primary btn-sm">Save settings</button>
</form></div></div></div>
<div class="col-lg-5"><div class="card shadow-sm"><div class="card-header fw-semibold">Data retention</div><div class="card-body">
  <p class="small">Closed sessions older than the retention period are deleted daily by <span class="mono">certitrack:purge</span> (requires the scheduler). You can also run it now.</p>
  <div class="input-group input-group-sm mb-2"><span class="input-group-text">Older than (days)</span><input type="number" id="purge-days" class="form-control" min="1" placeholder="use setting"><button class="btn btn-outline-danger" id="btn-purge">Purge now</button></div></div></div></div></div>
@endsection
@push('scripts')
<script>
const $ = (id) => document.getElementById(id); const NUM = ['heartbeat_interval_sec', 'heartbeat_timeout_sec', 'disconnect_after_sec', 'abandon_after_sec', 'retention_days'];
(async () => {
  try {
    const r = await CT.api('/admin/settings'); const d = r.data;
    NUM.forEach((k) => $(k).value = d[k]); $('require_assignment').checked = !!d.require_assignment;
    $('events').innerHTML = Object.entries(r.meta.event_types).filter(([, v]) => v.source === 'extension').map(([k, v]) => `<div class="form-check"><input class="form-check-input ev" type="checkbox" value="${k}" id="ev-${k}" ${d.enabled_events.includes(k) ? 'checked' : ''}><label class="form-check-label" for="ev-${k}">${CT.esc(v.label)} <span class="text-muted">(${v.severity})</span></label></div>`).join('');
  } catch (e) { $('s-err').textContent = CT.errText(e); }
})();
$('s-form').onsubmit = async (ev) => {
  ev.preventDefault(); $('s-err').textContent = '';
  const body = { require_assignment: $('require_assignment').checked, enabled_events: [...document.querySelectorAll('.ev:checked')].map((c) => c.value) };
  NUM.forEach((k) => body[k] = +$(k).value);
  try { await CT.api('/admin/settings', { method: 'PUT', body }); CT.notify('Settings saved. Extensions pick them up the next time they fetch configuration.'); } catch (e) { $('s-err').textContent = CT.errText(e); }
};
$('btn-purge').onclick = async () => {
  if (!confirm('Permanently delete closed sessions older than the retention period?')) return;
  try { const r = await CT.api('/admin/retention/purge', { method: 'POST', body: { days: $('purge-days').value ? +$('purge-days').value : null } }); CT.notify(`Deleted ${r.data.deleted_sessions} session(s) older than ${r.data.days} days.`); } catch (e) { CT.notify(CT.errText(e), 'danger'); }
};
</script>
@endpush
