<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Consolidates issue_date (DATE) and issue_time (TIME) into a single
     * issue_datetime (TIMESTAMP) column for proper chronological ordering.
     *
     * This is required for Verifactu compliance where invoice sequence
     * validation depends on precise temporal ordering.
     */
    public function up(): void
    {
        Schema::table('verifactu_invoices', function (Blueprint $table) {
            $table->timestamp('issue_datetime')
                ->after('number')
                ->nullable()
                ->index()
                ->comment('Combined issue date and time for chronological ordering');
        });

        // Migrate existing data: combine issue_date + issue_time into issue_datetime
        // Use driver-specific SQL for compatibility (MySQL uses TIMESTAMP(), SQLite uses concatenation)
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            DB::statement("
                UPDATE verifactu_invoices
                SET issue_datetime = issue_date || ' ' || issue_time
                WHERE issue_date IS NOT NULL
            ");
        } else {
            DB::statement('
                UPDATE verifactu_invoices
                SET issue_datetime = TIMESTAMP(issue_date, issue_time)
                WHERE issue_date IS NOT NULL
            ');
        }

        // Make issue_datetime NOT NULL after migration
        Schema::table('verifactu_invoices', function (Blueprint $table) {
            $table->timestamp('issue_datetime')->nullable(false)->change();
        });

        // Drop old columns and their indexes
        Schema::table('verifactu_invoices', function (Blueprint $table) {
            $table->dropIndex(['issue_date']);
            $table->dropColumn(['issue_date', 'issue_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('verifactu_invoices', function (Blueprint $table) {
            $table->date('issue_date')->after('number')->nullable();
            $table->time('issue_time')->after('issue_date')->nullable();
        });

        // Restore data from issue_datetime (DATE() and TIME() work in both MySQL and SQLite)
        DB::statement('
            UPDATE verifactu_invoices
            SET issue_date = DATE(issue_datetime),
                issue_time = TIME(issue_datetime)
            WHERE issue_datetime IS NOT NULL
        ');

        // Make columns NOT NULL and add index
        Schema::table('verifactu_invoices', function (Blueprint $table) {
            $table->date('issue_date')->nullable(false)->index()->change();
            $table->time('issue_time')->nullable(false)->change();
        });

        // Drop issue_datetime
        Schema::table('verifactu_invoices', function (Blueprint $table) {
            $table->dropIndex(['issue_datetime']);
            $table->dropColumn('issue_datetime');
        });
    }
};
