<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a member owes: the yearly dues, the entry fee, or anything else
        // management charges. Collections are payments (purpose "dues") of the
        // same contact; they settle the oldest charges first.
        Schema::create('dues_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            // annual, entry, other
            $table->string('kind', 20);
            // The year the charge belongs to; payments settle older years first.
            $table->unsignedSmallInteger('year');
            $table->decimal('amount', 12, 2);
            $table->string('description')->nullable();
            // "annual-2026", "entry-2026": at most one of each per member;
            // null for other charges.
            $table->string('period_key', 30)->nullable();
            // A cancelled charge stays for the record but is not owed.
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['contact_id', 'period_key']);
            $table->index('year');
        });

        // Members who pay no yearly dues for a while (or for good).
        Schema::create('dues_exemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('from_year');
            $table->unsignedSmallInteger('until_year')->nullable();
            $table->string('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dues_exemptions');
        Schema::dropIfExists('dues_charges');
    }
};
