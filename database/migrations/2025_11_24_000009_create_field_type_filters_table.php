<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_type_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_type_id')->constrained('field_types');
            $table->text('filter_method_description');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_type_filters');
    }
};
