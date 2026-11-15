<?php

namespace Database\Seeders;

use App\Models\CertificationPlatform;
use App\Models\SessionAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * DEVELOPMENT ACCOUNTS ONLY. All use the password below - change/remove before any real deployment.
 * No sessions or events are seeded: every record in the dashboards comes from real extension activity.
 */
class DatabaseSeeder extends Seeder
{
    public const DEV_PASSWORD = 'Password123!';

    public function run(): void
    {
        $mk = fn (string $name, string $email, string $role, ?string $no = null) => User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => self::DEV_PASSWORD, 'role' => $role, 'student_number' => $no, 'is_active' => true]
        );

        $mk('System Administrator', 'admin@certitrack.test', 'admin');
        $i1 = $mk('Ines Instructor', 'instructor1@certitrack.test', 'instructor');
        $i2 = $mk('Ivan Instructor', 'instructor2@certitrack.test', 'instructor');
        $s1 = $mk('Sara Student', 'student1@certitrack.test', 'student', '2026-0001');
        $s2 = $mk('Sam Student', 'student2@certitrack.test', 'student', '2026-0002');
        $s3 = $mk('Sofia Student', 'student3@certitrack.test', 'student', '2026-0003');

        // Sample platform registry. Hostnames are EXAMPLES: verify the real hosts your exams use
        // (including single-sign-on hosts) and edit them in Admin > Platforms.
        $platforms = [
            ['cisco-netacad', 'Cisco Networking Academy', 'Courses and exams delivered through Cisco Networking Academy.', [
                ['netacad.com', 'suffix', null], ['skillsforall.com', 'suffix', null], ['cisco.com', 'suffix', null],
            ]],
            ['fortinet-training', 'Fortinet Training Institute', 'Fortinet Training Institute courses and assessments.', [
                ['training.fortinet.com', 'suffix', null], ['fortinet.com', 'suffix', null],
            ]],
            ['local-test', 'Local Test Page (demo)', 'Neutral page served by this backend at /demo-target, for trying the extension without any real platform.', [
                ['localhost', 'exact', '/demo-target'], ['127.0.0.1', 'exact', '/demo-target'],
            ]],
        ];
        foreach ($platforms as [$slug, $name, $desc, $domains]) {
            $p = CertificationPlatform::updateOrCreate(['slug' => $slug], ['name' => $name, 'description' => $desc, 'is_active' => true]);
            foreach ($domains as [$host, $type, $path]) {
                $p->domains()->firstOrCreate(['host' => $host, 'path_prefix' => $path], ['match_type' => $type, 'is_active' => true]);
            }
        }

        // Instructor 1 supervises students 1 and 2 (all platforms); instructor 2 supervises student 3.
        foreach ([[$s1, $i1], [$s2, $i1], [$s3, $i2]] as [$s, $i]) {
            SessionAssignment::firstOrCreate(['student_id' => $s->id, 'instructor_id' => $i->id, 'platform_id' => null]);
        }
    }
}
