# Typed Submission Outcomes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every Verifactu registry carries one outcome from a closed set (`pending`, `sent`/`accepted`, `accepted_with_errors`, `rejected`, `error`, `exhausted`), every real change is announced by one typed event, `verifactu:retry-failed` resends stale `pending` rows, and exhaustion becomes a persisted state with a one-time signal (AID-1626).

**Architecture:** The enum gains two cases and an `isRegistered()` helper. The parser alone decides «accepted with errors» and flags it on `AeatResponse`. `RegistryManager` writers announce each real transition after commit through a shared quiet dispatcher. `markAsFailed()` crosses into `exhausted` under the row lock using a budget. The retry pass gains a new selection query (stale `pending`, `error` under budget, optional `exhausted`) and an over-budget sweep. A data migration converts legacy `ParcialmenteCorrecto` rows.

**Tech Stack:** PHP ^8.3, Laravel 12/13 (Spatie package tools), Pest 4, MariaDB/MySQL, Orchestra Testbench.

**Spec:** `docs/superpowers/specs/2026-10-13-typed-submission-outcomes-design.md` (read it before any task).

## Global Constraints

- Compatible within `1.x` (CONSTITUTION §8): no public method, class, signature or semantics is removed or renamed; new parameters are trailing, optional and defaulted; widened types only.
- `isPending()` and `isSuccessful()` on `RegistryStatusEnum` keep their exact current answers; they only gain `@deprecated`.
- New status column values, exactly: `accepted_with_errors`, `exhausted`.
- Config defaults live at the read point: `config('verifactu.retry.max_submission_attempts', 3)` and `config('verifactu.retry.pending_grace', 300)`.
- `verifactu.retry.max_attempts` keeps meaning the queue-level `$tries` of `SubmitRegistryToAeatJob`.
- `aeat_csv` is stored as `null`, never `''` (UNIQUE index).
- New events are dispatched after commit (`DB::afterCommit`), only on a real change of `status`, and quietly (a throwing listener is logged, never propagated).
- The new migration must be listed in `LaraVerifactuServiceProvider::hasMigrations()`.
- Code, comments, docblocks and commit messages in English. Conventional commits with the ticket: `feat: ... (AID-1626)`, ending with the trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Run every command from the package root `~/development/packages/aichadigital/lara-verifactu`. Feature tests need the local MariaDB from `tests/TestCase.php` (`verifactu_test`).
- Pest file-level functions live in the global namespace: any helper function a test file declares must carry a unique prefix, checked with `rg -n "function <name>\(" tests` before committing.
- Laravel-idiomatic code (umbrella `CLAUDE.md`): facades over raw PHP, Eloquent over `DB::` in domain logic (the migration and the sweep's locked read are the exceptions already used by this package).

## Review Focus

- **`--max-attempts=0` or a non-numeric value:** an operator typo must fail loud and touch nothing, never sweep every `error` row to `exhausted`. Pinned in Task 5 (`submissionBudget()` throws) and Task 6 (command test).
- **A consumer whose published `config/verifactu.php` predates this release:** the keys `max_submission_attempts` and `pending_grace` are absent; the package must behave as 3 and 300. Pinned in Task 5 and Task 6.
- **A consumer listener for the new events that throws:** the persisted outcome stands and no exception reaches the caller. Pinned in Task 3.
- **A caller that wraps `register(..., submitToAeat: false)` in its own transaction and rolls back:** no phantom `null → pending` event. Pinned in Task 3.
- **A row exhausted on the queued-job path and later retried with `--include-exhausted` that fails again:** it stays `exhausted` and no second exhaustion event fires. Pinned in Task 6.

---

### Task 1: Enum outcomes, helpers and status counts

**Files:**

- Modify: `src/Enums/RegistryStatusEnum.php`
- Modify: `src/Commands/StatusCommand.php:72-90`
- Create: `tests/Unit/Enums/RegistryStatusEnumTest.php`
- Create: `tests/Feature/TypedOutcomeSealTest.php`
- Modify: `tests/Feature/CommandsTest.php` (append)

**Interfaces:**

- Produces: `RegistryStatusEnum::ACCEPTED_WITH_ERRORS` (`'accepted_with_errors'`), `RegistryStatusEnum::EXHAUSTED` (`'exhausted'`), `RegistryStatusEnum::isRegistered(): bool`. `isFiledAtAeat()`, `hasAgencyVerdict()`, `isFinal()` include `ACCEPTED_WITH_ERRORS`; `agencyVerdictValues()` therefore returns it.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Enums/RegistryStatusEnumTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;

/**
 * AID-1626 — the full helper matrix, one row per case.
 *
 * Pinned as a whole so a new case cannot slip in without its answer to every
 * helper being decided, and so the deprecated isPending()/isSuccessful() are
 * proven to keep their 1.x semantics (CONSTITUTION §8).
 */
it('answers every helper for every case', function (RegistryStatusEnum $status, array $expected) {
    expect([
        'isFinal' => $status->isFinal(),
        'isPending' => $status->isPending(),
        'isSuccessful' => $status->isSuccessful(),
        'isFiledAtAeat' => $status->isFiledAtAeat(),
        'hasAgencyVerdict' => $status->hasAgencyVerdict(),
        'canRetry' => $status->canRetry(),
        'isRegistered' => $status->isRegistered(),
    ])->toBe($expected);
})->with([
    'pending' => [RegistryStatusEnum::PENDING, ['isFinal' => false, 'isPending' => true, 'isSuccessful' => false, 'isFiledAtAeat' => false, 'hasAgencyVerdict' => false, 'canRetry' => true, 'isRegistered' => false]],
    'sent' => [RegistryStatusEnum::SENT, ['isFinal' => false, 'isPending' => true, 'isSuccessful' => false, 'isFiledAtAeat' => true, 'hasAgencyVerdict' => true, 'canRetry' => false, 'isRegistered' => true]],
    'accepted' => [RegistryStatusEnum::ACCEPTED, ['isFinal' => true, 'isPending' => false, 'isSuccessful' => true, 'isFiledAtAeat' => true, 'hasAgencyVerdict' => true, 'canRetry' => false, 'isRegistered' => true]],
    'accepted_with_errors' => [RegistryStatusEnum::ACCEPTED_WITH_ERRORS, ['isFinal' => true, 'isPending' => false, 'isSuccessful' => false, 'isFiledAtAeat' => true, 'hasAgencyVerdict' => true, 'canRetry' => false, 'isRegistered' => true]],
    'rejected' => [RegistryStatusEnum::REJECTED, ['isFinal' => true, 'isPending' => false, 'isSuccessful' => false, 'isFiledAtAeat' => false, 'hasAgencyVerdict' => true, 'canRetry' => false, 'isRegistered' => false]],
    'error' => [RegistryStatusEnum::ERROR, ['isFinal' => false, 'isPending' => false, 'isSuccessful' => false, 'isFiledAtAeat' => false, 'hasAgencyVerdict' => false, 'canRetry' => true, 'isRegistered' => false]],
    'exhausted' => [RegistryStatusEnum::EXHAUSTED, ['isFinal' => false, 'isPending' => false, 'isSuccessful' => false, 'isFiledAtAeat' => false, 'hasAgencyVerdict' => false, 'canRetry' => false, 'isRegistered' => false]],
]);

it('has a matrix row for every case', function () {
    expect(RegistryStatusEnum::cases())->toHaveCount(7);
});

it('stores the new outcomes under stable column values', function () {
    expect(RegistryStatusEnum::ACCEPTED_WITH_ERRORS->value)->toBe('accepted_with_errors')
        ->and(RegistryStatusEnum::EXHAUSTED->value)->toBe('exhausted')
        ->and(RegistryStatusEnum::ACCEPTED_WITH_ERRORS->getDescription())->toBe('Aceptado con errores por AEAT')
        ->and(RegistryStatusEnum::EXHAUSTED->getDescription())->toBe('Reintentos agotados');
});

it('lists accepted_with_errors, and not exhausted, among the agency verdict values', function () {
    expect(RegistryStatusEnum::agencyVerdictValues())
        ->toContain('accepted_with_errors')
        ->not->toContain('exhausted');
});
```

`tests/Feature/TypedOutcomeSealTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Registry;

/**
 * AID-1626 — an accepted-with-errors record is a verdict: the agency
 * registered it, so it is sealed like SENT/ACCEPTED (AID-220). An exhausted
 * record is not a verdict: nothing was filed, so it behaves like ERROR.
 */
it('refuses to delete a registry accepted with errors', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ACCEPTED_WITH_ERRORS]);

    expect(fn () => $registry->delete())->toThrow(VerifactuException::class, 'cannot be deleted');
});

