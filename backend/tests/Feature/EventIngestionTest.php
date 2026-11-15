<?php

namespace Tests\Feature;

use App\Models\MonitoringEvent;
use App\Models\ExamSession;
use App\Services\SessionLifecycle;
use Laravel\Sanctum\Sanctum;

class EventIngestionTest extends CertiTrackTestCase
{
    public function test_requires_consent_and_single_open_session(): void
    {
        $p = $this->platform(); $s = $this->user('student');
        Sanctum::actingAs($s);
        $this->postJson('/api/sessions', ['platform_id' => $p->id])->assertStatus(422);
        $this->postJson('/api/sessions', ['platform_id' => $p->id, 'consent' => true])->assertCreated();
        $this->postJson('/api/sessions', ['platform_id' => $p->id, 'consent' => true])->assertStatus(409);
    }

    public function test_batch_is_stored_with_server_severity_and_duplicates_are_ignored(): void
    {
        $p = $this->platform(); $s = $this->user('student'); $sid = $this->startSession($s, $p);
        $batch = [$this->ev(1), $this->ev(2, 'navigation_outside_domain', ['host' => 'Example.COM', 'url' => 'https://example.com/?token=SECRET'])];
        $r = $this->postJson("/api/sessions/$sid/events", ['events' => $batch])->assertOk();
        $this->assertCount(2, $r->json('data.accepted'));
        // resend identical batch -> duplicates, nothing new stored
        $r2 = $this->postJson("/api/sessions/$sid/events", ['events' => $batch])->assertOk();
        $this->assertCount(0, $r2->json('data.accepted'));
        $this->assertCount(2, $r2->json('data.duplicates'));
        $this->assertSame(2, MonitoringEvent::where('session_id', $sid)->where('source', 'extension')->count());
        $nav = MonitoringEvent::where('type', 'navigation_outside_domain')->first();
        $this->assertSame('medium', $nav->severity);                       // decided by the server
        $this->assertSame(['host' => 'example.com'], $nav->details);        // url/query stripped
        $this->assertSame(2, ExamSession::find($sid)->event_count);
    }

    public function test_invalid_and_unknown_events_are_rejected_without_blocking_the_batch(): void
    {
        $p = $this->platform(); $s = $this->user('student'); $sid = $this->startSession($s, $p);
        $bad = ['event_uuid' => 'not-a-uuid', 'seq' => 9, 'type' => 'tab_switched', 'client_timestamp' => now()->toIso8601String()];
        $r = $this->postJson("/api/sessions/$sid/events", ['events' => [$bad, $this->ev(1, 'heartbeat_timeout'), $this->ev(2)]])->assertOk();
        $this->assertCount(1, $r->json('data.accepted'));
        $this->assertCount(2, $r->json('data.rejected'));
    }

    public function test_same_seq_with_different_uuid_is_rejected(): void
    {
        $p = $this->platform(); $s = $this->user('student'); $sid = $this->startSession($s, $p);
        $this->postJson("/api/sessions/$sid/events", ['events' => [$this->ev(1)]])->assertOk();
        $r = $this->postJson("/api/sessions/$sid/events", ['events' => [$this->ev(1)]])->assertOk();
        $this->assertSame('seq_conflict', $r->json('data.rejected.0.reason'));
    }

    public function test_events_after_end_are_kept_and_marked_late(): void
    {
        $p = $this->platform(); $s = $this->user('student'); $sid = $this->startSession($s, $p);
        $this->postJson("/api/sessions/$sid/end", ['last_seq' => 2])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson("/api/sessions/$sid/end")->assertOk(); // idempotent
        $this->postJson("/api/sessions/$sid/events", ['events' => [$this->ev(1)]])->assertOk();
        $this->assertTrue(MonitoringEvent::where('seq', 1)->first()->received_after_end);
        $this->assertSame(1, ExamSession::find($sid)->possibly_missing_events); // client said 2, server has 1
        $this->postJson("/api/sessions/$sid/heartbeat")->assertStatus(409);
    }

    public function test_heartbeat_timeout_interrupts_then_resumes(): void
    {
        $p = $this->platform(); $s = $this->user('student'); $sid = $this->startSession($s, $p);
        $this->travel(3)->minutes();
        SessionLifecycle::sweep();
        $this->assertSame('interrupted', ExamSession::find($sid)->status);
        $this->assertTrue(MonitoringEvent::where('session_id', $sid)->where('type', 'heartbeat_timeout')->where('severity', 'medium')->exists());
        $this->travel(2)->minutes();
        SessionLifecycle::sweep();
        $this->assertTrue(MonitoringEvent::where('session_id', $sid)->where('type', 'extension_disconnected')->where('severity', 'high')->exists());
        $this->postJson("/api/sessions/$sid/heartbeat")->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertTrue(MonitoringEvent::where('session_id', $sid)->where('type', 'monitoring_resumed')->exists());
        $this->travel(40)->minutes();
        SessionLifecycle::sweep();
        $this->assertSame('abandoned', ExamSession::find($sid)->status);
    }
}
