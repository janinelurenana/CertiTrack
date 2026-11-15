<?php

namespace Tests\Feature;

use Laravel\Sanctum\Sanctum;

class AuthAndRbacTest extends CertiTrackTestCase
{
    public function test_login_returns_token_and_rejects_bad_password(): void
    {
        $u = $this->user('student', 'a@t.test');
        $this->postJson('/api/login', ['email' => 'a@t.test', 'password' => 'Password123!'])->assertOk()->assertJsonStructure(['data' => ['token', 'user' => ['role']]]);
        $this->postJson('/api/login', ['email' => 'a@t.test', 'password' => 'nope'])->assertStatus(401);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/platforms')->assertStatus(401);
        $this->postJson('/api/sessions', [])->assertStatus(401);
    }

    public function test_role_gates(): void
    {
        $student = $this->user('student');
        Sanctum::actingAs($student);
        $this->getJson('/api/instructor/sessions')->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
        Sanctum::actingAs($this->user('instructor'));
        $this->postJson('/api/sessions', [])->assertForbidden();
        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_pairing_code_is_single_use_and_yields_working_token(): void
    {
        $student = $this->user('student');
        Sanctum::actingAs($student);
        $code = $this->postJson('/api/pairing-codes')->assertCreated()->json('data.code');
        $this->app['auth']->forgetGuards();
        $res = $this->postJson('/api/extension/pair', ['code' => $code, 'device_name' => 'test'])->assertCreated();
        $this->assertNotEmpty($res->json('data.token'));
        $this->postJson('/api/extension/pair', ['code' => $code])->assertStatus(422);
    }

    public function test_student_cannot_read_another_students_session(): void
    {
        $p = $this->platform(); $a = $this->user('student'); $b = $this->user('student');
        $sid = $this->startSession($a, $p);
        Sanctum::actingAs($b);
        $this->getJson("/api/sessions/$sid")->assertNotFound();
        $this->getJson("/api/sessions/$sid/events")->assertNotFound();
        $this->postJson("/api/sessions/$sid/events", ['events' => [$this->ev(1)]])->assertNotFound();
        $this->postJson("/api/sessions/$sid/end")->assertNotFound();
    }

    public function test_instructor_only_sees_assigned_students(): void
    {
        $p = $this->platform(); $s1 = $this->user('student'); $s2 = $this->user('student'); $i = $this->user('instructor');
        $this->assign($s1, $i);
        $sid1 = $this->startSession($s1, $p);
        $sid2 = $this->startSession($s2, $p);
        Sanctum::actingAs($i);
        $this->getJson("/api/instructor/sessions/$sid1/events")->assertOk();
        $this->getJson("/api/instructor/sessions/$sid2/events")->assertNotFound();
        $ids = collect($this->getJson('/api/instructor/sessions')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$sid1], $ids);
    }
}