it('lets an exhausted registry be deleted, like an error one', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::EXHAUSTED]);

    $registry->delete();

    expect(Registry::find($registry->id))->toBeNull();
});
```

Append to `tests/Feature/CommandsTest.php` (add `use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;`, `use AichaDigital\LaraVerifactu\Models\Registry;` and `use Illuminate\Support\Facades\Artisan;` to its imports):

```php
it('counts accepted-with-errors and exhausted registries in verifactu:status', function () {
    config()->set('verifactu.certificate.path', '/nonexistent/certificate.p12');

    Registry::factory()->create(['status' => RegistryStatusEnum::ACCEPTED_WITH_ERRORS]);
    Registry::factory()->count(2)->create(['status' => RegistryStatusEnum::EXHAUSTED]);

    Artisan::call('verifactu:status');
    $output = Artisan::output();

    expect($output)->toMatch('/Accepted With Errors Registries\s*\|\s*1\s/')
        ->toMatch('/Exhausted Registries\s*\|\s*2\s/');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Unit/Enums/RegistryStatusEnumTest.php tests/Feature/TypedOutcomeSealTest.php tests/Feature/CommandsTest.php`
Expected: FAIL — `Undefined constant ... ACCEPTED_WITH_ERRORS`.

- [ ] **Step 3: Implement the enum**

In `src/Enums/RegistryStatusEnum.php` add the cases after `ERROR`, extend `getDescription()`, the helpers, and the deprecations. Keep the existing docblocks of `isFiledAtAeat()` / `hasAgencyVerdict()` and add one sentence each about `ACCEPTED_WITH_ERRORS`:

```php
    case ERROR = 'error';
    case ACCEPTED_WITH_ERRORS = 'accepted_with_errors';
    case EXHAUSTED = 'exhausted';

    public function getDescription(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente de envío',
            self::SENT => 'Enviado a AEAT',
            self::ACCEPTED => 'Aceptado por AEAT',
            self::REJECTED => 'Rechazado por AEAT',
            self::ERROR => 'Error en procesamiento',
            self::ACCEPTED_WITH_ERRORS => 'Aceptado con errores por AEAT',
            self::EXHAUSTED => 'Reintentos agotados',
        };
    }

    /**
     * EXHAUSTED is deliberately not final: `verifactu:retry-failed
     * --include-exhausted` can give it one more attempt (AID-1626).
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::ACCEPTED, self::REJECTED, self::ACCEPTED_WITH_ERRORS], true);
    }

    /**
     * @deprecated Since 1.4: it reports a registered `Correcto` record (SENT) as
     *             pending. Compare against PENDING, or use isRegistered().
     *             Kept unchanged for the 1.x line (CONSTITUTION §8).
     */
    public function isPending(): bool
    {
        return in_array($this, [self::PENDING, self::SENT]);
    }

    /**
     * @deprecated Since 1.4: it misses a plain `Correcto` answer (SENT) and an
     *             accepted-with-errors one. Use isRegistered(). Kept unchanged
     *             for the 1.x line (CONSTITUTION §8).
     */
    public function isSuccessful(): bool
    {
        return $this === self::ACCEPTED;
    }

    /**
     * The agency holds this record as registered (AID-1626): `Correcto` (SENT),
     * a duplicate `Correcta` (ACCEPTED), or accepted with errors. This is the
     * helper a consumer should ask «is this invoice registered?».
     */
    public function isRegistered(): bool
    {
        return in_array($this, [self::SENT, self::ACCEPTED, self::ACCEPTED_WITH_ERRORS], true);
    }
```

`isFiledAtAeat()` returns `in_array($this, [self::SENT, self::ACCEPTED, self::ACCEPTED_WITH_ERRORS], true)`; add to its docblock: «ACCEPTED_WITH_ERRORS is filed too: AEAT registered it with warnings, so re-sending it would duplicate it (AID-1626).» `hasAgencyVerdict()` returns `in_array($this, [self::SENT, self::ACCEPTED, self::ACCEPTED_WITH_ERRORS, self::REJECTED], true)`; add: «EXHAUSTED is not a verdict: the agency never ruled, it behaves like ERROR (AID-1626).» `canRetry()` is unchanged.

- [ ] **Step 4: Count the new statuses in `verifactu:status`**

In `src/Commands/StatusCommand.php::displayStatistics()`, after `$errorRegistries`:

```php
        $acceptedWithErrorsRegistries = Registry::where('status', RegistryStatusEnum::ACCEPTED_WITH_ERRORS->value)->count();
        $exhaustedRegistries = Registry::where('status', RegistryStatusEnum::EXHAUSTED->value)->count();
```

and append to `$stats` after the `Error Registries` row:

```php
            ['Accepted With Errors Registries', $acceptedWithErrorsRegistries],
            ['Exhausted Registries', $exhaustedRegistries],
```

- [ ] **Step 5: Run the tests and the existing enum suite**

Run: `vendor/bin/pest tests/Unit/Enums/RegistryStatusEnumTest.php tests/Unit/EnumsTest.php tests/Feature/TypedOutcomeSealTest.php tests/Feature/CommandsTest.php tests/Feature/RegistryTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Enums/RegistryStatusEnum.php src/Commands/StatusCommand.php tests/Unit/Enums/RegistryStatusEnumTest.php tests/Feature/TypedOutcomeSealTest.php tests/Feature/CommandsTest.php
git commit -m "feat: add accepted-with-errors and exhausted registry outcomes (AID-1626)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The parser decides «accepted with errors»

**Files:**

- Modify: `src/Support/AeatResponse.php`
- Modify: `src/Services/AeatResponseParser.php:23-60` (accepted path), `:62-80` (duplicate path), constants
- Test: `tests/Unit/AeatResponseParserTest.php`, `tests/Unit/AeatResponseTest.php`

**Interfaces:**

- Produces: `AeatResponse::isAcceptedWithErrors(): bool`; constructor trailing param `bool $acceptedWithErrors = false`; factory `AeatResponse::acceptedWithErrors(?string $csv = null, ?string $message = null, ?array $data = null, ?array $errors = null): self` (success + flag); `AeatResponse::duplicate(..., bool $acceptedWithErrors = false)`; `toArray()` gains key `accepted_with_errors`. On every accepted and duplicate answer, `getData()['lineas']` is the list of line details shaped `{estado_registro, codigo, descripcion, registro_duplicado, registro_duplicado_estado}`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/AeatResponseParserTest.php`:

```php
it('flags a ParcialmenteCorrecto envelope as accepted with errors and keeps its lines', function () {
    $result = $this->parser->parse((object) [
        'CSV' => 'CSV0987654321',
        'EstadoEnvio' => 'ParcialmenteCorrecto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'AceptadoConErrores',
            'CodigoErrorRegistro' => '2003',
            'DescripcionErrorRegistro' => 'Huella incorrecta',
        ],
    ]);

    expect($result->isAcceptedWithErrors())->toBeTrue()
        ->and($result->isSuccess())->toBeTrue()
        ->and($result->isDuplicate())->toBeFalse()
        ->and($result->getData()['lineas'])->toBe([[
            'estado_registro' => 'AceptadoConErrores',
            'codigo' => '2003',
            'descripcion' => 'Huella incorrecta',
            'registro_duplicado' => false,
            'registro_duplicado_estado' => null,
        ]]);
});

it('flags a single AceptadoConErrores line under a Correcto envelope', function () {
    $result = $this->parser->parse((object) [
        'CSV' => 'CSV1111111111',
        'EstadoEnvio' => 'Correcto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'AceptadoConErrores',
            'CodigoErrorRegistro' => '1142',
            'DescripcionErrorRegistro' => 'CuotaRepercutida fuera de tolerancia',
        ],
    ]);

    expect($result->isAcceptedWithErrors())->toBeTrue()
        ->and($result->isSuccess())->toBeTrue();
});

it('does not flag a plain Correcto answer, but still returns its lines', function () {
    $result = $this->parser->parse((object) [
        'CSV' => 'CSV2222222222',
        'EstadoEnvio' => 'Correcto',
        'RespuestaLinea' => (object) ['EstadoRegistro' => 'Correcto'],
    ]);

    expect($result->isAcceptedWithErrors())->toBeFalse()
        ->and($result->isSuccess())->toBeTrue()
        ->and($result->getData()['lineas'])->toBe([[
            'estado_registro' => 'Correcto',
            'codigo' => null,
            'descripcion' => null,
            'registro_duplicado' => false,
            'registro_duplicado_estado' => null,
        ]]);
});

it('flags a duplicate of an accepted-with-errors record as accepted with errors', function () {
    // L21 spells the duplicate state `AceptadaConErrores` (feminine), unlike
    // the L19 line state `AceptadoConErrores`.
    $result = $this->parser->parse((object) [
        'EstadoEnvio' => 'Incorrecto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'Incorrecto',
            'CodigoErrorRegistro' => '3000',
            'DescripcionErrorRegistro' => 'Registro de facturación duplicado.',
            'RegistroDuplicado' => (object) ['EstadoRegistroDuplicado' => 'AceptadaConErrores'],
        ],
    ]);

    expect($result->isDuplicate())->toBeTrue()
        ->and($result->isAcceptedWithErrors())->toBeTrue()
        ->and($result->isSuccess())->toBeTrue();
});

it('does not flag a duplicate of a Correcta record', function () {
    $result = $this->parser->parse((object) [
        'EstadoEnvio' => 'Incorrecto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'Incorrecto',
            'CodigoErrorRegistro' => '3000',
            'DescripcionErrorRegistro' => 'Registro de facturación duplicado.',
            'RegistroDuplicado' => (object) ['EstadoRegistroDuplicado' => 'Correcta'],
        ],
    ]);

    expect($result->isDuplicate())->toBeTrue()
        ->and($result->isAcceptedWithErrors())->toBeFalse();
});

