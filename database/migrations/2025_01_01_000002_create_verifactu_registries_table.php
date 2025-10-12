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
        Schema::create('verifactu_registries', function (Blueprint $table) {
            $table->id();

            // Foreign key to invoice
            $table->foreignId('invoice_id')
                ->constrained('verifactu_invoices')
                ->onDelete('cascade');

            // Registry identification
            $table->string('registry_number')->unique()->index();
            $table->timestamp('registry_date');

            // Blockchain hash
            $table->string('hash', 64)->unique();
            $table->string('previous_hash', 64)->nullable()->index();

            // QR Code
            $table->string('qr_url')->nullable();
            $table->text('qr_svg')->nullable();
            $table->binary('qr_png')->nullable();

            // XML
            $table->longText('xml');
            $table->longText('signed_xml')->nullable();

            // AEAT submission
            $table->string('status')->default('pending')->index(); // RegistryStatusEnum
            $table->timestamp('submitted_at')->nullable();
            $table->string('aeat_csv')->nullable()->unique();
            $table->text('aeat_response')->nullable();
            $table->text('aeat_error')->nullable();
            $table->integer('submission_attempts')->default(0);

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('submitted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verifactu_registries');
    }
};
