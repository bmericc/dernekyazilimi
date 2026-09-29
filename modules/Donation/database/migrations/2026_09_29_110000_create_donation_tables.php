<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a donation is for (general fund, a project, an event...).
        Schema::create('donation_causes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('target_amount', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // A donation; its collection is the payment (payments.payable).
        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cause_id')->nullable()->constrained('donation_causes')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('donor_name')->nullable();
            $table->string('donor_email')->nullable();
            $table->string('donor_phone', 30)->nullable();
            // The donor does not want their name mentioned (thanks lists, reports).
            $table->boolean('hide_name')->default(false);
            $table->string('message', 1000)->nullable();
            $table->string('source', 20)->default('online');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
        Schema::dropIfExists('donation_causes');
    }
};
