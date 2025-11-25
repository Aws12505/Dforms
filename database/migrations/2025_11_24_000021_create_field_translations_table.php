<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_id')->constrained('fields')->onDelete('cascade');
            $table->foreignId('language_id')->constrained('languages');
            $table->string('label', 255);
            $table->text('helper_text')->nullable();
            $table->text('default_value')->nullable();
            $table->timestamps();

            $table->unique(['field_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_translations');
    }
};
