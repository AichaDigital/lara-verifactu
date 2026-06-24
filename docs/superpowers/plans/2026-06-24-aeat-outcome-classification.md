# AEAT Outcome Classification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a real AEAT validation rejection (`EstadoEnvio`/`EstadoRegistro=Incorrecto`) persist as `REJECTED` with its reason metadata, distinct from a transport failure which stays `ERROR`.

**Architecture:** Add a validation-rejection discriminator to `AeatResponse`. The parser classifies a well-formed AEAT rejection (a response object that AEAT evaluated and rejected) as a rejection carrying structured line metadata, vs a transport/parse failure. `InvoiceRegistrar` routes a rejection to a new `RegistryManager::markAsRejected()` (→ `REJECTED`, persists `aeat_response`) while transport failures keep `markAsFailed()` (→ `ERROR`). `getRetryableRegistries()` already selects only `ERROR`, so rejections fall out of retry automatically.

**Tech Stack:** PHP 8.3, Laravel 12, Pest, Spatie Laravel Package Tools, orchestra/testbench.

## Global Constraints

- **Issue:** AID-257 (PR 1; unblocks AID-137). Scope is classification only — no `amendRejected`, no subsanación XML, no schema columns.
- **No migration:** `RegistryStatusEnum::REJECTED` (`'rejected'`) and the `aeat_response` column already exist.
- **Language:** all identifiers, comments, commit messages in English.
- **AEAT source of truth:** `docs/verifactu/` only. Real codes observed in `tests/Unit/AeatResponseParserTest.php`: `3000` = duplicado (carries a `RegistroDuplicado` block → key exists in AEAT), `3002` = NIF not identified (genuine rejection), `2003` = Huella incorrecta with `AceptadoConErrores` (AEAT *registered* it → success).
- **Tests:** Pest, run from the package root. Commit after each green task.
- **Idempotency rule (existing):** `markAsFailed` never overwrites a `SENT` registry; `markAsRejected` mirrors that guard.

---

### Task 1: `AeatResponse` validation-rejection discriminator

**Files:**
- Modify: `src/Support/AeatResponse.php`
- Test: `tests/Unit/AeatResponseTest.php`

**Interfaces:**
- Produces: `AeatResponse::rejection(?array $errors, ?string $message = null, ?array $data = null): self`; `AeatResponse::isValidationRejection(): bool`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/AeatResponseTest.php`:

```php
it('flags a rejection as a validation rejection failure carrying data', function () {
    $response = AeatResponse::rejection(
        errors: ['3002: NIF del IDFactura no identificado'],
        message: 'Incorrecto',
        data: ['estado_envio' => 'Incorrecto'],
    );

    expect($response->isFailure())->toBeTrue()
        ->and($response->isValidationRejection())->toBeTrue()
        ->and($response->getData())->toBe(['estado_envio' => 'Incorrecto'])
        ->and($response->getErrors())->toContain('3002: NIF del IDFactura no identificado');
});

it('treats a plain failure as transport, not a validation rejection', function () {
    $response = AeatResponse::failure(errors: ['Invalid response from AEAT server']);

    expect($response->isFailure())->toBeTrue()
        ->and($response->isValidationRejection())->toBeFalse();
});
```

(`use AichaDigital\LaraVerifactu\Support\AeatResponse;` is already at the top of that file.)

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/AeatResponseTest.php --filter='validation rejection'`
Expected: FAIL with "Call to undefined method ... rejection()" / "isValidationRejection()".

- [ ] **Step 3: Add the discriminator and factory**

In `src/Support/AeatResponse.php`, add `$rejection` as the last constructor parameter:

```php
    public function __construct(
        protected bool $success,
        protected ?string $code = null,
        protected ?string $message = null,
        protected ?array $data = null,
        protected ?array $errors = null,
        protected bool $rejection = false,
    ) {}
```

Add the accessor after `isFailure()`:

```php
    public function isValidationRejection(): bool
    {
        return $this->rejection;
    }
```

Add the factory after `failure()`:

