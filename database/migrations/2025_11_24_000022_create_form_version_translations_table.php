<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_version_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_version_id')->constrained('form_versions')->onDelete('cascade');
            $table->foreignId('language_id')->constrained('languages');
            $table->string('name', 255);
            $table->timestamps();

            $table->unique(['form_version_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_version_translations');
    }
};
