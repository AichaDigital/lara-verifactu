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
        Schema::create('verifactu_invoices', function (Blueprint $table) {
            $table->id();

            // Invoice identification
            $table->string('serie')->nullable()->index();
            $table->string('number')->index();
            $table->date('issue_date')->index();
            $table->time('issue_time');

            // Invoice type
            $table->string('type'); // InvoiceTypeEnum
            $table->boolean('simplified')->default(false);
            $table->string('rectification_type')->nullable();

            // Amounts
            $table->decimal('base_amount', 12, 2);
            $table->decimal('tax_amount', 12, 2);
            $table->decimal('total_amount', 12, 2);
            $table->string('currency', 3)->default('EUR');

            // Recipient information
            $table->string('recipient_nif')->nullable()->index();
            $table->string('recipient_id_type')->nullable(); // IdTypeEnum
            $table->string('recipient_id')->nullable()->index();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_country', 2)->nullable();

            // Tax regime and operation
            $table->string('regime_type'); // RegimeTypeEnum
            $table->string('operation_key'); // OperationTypeEnum

            // Additional fields
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->unique(['serie', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verifactu_invoices');
    }
};
