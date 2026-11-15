<?php

namespace Tests\Feature;

use App\Models\CertificationPlatform;
use App\Models\SessionAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class CertiTrackTestCase extends TestCase
{
    use RefreshDatabase;

    protected function user(string $role, string $email = null): User
    {
        return User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => $email ?? $role . uniqid() . '@t.test', 'password' => 'Password123!', 'role' => $role, 'is_active' => true]);
    }

    protected function platform(): CertificationPlatform
    {
        $p = CertificationPlatform::create(['name' => 'Test Platform', 'slug' => 'test-' . uniqid(), 'is_active' => true]);
        $p->domains()->create(['host' => 'exam.test', 'match_type' => 'suffix', 'is_active' => true]);
        return $p;
    }

    protected function assign(User $student, User $instructor, ?int $platformId = null): void
    {
        SessionAssignment::create(['student_id' => $student->id, 'instructor_id' => $instructor->id, 'platform_id' => $platformId]);
    }

    protected function startSession(User $student, CertificationPlatform $p): int
    {
        Sanctum::actingAs($student);
        return $this->postJson('/api/sessions', ['platform_id' => $p->id, 'consent' => true])->assertCreated()->json('data.id');
    }

    protected function ev(int $seq, string $type = 'tab_switched', array $details = ['direction' => 'away']): array
    {
        return ['event_uuid' => (string) \Illuminate\Support\Str::uuid(), 'seq' => $seq, 'type' => $type, 'client_timestamp' => now()->toIso8601String(), 'details' => $details];
    }
}