it('never flags a rejection as accepted with errors', function () {
    $result = $this->parser->parse((object) [
        'EstadoEnvio' => 'Incorrecto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'Incorrecto',
            'CodigoErrorRegistro' => '1100',
            'DescripcionErrorRegistro' => 'Valor no permitido',
        ],
    ]);

    expect($result->isValidationRejection())->toBeTrue()
        ->and($result->isAcceptedWithErrors())->toBeFalse();
});
```

Append to `tests/Unit/AeatResponseTest.php`:

```php
describe('acceptedWithErrors', function () {
    it('is a success carrying the flag and the CSV', function () {
        $response = AeatResponse::acceptedWithErrors(csv: 'CSV-AWE', message: 'ParcialmenteCorrecto');

        expect($response->isSuccess())->toBeTrue()
            ->and($response->isAcceptedWithErrors())->toBeTrue()
            ->and($response->isDuplicate())->toBeFalse()
            ->and($response->getCsv())->toBe('CSV-AWE');
    });

    it('can mark a duplicate as accepted with errors', function () {
        $response = AeatResponse::duplicate(csv: null, acceptedWithErrors: true);

        expect($response->isDuplicate())->toBeTrue()
            ->and($response->isAcceptedWithErrors())->toBeTrue();
    });

    it('defaults to false for every other construction', function () {
        expect((new AeatResponse(true))->isAcceptedWithErrors())->toBeFalse()
            ->and(AeatResponse::duplicate()->isAcceptedWithErrors())->toBeFalse()
            ->and(AeatResponse::rejection()->isAcceptedWithErrors())->toBeFalse();
    });

    it('exposes the flag in toArray()', function () {
        expect(AeatResponse::acceptedWithErrors()->toArray()['accepted_with_errors'])->toBeTrue();
    });
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Unit/AeatResponseParserTest.php tests/Unit/AeatResponseTest.php`
Expected: FAIL — `Call to undefined method ... isAcceptedWithErrors()`.

- [ ] **Step 3: Implement `AeatResponse`**

Add `@param  bool  $acceptedWithErrors  True when AEAT registered the record with warnings (ParcialmenteCorrecto / AceptadoConErrores / duplicate AceptadaConErrores, AID-1626).` to the constructor docblock and the trailing promoted parameter `protected bool $acceptedWithErrors = false,` after `$duplicate`. Add:

```php
    /**
     * AEAT registered the record with warnings (AID-1626). Also a success —
     * the record must never be re-sent — and, for a duplicate answer, also
     * isDuplicate().
     */
    public function isAcceptedWithErrors(): bool
    {
        return $this->acceptedWithErrors;
    }
```

Add `'accepted_with_errors' => $this->acceptedWithErrors,` as the last key of `toArray()`. Add the factory:

```php
    /**
     * AEAT registered the record but reported errors that do not cause its
     * rejection (AID-1626; L19 «se registra en el sistema»). The CSV stays null
     * when the answer carries none, never '' (UNIQUE index on `aeat_csv`).
     *
     * @param  array<string, mixed>|null  $data
     * @param  array<int, string>|null  $errors
     */
    public static function acceptedWithErrors(
        ?string $csv = null,
        ?string $message = null,
        ?array $data = null,
        ?array $errors = null,
    ): self {
        return new self(
            success: true,
            code: $csv,
            message: $message,
            data: $data,
            errors: $errors,
            acceptedWithErrors: true,
        );
    }
```

Give `duplicate()` a trailing `bool $acceptedWithErrors = false,` parameter and pass `acceptedWithErrors: $acceptedWithErrors` to `new self(...)`.

- [ ] **Step 4: Implement the parser decision**

In `src/Services/AeatResponseParser.php` add constants next to the existing ones:

```php
    /** L19 line state of a record registered with warnings (masculine). */
    private const LINE_ACCEPTED_WITH_ERRORS = 'AceptadoConErrores';

    /** L21 duplicate state of a record registered with warnings (feminine). */
    private const DUPLICATE_ACCEPTED_WITH_ERRORS = 'AceptadaConErrores';
```

In `parse()`, compute `$lineDetails = $this->collectLineDetails($response);` right after `$lineErrors` (remove the later duplicate assignment), and replace the accepted branch:

```php
        if ($accepted) {
            $message = $submissionStatus ?? self::STATUS_CORRECT;
            $data = [
                'csv' => $csv,
                'estado_envio' => $submissionStatus,
                'timestamp' => $this->presentationTimestamp($response),
                'lineas' => $lineDetails,
            ];
            $errors = $lineErrors === [] ? null : $lineErrors;

            // AID-1626: the parser is the only place that decides «with errors».
            if ($this->isAcceptedWithErrors($submissionStatus, $lineDetails)) {
                return AeatResponse::acceptedWithErrors(csv: $csv, message: $message, data: $data, errors: $errors);
            }

            return new AeatResponse(success: true, code: $csv, message: $message, data: $data, errors: $errors);
        }
```

In the duplicate branch pass `acceptedWithErrors: $this->isDuplicateAcceptedWithErrors($lineDetails),` to `AeatResponse::duplicate(...)`. Add the two helpers:

```php
    /**
     * Registered with warnings (AID-1626): a ParcialmenteCorrecto envelope, or
     * any line in AceptadoConErrores under any accepted envelope.
     *
     * @param  array<int, array{estado_registro: ?string, codigo: ?string, descripcion: ?string, registro_duplicado: bool, registro_duplicado_estado: ?string}>  $lineDetails
     */
    private function isAcceptedWithErrors(?string $submissionStatus, array $lineDetails): bool
    {
        if ($submissionStatus === self::STATUS_PARTIALLY_CORRECT) {
            return true;
        }

        foreach ($lineDetails as $line) {
            if ($line['estado_registro'] === self::LINE_ACCEPTED_WITH_ERRORS) {
                return true;
            }
        }

        return false;
    }

    /**
     * A duplicate answer whose filed original was accepted with errors (L21
     * `AceptadaConErrores`, feminine — never the L19 constant).
     *
     * @param  array<int, array{estado_registro: ?string, codigo: ?string, descripcion: ?string, registro_duplicado: bool, registro_duplicado_estado: ?string}>  $lineDetails
     */
    private function isDuplicateAcceptedWithErrors(array $lineDetails): bool
    {
        foreach ($lineDetails as $line) {
            if ($line['registro_duplicado_estado'] === self::DUPLICATE_ACCEPTED_WITH_ERRORS) {
                return true;
            }
        }

        return false;
    }
```

Update the class docblock line «AceptadoConErrores means AEAT REGISTERED the record (do not resubmit), so it maps to a successful response carrying the error details.» to add «flagged isAcceptedWithErrors() (AID-1626)». Replace the literal `'AceptadaConErrores'` inside `DUPLICATE_STATES_FILED` with `self::DUPLICATE_ACCEPTED_WITH_ERRORS`.

- [ ] **Step 5: Extend the two pinned existing tests**

In `tests/Unit/AeatResponseParserTest.php`, test «parses a record accepted with errors as success carrying the error details»: append `->and($result->isAcceptedWithErrors())->toBeTrue()`. Test «parses a duplicate of an accepted-with-errors record as already filed»: append `->and($result->isAcceptedWithErrors())->toBeTrue()`.

- [ ] **Step 6: Run the unit suite**

Run: `vendor/bin/pest tests/Unit`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Support/AeatResponse.php src/Services/AeatResponseParser.php tests/Unit/AeatResponseParserTest.php tests/Unit/AeatResponseTest.php
git commit -m "feat: flag accepted-with-errors answers in the AEAT response parser (AID-1626)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Typed transition events, after commit and quiet

**Files:**

- Create: `src/Events/RegistryStatusChangedEvent.php`
- Create: `src/Events/RegistryRetriesExhaustedEvent.php`
- Create: `src/Support/DispatchesQuietly.php`
- Modify: `src/Services/InvoiceRegistrar.php` (move `dispatchQuietly()` into the trait)
- Modify: `src/Services/RegistryManager.php` (`createRegistry`, `createCancellationRegistry`, `markAsSubmitted`, `markAsAccepted`, `markAsFailed`, `markAsRejected`, new private `announceTransition`, `linesOf`)
- Create: `tests/Feature/RegistryStatusChangedEventTest.php`
- Modify: `tests/Feature/ListenerFailureIsolationTest.php` (append)

**Interfaces:**

- Consumes: `RegistryStatusEnum` (Task 1).
- Produces:
  - `RegistryStatusChangedEvent(RegistryContract $registry, ?RegistryStatusEnum $from, RegistryStatusEnum $to, ?string $error, ?array $lines)` (public readonly properties).
  - `RegistryRetriesExhaustedEvent(RegistryContract $registry, string $lastError, int $attempts)`.
  - Trait `AichaDigital\LaraVerifactu\Support\DispatchesQuietly` with `private function dispatchQuietly(object $event, RegistryContract $registry): void`.
  - `RegistryManager::announceTransition(Registry $registry, ?RegistryStatusEnum $from, ?string $error = null, ?array $lines = null): void` (private). Fires nothing when `$from === $registry->status`; fires `RegistryRetriesExhaustedEvent` too when the new status is `EXHAUSTED`. Tasks 4–6 call it.
  - `RegistryManager::linesOf(?array $aeatResponse): ?array` (private).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/RegistryStatusChangedEventTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\RegistryRetriesExhaustedEvent;
use AichaDigital\LaraVerifactu\Events\RegistryStatusChangedEvent;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * AID-1626 — one typed event per real change of a registry's outcome, after
 * commit, so a consumer needs one listener and never parses text.
 */
beforeEach(function () {
    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
    config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
    config()->set('verifactu.system.vendor_nif', 'B70123456');
    config()->set('verifactu.system.name', 'LaraVerifactu');
    config()->set('verifactu.system.id', 'LV');
    config()->set('verifactu.system.version', '1.0');
    config()->set('verifactu.system.installation_number', '1');

    $qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
    $qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generatePng')->andReturn('png-binary');

    $this->manager = new RegistryManager(new HashGenerator, $qrGenerator, new XmlBuilder);

    Event::fake([RegistryStatusChangedEvent::class, RegistryRetriesExhaustedEvent::class]);
});

it('announces the creation of a registry as null to pending', function () {
    $registry = $this->manager->createRegistry(Invoice::factory()->create());

    Event::assertDispatchedTimes(RegistryStatusChangedEvent::class, 1);
    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->registry->getId() === $registry->getId()
        && $event->from === null
        && $event->to === RegistryStatusEnum::PENDING
        && $event->error === null
        && $event->lines === null);
});

it('announces a first failure as pending to error, with the reason', function () {
    $registry = Registry::factory()->create();

    $this->manager->markAsFailed($registry, 'connection reset');

    Event::assertDispatchedTimes(RegistryStatusChangedEvent::class, 1);
    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->from === RegistryStatusEnum::PENDING
        && $event->to === RegistryStatusEnum::ERROR
        && $event->error === 'connection reset'
        && $event->lines === null);
});

it('stays silent when a further failure leaves the outcome unchanged', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 1]);

    $this->manager->markAsFailed($registry, 'connection reset again');

    Event::assertNotDispatched(RegistryStatusChangedEvent::class);
});

it('stays silent when an idempotency guard cuts the write', function () {
    $registry = Registry::factory()->submitted()->create();

    $this->manager->markAsSubmitted($registry, 'OTHER-CSV', 'Correcto');
    $this->manager->markAsFailed($registry, 'late failure');

    Event::assertNotDispatched(RegistryStatusChangedEvent::class);
});

it('announces a registration with no error and no lines', function () {
    $registry = Registry::factory()->create();

    $this->manager->markAsSubmitted($registry, 'CSV-SENT', 'Correcto');

    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->from === RegistryStatusEnum::PENDING
        && $event->to === RegistryStatusEnum::SENT
        && $event->error === null
        && $event->lines === null);
});

it('announces a duplicate reconciliation as error to accepted', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 1]);

    $this->manager->markAsAccepted($registry, null, 'Registro duplicado');

    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->from === RegistryStatusEnum::ERROR
        && $event->to === RegistryStatusEnum::ACCEPTED);
});

it('carries the agency lines on a rejection', function () {
    $registry = Registry::factory()->create();
    $lines = [[
        'estado_registro' => 'Incorrecto',
        'codigo' => '1100',
        'descripcion' => 'Valor no permitido',
        'registro_duplicado' => false,
        'registro_duplicado_estado' => null,
    ]];

    $this->manager->markAsRejected($registry, '1100: Valor no permitido', ['estado_envio' => 'Incorrecto', 'lineas' => $lines]);

    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->to === RegistryStatusEnum::REJECTED
        && $event->error === '1100: Valor no permitido'
        && $event->lines === $lines);
});

it('waits for the surrounding transaction to commit', function () {
    $registry = Registry::factory()->create();

    DB::transaction(function () use ($registry): void {
        $this->manager->markAsFailed($registry, 'connection reset');

        Event::assertNotDispatched(RegistryStatusChangedEvent::class);
    });

    Event::assertDispatchedTimes(RegistryStatusChangedEvent::class, 1);
});

it('announces nothing when the surrounding transaction rolls back', function () {
    $registry = Registry::factory()->create();

    try {
        DB::transaction(function () use ($registry): void {
            $this->manager->markAsFailed($registry, 'connection reset');

            throw new RuntimeException('the caller rolls back');
        });
    } catch (RuntimeException) {
        // expected
    }

    Event::assertNotDispatched(RegistryStatusChangedEvent::class);
    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::PENDING);
});

it('announces no creation for a registry made in a transaction the caller rolls back', function () {
    $invoice = Invoice::factory()->create();

    try {
        DB::transaction(function () use ($invoice): void {
            $this->manager->createRegistry($invoice);

            throw new RuntimeException('the caller rolls back');
        });
    } catch (RuntimeException) {
        // expected
    }

    Event::assertNotDispatched(RegistryStatusChangedEvent::class);
    expect(Registry::query()->where('invoice_id', $invoice->id)->exists())->toBeFalse();
});
```

Append to `tests/Feature/ListenerFailureIsolationTest.php` (add `use AichaDigital\LaraVerifactu\Events\RegistryStatusChangedEvent;`):

```php
it('keeps a persisted outcome when a RegistryStatusChangedEvent listener throws', function () {
    Event::listen(RegistryStatusChangedEvent::class, function (): void {
        throw new RuntimeException('consumer listener blew up');
    });

    $registry = Registry::factory()->create();

    $this->registryManager->markAsFailed($registry, 'connection reset');

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::ERROR);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Feature/RegistryStatusChangedEventTest.php tests/Feature/ListenerFailureIsolationTest.php`
Expected: FAIL — `Class "AichaDigital\LaraVerifactu\Events\RegistryStatusChangedEvent" not found`.

- [ ] **Step 3: Create the events**

`src/Events/RegistryStatusChangedEvent.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Events;

use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Registry Status Changed Event (AID-1626)
 *
 * Fired once per persisted change of a registry's outcome, after the write
 * commits, so one listener follows a registry's fiscal fate without parsing
 * messages. `$from` is null on creation. `$error` is the readable reason for
 * error, exhausted and rejected. `$lines` is the agency's per-line detail for
 * rejected and accepted_with_errors, in the AeatResponseParser shape
 * (estado_registro, codigo, descripcion, registro_duplicado,
 * registro_duplicado_estado); null when unknown or not applicable.
 */
class RegistryStatusChangedEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>|null  $lines
     */
    public function __construct(
        public readonly RegistryContract $registry,
        public readonly ?RegistryStatusEnum $from,
        public readonly RegistryStatusEnum $to,
        public readonly ?string $error,
        public readonly ?array $lines,
    ) {}
}
```

`src/Events/RegistryRetriesExhaustedEvent.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Events;

use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Registry Retries Exhausted Event (AID-1626)
 *
 * Fired once, when a registry crosses into EXHAUSTED: its submission budget is
 * spent and the package will not resend it on its own. Not fired again when an
 * already exhausted registry fails a manual extra attempt.
 */
class RegistryRetriesExhaustedEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly RegistryContract $registry,
        public readonly string $lastError,
        public readonly int $attempts,
    ) {}
}
```

- [ ] **Step 4: Extract the quiet dispatcher**

`src/Support/DispatchesQuietly.php` — move the method and its full docblock from `InvoiceRegistrar` (lines starting `Dispatch an outcome event without letting a consumer listener rewrite the outcome (AID-729).`) verbatim:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use Illuminate\Support\Facades\Log;

/**
 * Shared by InvoiceRegistrar and RegistryManager so the AID-729 guarantee —
 * a throwing listener never reclassifies an outcome — has one implementation
 * (AID-1626).
 */
trait DispatchesQuietly
{
    /**
     * (docblock moved verbatim from InvoiceRegistrar::dispatchQuietly())
     */
    private function dispatchQuietly(object $event, RegistryContract $registry): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            Log::channel(config('verifactu.logging.channel', 'single'))
                ->error('A listener threw while handling a Verifactu outcome event', [
                    'registry_number' => $registry->getRegistryNumber(),
                    'event' => $event::class,
                    'error' => AeatLogSanitizer::redactText($e->getMessage()),
                    ...AeatLogSanitizer::traceContext($e),
                ]);
        }
    }
}
```

In `InvoiceRegistrar`: delete the private `dispatchQuietly()` method, add `use DispatchesQuietly;` as the first line of the class body and `use AichaDigital\LaraVerifactu\Support\DispatchesQuietly;` to the imports. Call sites stay unchanged.

- [ ] **Step 5: Announce transitions from `RegistryManager`**

Add `use DispatchesQuietly;` to the class body and imports for `RegistryStatusChangedEvent`, `RegistryRetriesExhaustedEvent`, `DispatchesQuietly`. Add the two private methods (next to `lockAndRefresh()`):

```php
    /**
     * Announce a persisted change of outcome (AID-1626).
     *
     * Deferred with DB::afterCommit(): called inside the writer's transaction,
     * it fires only once the outermost transaction commits, and never if a
     * caller's surrounding transaction rolls back. Outside any transaction it
     * fires at once. Nothing fires when the status did not change — an
     * idempotency guard that cuts the write, or a failure that leaves ERROR in
     * ERROR. The values are captured now, not read at dispatch time.
     *
     * @param  array<int, array<string, mixed>>|null  $lines
     */
    private function announceTransition(
        Registry $registry,
        ?RegistryStatusEnum $from,
        ?string $error = null,
        ?array $lines = null,
    ): void {
        $to = $registry->status;

        if ($from === $to) {
            return;
        }

        $attempts = $registry->submission_attempts;

        DB::afterCommit(function () use ($registry, $from, $to, $error, $lines, $attempts): void {
            $this->dispatchQuietly(new RegistryStatusChangedEvent($registry, $from, $to, $error, $lines), $registry);

            // A change INTO exhausted is always the crossing: an exhausted row
            // that fails again keeps its status and never reaches here.
            if ($to === RegistryStatusEnum::EXHAUSTED) {
                $this->dispatchQuietly(
                    new RegistryRetriesExhaustedEvent($registry, $error ?? 'Retry budget exhausted', $attempts),
                    $registry,
                );
            }
        });
    }

    /**
     * The agency lines of a structured response, or null when unknown.
     *
     * @param  array<string, mixed>|null  $aeatResponse
     * @return array<int, array<string, mixed>>|null
     */
    private function linesOf(?array $aeatResponse): ?array
    {
        $lines = $aeatResponse['lineas'] ?? null;

        /** @var array<int, array<string, mixed>>|null */
        return is_array($lines) ? $lines : null;
    }
