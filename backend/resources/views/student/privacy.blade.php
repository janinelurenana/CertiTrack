@extends('layouts.app')
@section('title', 'What is collected - CertiTrack')
@section('content')
<div class="row justify-content-center"><div class="col-lg-9">
<h1 class="h3">What CertiTrack collects, and what it does not</h1>
<p class="text-muted">Consent version <span class="mono">{{ config('certitrack.consent_version') }}</span>. You give consent each time you press Start monitoring in the extension.</p>

<div class="card mb-3"><div class="card-header fw-semibold">When monitoring runs</div><div class="card-body">
<p class="mb-0">Only between pressing <strong>Start monitoring</strong> and <strong>End monitoring</strong> in the extension. The extension shows an <span class="badge bg-danger">ON</span> badge and a status panel while active. Outside a session nothing is recorded.</p></div></div>

<div class="card mb-3"><div class="card-header fw-semibold">Collected during a session</div><div class="card-body">
<ul class="mb-0">
<li>You switched to another tab, or back to the examination tab (no information about the other tab).</li>
<li>A new browser tab was opened (no address recorded).</li>
<li>The examination tab was closed.</li>
<li>The examination tab navigated to a website outside the platform's approved domains (<strong>only the website's host name</strong>, e.g. <span class="mono">example.com</span>; never the full address, search terms, or query parameters).</li>
<li>The browser window lost or regained focus.</li>
<li>The extension lost or regained its connection to CertiTrack, plus periodic "still running" heartbeats.</li>
<li>Time stamps for each of the above, your account, the platform, and when the session started and ended.</li>
</ul></div></div>

<div class="card mb-3"><div class="card-header fw-semibold text-danger">Never collected</div><div class="card-body">
<ul class="mb-0"><li>Webcam or microphone.</li><li>Screenshots or screen recordings.</li><li>Keystrokes or clipboard contents.</li><li>Passwords, authentication tokens, or examination answers.</li><li>Page content, page titles, or your general browsing history.</li><li>Activity in other browsers, applications, or devices.</li></ul></div></div>

<div class="card mb-3"><div class="card-header fw-semibold">How the information is used</div><div class="card-body">
<ul class="mb-0">
<li>Your assigned instructor (and administrators) can view session timelines and add review remarks. Access to your records is logged.</li>
<li>Events show <strong>technical activity only</strong>. A tab switch or lost focus can have innocent causes and is <strong>not proof of misconduct</strong>. Instructors make the judgement, not the software.</li>
<li>You can view all of your own sessions and events under <a href="/student">My monitoring</a>, and revoke a paired extension at any time.</li>
<li>Records are deleted automatically after the retention period configured by your institution (currently set by the administrator; default 180 days).</li>
</ul></div></div>

<div class="card mb-3"><div class="card-header fw-semibold">Limitations you should know about</div><div class="card-body">
<ul class="mb-0"><li>The extension can only observe Chrome on the computer where it is installed.</li><li>Disabling or uninstalling the extension stops monitoring; the server will record that heartbeats stopped.</li><li>Records generated in the browser are not tamper-proof, and CertiTrack cannot prevent or prove cheating.</li></ul></div></div>

<p class="small text-muted">Your data is processed with reference to the Philippine Data Privacy Act of 2012 (RA 10173) and your institution's privacy policy. Contact your institution's Data Protection Officer to exercise access, correction, or deletion rights.</p>
</div></div>
@endsection
