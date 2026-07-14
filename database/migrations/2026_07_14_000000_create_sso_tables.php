<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('base_url', 2048);
            $table->string('callback_url', 2048);
            $table->string('client_id')->unique();
            $table->string('client_secret_hash');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_workspace', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->primary(['user_id', 'workspace_id']);
        });

        Schema::create('sso_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('code_hash', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_codes');
        Schema::dropIfExists('user_workspace');
        Schema::dropIfExists('workspaces');
    }
};
