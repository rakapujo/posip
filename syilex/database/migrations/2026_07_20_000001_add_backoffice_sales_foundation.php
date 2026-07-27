<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_sales', function (Blueprint $table) {
            $table->foreignId('terminal_id')->nullable()->change();
            $table->foreignId('shift_id')->nullable()->change();
            $table->string('source', 20)->default('pos')->after('nomor_dokumen');
            $table->unsignedInteger('tempo_hari')->default(0)->after('grand_total');
            $table->date('tanggal_jatuh_tempo')->nullable()->after('tempo_hari');
            $table->boolean('cash_payment')->default(false)->after('tanggal_jatuh_tempo');
            $table->string('cash_metode', 20)->nullable()->after('cash_payment');
            $table->string('cash_no_referensi', 100)->nullable()->after('cash_metode');
            $table->string('cash_bank_nama', 100)->nullable()->after('cash_no_referensi');
            $table->string('cash_bank_rekening', 50)->nullable()->after('cash_bank_nama');
            $table->dateTime('approved_at')->nullable()->after('notes');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->index(['source', 'status', 'tanggal'], 'doc_sales_source_status_tanggal_index');
        });

        $this->changeStringColumn('doc_sales', 'status', 20, false, 'completed');
        DB::table('doc_sales')->whereNull('source')->update(['source' => 'pos']);

        Schema::table('doc_sales_returns', function (Blueprint $table) {
            $table->foreignId('terminal_id')->nullable()->change();
            $table->foreignId('shift_id')->nullable()->change();
            $table->string('source', 20)->default('pos')->after('nomor_dokumen');
            $table->string('status', 20)->default('draft')->after('refund_method');
            $table->dateTime('locked_at')->nullable()->after('notes');
            $table->foreignId('locked_by')->nullable()->after('locked_at')->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable()->after('locked_by');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->index(['source', 'status', 'tanggal'], 'sales_returns_source_status_date_index');
        });

        $this->changeStringColumn('doc_sales_returns', 'refund_method', 20, true);
        DB::table('doc_sales_returns')->update([
            'source' => DB::raw("COALESCE(source, 'pos')"),
            'status' => 'approved',
        ]);

        Schema::table('master_customer', function (Blueprint $table) {
            $table->unsignedInteger('tempo_default')->default(0)->after('kategori_customer_id');
        });

        Schema::table('doc_promo', function (Blueprint $table) {
            $table->string('channel', 20)->default('keduanya')->after('deskripsi');
            $table->index(['channel', 'status'], 'doc_promo_channel_status_index');
        });
        DB::table('doc_promo')->whereNull('channel')->update(['channel' => 'keduanya']);

        Schema::create('customer_deposit', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('customer_id')->constrained('master_customer');
            $table->foreignId('retur_id')->nullable()->constrained('doc_sales_returns')->nullOnDelete();
            $table->string('no_referensi', 50)->nullable();
            $table->text('keterangan')->nullable();
            $table->dateTime('tanggal');
            $table->decimal('nominal_awal', 15, 2);
            $table->decimal('nominal_terpakai', 15, 2)->default(0);
            $table->decimal('sisa_deposit', 15, 2);
            $table->string('status', 20)->default('available');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('no_referensi');
        });

        Schema::create('customer_piutang', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('customer_id')->constrained('master_customer');
            $table->foreignId('sales_id')->unique()->constrained('doc_sales');
            $table->dateTime('tanggal');
            $table->date('tanggal_jatuh_tempo')->nullable();
            $table->decimal('nominal_awal', 15, 2);
            $table->decimal('nominal_terbayar', 15, 2)->default(0);
            $table->decimal('sisa_piutang', 15, 2);
            $table->string('status', 20)->default('unpaid');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'status']);
            $table->index('tanggal_jatuh_tempo');
        });

        Schema::create('doc_pembayaran_piutang', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('nomor_dokumen', 50)->unique();
            $table->date('tanggal');
            $table->foreignId('customer_id')->constrained('master_customer');
            $table->decimal('total_bayar_cash', 15, 2)->default(0);
            $table->decimal('total_bayar_deposit', 15, 2)->default(0);
            $table->decimal('total_pembayaran', 15, 2)->default(0);
            $table->string('metode_pembayaran', 20)->default('cash');
            $table->string('no_referensi', 50)->nullable();
            $table->string('bank_nama', 50)->nullable();
            $table->string('bank_rekening', 30)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'tanggal']);
            $table->index('status');
        });

        Schema::create('doc_pembayaran_piutang_detail', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('pembayaran_id')->constrained('doc_pembayaran_piutang')->cascadeOnDelete();
            $table->foreignId('piutang_id')->constrained('customer_piutang');
            $table->decimal('nominal_dibayar', 15, 2);
            $table->string('sumber', 20);

            $table->index('piutang_id');
            $table->unique(['pembayaran_id', 'piutang_id'], 'payment_piutang_detail_unique');
        });

        Schema::create('doc_pembayaran_piutang_deposit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pembayaran_id')->constrained('doc_pembayaran_piutang')->cascadeOnDelete();
            $table->foreignId('deposit_id')->constrained('customer_deposit');
            $table->decimal('nominal_digunakan', 15, 2);

            $table->unique(['pembayaran_id', 'deposit_id'], 'payment_piutang_deposit_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('doc_sales_returns')->whereNot('source', 'pos')->exists()
            || DB::table('doc_sales_returns')->whereNot('status', 'approved')->exists()
            || DB::table('doc_sales_returns')->whereNull('refund_method')->orWhereNotIn('refund_method', ['cash', 'credit'])->exists()
            || DB::table('doc_sales_returns')->whereNull('terminal_id')->orWhereNull('shift_id')->exists()) {
            throw new RuntimeException('Cannot roll back: backoffice sales return rows require the expanded schema.');
        }

        if (DB::table('doc_sales')->whereNot('source', 'pos')->exists()
            || DB::table('doc_sales')->whereNotIn('status', ['completed', 'voided'])->exists()
            || DB::table('doc_sales')->whereNull('terminal_id')->orWhereNull('shift_id')->exists()) {
            throw new RuntimeException('Cannot roll back: backoffice sales rows require the expanded schema.');
        }

        Schema::dropIfExists('doc_pembayaran_piutang_deposit');
        Schema::dropIfExists('doc_pembayaran_piutang_detail');
        Schema::dropIfExists('doc_pembayaran_piutang');
        Schema::dropIfExists('customer_piutang');
        Schema::dropIfExists('customer_deposit');

        Schema::table('doc_promo', function (Blueprint $table) {
            $table->dropIndex('doc_promo_channel_status_index');
            $table->dropColumn('channel');
        });

        Schema::table('master_customer', function (Blueprint $table) {
            $table->dropColumn('tempo_default');
        });

        Schema::table('doc_sales_returns', function (Blueprint $table) {
            $table->dropIndex('sales_returns_source_status_date_index');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('locked_by');
            $table->dropColumn(['source', 'status', 'locked_at', 'approved_at']);
            $table->foreignId('terminal_id')->nullable(false)->change();
            $table->foreignId('shift_id')->nullable(false)->change();
        });
        $this->changeEnumColumn('doc_sales_returns', 'refund_method', "'cash','credit'", false);

        Schema::table('doc_sales', function (Blueprint $table) {
            $table->dropIndex('doc_sales_source_status_tanggal_index');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'source',
                'tempo_hari',
                'tanggal_jatuh_tempo',
                'cash_payment',
                'cash_metode',
                'cash_no_referensi',
                'cash_bank_nama',
                'cash_bank_rekening',
                'approved_at',
            ]);
            $table->foreignId('terminal_id')->nullable(false)->change();
            $table->foreignId('shift_id')->nullable(false)->change();
        });
        $this->changeEnumColumn('doc_sales', 'status', "'completed','voided'", false, 'completed');
    }

    private function changeStringColumn(
        string $table,
        string $column,
        int $length,
        bool $nullable,
        ?string $default = null,
    ): void {
        if (DB::getDriverName() === 'mysql') {
            $null = $nullable ? 'NULL' : 'NOT NULL';
            $defaultSql = $default === null ? '' : " DEFAULT '{$default}'";
            DB::statement("ALTER TABLE {$table} MODIFY {$column} VARCHAR({$length}) {$null}{$defaultSql}");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $length, $nullable, $default) {
            $newColumn = $blueprint->string($column, $length)->nullable($nullable);
            if ($default !== null) {
                $newColumn->default($default);
            }
            $newColumn->change();
        });
    }

    private function changeEnumColumn(
        string $table,
        string $column,
        string $values,
        bool $nullable,
        ?string $default = null,
    ): void {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $null = $nullable ? 'NULL' : 'NOT NULL';
        $defaultSql = $default === null ? '' : " DEFAULT '{$default}'";
        DB::statement("ALTER TABLE {$table} MODIFY {$column} ENUM({$values}) {$null}{$defaultSql}");
    }
};
