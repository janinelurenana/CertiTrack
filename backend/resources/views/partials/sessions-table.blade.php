{{-- Shared by instructor and admin dashboards. Data: GET /api/instructor/sessions (visibility enforced server-side). --}}
<div class="card shadow-sm">
  <div class="card-header fw-semibold">Sessions</div>
  <div class="card-body border-bottom">
    <form id="sf" class="row g-2 align-items-end" aria-label="Filter sessions">
      <div class="col-6 col-md-3 col-lg-2"><label class="form-label small mb-0" for="sf-q">Search student</label><input id="sf-q" class="form-control form-control-sm" placeholder="Name or email"></div>
      <div class="col-6 col-md-3 col-lg-2"><label class="form-label small mb-0" for="sf-student">Student</label><select id="sf-student" class="form-select form-select-sm"><option value="">All</option></select></div>
      <div class="col-6 col-md-3 col-lg-2"><label class="form-label small mb-0" for="sf-platform">Platform</label><select id="sf-platform" class="form-select form-select-sm"><option value="">All</option></select></div>
      <div class="col-6 col-md-3 col-lg-2"><label class="form-label small mb-0" for="sf-status">Status</label><select id="sf-status" class="form-select form-select-sm"><option value="">All</option>
        @foreach(['active','interrupted','completed','abandoned'] as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach</select></div>
      <div class="col-6 col-md-3 col-lg-2"><label class="form-label small mb-0" for="sf-review">Review</label><select id="sf-review" class="form-select form-select-sm"><option value="">All</option>
        <option value="pending_review">Pending review</option><option value="reviewed">Reviewed</option><option value="no_concern">No concern</option><option value="requires_investigation">Requires further investigation</option></select></div>
      <div class="col-6 col-md-3 col-lg-1"><label class="form-label small mb-0" for="sf-from">From</label><input id="sf-from" type="date" class="form-control form-control-sm"></div>
      <div class="col-6 col-md-3 col-lg-1"><label class="form-label small mb-0" for="sf-to">To</label><input id="sf-to" type="date" class="form-control form-control-sm"></div>
      <div class="col-12 d-flex gap-2 align-items-center">
        <button class="btn btn-primary btn-sm" type="submit">Apply</button>
        <button class="btn btn-outline-secondary btn-sm" type="button" id="sf-reset">Reset</button>
        <button class="btn btn-outline-secondary btn-sm" type="button" id="sf-refresh">Refresh</button>
        <div class="form-check ms-2"><input class="form-check-input" type="checkbox" id="sf-auto" checked><label class="form-check-label small" for="sf-auto">Auto-refresh (15 s)</label></div>
      </div>
    </form>
  </div>
  <div class="card-body p-0"><div id="sessions-table" class="table-responsive"></div><div id="sessions-pager" class="p-2"></div></div>
  <div class="card-footer small text-muted">"Review priority" is a weighted count of recorded events used only to sort sessions for manual review. It is not a measure of misconduct.</div>
</div>
<script>
(() => {
  const $ = (id) => document.getElementById(id);
  let page = 1, sort = 'started_at', dir = 'desc';
  const q = () => ({ page, sort, dir, q: $('sf-q').value.trim(), student_id: $('sf-student').value, platform_id: $('sf-platform').value, status: $('sf-status').value, review_status: $('sf-review').value, from: $('sf-from').value, to: $('sf-to').value });

  async function load(quiet) {
    if (!quiet) CT.loading($('sessions-table'));
    try {
      const r = await CT.api('/instructor/sessions', { query: q() });
      if (!r.data.length) { $('sessions-table').innerHTML = CT.empty('No sessions match these filters.'); $('sessions-pager').innerHTML = ''; return; }
      const th = (k, label) => `<th data-sort="${k}" scope="col" aria-sort="${sort === k ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'}">${label}${sort === k ? (dir === 'asc' ? ' \u25B2' : ' \u25BC') : ''}</th>`;
      $('sessions-table').innerHTML = `<table class="table table-hover align-middle mb-0"><caption class="visually-hidden">Monitoring sessions</caption><thead><tr>
        ${th('student', 'Student')}<th scope="col">Platform</th>${th('started_at', 'Started')}${th('status', 'Status')}<th scope="col">Duration</th>${th('event_count', 'Events')}${th('review_score', 'Review priority')}<th scope="col">Review</th><th></th></tr></thead><tbody>` +
        r.data.map((s) => `<tr><td>${CT.esc(s.student?.name)}<br><small class="text-muted">${CT.esc(s.student?.email)}</small></td><td>${CT.esc(s.platform?.name)}</td>
          <td>${CT.fmt(s.started_at)}</td><td>${CT.statusBadge(s.status)}</td><td>${CT.dur(s.duration_seconds)}</td>
          <td>${s.event_count}${s.possibly_missing_events ? ` <span class="badge bg-warning text-dark" title="Extension reports ${s.possibly_missing_events} event(s) the server did not receive">${s.possibly_missing_events} missing?</span>` : ''}</td>
          <td>${s.review_score}</td><td>${CT.reviewBadge(s.review_status)}</td><td><a class="btn btn-sm btn-outline-primary" href="/sessions/${s.id}">Open</a></td></tr>`).join('') + '</tbody></table>';
      $('sessions-table').querySelectorAll('th[data-sort]').forEach((h) => h.onclick = () => { dir = sort === h.dataset.sort && dir === 'desc' ? 'asc' : 'desc'; sort = h.dataset.sort; page = 1; load(); });
      CT.pager($('sessions-pager'), r, (p) => { page = p; load(); });
    } catch (e) { $('sessions-table').innerHTML = CT.failed(e); }
  }

  async function init() {
    try {
      const [st, pl] = await Promise.all([CT.api('/instructor/students'), CT.api('/platforms')]);
      st.data.forEach((s) => $('sf-student').insertAdjacentHTML('beforeend', `<option value="${s.id}">${CT.esc(s.name)}</option>`));
      pl.data.forEach((p) => $('sf-platform').insertAdjacentHTML('beforeend', `<option value="${p.id}">${CT.esc(p.name)}</option>`));
    } catch (e) { CT.notify(CT.errText(e), 'danger'); }
    load();
  }
  $('sf').onsubmit = (e) => { e.preventDefault(); page = 1; load(); };
  $('sf-reset').onclick = () => { $('sf').reset(); $('sf-auto').checked = true; page = 1; load(); };
  $('sf-refresh').onclick = () => load();
  setInterval(() => { if ($('sf-auto').checked && !document.hidden) load(true); }, 15000);
  window.CTSessionsReload = load;
  init();
})();
</script>