```

Wire the callers:

- `createRegistry()` and `createCancellationRegistry()`: after `event(new RegistryCreatedEvent($registry, $invoice));` add `$this->announceTransition($registry, null);`.
- `markAsSubmitted()` and `markAsAccepted()`: after the `isFiledAtAeat()` guard add `$from = $registry->status;`; after `$registry->update([...])` add `$this->announceTransition($registry, $from);`.
- `markAsFailed()`: after the `hasAgencyVerdict()` guard add `$from = $registry->status;`; after the update add `$this->announceTransition($registry, $from, error: $error);`.
- `markAsRejected()`: after the guard add `$from = $registry->status;`; after the update add `$this->announceTransition($registry, $from, error: $error, lines: $this->linesOf($aeatResponse));`.

- [ ] **Step 6: Run the new tests and the event-related suites**

Run: `vendor/bin/pest tests/Feature/RegistryStatusChangedEventTest.php tests/Feature/ListenerFailureIsolationTest.php tests/Feature/EventsTest.php tests/Feature/RegistryManagerTest.php tests/Feature/IdempotencyAndTransactionsTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Events/RegistryStatusChangedEvent.php src/Events/RegistryRetriesExhaustedEvent.php src/Support/DispatchesQuietly.php src/Services/InvoiceRegistrar.php src/Services/RegistryManager.php tests/Feature/RegistryStatusChangedEventTest.php tests/Feature/ListenerFailureIsolationTest.php
git commit -m "feat: announce every registry outcome change after commit (AID-1626)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Persist accepted-with-errors with its lines

**Files:**

- Modify: `src/Services/RegistryManager.php` (new `markAsAcceptedWithErrors()` after `markAsAccepted()`)
- Modify: `src/Services/InvoiceRegistrar.php` (`submitToAeat()` success branch, new private `structuredOutcome()`)
- Create: `tests/Feature/AcceptedWithErrorsTest.php`
- Modify: `tests/Feature/DuplicateReconciliationTest.php:175-197`

**Interfaces:**

- Consumes: `AeatResponse::isAcceptedWithErrors()` and `getData()['lineas']` (Task 2); `announceTransition()`, `linesOf()` (Task 3); `RegistryStatusEnum::ACCEPTED_WITH_ERRORS` (Task 1).
- Produces: `RegistryManager::markAsAcceptedWithErrors(RegistryContract $registry, ?string $aeatCsv, array $aeatResponse): void`. Persisted `aeat_response` shape: `['estado_envio' => ?string, 'csv' => ?string, 'lineas' => list<line>]`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AcceptedWithErrorsTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\RegistryStatusChangedEvent;
use AichaDigital\LaraVerifactu\Events\RegistrySubmittedEvent;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\AeatResponseParser;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use Illuminate\Support\Facades\Event;

/**
 * AID-1626 — ParcialmenteCorrecto / AceptadoConErrores used to be stored as
 * `sent` with aeat_response = "ParcialmenteCorrecto", discarding the per-line
 * codes. It is now its own outcome, persisted with the agency's lines.
 */
beforeEach(function () {
    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
    config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
    config()->set('verifactu.system.vendor_nif', 'B70123456');
    config()->set('verifactu.system.name', 'LaraVerifactu');
    config()->set('verifactu.system.id', 'LV');
    config()->set('verifactu.system.version', '1.0');
    config()->set('verifactu.system.installation_number', '1');

    $qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
    $qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generatePng')->andReturn('png-binary');

    $this->registryManager = new RegistryManager(new HashGenerator, $qrGenerator, new XmlBuilder);
    $this->aeatClient = Mockery::mock(AeatClientContract::class);

    $this->registrar = new InvoiceRegistrar(
        $this->registryManager,
        Mockery::mock(CertificateManagerContract::class),
        $this->aeatClient,
    );
});

it('persists a ParcialmenteCorrecto answer as accepted_with_errors, with its lines', function () {
    $invoice = Invoice::factory()->create();

    $this->aeatClient->shouldReceive('sendRegistration')->once()->andReturn((new AeatResponseParser)->parse((object) [
        'CSV' => 'CSV-AWE-0001',
        'EstadoEnvio' => 'ParcialmenteCorrecto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'AceptadoConErrores',
            'CodigoErrorRegistro' => '2003',
            'DescripcionErrorRegistro' => 'Huella incorrecta',
        ],
    ]));

    $this->registrar->register($invoice);

    $registry = Registry::query()->where('invoice_id', $invoice->id)->firstOrFail();

    expect($registry->status)->toBe(RegistryStatusEnum::ACCEPTED_WITH_ERRORS)
        ->and($registry->aeat_csv)->toBe('CSV-AWE-0001')
        ->and($registry->aeat_error)->toBeNull()
        ->and($registry->submitted_at)->not->toBeNull()
        ->and($registry->submission_attempts)->toBe(1)
        ->and($registry->aeat_response)->toBe([
            'estado_envio' => 'ParcialmenteCorrecto',
            'csv' => 'CSV-AWE-0001',
            'lineas' => [[
                'estado_registro' => 'AceptadoConErrores',
                'codigo' => '2003',
                'descripcion' => 'Huella incorrecta',
                'registro_duplicado' => false,
                'registro_duplicado_estado' => null,
            ]],
        ]);
});

it('persists a single AceptadoConErrores line under a Correcto envelope as accepted_with_errors', function () {
    $invoice = Invoice::factory()->create();

    $this->aeatClient->shouldReceive('sendRegistration')->once()->andReturn((new AeatResponseParser)->parse((object) [
        'CSV' => 'CSV-AWE-0002',
        'EstadoEnvio' => 'Correcto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'AceptadoConErrores',
            'CodigoErrorRegistro' => '1142',
            'DescripcionErrorRegistro' => 'CuotaRepercutida fuera de tolerancia',
        ],
    ]));

    $this->registrar->register($invoice);

    expect(Registry::query()->where('invoice_id', $invoice->id)->firstOrFail()->status)
        ->toBe(RegistryStatusEnum::ACCEPTED_WITH_ERRORS);
});

it('tells the RegistrySubmittedEvent listener the record was accepted with errors', function () {
    Event::fake([RegistrySubmittedEvent::class, RegistryStatusChangedEvent::class]);
    $invoice = Invoice::factory()->create();

    $this->aeatClient->shouldReceive('sendRegistration')->once()->andReturn((new AeatResponseParser)->parse((object) [
        'CSV' => 'CSV-AWE-0003',
        'EstadoEnvio' => 'ParcialmenteCorrecto',
        'RespuestaLinea' => (object) ['EstadoRegistro' => 'AceptadoConErrores', 'CodigoErrorRegistro' => '2003', 'DescripcionErrorRegistro' => 'Huella incorrecta'],
    ]));

    $this->registrar->register($invoice);

    Event::assertDispatched(RegistrySubmittedEvent::class, fn (RegistrySubmittedEvent $event) => $event->response->isAcceptedWithErrors());
    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->from === RegistryStatusEnum::PENDING
        && $event->to === RegistryStatusEnum::ACCEPTED_WITH_ERRORS
        && $event->error === null
        && $event->lines[0]['codigo'] === '2003');
});

it('stores a missing CSV as null, never an empty string', function () {
    $registry = Registry::factory()->create();

    $this->registryManager->markAsAcceptedWithErrors($registry, '', ['estado_envio' => 'ParcialmenteCorrecto', 'csv' => null, 'lineas' => []]);

    expect($registry->fresh()->aeat_csv)->toBeNull();
});

it('does not overwrite a record the agency already holds', function () {
    $registry = Registry::factory()->submitted()->create(['aeat_csv' => 'CSV-ORIGINAL']);

    $this->registryManager->markAsAcceptedWithErrors($registry, 'CSV-OTHER', ['estado_envio' => 'ParcialmenteCorrecto', 'csv' => 'CSV-OTHER', 'lineas' => []]);

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::SENT)
        ->aeat_csv->toBe('CSV-ORIGINAL');
});

it('never re-sends a record accepted with errors', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ACCEPTED_WITH_ERRORS, 'aeat_csv' => 'CSV-HELD']);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $response = $this->registrar->submitToAeat($registry);

    expect($response->isSuccess())->toBeTrue()
        ->and($response->getMessage())->toBe('Already submitted');
});

