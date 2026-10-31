<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('job_posts', 'deadline')) {
            Schema::table('job_posts', function (Blueprint $table): void {
                $table->date('deadline')->nullable()->after('work_mode');
                $table->enum('urgency', ['low', 'normal', 'high', 'urgent'])->nullable()->after('deadline');
                $table->string('preferred_experience', 255)->nullable()->after('urgency');
                $table->index(['deadline', 'urgency']);
            });
        }

        if (!Schema::hasTable('job_skill')) {
            Schema::create('job_skill', function (Blueprint $table): void {
                $table->foreignId('job_post_id')->constrained('job_posts')->cascadeOnDelete();
                $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
                $table->primary(['job_post_id', 'skill_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('job_skill');
        if (Schema::hasColumn('job_posts', 'deadline')) {
            Schema::table('job_posts', function (Blueprint $table): void {
                $table->dropIndex(['deadline', 'urgency']);
                $table->dropColumn(['deadline', 'urgency', 'preferred_experience']);
            });
        }
    }
};
