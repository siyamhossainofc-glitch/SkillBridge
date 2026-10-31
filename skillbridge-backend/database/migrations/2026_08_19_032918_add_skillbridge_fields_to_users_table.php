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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('seeker')->after('password');
            $table->string('phone', 20)->nullable()->after('role');
            $table->text('address')->nullable()->after('phone');
            $table->decimal('lat', 10, 8)->nullable()->after('address');
            $table->decimal('lng', 11, 8)->nullable()->after('lat');
            $table->boolean('nid_verified')->default(false)->after('lng');
            $table->decimal('rating', 3, 2)->default(0)->after('nid_verified');
            $table->text('bio')->nullable()->after('rating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role',
                'phone',
                'address',
                'lat',
                'lng',
                'nid_verified',
                'rating',
                'bio',
            ]);
        });
    }
};