it('seals a record accepted with errors once it is persisted', function () {
    $registry = Registry::factory()->create();

    $this->registryManager->markAsAcceptedWithErrors($registry, 'CSV-AWE-0004', ['estado_envio' => 'ParcialmenteCorrecto', 'csv' => 'CSV-AWE-0004', 'lineas' => []]);

    expect(fn () => $registry->fresh()->delete())->toThrow(VerifactuException::class, 'cannot be deleted');
});
```

In `tests/Feature/DuplicateReconciliationTest.php`, test «reconciles a duplicate of an accepted-with-errors record end to end»: change the expectation to `RegistryStatusEnum::ACCEPTED_WITH_ERRORS` and add above it the comment `// AID-1626: the original was accepted with errors, so the reconciled record is too.`

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Feature/AcceptedWithErrorsTest.php tests/Feature/DuplicateReconciliationTest.php`
Expected: FAIL — `Call to undefined method ... markAsAcceptedWithErrors()` and status `sent`/`accepted` instead of `accepted_with_errors`.

- [ ] **Step 3: Implement the writer**

In `src/Services/RegistryManager.php`, after `markAsAccepted()`:

```php
    /**
     * Mark a registry as registered with warnings (AID-1626).
     *
     * Reached on ParcialmenteCorrecto / AceptadoConErrores, or on a duplicate
     * whose original was AceptadaConErrores. AEAT holds the record, so it is
     * filed, sealed and never re-sent; `aeat_error` is left alone because this
     * is a valid record with warnings, not an error. The agency's lines go in
     * `aeat_response` so the consumer never has to parse text.
     *
     * @param  array<string, mixed>  $aeatResponse  Structured: estado_envio, csv, lineas.
     */
    public function markAsAcceptedWithErrors(
        RegistryContract $registry,
        ?string $aeatCsv,
        array $aeatResponse
    ): void {
        if ($registry instanceof Registry) {
            DB::transaction(function () use ($registry, $aeatCsv, $aeatResponse): void {
                // Locked read: a plain refresh sees the REPEATABLE READ snapshot (AID-731).
                $this->lockAndRefresh($registry);

                if ($registry->status->isFiledAtAeat()) {
                    return;
                }

                $from = $registry->status;
                $currentAttempts = $registry->submission_attempts ?? 0;

                $registry->update([
                    'status' => RegistryStatusEnum::ACCEPTED_WITH_ERRORS->value,
                    'submitted_at' => $registry->submitted_at ?? Carbon::now(),
                    // Never '': it would collide on the UNIQUE index of aeat_csv.
                    'aeat_csv' => $aeatCsv === '' ? null : $aeatCsv,
                    'aeat_response' => $aeatResponse,
                    'submission_attempts' => $currentAttempts + 1,
                ]);

                $this->announceTransition($registry, $from, lines: $this->linesOf($aeatResponse));
            });
        }
    }
```

- [ ] **Step 4: Route the answer in `InvoiceRegistrar::submitToAeat()`**

Replace the `if ($response->isDuplicate()) { ... } else { ... }` block inside `if ($response->isSuccess())` with:

```php
            if ($response->isAcceptedWithErrors()) {
                // Asked first (AID-1626): a duplicate of a record accepted
                // with errors is accepted with errors too. The parser decided;
                // nothing here compares strings.
                $this->registryManager->markAsAcceptedWithErrors(
                    $registry,
                    $response->getCsv(),
                    $this->structuredOutcome($response)
                );
            } elseif ($response->isDuplicate()) {
                // (existing duplicate comment and markAsAccepted call, unchanged)
            } else {
                // (existing markAsSubmitted call, unchanged)
            }
```

Add `'accepted_with_errors' => $response->isAcceptedWithErrors(),` to the `Registry submitted successfully` log context. Add the helper next to `dispatchQuietly`'s old place:

```php
    /**
     * The agency's answer as persisted for an accepted-with-errors record
     * (AID-1626): never the raw message, always the lines.
     *
     * @return array{estado_envio: mixed, csv: ?string, lineas: mixed}
     */
    private function structuredOutcome(AeatResponse $response): array
    {
        $data = $response->getData() ?? [];

        return [
            'estado_envio' => $data['estado_envio'] ?? $response->getMessage(),
            'csv' => $response->getCsv(),
            'lineas' => $data['lineas'] ?? [],
        ];
    }
```

- [ ] **Step 5: Run the tests and the submission suites**

Run: `vendor/bin/pest tests/Feature/AcceptedWithErrorsTest.php tests/Feature/DuplicateReconciliationTest.php tests/Feature/ListenerFailureIsolationTest.php tests/Feature/RegistryManagerTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Services/RegistryManager.php src/Services/InvoiceRegistrar.php tests/Feature/AcceptedWithErrorsTest.php tests/Feature/DuplicateReconciliationTest.php
git commit -m "feat: persist accepted-with-errors answers with their agency lines (AID-1626)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Submission budget and the crossing into exhausted

**Files:**

- Modify: `config/verifactu.php:240-258` (retry block)
- Modify: `src/Services/RegistryManager.php` (`markAsFailed()`, new public `submissionBudget()`)
- Modify: `src/Services/InvoiceRegistrar.php` (`submitToAeat()` trailing param)
- Create: `tests/Feature/RetryBudgetExhaustionTest.php`

**Interfaces:**

- Consumes: `announceTransition()` (Task 3), `RegistryStatusEnum::EXHAUSTED` (Task 1).
- Produces:
  - `RegistryManager::submissionBudget(?int $override = null): int` — the override or `config('verifactu.retry.max_submission_attempts', 3)`; throws `VerifactuException` when the result is below 1.
  - `RegistryManager::markAsFailed(RegistryContract $registry, string $error, ?int $maxSubmissionAttempts = null): void`.
  - `InvoiceRegistrar::submitToAeat(RegistryContract $registry, ?int $maxSubmissionAttempts = null): AeatResponse` — passes the budget to both `markAsFailed()` calls.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/RetryBudgetExhaustionTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\RegistryRetriesExhaustedEvent;
use AichaDigital\LaraVerifactu\Events\RegistryStatusChangedEvent;
use AichaDigital\LaraVerifactu\Exceptions\AeatException;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use Illuminate\Support\Facades\Event;

/**
 * AID-1626 — an `error` row whose attempts reach the budget used to drop out
 * of the retry query with no transition and no signal. It now crosses into
 * `exhausted`, under the row lock, with exactly one RegistryRetriesExhaustedEvent.
 */
beforeEach(function () {
    $this->manager = app(RegistryManager::class);

    Event::fake([RegistryStatusChangedEvent::class, RegistryRetriesExhaustedEvent::class]);
});

it('crosses into exhausted when a failure reaches the budget, and says so once', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 2]);

    $this->manager->markAsFailed($registry, 'connection reset');

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::EXHAUSTED)
        ->submission_attempts->toBe(3)
        ->aeat_error->toBe('connection reset');

    Event::assertDispatchedTimes(RegistryRetriesExhaustedEvent::class, 1);
    Event::assertDispatched(RegistryRetriesExhaustedEvent::class, fn (RegistryRetriesExhaustedEvent $event) => $event->lastError === 'connection reset' && $event->attempts === 3);
    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->from === RegistryStatusEnum::ERROR
        && $event->to === RegistryStatusEnum::EXHAUSTED
        && $event->error === 'connection reset');
});

it('stays in error below the budget', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 1]);

    $this->manager->markAsFailed($registry, 'connection reset');

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::ERROR);
    Event::assertNotDispatched(RegistryRetriesExhaustedEvent::class);
});

it('lets an explicit budget override the configured one', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 2]);

    $this->manager->markAsFailed($registry, 'connection reset', 18);

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::ERROR);
});

it('reads the budget from verifactu.retry.max_submission_attempts', function () {
    config()->set('verifactu.retry.max_submission_attempts', 2);
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 1]);

    $this->manager->markAsFailed($registry, 'connection reset');

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);
});

it('falls back to 3 for a published config that predates the key', function () {
    // mergeConfigFrom() is shallow: a consumer who published config before
    // 1.4 has a `retry` array without max_submission_attempts.
    config()->set('verifactu.retry', ['enabled' => true, 'max_attempts' => 1, 'delay' => 60]);

    $belowBudget = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 1]);
    $atBudget = Registry::factory()->create(['status' => RegistryStatusEnum::ERROR, 'submission_attempts' => 2]);

    $this->manager->markAsFailed($belowBudget, 'connection reset');
    $this->manager->markAsFailed($atBudget, 'connection reset');

    expect($belowBudget->fresh()->status)->toBe(RegistryStatusEnum::ERROR)
        ->and($atBudget->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);
});

it('crosses from pending straight to exhausted on a budget of one', function () {
    $registry = Registry::factory()->create();

    $this->manager->markAsFailed($registry, 'connection reset', 1);

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);
    Event::assertDispatchedTimes(RegistryRetriesExhaustedEvent::class, 1);
});

it('keeps an exhausted registry exhausted, silently, when it fails again', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::EXHAUSTED, 'submission_attempts' => 3]);

    $this->manager->markAsFailed($registry, 'still unreachable', 18);

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::EXHAUSTED)
        ->submission_attempts->toBe(4)
        ->aeat_error->toBe('still unreachable');

    Event::assertNotDispatched(RegistryRetriesExhaustedEvent::class);
    Event::assertNotDispatched(RegistryStatusChangedEvent::class);
});

it('never lets the budget override an agency verdict', function () {
    $registry = Registry::factory()->submitted()->create();

    $this->manager->markAsFailed($registry, 'late failure', 1);

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::SENT);
});

it('refuses a budget below one', function (int $budget) {
    expect(fn () => $this->manager->submissionBudget($budget))
        ->toThrow(VerifactuException::class, 'at least 1');
})->with([0, -3]);

