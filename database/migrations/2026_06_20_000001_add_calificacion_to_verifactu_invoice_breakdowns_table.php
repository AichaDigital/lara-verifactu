<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * AID-223: AEAT CalificacionOperacion (list L9) for a breakdown. Nullable —
     * null means S1 (subject, not exempt, no reverse charge). The v1.0 core emits
     * S1 and N2 (no sujeta por reglas de localización). 2-char L9 code.
     */
    public function up(): void
    {
        Schema::table('verifactu_invoice_breakdowns', function (Blueprint $table) {
            $table->string('calificacion', 2)->nullable()->after('exemption_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('verifactu_invoice_breakdowns', function (Blueprint $table) {
            $table->dropColumn('calificacion');
        });
    }
};
