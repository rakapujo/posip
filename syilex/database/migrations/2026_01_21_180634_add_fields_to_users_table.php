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
            // Add ULID after id
            $table->char('ulid', 26)->unique()->after('id');

            // Add phone and avatar
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('avatar')->nullable()->after('phone');

            // Add status
            $table->enum('status', ['active', 'inactive'])->default('active')->after('remember_token');

            // Add created_by and updated_by
            $table->unsignedBigInteger('created_by')->nullable()->after('updated_at');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');

            // Foreign keys
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');

            // Index for status
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop foreign keys first
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);

            // Drop index
            $table->dropIndex(['status']);

            // Drop columns
            $table->dropColumn([
                'ulid',
                'phone',
                'avatar',
                'status',
                'created_by',
                'updated_by',
            ]);
        });
    }
};