it('exhausts a registry on a transport failure at the budget passed to submitToAeat', function () {
    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
    config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
    config()->set('verifactu.system.vendor_nif', 'B70123456');
    config()->set('verifactu.system.name', 'LaraVerifactu');
    config()->set('verifactu.system.id', 'LV');
    config()->set('verifactu.system.version', '1.0');
    config()->set('verifactu.system.installation_number', '1');

    $qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
    $qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generatePng')->andReturn('png-binary');

    $manager = new RegistryManager(new HashGenerator, $qrGenerator, new XmlBuilder);
    $client = Mockery::mock(AeatClientContract::class);
    $client->shouldReceive('sendRegistration')->once()->andThrow(new RuntimeException('connection timed out'));
    $registrar = new InvoiceRegistrar($manager, Mockery::mock(CertificateManagerContract::class), $client);

    /** @var Registry $registry */
    $registry = $manager->createRegistry(Invoice::factory()->create());
    tamperRegistryColumns($registry->id, ['status' => RegistryStatusEnum::ERROR->value, 'submission_attempts' => 4]);

    expect(fn () => $registrar->submitToAeat($registry->fresh(), 5))->toThrow(AeatException::class);
    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Feature/RetryBudgetExhaustionTest.php`
Expected: FAIL — status `error` where `exhausted` is expected, and `Call to undefined method ... submissionBudget()`.

- [ ] **Step 3: Configuration**

Replace the retry block of `config/verifactu.php` (heading «Retry Configuration (v2.0)» down to the closing `],`) with:

```php
    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | max_submission_attempts is the TOTAL budget of submissions per registry
    | (AID-1626). When a failure reaches it, the registry moves to `exhausted`
    | and RegistryRetriesExhaustedEvent fires once. Set it equal to the
    | --max-attempts you schedule for `verifactu:retry-failed`, so every path
    | exhausts at the same number.
    |
    | pending_grace is how old, in seconds, a `pending` registry must be before
    | `verifactu:retry-failed` resends it. It avoids racing an in-flight
    | register() or a still-queued SubmitRegistryToAeatJob.
    |
    | max_attempts is NOT that budget: it is the queue-level $tries of
    | SubmitRegistryToAeatJob only.
    |
    */

    'retry' => [
        'enabled' => env('VERIFACTU_RETRY_ENABLED', true),
        'max_attempts' => env('VERIFACTU_RETRY_MAX_ATTEMPTS', 1), // queue $tries of SubmitRegistryToAeatJob
        'max_submission_attempts' => env('VERIFACTU_RETRY_MAX_SUBMISSION_ATTEMPTS', 3),
        'pending_grace' => env('VERIFACTU_RETRY_PENDING_GRACE', 300),
        'delay' => env('VERIFACTU_RETRY_DELAY', 60), // Delay between retries in seconds
        'backoff' => [60, 120, 240], // Exponential backoff (if manual retry with >1 attempts)
    ],
```

- [ ] **Step 4: Budget resolution and the crossing**

In `src/Services/RegistryManager.php` add, before `markAsFailed()`:

```php
    /**
     * The total submission budget per registry (AID-1626).
     *
     * The default lives here, at the read point, not only in the published
     * config: mergeConfigFrom() is shallow, so a consumer who published the
     * config before 1.4 has no such key and must still get 3.
     *
     * @throws VerifactuException
     */
    public function submissionBudget(?int $override = null): int
    {
        $budget = $override ?? (int) config('verifactu.retry.max_submission_attempts', 3);

        if ($budget < 1) {
            throw VerifactuException::make(sprintf(
                'The submission budget must be at least 1, got %d. A budget of 0 would move every '
                . 'failed registry to exhausted without resending any.',
                $budget,
            ));
        }

        return $budget;
    }
```

Replace `markAsFailed()` with:

```php
    /**
     * Mark a registry as failed, or as exhausted when this failure spends its
     * submission budget (AID-1626).
     *
     * Uses atomic update with state verification to prevent overwriting a
     * successful submission with an error. The crossing is decided under the
     * row lock, so two workers cannot both report it. An already exhausted
     * registry that fails again (a manual --include-exhausted attempt) stays
     * exhausted and is not a new crossing.
     *
     * @param  int|null  $maxSubmissionAttempts  The budget; null reads verifactu.retry.max_submission_attempts.
     */
    public function markAsFailed(
        RegistryContract $registry,
        string $error,
        ?int $maxSubmissionAttempts = null
    ): void {
        if ($registry instanceof Registry) {
            $budget = $this->submissionBudget($maxSubmissionAttempts);

            DB::transaction(function () use ($registry, $error, $budget): void {
                // Locked read: a plain refresh sees the REPEATABLE READ snapshot (AID-731).
                $this->lockAndRefresh($registry);

                // (keep the existing hasAgencyVerdict() comment and guard verbatim)
                if ($registry->status->hasAgencyVerdict()) {
                    return;
                }

                $from = $registry->status;
                $attempts = ($registry->submission_attempts ?? 0) + 1;

                $status = $from === RegistryStatusEnum::EXHAUSTED || $attempts >= $budget
                    ? RegistryStatusEnum::EXHAUSTED
                    : RegistryStatusEnum::ERROR;

                $registry->update([
                    'status' => $status->value,
                    'aeat_error' => $error,
                    'submission_attempts' => $attempts,
                ]);

                $this->announceTransition($registry, $from, error: $error);
            });
        }
    }
```

- [ ] **Step 5: Thread the budget through `submitToAeat()`**

Change the signature to `public function submitToAeat(RegistryContract $registry, ?int $maxSubmissionAttempts = null): AeatResponse`, add `@param  int|null  $maxSubmissionAttempts  Budget for the crossing into exhausted (AID-1626); null reads the config.` to its docblock, and pass `$maxSubmissionAttempts` as the third argument of both `$this->registryManager->markAsFailed(...)` calls (the transport-failure catch and the non-rejection failure branch).

- [ ] **Step 6: Run the tests and the suites that call `markAsFailed()`**

Run: `vendor/bin/pest tests/Feature/RetryBudgetExhaustionTest.php tests/Feature/IdempotencyAndTransactionsTest.php tests/Feature/RetryOverlapTest.php tests/Feature/PayloadIntegrityTest.php tests/Feature/ListenerFailureIsolationTest.php tests/Feature/RegistryStatusChangedEventTest.php tests/Arch`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add config/verifactu.php src/Services/RegistryManager.php src/Services/InvoiceRegistrar.php tests/Feature/RetryBudgetExhaustionTest.php
git commit -m "feat: move a registry to exhausted when it spends its submission budget (AID-1626)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Resend stale pending rows and sweep over-budget errors

**Files:**

- Modify: `src/Services/RegistryManager.php` (new `getResubmittableRegistries()`, `exhaustOverBudget()`)
- Modify: `src/Services/InvoiceRegistrar.php:330-365` (`retryFailed()`)
- Modify: `src/Commands/RetryFailedCommand.php`
- Modify: `src/Jobs/RetryFailedRegistriesJob.php`
- Create: `tests/Feature/ResendRetryCommandTest.php`
- Modify: `tests/Feature/JobsTest.php` (append)

**Interfaces:**

- Consumes: `submissionBudget()`, `markAsFailed(..., ?int)`, `submitToAeat(..., ?int)` (Task 5); `announceTransition()` (Task 3).
- Produces:
  - `RegistryManager::getResubmittableRegistries(int $maxSubmissionAttempts, int $limit = 50, bool $includeExhausted = false): Collection<int, Registry>`.
  - `RegistryManager::exhaustOverBudget(int $maxSubmissionAttempts): int` (number swept).
  - `InvoiceRegistrar::retryFailed(?int $maxAttempts = null, int $limit = 50, bool $includeExhausted = false): array{success: int, failed: int, skipped: int, exhausted: int}` (`skipped` always 0).
  - `verifactu:retry-failed {--max-attempts=} {--limit=50} {--include-exhausted}`.
  - `RetryFailedRegistriesJob(?int $maxAttempts = null, int $limit = 50)` with `public readonly int $maxAttempts` resolved at construction.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ResendRetryCommandTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Contracts\RegistryContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\RegistryRetriesExhaustedEvent;
use AichaDigital\LaraVerifactu\Events\RegistryStatusChangedEvent;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\AeatResponse;
use Illuminate\Support\Facades\Event;

/**
 * AID-1626 — `verifactu:retry-failed` resends stale `pending` rows (abandoned
 * by submitToAeat=false or a crash), sweeps over-budget `error` rows into
 * `exhausted`, and keeps `--max-attempts` as the governing budget (clientes
 * schedules 18, AID-721).
 */
beforeEach(function () {
    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
    config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
    config()->set('verifactu.system.vendor_nif', 'B70123456');
    config()->set('verifactu.system.name', 'LaraVerifactu');
    config()->set('verifactu.system.id', 'LV');
    config()->set('verifactu.system.version', '1.0');
    config()->set('verifactu.system.installation_number', '1');

    $qrGenerator = Mockery::mock(QrGeneratorContract::class);
    $qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
    $qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
    $qrGenerator->shouldReceive('generatePng')->andReturn('png-binary');

    $this->registryManager = new RegistryManager(new HashGenerator, $qrGenerator, new XmlBuilder);
    $this->aeatClient = Mockery::mock(AeatClientContract::class);

    $this->app->instance(InvoiceRegistrar::class, new InvoiceRegistrar(
        $this->registryManager,
        Mockery::mock(CertificateManagerContract::class),
        $this->aeatClient,
    ));
});

/**
 * A real, chain-valid registry (its payload passes the AID-730 check) moved to
 * the requested state and age, the way a backfill would.
 */
function resendCommandTestRegistry(
    RegistryManager $manager,
    RegistryStatusEnum $status,
    int $attempts = 0,
    int $ageSeconds = 3600,
): Registry {
    /** @var Registry $registry */
    $registry = $manager->createRegistry(Invoice::factory()->create());

    tamperRegistryColumns($registry->id, [
        'status' => $status->value,
        'submission_attempts' => $attempts,
        'aeat_error' => $status === RegistryStatusEnum::PENDING ? null : 'connection reset',
        'created_at' => now()->subSeconds($ageSeconds),
    ]);

    return $registry->fresh();
}

it('resends a pending registry once it is older than the grace period', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 301);

    $this->aeatClient->shouldReceive('sendRegistration')->once()
        ->andReturn(new AeatResponse(success: true, code: 'CSV-PENDING-OK', message: 'Correcto'));

    $this->artisan('verifactu:retry-failed')->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::SENT);
});

it('leaves a pending registry younger than the grace period alone', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 10);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $this->artisan('verifactu:retry-failed')
        ->expectsOutputToContain('No failed registries to retry')
        ->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::PENDING);
});

it('applies a 300 second grace for a published config that predates the key', function () {
    config()->set('verifactu.retry', ['enabled' => true, 'max_attempts' => 1, 'delay' => 60]);

    resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 290);
    $stale = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 310);

    $this->aeatClient->shouldReceive('sendRegistration')->once()
        ->withArgs(fn (RegistryContract $registry) => $registry->getId() === $stale->id)
        ->andReturn(new AeatResponse(success: true, code: 'CSV-GRACE-OK', message: 'Correcto'));

    $this->artisan('verifactu:retry-failed')->assertSuccessful();
});

it('resends an error registry under budget', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 1);

    $this->aeatClient->shouldReceive('sendRegistration')->once()
        ->andReturn(new AeatResponse(success: true, code: 'CSV-ERROR-OK', message: 'Correcto'));

    $this->artisan('verifactu:retry-failed')->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::SENT);
});

it('sweeps an over-budget error registry to exhausted without resending it', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 3);
    Event::fake([RegistryStatusChangedEvent::class, RegistryRetriesExhaustedEvent::class]);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $this->artisan('verifactu:retry-failed')
        ->expectsOutputToContain('Swept to exhausted')
        ->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);
    Event::assertDispatchedTimes(RegistryRetriesExhaustedEvent::class, 1);
    Event::assertDispatched(RegistryStatusChangedEvent::class, fn (RegistryStatusChangedEvent $event) => $event->from === RegistryStatusEnum::ERROR
        && $event->to === RegistryStatusEnum::EXHAUSTED
        && $event->error === 'connection reset');
});

it('exhausts a registry whose last attempt fails', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 2);
    Event::fake([RegistryRetriesExhaustedEvent::class]);

    $this->aeatClient->shouldReceive('sendRegistration')->once()->andThrow(new RuntimeException('connection timed out'));

    $this->artisan('verifactu:retry-failed')->assertFailed();

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::EXHAUSTED)
        ->submission_attempts->toBe(3);
    Event::assertDispatchedTimes(RegistryRetriesExhaustedEvent::class, 1);
});

it('governs selection and exhaustion with --max-attempts=18', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 5);

    $this->aeatClient->shouldReceive('sendRegistration')->once()->andThrow(new RuntimeException('connection timed out'));

    $this->artisan('verifactu:retry-failed', ['--max-attempts' => 18])->assertFailed();

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::ERROR)
        ->submission_attempts->toBe(6);

    // Without the option the configured budget (3) applies: swept, not resent.
    $this->artisan('verifactu:retry-failed')->assertSuccessful();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);
});

it('leaves exhausted registries out unless --include-exhausted is given', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::EXHAUSTED, attempts: 3);

    $this->aeatClient->shouldReceive('sendRegistration')->once()
        ->andReturn(new AeatResponse(success: true, code: 'CSV-EXHAUSTED-OK', message: 'Correcto'));

    $this->artisan('verifactu:retry-failed')->assertSuccessful();
    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::EXHAUSTED);

    $this->artisan('verifactu:retry-failed', ['--include-exhausted' => true])->assertSuccessful();

    // History is kept: the counter is not reset.
    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::SENT)
        ->submission_attempts->toBe(4);
});

it('keeps an exhausted registry exhausted, silently, when its extra attempt fails', function () {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::EXHAUSTED, attempts: 3);
    Event::fake([RegistryRetriesExhaustedEvent::class]);

    $this->aeatClient->shouldReceive('sendRegistration')->once()->andThrow(new RuntimeException('connection timed out'));

    $this->artisan('verifactu:retry-failed', ['--include-exhausted' => true])->assertFailed();

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::EXHAUSTED)
        ->submission_attempts->toBe(4);
    Event::assertNotDispatched(RegistryRetriesExhaustedEvent::class);
});

it('refuses a zero or non-numeric --max-attempts and touches nothing', function (string $value) {
    $registry = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 1);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    $this->artisan('verifactu:retry-failed', ['--max-attempts' => $value])->assertFailed();

    expect($registry->fresh()->status)->toBe(RegistryStatusEnum::ERROR);
})->with(['0', 'abc']);

it('resends the oldest candidates first', function () {
    $older = resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 7200);
    resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::PENDING, ageSeconds: 3600);

    $this->aeatClient->shouldReceive('sendRegistration')->once()
        ->withArgs(fn (RegistryContract $registry) => $registry->getId() === $older->id)
        ->andReturn(new AeatResponse(success: true, code: 'CSV-OLDEST-OK', message: 'Correcto'));

    $this->artisan('verifactu:retry-failed', ['--limit' => 1])->assertSuccessful();
});

it('keeps the skipped key, at zero, and reports the sweep in retryFailed()', function () {
    resendCommandTestRegistry($this->registryManager, RegistryStatusEnum::ERROR, attempts: 3);

    $this->aeatClient->shouldReceive('sendRegistration')->never();

    expect(app(InvoiceRegistrar::class)->retryFailed())
        ->toBe(['success' => 0, 'failed' => 0, 'skipped' => 0, 'exhausted' => 1]);
});
```

Append to `tests/Feature/JobsTest.php`:

```php
it('resolves the retry job budget from the config when none is given', function () {
    config()->set('verifactu.retry.max_submission_attempts', 7);

    expect((new RetryFailedRegistriesJob)->maxAttempts)->toBe(7)
        ->and((new RetryFailedRegistriesJob(18))->maxAttempts)->toBe(18);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Feature/ResendRetryCommandTest.php tests/Feature/JobsTest.php`
Expected: FAIL — pending rows not resent, `--include-exhausted` option unknown, no `exhausted` key.

- [ ] **Step 3: Selection and sweep in `RegistryManager`**

Add after `getRetryableRegistries()` (which, with `getPendingRegistries()`, keeps its result set):

```php
    /**
     * Registries `verifactu:retry-failed` should send (AID-1626).
     *
     * - `pending` older than verifactu.retry.pending_grace seconds: abandoned by
     *   submitToAeat=false or a crash between the creation commit and the
     *   submission. The grace avoids racing an in-flight register() or a queued
     *   SubmitRegistryToAeatJob; if a double submission still happens, AEAT
     *   answers «duplicate» and AID-727 reconciles it.
     * - `error` still under the budget.
     * - `exhausted`, only when asked: one more attempt each, counter kept.
     *
     * Oldest first, so the chain is presented in the order it was built.
     *
     * @return Collection<int, Registry>
     */
    public function getResubmittableRegistries(
        int $maxSubmissionAttempts,
        int $limit = 50,
        bool $includeExhausted = false,
    ): Collection {
        $staleBefore = Carbon::now()->subSeconds((int) config('verifactu.retry.pending_grace', 300));

        return Registry::query()
            ->where(function ($query) use ($maxSubmissionAttempts, $includeExhausted, $staleBefore): void {
                $query
                    ->where(function ($pending) use ($staleBefore): void {
                        $pending->where('status', RegistryStatusEnum::PENDING->value)
                            ->where('created_at', '<', $staleBefore);
                    })
                    ->orWhere(function ($error) use ($maxSubmissionAttempts): void {
                        $error->where('status', RegistryStatusEnum::ERROR->value)
                            ->where('submission_attempts', '<', $maxSubmissionAttempts);
                    });

                if ($includeExhausted) {
                    $query->orWhere('status', RegistryStatusEnum::EXHAUSTED->value);
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Move every `error` registry already at or past the budget to `exhausted`
     * (AID-1626), announcing each crossing.
     *
     * This converts rows left behind before 1.4 — when such a row simply
     * dropped out of the retry query — using the budget of THIS run, i.e. the
     * consumer's real one, not a guess made at migration time. Each row is
     * re-read under its lock: a concurrent writer may have moved it on.
     *
     * @return int The number of registries swept.
     */
    public function exhaustOverBudget(int $maxSubmissionAttempts): int
    {
        $candidates = Registry::query()
            ->where('status', RegistryStatusEnum::ERROR->value)
            ->where('submission_attempts', '>=', $maxSubmissionAttempts)
            ->orderBy('id')
            ->pluck('id');

        $swept = 0;

        foreach ($candidates as $id) {
            $swept += DB::transaction(function () use ($id, $maxSubmissionAttempts): int {
                $registry = Registry::query()->whereKey($id)->lockForUpdate()->first();

                if ($registry === null
                    || $registry->status !== RegistryStatusEnum::ERROR
                    || $registry->submission_attempts < $maxSubmissionAttempts) {
                    return 0;
                }

                $registry->update(['status' => RegistryStatusEnum::EXHAUSTED->value]);

                $this->announceTransition(
                    $registry,
                    RegistryStatusEnum::ERROR,
                    error: $registry->aeat_error ?? 'Retry budget exhausted',
                );

                return 1;
            });
        }

        return $swept;
    }
```

- [ ] **Step 4: `retryFailed()`**

Replace `InvoiceRegistrar::retryFailed()`:

```php
    /**
     * Resend what the package still owes the agency (AID-1626): stale pending
     * registries, error ones under budget and, when asked, exhausted ones.
     * Over-budget errors are first swept to exhausted. `skipped` is kept, always
     * 0, so existing readers of the array do not break.
     *
     * @param  int|null  $maxAttempts  Total budget for this run; null reads verifactu.retry.max_submission_attempts.
     * @return array{success: int, failed: int, skipped: int, exhausted: int}
     *
     * @throws VerifactuException When the budget is below 1.
     */
    public function retryFailed(?int $maxAttempts = null, int $limit = 50, bool $includeExhausted = false): array
    {
        $budget = $this->registryManager->submissionBudget($maxAttempts);

        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'exhausted' => $this->registryManager->exhaustOverBudget($budget),
        ];

        foreach ($this->registryManager->getResubmittableRegistries($budget, $limit, $includeExhausted) as $registry) {
            try {
                // The same budget the selection used decides the crossing.
                $response = $this->submitToAeat($registry, $budget);

                if ($response->isSuccess()) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                }
            } catch (\Throwable) {
                $results['failed']++;
            }
        }

        return $results;
    }
```

- [ ] **Step 5: The command**

In `src/Commands/RetryFailedCommand.php` replace the signature and `retry()`:

```php
    protected $signature = 'verifactu:retry-failed
                            {--max-attempts= : Total submission budget per registry for this run (default: verifactu.retry.max_submission_attempts)}
                            {--limit=50 : Maximum number of registries to resend}
                            {--include-exhausted : Also give each exhausted registry one more attempt}';

    protected $description = 'Resend pending and failed registry submissions to AEAT';
```

```php
    private function retry(InvoiceRegistrar $registrar): int
    {
        $maxAttempts = $this->option('max-attempts');

        $this->info('Retrying failed registries...');
        $this->newLine();

        try {
            $results = $registrar->retryFailed(
                $maxAttempts === null ? null : (int) $maxAttempts,
                (int) $this->option('limit'),
                (bool) $this->option('include-exhausted'),
            );

            if ($results['success'] === 0 && $results['failed'] === 0 && $results['exhausted'] === 0) {
                $this->info('No failed registries to retry');

                return self::SUCCESS;
            }

            $this->info('✓ Retry process completed');
            $this->table(
                ['Result', 'Count'],
                [
                    ['Success', $results['success']],
                    ['Failed', $results['failed']],
                    ['Swept to exhausted', $results['exhausted']],
                    ['Total resent', $results['success'] + $results['failed']],
                ]
            );

            return $results['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("✗ Failed to retry registries: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
```

- [ ] **Step 6: The job**

In `src/Jobs/RetryFailedRegistriesJob.php` replace the constructor (the property stays `int`, so readers of `$job->maxAttempts` keep an int):

```php
    /**
     * Total submission budget for the pass, resolved when the job is created.
     */
    public readonly int $maxAttempts;

    /**
     * Create a new job instance.
     *
     * @param  int|null  $maxAttempts  Null reads verifactu.retry.max_submission_attempts (AID-1626).
     */
    public function __construct(
        ?int $maxAttempts = null,
        public readonly int $limit = 50
    ) {
        $this->maxAttempts = $maxAttempts ?? (int) config('verifactu.retry.max_submission_attempts', 3);
        $this->onQueue(config('verifactu.queue.name', 'default'));
    }
```

Add `'exhausted' => $results['exhausted'],` to the `Retry of failed registries completed` log context.

- [ ] **Step 7: Run the tests and every retry suite**

Run: `vendor/bin/pest tests/Feature/ResendRetryCommandTest.php tests/Feature/JobsTest.php tests/Feature/RetryOverlapTest.php tests/Feature/DuplicateReconciliationTest.php tests/Feature/PayloadIntegrityTest.php tests/Feature/OverlapLockStoreTest.php tests/Feature/RegistryManagerTest.php`
Expected: PASS. Check helper uniqueness: `rg -n "function resendCommandTestRegistry\(" tests` returns one line.

- [ ] **Step 8: Commit**

```bash
git add src/Services/RegistryManager.php src/Services/InvoiceRegistrar.php src/Commands/RetryFailedCommand.php src/Jobs/RetryFailedRegistriesJob.php tests/Feature/ResendRetryCommandTest.php tests/Feature/JobsTest.php
git commit -m "feat: resend stale pending registries and sweep over-budget errors (AID-1626)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Data migration, upgrade notes and changelog

**Files:**

- Create: `database/migrations/2026_10_13_000001_backfill_accepted_with_errors_in_verifactu_registries.php`
- Modify: `src/LaraVerifactuServiceProvider.php` (`hasMigrations()` list)
- Create: `tests/Feature/AcceptedWithErrorsBackfillTest.php`
- Create: `tests/Arch/PublishedMigrationsTest.php`
- Create: `UPGRADE-1.4.md`
- Modify: `CHANGELOG.md` (`[Unreleased]`)
- Modify: `README.md:206`, `README.md:284-285`
- Modify: `docs/development/USAGE_EXAMPLES.md:58`, `:418`
- Modify: `docs/superpowers/specs/2026-10-13-typed-submission-outcomes-design.md` (§9 «Delivery»)

**Interfaces:**

- Consumes: the status values of Task 1.
- Produces: the migration's `up()` / `down()`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/AcceptedWithErrorsBackfillTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Events\RegistryStatusChangedEvent;
use AichaDigital\LaraVerifactu\Models\Registry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * AID-1626 — upgrade path. Before 1.4 a ParcialmenteCorrecto answer was stored
 * as `sent` with aeat_response = the message, through the `array` cast (so
 * JSON-quoted) or, if written by hand, raw. Both forms become
 * accepted_with_errors; nothing else is touched; no event fires.
 */
beforeEach(function () {
    $this->backfill = require dirname(__DIR__, 2)
        . '/database/migrations/2026_10_13_000001_backfill_accepted_with_errors_in_verifactu_registries.php';
});

it('converts both stored forms of ParcialmenteCorrecto and leaves every other row alone', function () {
    Event::fake([RegistryStatusChangedEvent::class]);

    $raw = Registry::factory()->create();
    $quoted = Registry::factory()->create();
    $correcto = Registry::factory()->create();
    $accepted = Registry::factory()->create();
    $error = Registry::factory()->create();

    tamperRegistryColumns($raw->id, ['status' => 'sent', 'aeat_response' => 'ParcialmenteCorrecto']);
    tamperRegistryColumns($quoted->id, ['status' => 'sent', 'aeat_response' => '"ParcialmenteCorrecto"']);
    tamperRegistryColumns($correcto->id, ['status' => 'sent', 'aeat_response' => '"Correcto"']);
    tamperRegistryColumns($accepted->id, ['status' => 'accepted', 'aeat_response' => '"Registro duplicado"']);
    tamperRegistryColumns($error->id, ['status' => 'error', 'aeat_response' => null, 'submission_attempts' => 9]);

    $this->backfill->up();

    $converted = ['estado_envio' => 'ParcialmenteCorrecto', 'lineas' => null];

    expect($raw->fresh())->status->toBe(RegistryStatusEnum::ACCEPTED_WITH_ERRORS)->aeat_response->toBe($converted)
        ->and($quoted->fresh())->status->toBe(RegistryStatusEnum::ACCEPTED_WITH_ERRORS)->aeat_response->toBe($converted)
        ->and($correcto->fresh()->status)->toBe(RegistryStatusEnum::SENT)
        ->and($accepted->fresh()->status)->toBe(RegistryStatusEnum::ACCEPTED)
        // ERROR rows are the retry sweep's job, with the consumer's budget.
        ->and($error->fresh()->status)->toBe(RegistryStatusEnum::ERROR);

    Event::assertNotDispatched(RegistryStatusChangedEvent::class);
});

it('reverts to sent with the message on down()', function () {
    $registry = Registry::factory()->create();
    tamperRegistryColumns($registry->id, ['status' => 'sent', 'aeat_response' => '"ParcialmenteCorrecto"']);

    $this->backfill->up();
    $this->backfill->down();

    expect($registry->fresh())
        ->status->toBe(RegistryStatusEnum::SENT)
        ->aeat_response->toBe('ParcialmenteCorrecto');
    expect(DB::table('verifactu_registries')->where('id', $registry->id)->value('aeat_response'))
        ->toBe('"ParcialmenteCorrecto"');
});
```

`tests/Arch/PublishedMigrationsTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\LaraVerifactuServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

/**
 * Every migration the package ships must be publishable (AID-1626).
 *
 * The suite loads the whole migrations folder, so a migration missing from
 * hasMigrations() passes CI and is never copied to a consumer — it happened
 * with AID-186. This compares the folder against the publish group.
 */
it('publishes every migration it ships', function () {
    $published = array_map(
        fn (string $path): string => basename($path),
        array_keys(ServiceProvider::pathsToPublish(LaraVerifactuServiceProvider::class, 'lara-verifactu-migrations')),
    );

    $shipped = array_map(
        fn (string $path): string => basename($path),
        File::glob(dirname(__DIR__, 2) . '/database/migrations/*.php'),
    );

    expect($published)->toEqualCanonicalizing($shipped);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/pest tests/Feature/AcceptedWithErrorsBackfillTest.php tests/Arch/PublishedMigrationsTest.php`
Expected: FAIL — migration file missing; publish group lacks it.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_10_13_000001_backfill_accepted_with_errors_in_verifactu_registries.php`:

```php
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
     * Back to `sent` with the message, in its cast form. Lines persisted by
     * 1.4 for records accepted with errors since the upgrade are dropped.
     */
    public function down(): void
    {
        DB::table('verifactu_registries')
            ->where('status', 'accepted_with_errors')
            ->update([
                'status' => 'sent',
                'aeat_response' => json_encode('ParcialmenteCorrecto'),
            ]);
    }
};
```

Register it as the last entry of `hasMigrations()` in `src/LaraVerifactuServiceProvider.php`:

```php
                // AID-1626: legacy ParcialmenteCorrecto rows become
                // accepted_with_errors. MUST be listed here or
                // publishMigrations() never copies it to consumers.
                '2026_10_13_000001_backfill_accepted_with_errors_in_verifactu_registries',
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/pest tests/Feature/AcceptedWithErrorsBackfillTest.php tests/Arch`
Expected: PASS.

- [ ] **Step 5: Correct the spec's delivery step**

`verifactu:install` only publishes the three `create_*` migrations (`src/Console/VerifactuInstallCommand.php:91-95`); the full set is published by Spatie's tag, whose `generateMigrationName()` reuses the existing filename for migrations already published. In the spec §9 «Delivery» sentence, replace «re-run `verifactu:install` to publish the new migration» with «publish the new migration with `php artisan vendor:publish --tag=lara-verifactu-migrations` (`verifactu:install` publishes only the three create migrations)».

- [ ] **Step 6: Write `UPGRADE-1.4.md`**

```markdown
# Upgrading to lara-verifactu 1.4

