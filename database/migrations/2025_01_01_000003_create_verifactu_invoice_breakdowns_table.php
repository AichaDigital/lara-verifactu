<?php

declare(strict_types=1);

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
        Schema::create('verifactu_invoice_breakdowns', function (Blueprint $table) {
            $table->id();

            // Foreign key to invoice
            $table->foreignId('invoice_id')
                ->constrained('verifactu_invoices')
                ->onDelete('cascade');

            // Tax breakdown
            $table->string('tax_type'); // TaxTypeEnum
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('base_amount', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('surcharge_rate', 5, 2)->nullable();
            $table->decimal('surcharge_amount', 12, 2)->nullable();

            // Exemption details
            $table->boolean('exempt')->default(false);
            $table->string('exemption_reason')->nullable();

            // Additional details
            $table->json('metadata')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index('invoice_id');
            $table->index('tax_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verifactu_invoice_breakdowns');
    }
};
