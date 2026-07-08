<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only fixture table for AID-344: a genuinely external "custom mode"
 * invoice table, deliberately unrelated to `verifactu_invoices` (different
 * name, no shared migration), so tests prove `Registry::invoice()` and the
 * registries FK no longer assume the native table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('serie')->nullable();
            $table->string('number');
            $table->dateTime('issue_datetime');
            $table->decimal('base_amount', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('total_amount', 12, 2);
            $table->string('recipient_nif')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_country')->nullable();
            $table->string('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_invoices');
    }
};