1.4 is a compatible minor release of the 1.x line. It adds two registry outcomes, a typed event for every outcome change, and lets `verifactu:retry-failed` resend abandoned `pending` registries (AID-1626).

## Steps

1. Update the package: `composer update aichadigital/lara-verifactu`.
2. Publish the new migration: `php artisan vendor:publish --tag=lara-verifactu-migrations`. Migrations you already published keep their file names and are not overwritten. `verifactu:install` is not enough: it publishes only the three `create_*` migrations.
3. Run it: `php artisan migrate`. It converts `sent` registries whose stored answer is `ParcialmenteCorrecto` into `accepted_with_errors`.
4. Set the total submission budget to the `--max-attempts` you schedule for `verifactu:retry-failed`. If you schedule `--max-attempts=18`:

   ```dotenv
   VERIFACTU_RETRY_MAX_SUBMISSION_ATTEMPTS=18
   ```

   Without it the budget is 3. A failure on the `register()` or queued-job path would then exhaust a registry at 3 while your schedule allows 18.

5. Optional: `VERIFACTU_RETRY_PENDING_GRACE` (seconds, default 300) is how old a `pending` registry must be before `verifactu:retry-failed` resends it.

## What changes in behaviour

- **New statuses.** `accepted_with_errors`: AEAT registered the record with warnings (`ParcialmenteCorrecto`, `AceptadoConErrores`, or a duplicate `AceptadaConErrores`); it is filed, sealed and never resent, and `aeat_response.lineas` carries the agency's codes. `exhausted`: the submission budget is spent; the package no longer resends it on its own.
- **`markAsFailed()` may write `exhausted`** when a failure reaches the budget, and fires `RegistryRetriesExhaustedEvent` once.
- **`verifactu:retry-failed` now resends `pending` registries** older than the grace period, and moves `error` registries already at or past the budget to `exhausted`, firing the events. `--max-attempts` still governs the run. `--include-exhausted` gives each exhausted registry one more attempt without resetting its counter.
- **A duplicate answer whose original was `AceptadaConErrores` is now `accepted_with_errors`**, not `accepted`.
- **New event `RegistryStatusChangedEvent`** (`registry`, `from`, `to`, `error`, `lines`) fires after commit for every persisted change of outcome, including creation (`null → pending`). Listen to it alone to follow a registry. Existing events are unchanged.
- **Deprecated:** `RegistryStatusEnum::isPending()` (it reports a registered `sent` record as pending) and `isSuccessful()` (it misses `sent`). Use `isRegistered()`, or compare against `PENDING`. Their answers do not change in 1.x.

