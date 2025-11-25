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
        Schema::create('users', function (Blueprint $table) {
            // ID from auth system - we use their exact ID
            $table->unsignedBigInteger('id')->primary();
            
            // Basic user info mirrored from auth system
            $table->string('name');
            $table->string('email')->unique();
            
            // Timestamps for tracking when we last synced this user
            $table->timestamps();
            
            // Index for faster lookups
            $table->index('email');
        });

        Schema::create('roles', function (Blueprint $table) {
            // ID from auth system - we use their exact ID
            $table->unsignedBigInteger('id')->primary();
            
            // Role name from auth system
            $table->string('name');
            
            // Optional: description if auth system provides it
            $table->text('description')->nullable();
            
            // Timestamps for tracking when we last synced this role
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            // ID from auth system - we use their exact ID
            $table->unsignedBigInteger('id')->primary();
            
            // Permission name from auth system
            $table->string('name');
            
            // Optional: description if auth system provides it
            $table->text('description')->nullable();
            
            // Timestamps for tracking when we last synced this permission
            $table->timestamps();
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            
            $table->foreignId('role_id')
                  ->constrained('roles')
                  ->cascadeOnDelete();
            
            $table->timestamps();
            
            // Prevent duplicate assignments
            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();
            
            $table->foreignId('permission_id')
                  ->constrained('permissions')
                  ->cascadeOnDelete();
            
            $table->timestamps();
            
            // Prevent duplicate assignments
            $table->unique(['user_id', 'permission_id']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('user_permissions');
    }
};
