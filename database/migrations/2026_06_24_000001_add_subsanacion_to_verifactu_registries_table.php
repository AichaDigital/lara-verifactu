<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the subsanación («ALTA POR RECHAZO», AID-137) circumstances:
     *  - subsanacion: emits <Subsanacion>S</Subsanacion>.
     *  - rechazo_previo: char(1) {N,S,X}; null when not emitted.
     *  - amends_registry_id: self-FK to the rejected registry being amended.
     * The unique partial index on amends_registry_id prevents a concurrent
     * double amendment of the same rejected record (spec §1).
     */
    public function up(): void
    {
        Schema::table('verifactu_registries', function (Blueprint $table) {
            $table->boolean('subsanacion')->default(false)->after('registry_type');
            $table->char('rechazo_previo', 1)->nullable()->after('subsanacion');
            $table->unsignedBigInteger('amends_registry_id')->nullable()->after('rechazo_previo');

            $table->foreign('amends_registry_id')
                ->references('id')
                ->on('verifactu_registries')
                ->nullOnDelete();
        });

        // Nullable unique index: one amendment per rejected record, but any
        // number of rows may have a null amends_registry_id. MySQL and SQLite
        // both treat multiple NULLs as distinct in a standard unique index, so
        // the null rows never collide — no WHERE clause is needed.
        Schema::table('verifactu_registries', function (Blueprint $table) {
            $table->unique('amends_registry_id', 'verifactu_registries_amends_unique');
        });
    }

    public function down(): void
    {
        Schema::table('verifactu_registries', function (Blueprint $table) {
            $table->dropForeign(['amends_registry_id']);
            $table->dropUnique('verifactu_registries_amends_unique');
        });

        Schema::table('verifactu_registries', function (Blueprint $table) {
            $table->dropColumn(['subsanacion', 'rechazo_previo', 'amends_registry_id']);
        });
    }
};
