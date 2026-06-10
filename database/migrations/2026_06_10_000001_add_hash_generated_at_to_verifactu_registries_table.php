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
     * Stores the exact FechaHoraHusoGenRegistro value (ISO 8601 with timezone
     * offset) used as input for the registry hash, so the fingerprint chain
     * can be rebuilt and verified at any later time (AEAT hash spec v0.1.2).
     */
    public function up(): void
    {
        Schema::table('verifactu_registries', function (Blueprint $table) {
            $table->string('hash_generated_at', 30)->nullable()->after('previous_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('verifactu_registries', function (Blueprint $table) {
            $table->dropColumn('hash_generated_at');
        });
    }
};
