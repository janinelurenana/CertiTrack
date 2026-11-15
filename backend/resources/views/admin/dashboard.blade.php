@extends('layouts.app')
@section('title', 'Admin - CertiTrack')
@section('content')
<div class="row g-3 mb-3" id="stat-cards"></div>
<div class="row g-3 mb-3">
  <div class="col-md-6"><div class="card shadow-sm"><div class="card-header fw-semibold">Sessions started per day (last 14 days)</div><div class="card-body"><div class="chart-box"><canvas id="c-day" role="img" aria-label="Sessions per day"></canvas></div></div></div></div>
  <div class="col-md-3"><div class="card shadow-sm"><div class="card-header fw-semibold">Events by severity</div><div class="card-body"><div class="chart-box"><canvas id="c-sev" role="img" aria-label="Events by severity"></canvas></div></div></div></div>
  <div class="col-md-3"><div class="card shadow-sm"><div class="card-header fw-semibold">Sessions by status</div><div class="card-body"><div class="chart-box"><canvas id="c-status" role="img" aria-label="Sessions by status"></canvas></div></div></div></div>
</div>
@include('partials.sessions-table')
@endsection
@push('scripts')
<script>
(async () => {
  try {
    const { data } = await CT.api('/stats');
    const st = data.sessions_by_status, ur = data.users_by_role || {};
    const card = (label, val) => `<div class="col-6 col-lg-2"><div class="card card-stat shadow-sm"><div class="card-body"><div class="text-muted small">${label}</div><div class="display-6">${val}</div></div></div></div>`;
    document.getElementById('stat-cards').innerHTML = card('Students', ur.student || 0) + card('Instructors', ur.instructor || 0) + card('Administrators', ur.admin || 0) + card('Sessions', data.sessions_total) + card('Active now', st.active || 0) + card('Events stored', data.events_total);
    CT.chart('c-day', { type: 'line', data: { labels: Object.keys(data.sessions_per_day), datasets: [{ label: 'Sessions', data: Object.values(data.sessions_per_day), borderColor: '#0d6efd', tension: .2 }] }, options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
    const sev = data.events_by_severity;
    CT.chart('c-sev', { type: 'doughnut', data: { labels: ['info', 'low', 'medium', 'high'].map(CT.pretty), datasets: [{ data: ['info', 'low', 'medium', 'high'].map((k) => sev[k] || 0), backgroundColor: ['#6c757d', '#0d6efd', '#fd7e14', '#dc3545'] }] }, options: { maintainAspectRatio: false } });
    CT.chart('c-status', { type: 'doughnut', data: { labels: Object.keys(st).map(CT.pretty), datasets: [{ data: Object.values(st), backgroundColor: CT.PALETTE }] }, options: { maintainAspectRatio: false } });
  } catch (e) { document.getElementById('stat-cards').innerHTML = CT.failed(e); }
})();
</script>
@endpush
