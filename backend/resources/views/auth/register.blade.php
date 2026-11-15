@extends('layouts.app')
@section('title', 'Register - CertiTrack')
@section('content')
<div class="row justify-content-center"><div class="col-md-6 col-lg-5">
  <div class="card shadow-sm"><div class="card-body">
    <h1 class="h4 mb-3">Student registration</h1>
    @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="/register" novalidate>
      @csrf
      <div class="mb-3"><label class="form-label" for="name">Full name</label><input class="form-control" id="name" name="name" value="{{ old('name') }}" required></div>
      <div class="mb-3"><label class="form-label" for="email">Email</label><input class="form-control" id="email" type="email" name="email" value="{{ old('email') }}" required></div>
      <div class="mb-3"><label class="form-label" for="sn">Student number (optional)</label><input class="form-control" id="sn" name="student_number" value="{{ old('student_number') }}"></div>
      <div class="mb-3"><label class="form-label" for="password">Password (min. 8 characters)</label><input class="form-control" id="password" type="password" name="password" required autocomplete="new-password"></div>
      <div class="mb-3"><label class="form-label" for="pc">Confirm password</label><input class="form-control" id="pc" type="password" name="password_confirmation" required autocomplete="new-password"></div>
      <p class="small text-muted">Monitoring never starts at registration. You will be asked for explicit consent each time you start a session. <a href="/login">Back to login</a></p>
      <button class="btn btn-primary w-100">Create account</button>
    </form>
  </div></div>
</div></div>
@endsection
