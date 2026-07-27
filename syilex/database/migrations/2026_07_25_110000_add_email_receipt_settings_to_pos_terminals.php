<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_pos_terminal', function (Blueprint $table) {
            $table->string('mail_driver')->default('none')->after('default_printer');
            $table->string('mail_from_address')->nullable()->after('mail_driver');
            $table->string('mail_from_name')->nullable()->after('mail_from_address');
            $table->string('smtp_host')->nullable()->after('mail_from_name');
            $table->unsignedSmallInteger('smtp_port')->nullable()->after('smtp_host');
            $table->string('smtp_encryption')->nullable()->after('smtp_port');
            $table->string('smtp_username')->nullable()->after('smtp_encryption');
            $table->text('smtp_password')->nullable()->after('smtp_username');
            $table->text('resend_api_key')->nullable()->after('smtp_password');
        });
    }

    public function down(): void
    {
        Schema::table('master_pos_terminal', function (Blueprint $table) {
            $table->dropColumn([
                'mail_driver',
                'mail_from_address',
                'mail_from_name',
                'smtp_host',
                'smtp_port',
                'smtp_encryption',
                'smtp_username',
                'smtp_password',
                'resend_api_key',
            ]);
        });
    }
};
