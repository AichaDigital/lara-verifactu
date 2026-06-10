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
     * Cancellation records (RegistroAnulacion) are links of the fingerprint
     * chain in their own right, so they are persisted as registries with
     * their own hash. registry_type distinguishes them from registrations.
     */
    public function up(): void
    {
        Schema::table('verifactu_registries', function (Blueprint $table) {
            $table->string('registry_type', 20)->default('registration')->after('registry_date')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('verifactu_registries', function (Blueprint $table) {
            $table->dropIndex(['registry_type']);
        });

        Schema::table('verifactu_registries', function (Blueprint $table) {
            $table->dropColumn('registry_type');
        });
    }
};
