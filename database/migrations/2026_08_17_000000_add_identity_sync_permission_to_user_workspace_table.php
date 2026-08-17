<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_workspace', function (Blueprint $table): void {
            $table->boolean('can_sync_identity')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('user_workspace', function (Blueprint $table): void {
            $table->dropColumn('can_sync_identity');
        });
    }
};
