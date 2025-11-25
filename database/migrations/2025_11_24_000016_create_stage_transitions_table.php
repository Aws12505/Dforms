<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_version_id')->constrained('form_versions')->onDelete('cascade');
            $table->foreignId('from_stage_id')->constrained('stages')->onDelete('cascade');
            $table->foreignId('to_stage_id')->nullable()->constrained('stages')->onDelete('cascade');
            $table->boolean('to_complete')->default(false);
            $table->string('label', 255);
            $table->text('condition')->nullable(); // JSON
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_transitions');
    }
};
