<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The two forms a pre-1.4 ParcialmenteCorrecto answer was stored in: the
     * `array` cast JSON-encodes the message, a hand-written row may not.
     */
    private const LEGACY_FORMS = ['"ParcialmenteCorrecto"', 'ParcialmenteCorrecto'];

    /**
     * Give pre-1.4 accepted-with-errors records their own outcome (AID-1626).
     *
     * Until 1.4 a ParcialmenteCorrecto answer was persisted as `sent` with the
     * message as aeat_response, and the agency's lines were discarded. Those
     * lines cannot be recovered, so `lineas` is null — meaning «unknown», not
     * «no errors».
     *
     * Query builder on purpose: no model event and no RegistryStatusChangedEvent
     * fire for migrated rows; UPGRADE-1.4.md gives the reconciliation query.
     * `error` rows are not touched: `verifactu:retry-failed` sweeps them with
     * the consumer's own budget. A legacy duplicate answered AceptadaConErrores
     * cannot be told from Correcta in the stored text, so it stays `accepted`.
     */
    public function up(): void
    {
        DB::table('verifactu_registries')
            ->where('status', 'sent')
            ->whereIn('aeat_response', self::LEGACY_FORMS)
            ->update([
                'status' => 'accepted_with_errors',
                'aeat_response' => json_encode(['estado_envio' => 'ParcialmenteCorrecto', 'lineas' => null]),
            ]);
    }

    /**
     * Back to what a 1.3.x install can load.
     *
     * - `accepted_with_errors` becomes `sent` with the message in its cast
     *   form. This includes the rows 1.4 itself wrote for a duplicate answered
     *   AceptadaConErrores, which were `accepted` in 1.3: they revert to
     *   `sent`, not to `accepted`. Lines persisted by 1.4 are dropped.
     * - `exhausted` becomes `error`: the 1.3 enum has no such case and would
     *   throw a ValueError on load. `aeat_error` and `submission_attempts` are
     *   kept, so after a downgrade the 1.3 retry query picks the row up again
     *   only if its attempts are under its own limit.
     */
    public function down(): void
    {
        DB::table('verifactu_registries')
            ->where('status', 'accepted_with_errors')
            ->update([
                'status' => 'sent',
                'aeat_response' => json_encode('ParcialmenteCorrecto'),
            ]);

        DB::table('verifactu_registries')
            ->where('status', 'exhausted')
            ->update(['status' => 'error']);
    }
};
