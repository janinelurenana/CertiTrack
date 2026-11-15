@extends('layouts.app')
@section('title', 'Audit log - CertiTrack')
@section('content')
<div class="card shadow-sm"><div class="card-header d-flex flex-wrap gap-2 justify-content-between"><span class="fw-semibold">Audit log</span>
  <input id="a-filter" class="form-control form-control-sm w-auto" placeholder="Action prefix, e.g. session." aria-label="Filter by action prefix"></div>
  <div class="card-body p-0"><div id="log" class="table-responsive"></div><div id="pg" class="p-2"></div></div></div>
@endsection
@push('scripts')
<script>
const $ = (id) => document.getElementById(id); let page = 1, t;
async function load() {
  CT.loading($('log'));
  try {
    const r = await CT.api('/admin/audit-logs', { query: { page, action: $('a-filter').value.trim() } });
    $('log').innerHTML = r.data.length ? `<table class="table table-sm mb-0"><caption class="visually-hidden">Audit log</caption><thead><tr><th>When</th><th>User</th><th>Action</th><th>Subject</th><th>Details</th><th>IP</th></tr></thead><tbody>` +
      r.data.map((l) => `<tr><td class="text-nowrap">${CT.fmt(l.created_at)}</td><td>${CT.esc(l.user?.name || 'system')}</td><td class="mono">${CT.esc(l.action)}</td><td>${CT.esc(l.subject_type || '')} ${l.subject_id ? '#' + l.subject_id : ''}</td><td class="small text-muted">${CT.esc(l.metadata ? JSON.stringify(l.metadata) : '')}</td><td>${CT.esc(l.ip_address || '')}</td></tr>`).join('') + '</tbody></table>' : CT.empty('No audit entries.');
    CT.pager($('pg'), r, (p) => { page = p; load(); });
  } catch (e) { $('log').innerHTML = CT.failed(e); }
}
$('a-filter').oninput = () => { clearTimeout(t); t = setTimeout(() => { page = 1; load(); }, 300); };
load();
</script>
@endpush
