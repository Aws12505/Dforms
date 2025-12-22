<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stage_transition_translations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('stage_transition_id')->constrained('stage_transitions')->cascadeOnDelete();
    $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
    $table->string('label', 255)->nullable();
    $table->timestamps();

$table->unique(['stage_transition_id', 'language_id'], 'sttr_stid_lang_unique');
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stage_transition_translations');
    }
};
