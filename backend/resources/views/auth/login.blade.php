@extends('layouts.app')
@section('title', 'Log in - CertiTrack')
@section('content')
<div class="row justify-content-center"><div class="col-md-5 col-lg-4">
  <div class="card shadow-sm"><div class="card-body">
    <h1 class="h4 mb-3">Log in to CertiTrack</h1>
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    <form method="POST" action="/login" novalidate>
      @csrf
      <div class="mb-3"><label class="form-label" for="email">Email</label>
        <input class="form-control" id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></div>
      <div class="mb-3"><label class="form-label" for="password">Password</label>
        <input class="form-control" id="password" type="password" name="password" required autocomplete="current-password"></div>
      <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="remember" id="remember"><label class="form-check-label" for="remember">Remember me</label></div>
      <button class="btn btn-primary w-100">Log in</button>
    </form>
    <hr><p class="small mb-0">New student? <a href="/register">Create an account</a>. Staff accounts are created by an administrator.</p>
  </div></div>
</div></div>
@endsection
