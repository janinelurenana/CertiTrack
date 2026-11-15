/* CertiTrack dashboard helpers. Every number/table shown comes from /api/* (no hard-coded data). */
const CT = (() => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

  async function api(path, { method = 'GET', body, query } = {}) {
    let url = '/api' + path;
    if (query) {
      const p = new URLSearchParams();
      Object.entries(query).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') p.set(k, v); });
      const qs = p.toString(); if (qs) url += '?' + qs;
    }
    const res = await fetch(url, {
      method, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
    let data = null; try { data = await res.json(); } catch (e) { /* empty body */ }
    if (!res.ok) {
      const err = new Error(data?.message || res.statusText || 'Request failed');
      err.status = res.status; err.errors = data?.errors; err.data = data;
      if (res.status === 401 || res.status === 419) { location.href = '/login'; }
      throw err;
    }
    return data;
  }

  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // Server stores UTC; show in the viewer's local timezone.
  const fmt = (iso) => iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'medium' }) : '\u2014';
  const dur = (s) => { s = Math.max(0, Math.round(s || 0)); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60; return (h ? h + 'h ' : '') + (h || m ? m + 'm ' : '') + x + 's'; };
  const pretty = (s) => String(s || '').replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

  const SEV = { info: 'secondary', low: 'primary', medium: 'warning text-dark', high: 'danger' };
  const STATUS = { scheduled: 'secondary', active: 'success', interrupted: 'warning text-dark', completed: 'primary', abandoned: 'dark' };
  const REVIEW = { pending_review: 'secondary', reviewed: 'info text-dark', no_concern: 'success', requires_investigation: 'danger' };
  const sevBadge = (s) => `<span class="badge bg-${SEV[s] || 'secondary'}">${esc(pretty(s))}</span>`;
  const statusBadge = (s) => `<span class="badge bg-${STATUS[s] || 'secondary'}">${esc(pretty(s))}</span>`;
  const reviewBadge = (s) => `<span class="badge bg-${REVIEW[s] || 'secondary'}">${esc(pretty(s))}</span>`;

  function errText(e) {
    if (e.errors) return Object.values(e.errors).flat().join(' ');
    return e.message || 'Something went wrong.';
  }
  function notify(msg, type = 'success') {
    const box = document.getElementById('toasts'); if (!box) return;
    const d = document.createElement('div');
    d.className = `alert alert-${type} alert-dismissible shadow-sm`; d.setAttribute('role', type === 'danger' ? 'alert' : 'status');
    d.innerHTML = `${esc(msg)}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
    box.appendChild(d); setTimeout(() => d.remove(), 6000);
  }
  const loading = (el) => { el.innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm" role="status"></div> Loading\u2026</div>'; };
  const empty = (msg) => `<div class="empty-state">${esc(msg)}</div>`;
  const failed = (e) => `<div class="alert alert-danger" role="alert">${esc(errText(e))}</div>`;

  const charts = {};
  function chart(id, cfg) {
    const el = document.getElementById(id); if (!el) return;
    if (charts[id]) charts[id].destroy();
    charts[id] = new Chart(el, cfg);
  }
  const PALETTE = ['#0d6efd', '#198754', '#fd7e14', '#6f42c1', '#dc3545', '#20c997', '#6c757d', '#ffc107', '#0dcaf0', '#d63384'];
  function pager(el, meta, go) {
    if (!meta || meta.last_page <= 1) { el.innerHTML = ''; return; }
    el.innerHTML = `<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">
      <li class="page-item ${meta.current_page <= 1 ? 'disabled' : ''}"><button class="page-link" data-p="${meta.current_page - 1}">Previous</button></li>
      <li class="page-item disabled"><span class="page-link">Page ${meta.current_page} of ${meta.last_page} (${meta.total} total)</span></li>
      <li class="page-item ${meta.current_page >= meta.last_page ? 'disabled' : ''}"><button class="page-link" data-p="${meta.current_page + 1}">Next</button></li></ul></nav>`;
    el.querySelectorAll('button[data-p]').forEach((b) => b.addEventListener('click', () => go(+b.dataset.p)));
  }
  function detailText(d) {
    if (!d) return '';
    return Object.entries(d).map(([k, v]) => `${k.replace(/_/g, ' ')}: ${v === true ? 'yes' : v === false ? 'no' : v}`).join(' \u00b7 ');
  }
  return { api, esc, fmt, dur, pretty, sevBadge, statusBadge, reviewBadge, errText, notify, loading, empty, failed, chart, PALETTE, pager, detailText };
})();
