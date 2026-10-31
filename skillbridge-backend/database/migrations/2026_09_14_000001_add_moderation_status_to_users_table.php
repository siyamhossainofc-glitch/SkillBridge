<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'moderation_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->enum('moderation_status', ['active', 'suspended'])
                    ->default('active')
                    ->after('role')
                    ->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'moderation_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('moderation_status');
            });
        }
    }
};
