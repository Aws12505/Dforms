<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_version_id')->constrained('form_versions');
            $table->foreignId('current_stage_id')->constrained('stages');
            $table->uuid('public_identifier')->unique();
            $table->boolean('is_complete')->default(false);
            $table->boolean('is_considered')->default(false);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
    }
};
