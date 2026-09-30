<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which portal record a Fonzip record was imported into. Running the
        // import again (a later delta) skips what is already linked.
        Schema::create('fonzip_links', function (Blueprint $table) {
            $table->id();
            // contact, charge, payment, donation
            $table->string('kind', 20);
            $table->string('fonzip_id', 40);
            $table->morphs('linkable');
            $table->timestamps();

            $table->unique(['kind', 'fonzip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fonzip_links');
    }
};
