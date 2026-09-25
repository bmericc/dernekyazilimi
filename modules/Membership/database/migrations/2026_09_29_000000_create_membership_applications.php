<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The board decision behind joining and leaving (printed on the form).
        Schema::table('memberships', function (Blueprint $table) {
            $table->date('decision_date')->nullable()->after('left_at');
            $table->string('decision_number', 50)->nullable()->after('decision_date');
            $table->date('leave_decision_date')->nullable()->after('decision_number');
            $table->string('leave_decision_number', 50)->nullable()->after('leave_decision_date');
        });

        // A membership application: the form answers as submitted (so the
        // signed PDF can always be reproduced) and its progress.
        Schema::create('membership_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Year-sequence reference shown to the applicant, e.g. 2026-0007.
            $table->string('reference_no', 20)->unique();
            // references_pending, ready, approved, rejected, withdrawn
            $table->string('status', 30)->default('references_pending');
            $table->json('data');
            $table->timestamp('submitted_at');
            $table->timestamp('signed_form_received_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // One row per applicant–referee pair, so a member's references can be
        // listed and limited (in total, per calendar year).
        Schema::create('membership_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('membership_applications')->cascadeOnDelete();
            $table->foreignId('applicant_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('referee_contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            // pending, accepted, declined, expired, withdrawn
            $table->string('status', 20)->default('pending');
            $table->string('token_hash', 64);
            $table->timestamp('invited_at');
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->string('response_ip', 45)->nullable();
            $table->string('response_note', 500)->nullable();
            $table->timestamps();

            $table->index(['referee_contact_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_references');
        Schema::dropIfExists('membership_applications');
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropColumn(['decision_date', 'decision_number', 'leave_decision_date', 'leave_decision_number']);
        });
    }
};
