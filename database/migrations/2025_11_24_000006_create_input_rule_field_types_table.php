<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('input_rule_field_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('input_rule_id')->constrained('input_rules');
            $table->foreignId('field_type_id')->constrained('field_types');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('input_rule_field_types');
    }
};
