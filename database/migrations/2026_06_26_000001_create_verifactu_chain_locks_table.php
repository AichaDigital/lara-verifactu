<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the chain-lock sentinel table (AID-258).
     *
     * One row per fingerprint chain. Today there is a single 'global' chain:
     * the issuer is a per-installation config value (verifactu.company.tax_id)
     * and registries carry no issuer column, so the chain is global to the
     * instance. createRegistry()/createCancellationRegistry() take an exclusive
     * FOR UPDATE lock on the chain's sentinel row before reading the chain head
     * and inserting, so concurrent creations serialize and cannot fork the
     * chain. A dedicated sentinel row (rather than locking the head row) also
     * covers the empty-chain case, where there is no head row to lock.
     */
    public function up(): void
    {
        Schema::create('verifactu_chain_locks', function (Blueprint $table) {
            $table->id();
            $table->string('scope')->unique();
            $table->timestamps();
        });

        DB::table('verifactu_chain_locks')->insert([
            'scope' => 'global',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('verifactu_chain_locks');
    }
};
