<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_transition_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_transition_id')->constrained('stage_transitions')->onDelete('cascade');
            $table->foreignId('action_id')->constrained('actions');
            $table->text('action_props'); // JSON
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_transition_actions');
    }
};
