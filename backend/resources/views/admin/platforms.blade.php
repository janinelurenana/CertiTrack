@extends('layouts.app')
@section('title', 'Platforms - CertiTrack')
@section('content')
<div class="alert alert-info small" role="note">Adding a platform here is all that is needed to support it: the extension downloads these domain rules from <span class="mono">/api/platforms/config</span>. Include every host the platform uses, including single-sign-on hosts, otherwise normal sign-in redirects will be reported as "navigation outside approved domains". Query strings are never stored.</div>
<div class="row g-3">
<div class="col-lg-8" id="plist"></div>
<div class="col-lg-4"><div class="card shadow-sm"><div class="card-header fw-semibold">Add platform</div><div class="card-body">
  <form id="p-form" novalidate>
    <div class="mb-2"><label class="form-label small" for="p-name">Name</label><input id="p-name" class="form-control form-control-sm" required></div>
    <div class="mb-2"><label class="form-label small" for="p-slug">Slug (a-z, 0-9, -)</label><input id="p-slug" class="form-control form-control-sm" required pattern="[a-z0-9\-]+"></div>
    <div class="mb-2"><label class="form-label small" for="p-desc">Description</label><input id="p-desc" class="form-control form-control-sm"></div>
    <div id="p-err" class="text-danger small mb-2" role="alert"></div>
    <button class="btn btn-primary btn-sm">Add platform</button></form></div></div></div>
</div>
@endsection
@push('scripts')
<script>
const $ = (id) => document.getElementById(id);
async function load() {
  CT.loading($('plist'));
  try {
    const { data } = await CT.api('/admin/platforms');
    $('plist').innerHTML = data.length ? data.map((p) => `<div class="card shadow-sm mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><strong>${CT.esc(p.name)}</strong> <span class="text-muted mono small">${CT.esc(p.slug)}</span> ${p.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</span>
      <span><button class="btn btn-sm btn-outline-warning" data-tp="${p.id}" data-a="${p.is_active ? 1 : 0}">${p.is_active ? 'Deactivate' : 'Activate'}</button> <button class="btn btn-sm btn-outline-danger" data-dp="${p.id}">Delete</button></span></div>
      <div class="card-body"><p class="small text-muted">${CT.esc(p.description || '')}</p>
      <table class="table table-sm"><caption class="visually-hidden">Approved domains for ${CT.esc(p.name)}</caption><thead><tr><th>Host</th><th>Match</th><th>Path prefix</th><th>Active</th><th></th></tr></thead><tbody>
      ${p.domains.map((d) => `<tr><td class="mono">${CT.esc(d.host)}</td><td>${d.match_type === 'suffix' ? 'host + subdomains' : 'exact host'}</td><td class="mono">${CT.esc(d.path_prefix || '\u2014')}</td>
        <td><input type="checkbox" class="form-check-input" data-td="${d.id}" ${d.is_active ? 'checked' : ''} aria-label="Domain active"></td><td><button class="btn btn-sm btn-link text-danger" data-dd="${d.id}">Remove</button></td></tr>`).join('') || '<tr><td colspan="5" class="text-muted">No domains yet: the extension cannot start a session without at least one.</td></tr>'}</tbody></table>
      <form class="row g-2 d-form" data-pid="${p.id}"><div class="col-5"><input class="form-control form-control-sm" name="host" placeholder="example.com" aria-label="Host" required></div>
        <div class="col-3"><select class="form-select form-select-sm" name="match_type" aria-label="Match type"><option value="suffix">host + subdomains</option><option value="exact">exact host</option></select></div>
        <div class="col-2"><input class="form-control form-control-sm" name="path_prefix" placeholder="/path" aria-label="Path prefix"></div><div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">Add</button></div></form></div></div>`).join('') : CT.empty('No platforms configured.');
    const run = async (fn) => { try { await fn(); load(); } catch (e) { CT.notify(CT.errText(e), 'danger'); } };
    $('plist').querySelectorAll('[data-tp]').forEach((b) => b.onclick = () => run(() => CT.api('/admin/platforms/' + b.dataset.tp, { method: 'PATCH', body: { is_active: b.dataset.a !== '1' } })));
    $('plist').querySelectorAll('[data-dp]').forEach((b) => b.onclick = () => confirm('Delete this platform and its domain rules?') && run(() => CT.api('/admin/platforms/' + b.dataset.dp, { method: 'DELETE' })));
    $('plist').querySelectorAll('[data-td]').forEach((c) => c.onchange = () => run(() => CT.api('/admin/domains/' + c.dataset.td, { method: 'PATCH', body: { is_active: c.checked } })));
    $('plist').querySelectorAll('[data-dd]').forEach((b) => b.onclick = () => run(() => CT.api('/admin/domains/' + b.dataset.dd, { method: 'DELETE' })));
    $('plist').querySelectorAll('.d-form').forEach((f) => f.onsubmit = (ev) => { ev.preventDefault(); run(() => CT.api(`/admin/platforms/${f.dataset.pid}/domains`, { method: 'POST', body: { host: f.host.value.trim().toLowerCase(), match_type: f.match_type.value, path_prefix: f.path_prefix.value.trim() || null } })); });
  } catch (e) { $('plist').innerHTML = CT.failed(e); }
}
$('p-form').onsubmit = async (ev) => {
  ev.preventDefault(); $('p-err').textContent = '';
  try { await CT.api('/admin/platforms', { method: 'POST', body: { name: $('p-name').value, slug: $('p-slug').value, description: $('p-desc').value || null } }); $('p-form').reset(); CT.notify('Platform added. Now add its domains.'); load(); }
  catch (e) { $('p-err').textContent = CT.errText(e); }
};
load();
</script>
@endpush