```php
    /**
     * A well-formed AEAT response that AEAT evaluated and rejected
     * (EstadoEnvio/EstadoRegistro=Incorrecto), as opposed to a transport failure.
     *
     * @param  array<int, string>|null  $errors
     * @param  array<string, mixed>|null  $data
     */
    public static function rejection(?array $errors = null, ?string $message = null, ?array $data = null): self
    {
        return new self(
            success: false,
            message: $message,
            data: $data,
            errors: $errors,
            rejection: true,
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/AeatResponseTest.php`
Expected: PASS (all existing tests still green).

- [ ] **Step 5: Commit**

```bash
git add src/Support/AeatResponse.php tests/Unit/AeatResponseTest.php
git commit -m "feat: add validation-rejection discriminator to AeatResponse (AID-257)"
```

---

### Task 2: Parser classifies validation rejection + preserves line metadata

**Files:**
- Modify: `src/Services/AeatResponseParser.php`
- Test: `tests/Unit/AeatResponseParserTest.php`

**Interfaces:**
- Consumes: `AeatResponse::rejection()` (Task 1).
- Produces: `parse()` returns a rejection for a well-formed `Incorrecto` object; its `getData()` carries `['estado_envio' => string|null, 'lineas' => array<int, array{estado_registro, codigo, descripcion, registro_duplicado: bool}>]`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/AeatResponseParserTest.php`:

```php
it('classifies a validation rejection and preserves line metadata', function () {
    $response = (object) [
        'EstadoEnvio' => 'Incorrecto',
        'RespuestaLinea' => [
            (object) [
                'EstadoRegistro' => 'Incorrecto',
                'CodigoErrorRegistro' => '3002',
                'DescripcionErrorRegistro' => 'NIF del IDFactura no identificado',
            ],
        ],
    ];

    $result = $this->parser->parse($response);

    expect($result->isValidationRejection())->toBeTrue()
        ->and($result->getData()['estado_envio'])->toBe('Incorrecto')
        ->and($result->getData()['lineas'][0]['codigo'])->toBe('3002')
        ->and($result->getData()['lineas'][0]['estado_registro'])->toBe('Incorrecto')
        ->and($result->getData()['lineas'][0]['registro_duplicado'])->toBeFalse();
});

it('preserves the RegistroDuplicado signal on a duplicate-key rejection', function () {
    $response = (object) [
        'EstadoEnvio' => 'Incorrecto',
        'RespuestaLinea' => (object) [
            'EstadoRegistro' => 'Incorrecto',
            'CodigoErrorRegistro' => '3000',
            'DescripcionErrorRegistro' => 'Registro de facturación duplicado',
            'RegistroDuplicado' => (object) ['EstadoRegistroDuplicado' => 'Correcta'],
        ],
    ];

    $result = $this->parser->parse($response);

    expect($result->isValidationRejection())->toBeTrue()
        ->and($result->getData()['lineas'][0]['registro_duplicado'])->toBeTrue();
});

