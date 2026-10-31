<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addModerationColumns('job_posts');
        $this->addModerationColumns('services');
        $this->addModerationColumns('portfolio_items');
        $this->addModerationColumns('reviews');
    }

    private function addModerationColumns(string $tableName): void
    {
        if (!Schema::hasColumn($tableName, 'moderation_status')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->enum('moderation_status', ['visible', 'hidden'])
                    ->default('visible')
                    ->after('status')
                    ->index();
                $table->text('moderation_reason')->nullable()->after('moderation_status');
                $table->foreignId('moderated_by')->nullable()->after('moderation_reason')->constrained('users')->nullOnDelete();
                $table->timestamp('moderated_at')->nullable()->after('moderated_by');
            });
        }
    }

    public function down(): void
    {
        foreach (['job_posts', 'services', 'portfolio_items', 'reviews'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'moderation_status')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropForeign(['moderated_by']);
                    $table->dropColumn(['moderation_status', 'moderation_reason', 'moderated_by', 'moderated_at']);
                });
            }
        }
    }
};
