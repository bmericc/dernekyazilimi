<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A short-lived, single-use link the signing application on the
        // signer's computer uses to fetch a digest and return its signature.
        Schema::create('correspondence_signing_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('letter_id')->constrained('correspondence_letters')->cascadeOnDelete();
            // signature (over the package digest) or seal (over the final digest).
            $table->string('step', 20);
            // sha256 of the token in the link; the token itself is shown once.
            $table->char('token_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correspondence_signing_sessions');
    }
};
