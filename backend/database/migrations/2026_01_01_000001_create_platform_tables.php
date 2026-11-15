<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certification_platforms', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug', 80)->unique();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->timestamps();
        });

        // Approved domains / URL rules per platform (generic engine reads these).
        Schema::create('platform_domains', function (Blueprint $t) {
            $t->id();
            $t->foreignId('platform_id')->constrained('certification_platforms')->cascadeOnDelete();
            $t->string('host', 253);                       // e.g. netacad.com
            $t->enum('match_type', ['suffix', 'exact'])->default('suffix'); // suffix = host or any subdomain
            $t->string('path_prefix', 255)->nullable();    // optional, e.g. /exam
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['platform_id', 'host', 'path_prefix']);
        });

        Schema::create('session_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('instructor_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('platform_id')->nullable()->constrained('certification_platforms')->cascadeOnDelete(); // null = all platforms
            $t->timestamps();
            $t->index(['instructor_id', 'student_id']);
            $t->index(['student_id', 'platform_id']);
        });

        Schema::create('system_settings', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('session_assignments');
        Schema::dropIfExists('platform_domains');
        Schema::dropIfExists('certification_platforms');
    }
};