it('treats a non-object response as a transport failure, not a rejection', function () {
    $result = $this->parser->parse('unexpected');

    expect($result->isFailure())->toBeTrue()
        ->and($result->isValidationRejection())->toBeFalse();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/AeatResponseParserTest.php --filter='rejection'`
Expected: FAIL with "Call to undefined method ... isValidationRejection()" / data key errors.

- [ ] **Step 3: Implement classification + structured metadata**

In `src/Services/AeatResponseParser.php`, replace the final `return AeatResponse::failure(...)` block (the no-accepted path, lines 55-58) with:

```php
        $lineDetails = $this->collectLineDetails($response);

        // A well-formed AEAT rejection: AEAT evaluated the submission and said
        // Incorrecto (EstadoEnvio present, or per-line EstadoRegistro/errors). A
        // degenerate object with neither is treated as a transport failure.
        $isValidationRejection = $submissionStatus !== null || $lineDetails !== [];

        if ($isValidationRejection) {
            return AeatResponse::rejection(
                errors: $lineErrors === [] ? ['Submission rejected by AEAT'] : $lineErrors,
                message: $submissionStatus ?? 'Rejected by AEAT',
                data: [
                    'estado_envio' => $submissionStatus,
                    'lineas' => $lineDetails,
                ],
            );
        }

        return AeatResponse::failure(
            errors: $lineErrors === [] ? ['Invalid response from AEAT server'] : $lineErrors,
            message: $submissionStatus ?? 'Unknown AEAT response',
        );
```

Add this method after `collectLineErrors()`:

```php
    /**
     * Collect structured per-line rejection metadata (preserved for AID-137 to
     * tell a duplicate-key rejection from a genuine not-in-AEAT rejection).
     *
     * @return array<int, array{estado_registro: ?string, codigo: ?string, descripcion: ?string, registro_duplicado: bool}>
     */
    private function collectLineDetails(object $response): array
    {
        if (! property_exists($response, 'RespuestaLinea') || $response->RespuestaLinea === null) {
            return [];
        }

        $lines = is_array($response->RespuestaLinea)
            ? $response->RespuestaLinea
            : [$response->RespuestaLinea];

        $details = [];

        foreach ($lines as $line) {
            if (! is_object($line)) {
                continue;
            }

            $details[] = [
                'estado_registro' => $this->stringProperty($line, 'EstadoRegistro'),
                'codigo' => $this->stringProperty($line, 'CodigoErrorRegistro'),
                'descripcion' => $this->stringProperty($line, 'DescripcionErrorRegistro'),
                'registro_duplicado' => property_exists($line, 'RegistroDuplicado')
                    && $line->RegistroDuplicado !== null,
            ];
        }

        return $details;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/AeatResponseParserTest.php`
Expected: PASS — including the existing `'parses a rejected submission as failure with line errors'` and `'parses a duplicate record rejection'` (both still `isFailure()`, now also `isValidationRejection()`), and `'returns failure for a non-object response'`.

- [ ] **Step 5: Commit**

```bash
git add src/Services/AeatResponseParser.php tests/Unit/AeatResponseParserTest.php
git commit -m "feat: classify AEAT validation rejection with line metadata (AID-257)"
```

---

### Task 3: `RegistryManager::markAsRejected()`

**Files:**
- Modify: `src/Services/RegistryManager.php`
- Test: `tests/Feature/RegistryManagerTest.php`

**Interfaces:**
- Produces: `markAsRejected(RegistryContract $registry, string $error, ?array $aeatResponse = null): void` → sets `status=REJECTED`, `aeat_error`, `aeat_response`, increments `submission_attempts`; never overwrites `SENT`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/RegistryManagerTest.php`:

```php
describe('markAsRejected', function () {
    it('marks a registry REJECTED and persists the AEAT response metadata', function () {
        $invoice = Invoice::factory()->create();
        $registry = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'status' => RegistryStatusEnum::PENDING->value,
            'submission_attempts' => 0,
        ]);

        $this->registryManager->markAsRejected(
            $registry,
            '3002: NIF del IDFactura no identificado',
            ['estado_envio' => 'Incorrecto', 'lineas' => [['codigo' => '3002']]],
        );

        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::REJECTED)
            ->and($registry->aeat_error)->toContain('3002')
            ->and($registry->aeat_response['estado_envio'])->toBe('Incorrecto')
            ->and($registry->submission_attempts)->toBe(1);
    });

    it('never overwrites a SENT registry with REJECTED', function () {
        $invoice = Invoice::factory()->create();
        $registry = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'status' => RegistryStatusEnum::SENT->value,
        ]);

        $this->registryManager->markAsRejected($registry, 'late rejection', null);

        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::SENT);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/RegistryManagerTest.php --filter='markAsRejected'`
Expected: FAIL with "Call to undefined method ... markAsRejected()".

- [ ] **Step 3: Implement `markAsRejected` (mirror of `markAsFailed`)**

In `src/Services/RegistryManager.php`, add after `markAsFailed()` (after line 341):

```php
    /**
     * Mark a registry as a validation rejection (REJECTED) and persist the
     * structured AEAT response. Mirrors markAsFailed's SENT-idempotency guard.
     * REJECTED is terminal for retry: getRetryableRegistries() selects only ERROR.
     *
     * @param  array<string, mixed>|null  $aeatResponse
     */
    public function markAsRejected(
        RegistryContract $registry,
        string $error,
        ?array $aeatResponse = null
    ): void {
        if ($registry instanceof Registry) {
            DB::transaction(function () use ($registry, $error, $aeatResponse): void {
                $registry->refresh();

                if ($registry->status === RegistryStatusEnum::SENT) {
                    return;
                }

                $currentAttempts = $registry->submission_attempts ?? 0;

                $registry->update([
                    'status' => RegistryStatusEnum::REJECTED->value,
                    'aeat_error' => $error,
                    'aeat_response' => $aeatResponse,
                    'submission_attempts' => $currentAttempts + 1,
                ]);
            });
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/RegistryManagerTest.php --filter='markAsRejected'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/RegistryManager.php tests/Feature/RegistryManagerTest.php
git commit -m "feat: add RegistryManager::markAsRejected (AID-257)"
```

---

### Task 4: `InvoiceRegistrar` routes a validation rejection to `markAsRejected`

**Files:**
- Modify: `src/Services/InvoiceRegistrar.php:163-177`
- Test: `tests/Feature/RegistryManagerTest.php` (new describe block; reuses `Registry`/`Invoice` factories)

**Interfaces:**
- Consumes: `AeatResponse::isValidationRejection()` (Task 1), `RegistryManager::markAsRejected()` (Task 3).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/RegistryManagerTest.php` (add the imports `use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;`, `use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;`, `use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;`, `use AichaDigital\LaraVerifactu\Support\AeatResponse;` at the top):

```php
describe('submitToAeat outcome routing', function () {
    it('routes a validation rejection to REJECTED, not ERROR', function () {
        $invoice = Invoice::factory()->create();
        $registry = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'status' => RegistryStatusEnum::PENDING->value,
        ]);

        $aeatClient = Mockery::mock(AeatClientContract::class);
        $aeatClient->shouldReceive('sendRegistration')->andReturn(
            AeatResponse::rejection(
                errors: ['3002: NIF del IDFactura no identificado'],
                message: 'Incorrecto',
                data: ['estado_envio' => 'Incorrecto'],
            )
        );

        $registrar = new InvoiceRegistrar(
            $this->registryManager,
            Mockery::mock(CertificateManagerContract::class),
            $aeatClient,
        );

        $registrar->submitToAeat($registry);
        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::REJECTED)
            ->and($registry->aeat_response['estado_envio'])->toBe('Incorrecto');
    });

    it('routes a transport failure to ERROR', function () {
        $invoice = Invoice::factory()->create();
        $registry = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'status' => RegistryStatusEnum::PENDING->value,
        ]);

        $aeatClient = Mockery::mock(AeatClientContract::class);
        $aeatClient->shouldReceive('sendRegistration')->andReturn(
            AeatResponse::failure(errors: ['Invalid response from AEAT server'])
        );

        $registrar = new InvoiceRegistrar(
            $this->registryManager,
            Mockery::mock(CertificateManagerContract::class),
            $aeatClient,
        );

        $registrar->submitToAeat($registry);
        $registry->refresh();

        expect($registry->status)->toBe(RegistryStatusEnum::ERROR);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/RegistryManagerTest.php --filter='outcome routing'`
Expected: FAIL — the rejection case lands in `ERROR` (current `else` always calls `markAsFailed`).

- [ ] **Step 3: Branch the failure path**

In `src/Services/InvoiceRegistrar.php`, replace the `else` block at lines 163-177 with:

```php
                } else {
                    if ($response->isValidationRejection()) {
                        $this->registryManager->markAsRejected(
                            $registry,
                            $response->getErrorMessage(),
                            $response->getData()
                        );
                    } else {
                        $this->registryManager->markAsFailed(
                            $registry,
                            $response->getErrorMessage()
                        );
                    }

                    Log::channel(config('verifactu.logging.channel', 'single'))
                        ->error('Registry submission failed', [
                            'registry_number' => $registry->getRegistryNumber(),
                            'error' => AeatLogSanitizer::redactText((string) $response->getErrorMessage()),
                        ]);

                    // Dispatch failure event
                    event(new RegistryFailedEvent($registry, $response->getErrorMessage(), $registry->getSubmissionAttempts()));
                }
```

(The `catch (\Throwable $e)` path stays `markAsFailed` → `ERROR`: an exception is transport, never a validation rejection.)

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/RegistryManagerTest.php --filter='outcome routing'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/InvoiceRegistrar.php tests/Feature/RegistryManagerTest.php
git commit -m "feat: route AEAT validation rejection to REJECTED (AID-257)"
```

---

### Task 5: `canRetry()` drops `REJECTED`; retry selector excludes it

**Files:**
- Modify: `src/Enums/RegistryStatusEnum.php:41-44`
- Test: `tests/Unit/EnumsTest.php`, `tests/Feature/RegistryManagerTest.php`

**Interfaces:**
- Consumes: `RegistryManager::getRetryableRegistries()` (already `status = ERROR`).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/EnumsTest.php` (`use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;` is already present):

```php
it('excludes REJECTED from the retryable statuses', function () {
    expect(RegistryStatusEnum::REJECTED->canRetry())->toBeFalse()
        ->and(RegistryStatusEnum::PENDING->canRetry())->toBeTrue()
        ->and(RegistryStatusEnum::ERROR->canRetry())->toBeTrue();
});
```

Add to `tests/Feature/RegistryManagerTest.php`:

```php
it('does not select a REJECTED registry for retry', function () {
    $invoice = Invoice::factory()->create();
    Registry::factory()->create([
        'invoice_id' => $invoice->id,
        'status' => RegistryStatusEnum::REJECTED->value,
        'submission_attempts' => 0,
    ]);

    expect($this->registryManager->getRetryableRegistries())->toHaveCount(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/EnumsTest.php --filter='REJECTED'`
Expected: FAIL — `REJECTED->canRetry()` currently returns `true` (`canRetry` includes `REJECTED`). The Feature test already passes (selector is `ERROR`); it is a regression guard.

- [ ] **Step 3: Remove `REJECTED` from `canRetry()`**

In `src/Enums/RegistryStatusEnum.php`, change `canRetry()`:

```php
    public function canRetry(): bool
    {
        // REJECTED is a validation outcome (AID-257), not a transport retry.
        // The effective retry frontier is getRetryableRegistries() = status ERROR.
        return in_array($this, [self::PENDING, self::ERROR]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/EnumsTest.php tests/Feature/RegistryManagerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Enums/RegistryStatusEnum.php tests/Unit/EnumsTest.php tests/Feature/RegistryManagerTest.php
git commit -m "feat: drop REJECTED from canRetry, the retry frontier is ERROR (AID-257)"
```

---

### Task 6: Full suite + quality gate

**Files:** none (verification).

- [ ] **Step 1: Run the full test suite**

Run: `composer test`
Expected: all green (no regression in the existing parser/registrar/idempotency suites).

- [ ] **Step 2: Static analysis + format**

Run: `vendor/bin/pint src/Support/AeatResponse.php src/Services/AeatResponseParser.php src/Services/RegistryManager.php src/Services/InvoiceRegistrar.php src/Enums/RegistryStatusEnum.php && composer phpstan`
Expected: Pint `passed`, PHPStan `No errors`.

- [ ] **Step 3: Commit any pint/phpstan adjustments**

```bash
git add -A
git commit -m "chore: pint + phpstan for AEAT outcome classification (AID-257)" || echo "nothing to commit"
```

---

## Self-Review

- **Spec coverage (AID-257 scope):** classify `Incorrecto`→`REJECTED` (Task 2+4), keep transport→`ERROR` (Task 4), propagate discriminator + metadata on `aeat_response` (Tasks 1-3), `getRetryableRegistries` excludes `REJECTED` (Task 5), `canRetry()` drops `REJECTED` (Task 5). No `amendRejected`/subsanación (correctly out of scope). No migration (REJECTED + aeat_response pre-exist). ✓
- **Type consistency:** `isValidationRejection(): bool`, `rejection(?array,?string,?array)`, `markAsRejected(RegistryContract,string,?array)`, data shape `{estado_envio, lineas:[{estado_registro,codigo,descripcion,registro_duplicado}]}` — used identically across Tasks 1-4. ✓
- **No placeholders:** every step shows real code/commands. ✓
