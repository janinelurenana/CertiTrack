<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('platform_id')->constrained('certification_platforms');
            // Session lifecycle status (separate from event severity and review status).
            $t->enum('status', ['scheduled', 'active', 'interrupted', 'completed', 'abandoned'])->default('active');
            // Instructor review status (separate concept).
            $t->enum('review_status', ['pending_review', 'reviewed', 'no_concern', 'requires_investigation'])->default('pending_review');
            $t->string('client_id', 64)->nullable();        // extension install id
            $t->string('consent_version', 32);
            $t->timestamp('consented_at');
            $t->timestamp('started_at');
            $t->timestamp('ended_at')->nullable();
            $t->timestamp('last_heartbeat_at')->nullable();
            $t->timestamp('interrupted_at')->nullable();
            $t->unsignedInteger('event_count')->default(0);       // client-reported events accepted
            $t->unsignedInteger('review_score')->default(0);      // weighted activity count, ONLY for review prioritisation
            $t->unsignedInteger('client_last_seq')->default(0);   // highest seq the extension says it produced
            $t->timestamps();
            $t->index(['student_id', 'status']);
            $t->index(['platform_id', 'status']);
            $t->index('started_at');
            $t->index('review_status');
            $t->index(['status', 'last_heartbeat_at']);
        });

        Schema::create('monitoring_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $t->uuid('event_uuid')->unique();                // client-generated, global dedupe key
            $t->unsignedInteger('seq')->nullable();          // per-session client sequence (null for server events)
            $t->string('type', 64);
            $t->enum('severity', ['info', 'low', 'medium', 'high']);
            $t->enum('source', ['extension', 'server'])->default('extension');
            $t->timestamp('client_timestamp')->nullable();   // untrusted, ordering hint only
            $t->timestamp('received_at');                    // authoritative
            $t->timestamp('occurred_at');                    // clamped estimate used for timeline order
            $t->json('details')->nullable();                 // whitelisted, no query strings
            $t->boolean('received_after_end')->default(false);
            $t->boolean('flagged')->default(false);
            $t->timestamps();
            $t->unique(['session_id', 'seq']);
            $t->index(['session_id', 'occurred_at']);
            $t->index(['type', 'severity']);
        });

        Schema::create('session_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $t->foreignId('event_id')->nullable()->constrained('monitoring_events')->nullOnDelete();
            $t->foreignId('reviewer_id')->constrained('users');
            $t->enum('status', ['pending_review', 'reviewed', 'no_concern', 'requires_investigation']);
            $t->text('remarks')->nullable();
            $t->timestamps();
            $t->index(['session_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 80)->index();
            $t->string('subject_type', 80)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->json('metadata')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['subject_type', 'subject_id']);
        });

        Schema::create('pairing_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('code_hash', 64)->unique();
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pairing_codes');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('session_reviews');
        Schema::dropIfExists('monitoring_events');
        Schema::dropIfExists('exam_sessions');
    }
};
