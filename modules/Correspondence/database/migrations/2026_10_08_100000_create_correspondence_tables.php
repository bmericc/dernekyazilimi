<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An outgoing official letter. It is a draft until it gets its number;
        // from then on its content is fixed.
        Schema::create('correspondence_letters', function (Blueprint $table) {
            $table->id();
            // Document id of the e-Yazışma package and the verification code printed on the letter.
            $table->char('document_id', 36)->unique();
            $table->string('status', 20)->default('draft')->index();
            $table->string('subject');
            $table->longText('body')->nullable();
            // Letters this one refers to ("İlgi"), one per entry.
            $table->json('references')->nullable();
            // Who signs: [{first_name, last_name, title}].
            $table->json('signers')->nullable();
            // Standard file plan code and name.
            $table->string('file_code', 30)->nullable();
            $table->string('file_name', 150)->nullable();
            $table->unsignedSmallInteger('number_year')->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->string('document_no', 80)->nullable();
            $table->date('document_date')->nullable();
            // The e-Yazışma package on the private disk, complete or waiting for a signature.
            $table->string('package_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['number_year', 'number']);
        });

        Schema::create('correspondence_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_id')->constrained('correspondence_letters')->cascadeOnDelete();
            // institution (public body), legal (company, association...) or person.
            $table->string('kind', 20)->default('institution');
            $table->string('name');
            // DETSİS code, MERSİS number or T.C. identity number, by kind.
            $table->string('identifier', 30)->nullable();
            $table->string('address', 500)->nullable();
            // GRG (for action) or BLG (for information).
            $table->string('delivery', 3)->default('GRG');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('correspondence_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_id')->constrained('correspondence_letters')->cascadeOnDelete();
            $table->string('name');
            // File on the private disk; null for a physical attachment (a book, a CD...).
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Last number given in a year; locked while the next one is taken.
        Schema::create('correspondence_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correspondence_sequences');
        Schema::dropIfExists('correspondence_attachments');
        Schema::dropIfExists('correspondence_recipients');
        Schema::dropIfExists('correspondence_letters');
    }
};
