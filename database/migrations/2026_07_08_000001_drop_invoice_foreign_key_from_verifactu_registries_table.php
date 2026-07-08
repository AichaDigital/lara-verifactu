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
     * Drops the hardcoded FK from `invoice_id` to `verifactu_invoices` so a
     * custom-mode consumer's own invoice model/table isn't rejected by the
     * constraint on Verifactu::register() (AID-344). The column and its
     * index are kept — only the physical FK constraint is removed.
     *
     * Native mode is unaffected: the soft-delete cascade to `registry` is
     * already handled at the application level by Invoice::boot()'s
     * `deleting` event, independent of this DB constraint.
     */
    public function up(): void
    {
        Schema::table('verifactu_registries', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
        });
    }

    /**
     * Schema-level rollback is intentionally NOT provided.
     *
     * Re-adding the FK would make MySQL/MariaDB validate every existing
     * `invoice_id` against `verifactu_invoices`, which fails as soon as a
     * single custom-mode registry (any table other than the native one)
     * exists — exactly the capability this migration exists to allow. Unlike
     * the column-drop migrations elsewhere in this package (which restore a
     * defaulted column, safe against any existing data), a constraint add is
     * not safely reversible once custom-mode data exists. If this feature is
     * ever removed, do it via a dedicated migration written with knowledge
     * of the actual data at that time, not a blind down().
     */
    public function down(): void
    {
        // Intentionally no-op — see the docblock above.
    }
};
