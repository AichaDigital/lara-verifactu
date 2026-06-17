<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the dead `operation_key` column (AID-186).
     *
     * `OperationTypeEnum` mapped to no official AEAT list and `XmlBuilder` never
     * read `getOperationKey()`; the column only let consumers express a non-S1
     * intent that was then silently dropped. Removed for the v1.0 honest core.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('verifactu_invoices', 'operation_key')) {
            return;
        }

        Schema::table('verifactu_invoices', function (Blueprint $table) {
            $table->dropColumn('operation_key');
        });
    }

    /**
     * Schema-level rollback only: the column is re-added with its former default
     * ('01'), but per-row values removed by up() are not restored.
     */
    public function down(): void
    {
        if (Schema::hasColumn('verifactu_invoices', 'operation_key')) {
            return;
        }

        Schema::table('verifactu_invoices', function (Blueprint $table) {
            $table->string('operation_key')->default('01');
        });
    }
};
