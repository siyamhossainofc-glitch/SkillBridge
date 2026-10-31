<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('reviews')
            ->select(['reviewer_id', 'contract_id', 'reviewee_id', DB::raw('COUNT(*) AS duplicate_count')])
            ->groupBy('reviewer_id', 'contract_id', 'reviewee_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('reviewer_id')
            ->orderBy('contract_id')
            ->orderBy('reviewee_id')
            ->limit(20)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $details = $duplicates->map(fn ($duplicate): string => sprintf(
                '(reviewer_id=%d, contract_id=%d, reviewee_id=%d, rows=%d)',
                $duplicate->reviewer_id,
                $duplicate->contract_id,
                $duplicate->reviewee_id,
                $duplicate->duplicate_count,
            ))->implode('; ');

            throw new \RuntimeException(
                'Cannot add reviews_reviewer_contract_reviewee_unique because existing duplicate review tuples were found. '
                .'No records were modified or deleted. Resolve these duplicates manually, then rerun the migration. '
                .$details
            );
        }

        Schema::table('reviews', function (Blueprint $table) {
            $table->enum('status', ['published', 'hidden'])->default('published')->after('comment');
            $table->unique(['reviewer_id', 'contract_id', 'reviewee_id'], 'reviews_reviewer_contract_reviewee_unique');
            $table->index(['reviewee_id', 'status', 'rating'], 'reviews_public_aggregate_index');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique('reviews_reviewer_contract_reviewee_unique');
            $table->dropIndex('reviews_public_aggregate_index');
            $table->dropColumn('status');
        });
    }
};
