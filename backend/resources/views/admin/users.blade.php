@extends('layouts.app')
@section('title', 'Users - CertiTrack')
@section('content')
<div class="row g-3">
<div class="col-lg-8"><div class="card shadow-sm">
  <div class="card-header d-flex flex-wrap gap-2 justify-content-between"><span class="fw-semibold">Accounts</span>
    <div class="d-flex gap-2"><input id="u-q" class="form-control form-control-sm" placeholder="Search name/email" aria-label="Search users">
      <select id="u-role" class="form-select form-select-sm" aria-label="Filter by role"><option value="">All roles</option><option value="student">Student</option><option value="instructor">Instructor</option><option value="admin">Admin</option></select></div></div>
  <div class="card-body p-0"><div id="users" class="table-responsive"></div><div id="u-pager" class="p-2"></div></div></div></div>
<div class="col-lg-4"><div class="card shadow-sm"><div class="card-header fw-semibold" id="form-title">Add account</div><div class="card-body">
  <form id="u-form" novalidate><input type="hidden" id="f-id">
    <div class="mb-2"><label class="form-label small" for="f-name">Name</label><input id="f-name" class="form-control form-control-sm" required></div>
    <div class="mb-2"><label class="form-label small" for="f-email">Email</label><input id="f-email" type="email" class="form-control form-control-sm" required></div>
    <div class="mb-2"><label class="form-label small" for="f-role">Role</label><select id="f-role" class="form-select form-select-sm"><option value="student">Student</option><option value="instructor">Instructor</option><option value="admin">Administrator</option></select></div>
    <div class="mb-2"><label class="form-label small" for="f-sn">Student number</label><input id="f-sn" class="form-control form-control-sm"></div>
    <div class="mb-2"><label class="form-label small" for="f-pw">Password <span id="pw-hint" class="text-muted">(min. 8)</span></label><input id="f-pw" type="password" class="form-control form-control-sm" autocomplete="new-password"></div>
    <div id="f-err" class="text-danger small mb-2" role="alert"></div>
    <button class="btn btn-primary btn-sm" id="f-save">Create</button> <button type="button" class="btn btn-link btn-sm" id="f-cancel">Clear</button>
  </form></div></div></div>
</div>
@endsection
@push('scripts')
<script>
const $ = (id) => document.getElementById(id); let page = 1, timer;
async function load() {
  CT.loading($('users'));
  try {
    const r = await CT.api('/admin/users', { query: { page, q: $('u-q').value.trim(), role: $('u-role').value } });
    if (!r.data.length) { $('users').innerHTML = CT.empty('No accounts match.'); $('u-pager').innerHTML = ''; return; }
    $('users').innerHTML = `<table class="table table-hover align-middle mb-0"><caption class="visually-hidden">User accounts</caption><thead><tr><th>Name</th><th>Role</th><th>Status</th><th></th></tr></thead><tbody>` +
      r.data.map((u) => `<tr><td>${CT.esc(u.name)}<br><small class="text-muted">${CT.esc(u.email)}${u.student_number ? ' &middot; ' + CT.esc(u.student_number) : ''}</small></td><td>${CT.esc(CT.pretty(u.role))}</td>
        <td>${u.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Deactivated</span>'}</td>
        <td class="text-nowrap"><button class="btn btn-sm btn-outline-primary" data-edit='${CT.esc(JSON.stringify(u))}'>Edit</button>
        <button class="btn btn-sm btn-outline-warning" data-toggle="${u.id}" data-active="${u.is_active ? 1 : 0}">${u.is_active ? 'Deactivate' : 'Activate'}</button>
        <button class="btn btn-sm btn-outline-danger" data-del="${u.id}">Delete</button></td></tr>`).join('') + '</tbody></table>';
    $('users').querySelectorAll('[data-edit]').forEach((b) => b.onclick = () => edit(JSON.parse(b.dataset.edit)));
    $('users').querySelectorAll('[data-toggle]').forEach((b) => b.onclick = async () => { try { await CT.api('/admin/users/' + b.dataset.toggle, { method: 'PATCH', body: { is_active: b.dataset.active !== '1' } }); load(); } catch (e) { CT.notify(CT.errText(e), 'danger'); } });
    $('users').querySelectorAll('[data-del]').forEach((b) => b.onclick = async () => { if (!confirm('Delete this account permanently?')) return; try { await CT.api('/admin/users/' + b.dataset.del, { method: 'DELETE' }); CT.notify('Deleted.'); load(); } catch (e) { CT.notify(CT.errText(e), 'danger'); } });
    CT.pager($('u-pager'), r, (p) => { page = p; load(); });
  } catch (e) { $('users').innerHTML = CT.failed(e); }
}
function edit(u) { $('f-id').value = u.id; $('f-name').value = u.name; $('f-email').value = u.email; $('f-role').value = u.role; $('f-sn').value = u.student_number || ''; $('f-pw').value = ''; $('pw-hint').textContent = '(leave blank to keep)'; $('form-title').textContent = 'Edit account'; $('f-save').textContent = 'Save'; $('f-name').focus(); }
function clearForm() { $('u-form').reset(); $('f-id').value = ''; $('pw-hint').textContent = '(min. 8)'; $('form-title').textContent = 'Add account'; $('f-save').textContent = 'Create'; $('f-err').textContent = ''; }
$('f-cancel').onclick = clearForm;
$('u-form').onsubmit = async (ev) => {
  ev.preventDefault(); $('f-err').textContent = '';
  const id = $('f-id').value, body = { name: $('f-name').value, email: $('f-email').value, role: $('f-role').value, student_number: $('f-sn').value || null };
  if ($('f-pw').value) body.password = $('f-pw').value;
  try { await CT.api(id ? '/admin/users/' + id : '/admin/users', { method: id ? 'PATCH' : 'POST', body }); CT.notify(id ? 'Account updated.' : 'Account created.'); clearForm(); load(); }
  catch (e) { $('f-err').textContent = CT.errText(e); }
};
$('u-role').onchange = () => { page = 1; load(); };
$('u-q').oninput = () => { clearTimeout(timer); timer = setTimeout(() => { page = 1; load(); }, 300); };
load();
</script>
@endpush
