<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Email and/or SMS to the contacts that match the chosen criteria.
        Schema::create('bulk_messages', function (Blueprint $table) {
            $table->id();
            // draft, sending, done, cancelled
            $table->string('status', 20)->default('draft');
            // ["email", "sms"]
            $table->json('channels');
            // Criterion key => value (App\Modules\Audiences).
            $table->json('criteria')->nullable();
            // Informational messages go only on the channels the contact consented to.
            $table->boolean('informational')->default(true);
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();
            $table->text('sms')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // The recipients, fixed when sending starts, and how each send went.
        Schema::create('bulk_message_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            // null when not sent on this channel; pending, sent, failed
            $table->string('email_status', 10)->nullable();
            $table->string('sms_status', 10)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['bulk_message_id', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_message_recipients');
        Schema::dropIfExists('bulk_messages');
    }
};
