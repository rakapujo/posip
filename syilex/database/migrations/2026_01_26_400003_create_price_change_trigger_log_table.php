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
        Schema::create('price_change_trigger_log', function (Blueprint $table) {
            $table->id();
            $table->dateTime('triggered_at');
            $table->integer('documents_processed')->default(0);
            $table->enum('trigger_type', ['auto', 'manual']);
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            // Indexes
            $table->index('triggered_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_change_trigger_log');
    }
};
