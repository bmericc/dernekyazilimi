<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A template may also go out as SMS: the edited text and whether it is on.
        Schema::table('mail_templates', function (Blueprint $table) {
            $table->text('sms')->nullable()->after('body');
            $table->boolean('sms_enabled')->nullable()->after('sms');
        });

        // Scheduled notices sent to a person (birthday, reminders), so none goes twice.
        Schema::create('sent_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->timestamp('sent_at');
            $table->index(['contact_id', 'key', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_notices');
        Schema::table('mail_templates', function (Blueprint $table) {
            $table->dropColumn(['sms', 'sms_enabled']);
        });
    }
};
