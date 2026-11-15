<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'CertiTrack')</title>
  <link rel="stylesheet" href="/vendor/bootstrap.min.css">
  <link rel="stylesheet" href="/css/app.css">
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>
@php $u = auth()->user(); @endphp
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="/">CertiTrack</a>
    @if($u)
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        @if($u->role === 'student')
          <li class="nav-item"><a class="nav-link" href="/student">My monitoring</a></li>
          <li class="nav-item"><a class="nav-link" href="/student/privacy">What is collected</a></li>
        @elseif($u->role === 'instructor')
          <li class="nav-item"><a class="nav-link" href="/instructor">Sessions</a></li>
        @else
          <li class="nav-item"><a class="nav-link" href="/admin">Overview</a></li>
          <li class="nav-item"><a class="nav-link" href="/admin/users">Users</a></li>
          <li class="nav-item"><a class="nav-link" href="/admin/platforms">Platforms</a></li>
          <li class="nav-item"><a class="nav-link" href="/admin/assignments">Assignments</a></li>
          <li class="nav-item"><a class="nav-link" href="/admin/settings">Settings</a></li>
          <li class="nav-item"><a class="nav-link" href="/admin/audit">Audit log</a></li>
        @endif
      </ul>
      <span class="navbar-text me-3">{{ $u->name }} <span class="badge bg-secondary">{{ $u->role }}</span></span>
      <form method="POST" action="/logout">@csrf<button class="btn btn-outline-light btn-sm">Log out</button></form>
    </div>
    @endif
  </div>
</nav>
<main id="main" class="container-fluid py-4 px-md-4">
  <div id="toasts" class="position-fixed top-0 end-0 p-3" style="z-index:1080;max-width:420px" aria-live="polite"></div>
  @yield('content')
</main>
<script src="/vendor/bootstrap.bundle.min.js"></script>
<script src="/vendor/chart.umd.js"></script>
<script>window.CT_USER = @json($u ? ['id' => $u->id, 'name' => $u->name, 'role' => $u->role] : null);</script>
<script src="/js/app.js"></script>
@stack('scripts')
</body>
</html>
