<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('customer_credits');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Table will not be recreated - feature removed
    }
};
