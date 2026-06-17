<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the redundant `simplified` column (AID-187).
     *
     * `Invoice::isSimplified()` now derives simplified-ness from `type`
     * (InvoiceTypeEnum F2/R5), the AEAT-meaningful signal. The stored boolean was
     * a second source of truth that could disagree with `type` (e.g. type=F2 with
     * simplified=false). `type` is now authoritative: any row where the two
     * diverged follows `type` after this migration.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('verifactu_invoices', 'simplified')) {
            return;
        }

        Schema::table('verifactu_invoices', function (Blueprint $table) {
            $table->dropColumn('simplified');
        });
    }

    /**
     * Schema rollback that reconstructs the value from `type`: unlike a dead
     * column, `simplified` is derivable, so F2/R5 rows are restored to true and
     * everything else keeps the default (false).
     */
    public function down(): void
    {
        if (Schema::hasColumn('verifactu_invoices', 'simplified')) {
            return;
        }

        Schema::table('verifactu_invoices', function (Blueprint $table) {
            $table->boolean('simplified')->default(false);
        });

        // F2 (factura simplificada) and R5 (rectificativa en facturas
        // simplificadas) are the simplified TipoFactura codes (AEAT list L2).
        DB::table('verifactu_invoices')
            ->whereIn('type', ['F2', 'R5'])
            ->update(['simplified' => true]);
    }
};
