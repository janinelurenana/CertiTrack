@extends('layouts.app')
@section('title', 'Sessions - CertiTrack')
@section('content')
<div class="row g-3 mb-3" id="stat-cards"></div>
<div class="row g-3 mb-3">
  <div class="col-md-6"><div class="card shadow-sm"><div class="card-header fw-semibold">Sessions by status</div><div class="card-body"><div class="chart-box"><canvas id="c-status" role="img" aria-label="Sessions by status"></canvas></div></div></div></div>
  <div class="col-md-6"><div class="card shadow-sm"><div class="card-header fw-semibold">Events by type</div><div class="card-body"><div class="chart-box"><canvas id="c-types" role="img" aria-label="Events by type"></canvas></div></div></div></div>
</div>
@include('partials.sessions-table')
@endsection
@push('scripts')
<script>
(async () => {
  try {
    const { data } = await CT.api('/stats');
    const st = data.sessions_by_status;
    const card = (label, val) => `<div class="col-6 col-lg-3"><div class="card card-stat shadow-sm"><div class="card-body"><div class="text-muted small">${label}</div><div class="display-6">${val}</div></div></div></div>`;
    document.getElementById('stat-cards').innerHTML = card('Active sessions', st.active || 0) + card('Interrupted', st.interrupted || 0) + card('Awaiting review', data.pending_review) + card('Total sessions', data.sessions_total);
    CT.chart('c-status', { type: 'doughnut', data: { labels: Object.keys(st).map(CT.pretty), datasets: [{ data: Object.values(st), backgroundColor: CT.PALETTE }] }, options: { maintainAspectRatio: false } });
    CT.chart('c-types', { type: 'bar', data: { labels: Object.keys(data.events_by_type).map(CT.pretty), datasets: [{ label: 'Events', data: Object.values(data.events_by_type), backgroundColor: CT.PALETTE }] }, options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
  } catch (e) { document.getElementById('stat-cards').innerHTML = CT.failed(e); }
})();
</script>
@endpush
