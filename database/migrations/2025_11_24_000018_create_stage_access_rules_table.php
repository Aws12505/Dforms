<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_access_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained('stages')->onDelete('cascade');
            $table->text('allowed_users')->nullable(); // JSON array of user IDs
            $table->text('allowed_roles')->nullable(); // JSON array of role IDs
            $table->text('allowed_permissions')->nullable(); // JSON array of permission IDs
            $table->boolean('allow_authenticated_users')->default(false);
            $table->foreignId('email_field_id')->nullable()->constrained('fields');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_access_rules');
    }
};
