@extends('layouts.app')
@section('title', 'Assignments - CertiTrack')
@section('content')
<p class="text-muted small">Instructors can only see sessions of students assigned to them (optionally limited to one platform).</p>
<div class="row g-3">
<div class="col-lg-8"><div class="card shadow-sm"><div class="card-header fw-semibold">Current assignments</div><div class="card-body p-0"><div id="alist" class="table-responsive"></div></div></div></div>
<div class="col-lg-4"><div class="card shadow-sm"><div class="card-header fw-semibold">Assign student to instructor</div><div class="card-body">
  <form id="a-form" novalidate>
    <div class="mb-2"><label class="form-label small" for="a-student">Student</label><select id="a-student" class="form-select form-select-sm" required></select></div>
    <div class="mb-2"><label class="form-label small" for="a-instr">Instructor</label><select id="a-instr" class="form-select form-select-sm" required></select></div>
    <div class="mb-2"><label class="form-label small" for="a-plat">Platform</label><select id="a-plat" class="form-select form-select-sm"><option value="">All platforms</option></select></div>
    <div id="a-err" class="text-danger small mb-2" role="alert"></div><button class="btn btn-primary btn-sm">Assign</button></form></div></div></div>
</div>
@endsection
@push('scripts')
<script>
const $ = (id) => document.getElementById(id);
async function load() {
  CT.loading($('alist'));
  try {
    const { data } = await CT.api('/admin/assignments');
    $('alist').innerHTML = data.length ? `<table class="table table-hover mb-0"><caption class="visually-hidden">Assignments</caption><thead><tr><th>Student</th><th>Instructor</th><th>Platform</th><th></th></tr></thead><tbody>` +
      data.map((a) => `<tr><td>${CT.esc(a.student?.name)}</td><td>${CT.esc(a.instructor?.name)}</td><td>${CT.esc(a.platform?.name || 'All platforms')}</td><td><button class="btn btn-sm btn-outline-danger" data-del="${a.id}">Remove</button></td></tr>`).join('') + '</tbody></table>' : CT.empty('No assignments yet.');
    $('alist').querySelectorAll('[data-del]').forEach((b) => b.onclick = async () => { try { await CT.api('/admin/assignments/' + b.dataset.del, { method: 'DELETE' }); load(); } catch (e) { CT.notify(CT.errText(e), 'danger'); } });
  } catch (e) { $('alist').innerHTML = CT.failed(e); }
}
(async () => {
  try {
    const [s, i, p] = await Promise.all([CT.api('/admin/users', { query: { role: 'student', per_page: 100 } }), CT.api('/admin/users', { query: { role: 'instructor', per_page: 100 } }), CT.api('/admin/platforms')]);
    $('a-student').innerHTML = s.data.map((u) => `<option value="${u.id}">${CT.esc(u.name)}</option>`).join('');
    $('a-instr').innerHTML = i.data.map((u) => `<option value="${u.id}">${CT.esc(u.name)}</option>`).join('');
    p.data.forEach((x) => $('a-plat').insertAdjacentHTML('beforeend', `<option value="${x.id}">${CT.esc(x.name)}</option>`));
  } catch (e) { CT.notify(CT.errText(e), 'danger'); }
})();
$('a-form').onsubmit = async (ev) => {
  ev.preventDefault(); $('a-err').textContent = '';
  try { await CT.api('/admin/assignments', { method: 'POST', body: { student_id: +$('a-student').value, instructor_id: +$('a-instr').value, platform_id: $('a-plat').value ? +$('a-plat').value : null } }); CT.notify('Assigned.'); load(); }
  catch (e) { $('a-err').textContent = CT.errText(e); }
};
load();
</script>
@endpush