## Reconciling state you track yourself

Rows converted by the migration fire no event. If your application mirrors registry outcomes, re-read them once:

```sql
SELECT id, invoice_id, registry_number
FROM verifactu_registries
WHERE status = 'accepted_with_errors'
  AND aeat_response = '{"estado_envio":"ParcialmenteCorrecto","lineas":null}';
```

`lineas: null` on those rows means «unknown», not «no errors»: the lines were never stored before 1.4.

Rows moved to `exhausted` by the retry sweep do fire `RegistryStatusChangedEvent` and `RegistryRetriesExhaustedEvent`.
```

- [ ] **Step 7: CHANGELOG and docs**

Under `## [Unreleased]` of `CHANGELOG.md`, before the existing `### Added` entry text, add (soft-wrapped style matches the file: keep its ~78-column wrapping):

```markdown
### Added

- **Typed submission outcomes** (AID-1626). Two new registry statuses:
  `accepted_with_errors` (AEAT registered the record with warnings; filed,
  sealed, never resent; the agency's lines persisted in
  `aeat_response.lineas`) and `exhausted` (submission budget spent). New
  `RegistryStatusEnum::isRegistered()`. New `RegistryStatusChangedEvent`,
  fired after commit for every real change of outcome including creation,
  and `RegistryRetriesExhaustedEvent`, fired once on the crossing. New config
  keys `retry.max_submission_attempts` (default 3) and `retry.pending_grace`
  (default 300 s). New `--include-exhausted` option on
  `verifactu:retry-failed`. See `UPGRADE-1.4.md`.

### Changed

- **`verifactu:retry-failed` now resends `pending` registries** older than
  `retry.pending_grace`, which were abandoned before (AID-1626).
- **`verifactu:retry-failed` moves `error` registries at or past the budget to
  `exhausted`**, firing the new events; before, they silently dropped out of
  the retry query.
- **`markAsFailed()` writes `exhausted`** when a failure reaches the budget.
  `--max-attempts` keeps governing the run; unset, it reads
  `retry.max_submission_attempts`.
- **A `ParcialmenteCorrecto` / `AceptadoConErrores` answer is persisted as
  `accepted_with_errors`**, not `sent`; a duplicate `AceptadaConErrores`, not
  `accepted`. A data migration converts the legacy `sent` rows.
- `InvoiceRegistrar::retryFailed()` returns an extra `exhausted` key; `skipped`
  stays, always 0.

### Deprecated

- **`RegistryStatusEnum::isPending()` and `isSuccessful()`** (AID-1626): the
  first reports a registered `sent` record as pending, the second misses it.
  Use `isRegistered()`. Their answers are unchanged in 1.x.
```

Merge the existing PHP 8.5 bullet into the same `### Added` list (one `### Added` heading only).

`README.md:206`: `php artisan verifactu:retry-failed      # resend pending, failed and (opt-in) exhausted submissions`. `README.md:284-285`: replace the bullet with «- `ParcialmenteCorrecto` / `AceptadoConErrores` responses are persisted as `accepted_with_errors`: AEAT registered the record, so it is never resubmitted; the agency's codes are kept in `aeat_response.lineas`. Follow every outcome change with `RegistryStatusChangedEvent`.»

`docs/development/USAGE_EXAMPLES.md:58`: add a line after it, `VERIFACTU_RETRY_MAX_SUBMISSION_ATTEMPTS=3`. `:418`: `php artisan verifactu:retry-failed --max-attempts=3 --limit=50 # add --include-exhausted to retry exhausted registries`.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_10_13_000001_backfill_accepted_with_errors_in_verifactu_registries.php src/LaraVerifactuServiceProvider.php tests/Feature/AcceptedWithErrorsBackfillTest.php tests/Arch/PublishedMigrationsTest.php UPGRADE-1.4.md CHANGELOG.md README.md docs/development/USAGE_EXAMPLES.md docs/superpowers/specs/2026-10-13-typed-submission-outcomes-design.md
git commit -m "feat: migrate legacy accepted-with-errors rows and document the 1.4 upgrade (AID-1626)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Quality and conformity gates

Run by the main session, not a subagent: the consumer gates touch other checkouts.

**Files:** none planned; any fix found here gets its own commit referencing AID-1626.

- [ ] **Step 1: Full suite, static analysis, style**

Run: `composer test && vendor/bin/phpstan analyse && vendor/bin/pint --test`
Expected: all green. A pre-existing test that seeded attempts at the budget and asserted `error` is the intended behaviour change: update it, and name it in the commit body.

- [ ] **Step 2: Concurrency suite**

Run: `RUN_CONCURRENCY_IT=1 vendor/bin/pest tests/Concurrency`
Expected: PASS, nothing skipped (a `skipped` here means `pcntl` or the flag is missing — fix the environment, do not accept it).

- [ ] **Step 3: Antipatterns gate**

Invoke `/ad-laravel-antipatterns` over the files changed on the branch (`git diff --name-only main...HEAD -- src database config`). Fix findings in their own commit.

- [ ] **Step 4: larabill conformity gate**

In `~/development/packages/aichadigital/larabill`: record the baseline failure set (`vendor/bin/pest --ci > /tmp/larabill-baseline.log`), point Composer at the candidate with a `path` repository declaring `"options": {"symlink": false, "versions": {"aichadigital/lara-verifactu": "1.4.0"}}`, `composer update aichadigital/lara-verifactu`, verify the `vendor/` copy matches the candidate commit, run the suite again, and compare the failure sets in both directions (`FAILED` and ` FAIL ` markers). Restore `composer.json`, run `composer update aichadigital/lara-verifactu`, and confirm `git status --porcelain` is clean.

- [ ] **Step 5: clientes conformity gate**

In a throwaway worktree of `~/SitesLR12/clientes` `origin/main` (with `.env`, `composer install` under php85, `npm install && npm run build`), same baseline/candidate delta as Step 4, with a watcher that kills Pest if its log stops growing for 5 minutes and `pgrep -f pest` checked first (shared database). Remove the worktree afterwards.

- [ ] **Step 6: Record the outcome**

Comment on AID-1626 with the gate results (suite counts, deltas, commits). Open the MR only after all gates are green.
