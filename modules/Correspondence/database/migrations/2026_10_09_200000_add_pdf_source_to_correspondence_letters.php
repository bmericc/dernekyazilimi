<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correspondence_letters', function (Blueprint $table) {
            // composed: written in the portal, which prints it. pdf: a finished
            // letter uploaded as a PDF, numbered and dated outside the portal.
            $table->string('source', 10)->default('composed')->after('status');
            $table->string('pdf_path')->nullable()->after('body');
            $table->string('pdf_name')->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('correspondence_letters', function (Blueprint $table) {
            $table->dropColumn(['source', 'pdf_path', 'pdf_name']);
        });
    }
};
