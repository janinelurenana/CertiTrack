<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Laravel\Sanctum\Sanctum;

class ReviewAndPlatformTest extends CertiTrackTestCase
{
    public function test_instructor_review_is_saved_audited_and_scoped(): void
    {
        $p = $this->platform(); $s = $this->user('student'); $i = $this->user('instructor'); $other = $this->user('instructor');
        $this->assign($s, $i);
        $sid = $this->startSession($s, $p);
        $this->postJson("/api/sessions/$sid/events", ['events' => [$this->ev(1)]])->assertOk();
        $eid = \App\Models\MonitoringEvent::where('seq', 1)->value('id');

        Sanctum::actingAs($other);
        $this->postJson("/api/instructor/sessions/$sid/review", ['status' => 'reviewed'])->assertNotFound();

        Sanctum::actingAs($i);
        $this->postJson("/api/instructor/sessions/$sid/review", ['status' => 'bogus'])->assertStatus(422);
        $this->postJson("/api/instructor/sessions/$sid/review", ['status' => 'requires_investigation', 'remarks' => 'Check with student', 'event_id' => $eid])->assertCreated();
        $this->assertDatabaseHas('exam_sessions', ['id' => $sid, 'review_status' => 'requires_investigation']);
        $this->assertDatabaseHas('monitoring_events', ['id' => $eid, 'flagged' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'session.reviewed', 'user_id' => $i->id]);
    }

    public function test_new_platform_added_via_config_reaches_extension_config_without_code_changes(): void
    {
        $admin = $this->user('admin');
        Sanctum::actingAs($admin);
        $pid = $this->postJson('/api/admin/platforms', ['name' => 'Acme Cert', 'slug' => 'acme-cert'])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/platforms/$pid/domains", ['host' => 'exams.acme.test', 'match_type' => 'suffix'])->assertCreated();
        Sanctum::actingAs($this->user('student'));
        $cfg = $this->getJson('/api/platforms/config')->assertOk()->json('data.platforms');
        $acme = collect($cfg)->firstWhere('slug', 'acme-cert');
        $this->assertSame('exams.acme.test', $acme['domains'][0]['host']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.created']);
    }

    public function test_admin_cannot_demote_self_and_settings_validate(): void
    {
        $admin = $this->user('admin');
        Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/users/{$admin->id}", ['role' => 'student'])->assertStatus(422);
        $this->putJson('/api/admin/settings', ['heartbeat_interval_sec' => 5])->assertStatus(422);
        $this->putJson('/api/admin/settings', ['heartbeat_interval_sec' => 45])->assertOk();
        $this->assertSame(45, \App\Models\SystemSetting::get('heartbeat_interval_sec'));
        $this->assertGreaterThan(0, AuditLog::where('action', 'settings.updated')->count());
    }
}
