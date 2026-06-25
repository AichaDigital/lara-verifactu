# Amend-by-Rejection («ALTA POR RECHAZO») Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the AID-137 «ALTA POR RECHAZO» variant: take a `REJECTED` initial registration whose unique key is provably **not** in AEAT and re-send it corrected as a new chain link carrying `Subsanacion=S` + `RechazoPrevio=X`, with `amends_registry_id` pointing at the rejected record. The rejected record and its XML stay immutable. As part of the same issue (spec §8), make blockchain verification read from the persisted historical XML, not the mutable `Invoice`.

**Architecture:** A new value object `RegistrationCircumstances` (subsanación flag + `RechazoPrevioEnum`) is threaded through `XmlBuilder::buildRegistrationXml()` and `RegistryManager::createRegistry()` so the amendment emits `Subsanacion`/`RechazoPrevio` in XSD position (after `NombreRazonEmisor`, before `TipoFactura`) and persists the circumstances. `InvoiceRegistrar::amendRejected()` orchestrates five fail-loud guards, then creates the chained amendment registry. `HashGenerator` gains typed `*FromParts` methods so `verifyRegistryHash()` can rebuild hashes from the persisted XML (`sf:` XPath) per `registry_type` without ever reading `$registry->invoice`.

**Tech Stack:** PHP 8.3, Laravel 12, Pest, Spatie Laravel Package Tools, orchestra/testbench. Run all commands from the package root.

## Global Constraints

- **Issue:** AID-137 (PR 2). **Blocked by AID-257** (AEAT outcome classification, PR 1 — already merged: `RegistryStatusEnum::REJECTED` is reachable, `markAsRejected()` exists, `aeat_response` carries `{estado_envio, lineas:[{estado_registro, codigo, descripcion, registro_duplicado}]}`, `getRetryableRegistries()` selects only `ERROR`).
- **Scope:** ONLY the «ALTA POR RECHAZO» variant (`Subsanacion=S` + `RechazoPrevio=X`, key not in AEAT). The other subsanación variants (`RechazoPrevio=N`/`S`, «SIN REGISTRO PREVIO») are **out of scope → AID-209**.
- **Language:** all identifiers, comments, commit messages, PR title/description in English (umbrella rule).
- **AEAT source of truth:** `docs/verifactu/` only (WSDL/XSD/sede). `RechazoPrevioType {N,S,X}` is `SuministroInformacion.xsd:754`; `Subsanacion`/`RechazoPrevio` are direct `RegistroAlta` children after `NombreRazonEmisor`, before `TipoFactura` (`SuministroInformacion.xsd:105`). `RechazoPrevioType` is an XSD enum, so `validate()` accepts any member — the happy-path test must assert the literal `<sf:RechazoPrevio>X</sf:RechazoPrevio>`.
- **Migration policy:** lara-verifactu publishes real `.php` migrations (no `.php.stub` pair). A new migration MUST be appended to `LaraVerifactuServiceProvider::hasMigrations([...])` or it is never published to consumers — CI does not catch this (tests load the whole folder).
- **Tests:** Pest. Commit after each green task. Element assertions follow the existing `->toContain('<sf:Name>value</sf:Name>')` convention (XML emits `sf:`-prefixed elements).
- **Immutability:** `amendRejected` never mutates the rejected record's `Invoice` or XML; "already amended" derives from the double-amendment guard + the DB unique index, never an inverse column.

---

### Task 1: `RechazoPrevioEnum {N,S,X}`

**Files:**
- Create: `src/Enums/RechazoPrevioEnum.php`
- Test: `tests/Unit/EnumsTest.php`

**Interfaces:**
- Produces: `enum RechazoPrevioEnum: string { case N='N'; case S='S'; case X='X'; }` — mirrors `RegistryTypeEnum`'s backed-string-enum shape. The XSD-conformant `RechazoPrevioType` member set (`SuministroInformacion.xsd:754`). Consumed by Tasks 2/3/5.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/EnumsTest.php` (add the import `use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;` at the top, after the existing enum imports):

```php
describe('RechazoPrevioEnum', function () {
    it('has the XSD-conformant RechazoPrevioType members', function () {
        expect(RechazoPrevioEnum::N->value)->toBe('N')
            ->and(RechazoPrevioEnum::S->value)->toBe('S')
            ->and(RechazoPrevioEnum::X->value)->toBe('X');
    });

    it('maps the amend-by-rejection case to X (key not in AEAT)', function () {
        expect(RechazoPrevioEnum::tryFrom('X'))->toBe(RechazoPrevioEnum::X)
            ->and(RechazoPrevioEnum::tryFrom('Z'))->toBeNull();
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/EnumsTest.php --filter='RechazoPrevioEnum'`
Expected: FAIL with `Class "AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum" not found`.

- [ ] **Step 3: Create the enum**

Create `src/Enums/RechazoPrevioEnum.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Enums;

/**
 * RechazoPrevioType (SuministroInformacion.xsd:754) — distinguished by whether
 * the record exists in AEAT:
 *  - N: no prior AEAT rejection.
 *  - S: prior rejection AND the record exists in AEAT (post-1.0, AID-209).
 *  - X: the record does NOT exist in AEAT (initial alta rejected) → AID-137.
 */
enum RechazoPrevioEnum: string
{
    case N = 'N';
    case S = 'S';
    case X = 'X';
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/EnumsTest.php --filter='RechazoPrevioEnum'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Enums/RechazoPrevioEnum.php tests/Unit/EnumsTest.php
git commit -m "feat: add RechazoPrevioEnum {N,S,X} for subsanación circumstances (AID-137)"
```

### Task 2: `RegistrationCircumstances` value object

**Files:**
- Create: `src/Support/RegistrationCircumstances.php`
- Test: `tests/Unit/RegistrationCircumstancesTest.php`

**Interfaces:**
- Produces: `final readonly class RegistrationCircumstances` with `__construct(public bool $subsanacion = false, public ?RechazoPrevioEnum $rechazoPrevio = null)`. These are **registry** circumstances, not invoice data (spec §4) — never fields on `InvoiceContract`. A null/default instance means "normal alta, emit nothing". Threaded through Tasks 5/6, constructed `(subsanacion: true, rechazoPrevio: RechazoPrevioEnum::X)` by Task 9.
- Consumes: `RechazoPrevioEnum` (Task 1).

Mirrors the readonly-VO shape of `CancellationRecord`/`RegistryChain` in `src/Support/`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/RegistrationCircumstancesTest.php`:

```php
<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;
use AichaDigital\LaraVerifactu\Support\RegistrationCircumstances;

it('defaults to an empty (normal alta) circumstance', function () {
    $circumstances = new RegistrationCircumstances;

    expect($circumstances->subsanacion)->toBeFalse()
        ->and($circumstances->rechazoPrevio)->toBeNull();
});

it('carries the amend-by-rejection circumstance (S + X)', function () {
    $circumstances = new RegistrationCircumstances(
        subsanacion: true,
        rechazoPrevio: RechazoPrevioEnum::X,
    );

    expect($circumstances->subsanacion)->toBeTrue()
        ->and($circumstances->rechazoPrevio)->toBe(RechazoPrevioEnum::X);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/RegistrationCircumstancesTest.php`
Expected: FAIL with `Class "AichaDigital\LaraVerifactu\Support\RegistrationCircumstances" not found`.

- [ ] **Step 3: Create the value object**

Create `src/Support/RegistrationCircumstances.php`:

```php
<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Support;

use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;

/**
 * Registry-level circumstances of a RegistroAlta: whether it is a subsanación
 * (Subsanacion=S) and, if so, the RechazoPrevio value. These describe the
 * registry, not the invoice (spec §4), so they are passed alongside the
 * invoice rather than read from InvoiceContract. A default instance means a
 * normal alta — the builder emits neither element.
 */
final readonly class RegistrationCircumstances
{
    public function __construct(
        public bool $subsanacion = false,
        public ?RechazoPrevioEnum $rechazoPrevio = null,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/RegistrationCircumstancesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Support/RegistrationCircumstances.php tests/Unit/RegistrationCircumstancesTest.php
git commit -m "feat: add RegistrationCircumstances value object (AID-137)"
```

### Task 3: Migration — `subsanacion` / `rechazo_previo` / `amends_registry_id` (+ register in `hasMigrations`, Registry fillable/casts)

**Files:**
- Create: `database/migrations/2026_06_24_000001_add_subsanacion_to_verifactu_registries_table.php`
- Modify: `src/LaraVerifactuServiceProvider.php` (`hasMigrations([...])`)
- Modify: `src/Models/Registry.php` (`$fillable`, `$casts`, PHPDoc props)
- Test: `tests/Feature/RegistryManagerTest.php`

**Interfaces:**
- New columns on `verifactu_registries`: `subsanacion` boolean default `false`; `rechazo_previo` char(1) nullable cast to `RechazoPrevioEnum` (null = not emitted); `amends_registry_id` unsignedBigInteger nullable self-FK to `verifactu_registries.id`. Plus a **unique index on `amends_registry_id` where not null** (prevents a concurrent double amendment; spec §1 `[P1#4]`).
- Consumes: `RechazoPrevioEnum` (Task 1).

The mass-assignment + cast wiring lands here so later tasks can `Registry::factory()->create(['rechazo_previo' => 'X', ...])` and read `$registry->rechazo_previo` as an enum. Follows the column-add pattern of `2026_06_10_000002_add_registry_type_to_verifactu_registries_table.php` (separate `Schema::table` calls for index vs column on `down()`).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/RegistryManagerTest.php`:

```php
describe('subsanación columns', function () {
    it('persists subsanacion, rechazo_previo and amends_registry_id with the enum cast', function () {
        $invoice = Invoice::factory()->create();
        $rejected = Registry::factory()->create(['invoice_id' => $invoice->id]);

        $amendment = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'subsanacion' => true,
            'rechazo_previo' => 'X',
            'amends_registry_id' => $rejected->id,
        ]);

        $amendment->refresh();

        expect($amendment->subsanacion)->toBeTrue()
            ->and($amendment->rechazo_previo)->toBe(RechazoPrevioEnum::X)
            ->and($amendment->amends_registry_id)->toBe($rejected->id);
    });

    it('rejects a second amendment of the same rejected registry at the DB level', function () {
        $invoice = Invoice::factory()->create();
        $rejected = Registry::factory()->create(['invoice_id' => $invoice->id]);

        Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'amends_registry_id' => $rejected->id,
        ]);

        expect(fn () => Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'amends_registry_id' => $rejected->id,
        ]))->toThrow(Illuminate\Database\QueryException::class);
    });

    it('allows multiple registries with a null amends_registry_id', function () {
        $invoice = Invoice::factory()->create();

        Registry::factory()->create(['invoice_id' => $invoice->id, 'amends_registry_id' => null]);
        Registry::factory()->create(['invoice_id' => $invoice->id, 'amends_registry_id' => null]);

        expect(Registry::whereNull('amends_registry_id')->count())->toBe(2);
    });
});
```

Add the import `use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;` at the top of the file.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/RegistryManagerTest.php --filter='subsanación columns'`
Expected: FAIL — unknown column `subsanacion` (the migration does not exist yet).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_24_000001_add_subsanacion_to_verifactu_registries_table.php`:

```php
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
            $table->dropUnique('verifactu_registries_amends_unique');
            $table->dropForeign(['amends_registry_id']);
        });

        Schema::table('verifactu_registries', function (Blueprint $table) {
            $table->dropColumn(['subsanacion', 'rechazo_previo', 'amends_registry_id']);
        });
    }
};
```

> **Note on the unique index:** a plain `->unique()` on a nullable column (a "nullable unique index") enforces single-amendment for non-null values; multiple null `amends_registry_id` rows are the norm (every normal registry) and MUST stay allowed. MySQL and SQLite both treat multiple `NULL`s as distinct in a standard unique index (they never collide), which is the behaviour the third test asserts; the index name is pinned so `down()` can drop it.

- [ ] **Step 4: Register the migration in the ServiceProvider**

In `src/LaraVerifactuServiceProvider.php`, append the new entry to the `hasMigrations([...])` array (after `'2026_06_20_000001_add_calificacion_to_verifactu_invoice_breakdowns_table'`):

```php
                '2026_06_20_000001_add_calificacion_to_verifactu_invoice_breakdowns_table',
                // AID-137: subsanación («ALTA POR RECHAZO») columns. MUST be
                // listed here or publishMigrations() never copies it to
                // consumers (CI does not catch this — tests load the folder).
                '2026_06_24_000001_add_subsanacion_to_verifactu_registries_table',
```

- [ ] **Step 5: Wire the model fillable + cast + PHPDoc**

In `src/Models/Registry.php`:

Add to `$fillable` (after `'registry_type',`):

```php
        'subsanacion',
        'rechazo_previo',
        'amends_registry_id',
```

Add to `$casts` (after `'registry_type' => RegistryTypeEnum::class,`):

```php
        'subsanacion' => 'boolean',
        'rechazo_previo' => RechazoPrevioEnum::class,
        'amends_registry_id' => 'integer',
```

Add the import `use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;` and extend the class PHPDoc property block (after `@property RegistryTypeEnum $registry_type`):

```php
 * @property bool $subsanacion
 * @property RechazoPrevioEnum|null $rechazo_previo
 * @property int|null $amends_registry_id
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/RegistryManagerTest.php --filter='subsanación columns'`
Expected: PASS (all three: persistence+cast, DB-level double-amendment rejection, multiple-null allowance).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_06_24_000001_add_subsanacion_to_verifactu_registries_table.php src/LaraVerifactuServiceProvider.php src/Models/Registry.php tests/Feature/RegistryManagerTest.php
git commit -m "feat: add subsanacion/rechazo_previo/amends_registry_id columns (AID-137)"
```

### Task 4: `RegistryContract` accessors — `getRegistryType()` / `getAmendsRegistryId()` / `getId()` (+ model)

**Files:**
- Modify: `src/Contracts/RegistryContract.php`
- Modify: `src/Models/Registry.php`
- Test: `tests/Feature/RegistryManagerTest.php`

**Interfaces:**
- Produces: `RegistryContract::getRegistryType(): RegistryTypeEnum` (guard 1 in Task 9), `RegistryContract::getAmendsRegistryId(): ?int`, and `RegistryContract::getId(): int|string|null` (consistent with `InvoiceContract::getId()`; used by guard 5 and `amends_registry_id` assignment in Task 9). The model already casts `registry_type` to the enum and (after Task 3) `amends_registry_id` to int, so all three accessors are thin reads.
- Consumes: `RegistryTypeEnum` (existing), the Task 3 column.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/RegistryManagerTest.php`:

```php
describe('registry contract accessors', function () {
    it('exposes the registry type, the amended registry id, and the registry id', function () {
        $invoice = Invoice::factory()->create();
        $rejected = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'registry_type' => RegistryTypeEnum::REGISTRATION->value,
        ]);
        $amendment = Registry::factory()->create([
            'invoice_id' => $invoice->id,
            'registry_type' => RegistryTypeEnum::REGISTRATION->value,
            'amends_registry_id' => $rejected->id,
        ]);

        expect($amendment->getRegistryType())->toBe(RegistryTypeEnum::REGISTRATION)
            ->and($amendment->getAmendsRegistryId())->toBe($rejected->id)
            ->and($rejected->getAmendsRegistryId())->toBeNull()
            ->and($rejected->getId())->toBe($rejected->id)
            ->and($amendment->getId())->toBe($amendment->id);
    });
});
```

Add the import `use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;` at the top of the file (if not already present from Task 3's edits).

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/RegistryManagerTest.php --filter='registry contract accessors'`
Expected: FAIL with `Call to undefined method ... getRegistryType()`.

- [ ] **Step 3: Add the contract methods**

In `src/Contracts/RegistryContract.php`, add the import `use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;` (after the existing `use ... RegistryStatusEnum;`) and declare the three methods (e.g. after `getRegistryDate()`):

```php
    /**
     * Get the registry primary key, or null when the model has not been persisted yet.
     * Mirrors InvoiceContract::getId() for guard and FK consistency.
     */
    public function getId(): int|string|null;

    /**
     * Get the registry type (RegistroAlta vs RegistroAnulacion).
     */
    public function getRegistryType(): RegistryTypeEnum;

    /**
     * Get the id of the rejected registry this one amends, or null when this
     * registry is not an amendment («ALTA POR RECHAZO», AID-137).
     */
    public function getAmendsRegistryId(): ?int;
```

- [ ] **Step 4: Implement the accessors on the model**

In `src/Models/Registry.php`, add (in the `RegistryContract Implementation` block, e.g. after `getRegistryDate()`):

```php
    /**
     * Get the registry primary key (mirrors InvoiceContract::getId()).
     */
    public function getId(): int|string|null
    {
        return $this->id;
    }

    /**
     * Get the registry type (RegistroAlta vs RegistroAnulacion).
     */
    public function getRegistryType(): RegistryTypeEnum
    {
        return $this->registry_type;
    }

    /**
     * Get the id of the rejected registry this one amends, or null.
     */
    public function getAmendsRegistryId(): ?int
    {
        return $this->amends_registry_id;
    }
```

(`RegistryTypeEnum` is already imported in the model.)

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/RegistryManagerTest.php --filter='registry contract accessors'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Contracts/RegistryContract.php src/Models/Registry.php tests/Feature/RegistryManagerTest.php
git commit -m "feat: add getRegistryType/getAmendsRegistryId/getId to RegistryContract (AID-137)"
```

### Task 5: `XmlBuilder` emits `Subsanacion` / `RechazoPrevio` (circumstances threaded through `buildRegistrationXml` + contract)

**Files:**
- Modify: `src/Contracts/XmlBuilderContract.php`
- Modify: `src/Services/XmlBuilder.php`
- Test: `tests/Unit/XmlBuilderTest.php`

**Interfaces:**
- `XmlBuilderContract::buildRegistrationXml(InvoiceContract $invoice, RegistryChain $chain, ?RegistrationCircumstances $circumstances = null): string` — the new third param is **optional**, so the existing 16 `buildRegistrationXml($invoice, $chain)` callers (tests + `RegistryManager`, until Task 6) keep compiling and behaving identically (null ⇒ emit nothing).
- XSD position (spec §5, `SuministroInformacion.xsd:105`): `Subsanacion` then `RechazoPrevio` are direct `RegistroAlta` children appended **after `NombreRazonEmisor`** and **immediately before** the `TipoFactura` guard/appendChild. Emit `<sf:Subsanacion>S</sf:Subsanacion>` only when `subsanacion` is true; `<sf:RechazoPrevio>{value}</sf:RechazoPrevio>` only when `rechazoPrevio` is non-null.
- Consumes: `RegistrationCircumstances` (Task 2), `RechazoPrevioEnum` (Task 1).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/XmlBuilderTest.php` (add the imports `use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;` and `use AichaDigital\LaraVerifactu\Support\RegistrationCircumstances;` at the top):

```php
it('omits Subsanacion and RechazoPrevio for a normal alta', function () {
    $invoice = createMockInvoiceForXml();

    $xml = $this->builder->buildRegistrationXml($invoice, $this->chain);

    expect($xml)
        ->not->toContain('<sf:Subsanacion>')
        ->not->toContain('<sf:RechazoPrevio>');
});

it('emits Subsanacion=S and RechazoPrevio=X before TipoFactura for an amendment', function () {
    $invoice = createMockInvoiceForXml();
    $circumstances = new RegistrationCircumstances(
        subsanacion: true,
        rechazoPrevio: RechazoPrevioEnum::X,
    );

    $xml = $this->builder->buildRegistrationXml($invoice, $this->chain, $circumstances);

    expect($xml)
        ->toContain('<sf:Subsanacion>S</sf:Subsanacion>')
        ->toContain('<sf:RechazoPrevio>X</sf:RechazoPrevio>');

    // XSD order: NombreRazonEmisor → Subsanacion → RechazoPrevio → TipoFactura.
    $emisorPos = strpos($xml, '<sf:NombreRazonEmisor>');
    $subsanacionPos = strpos($xml, '<sf:Subsanacion>');
    $rechazoPos = strpos($xml, '<sf:RechazoPrevio>');
    $tipoPos = strpos($xml, '<sf:TipoFactura>');

    expect($emisorPos)->toBeLessThan($subsanacionPos)
        ->and($subsanacionPos)->toBeLessThan($rechazoPos)
        ->and($rechazoPos)->toBeLessThan($tipoPos);
});

it('validates an amendment RegistroAlta against the AEAT XSD', function () {
    $invoice = createMockInvoiceForXml();
    $circumstances = new RegistrationCircumstances(
        subsanacion: true,
        rechazoPrevio: RechazoPrevioEnum::X,
    );

    $xml = $this->builder->buildRegistrationXml($invoice, $this->chain, $circumstances);

    expect($this->builder->validate($xml))->toBeTrue();
});
```

> The `validate()` test confirms XSD position is legal; the literal-`X` assertion is the load-bearing check spec §-«The `RechazoPrevio` value» calls out — XSD accepts any `{N,S,X}` member, so `validate()` alone would not catch a wrong value.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/XmlBuilderTest.php --filter='Subsanacion'`
Expected: FAIL — the third argument is not accepted yet / `Subsanacion` never appears.

- [ ] **Step 3: Extend the contract signature**

In `src/Contracts/XmlBuilderContract.php`, add the import `use AichaDigital\LaraVerifactu\Support\RegistrationCircumstances;` and change the registration method:

```php
    public function buildRegistrationXml(
        InvoiceContract $invoice,
        RegistryChain $chain,
        ?RegistrationCircumstances $circumstances = null
    ): string;
```

- [ ] **Step 4: Thread the param and emit the elements**

In `src/Services/XmlBuilder.php`:

Add the import `use AichaDigital\LaraVerifactu\Support\RegistrationCircumstances;`.

Update the public method to accept and forward the circumstances:

```php
    public function buildRegistrationXml(
        InvoiceContract $invoice,
        RegistryChain $chain,
        ?RegistrationCircumstances $circumstances = null
    ): string {
        try {
            $dom = $this->createDomDocument();
            $root = $this->createEnvelope($dom);

            $registroFactura = $dom->createElementNS(self::LR_NS, 'sfLR:RegistroFactura');
            $root->appendChild($registroFactura);

            $registroFactura->appendChild($this->buildRegistroAlta($dom, $invoice, $chain, $circumstances));

            return $this->formatXml($dom);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw XmlException::cannotBuildXml($e->getMessage());
        }
    }
```

Update the `buildRegistroAlta` signature to accept the circumstances:

```php
    private function buildRegistroAlta(
        DOMDocument $dom,
        InvoiceContract $invoice,
        RegistryChain $chain,
        ?RegistrationCircumstances $circumstances = null
    ): DOMElement {
```

Then, **between** the `NombreRazonEmisor` appendChild (currently line 209) and the `$type = $invoice->getType();` TipoFactura guard (currently line 216), insert:

```php
        $alta->appendChild($this->sfElement($dom, 'NombreRazonEmisor', $this->companyName()));

        // Subsanación circumstances (AID-137). XSD sequence
        // (SuministroInformacion.xsd:105): Subsanacion then RechazoPrevio, after
        // NombreRazonEmisor and before TipoFactura. Emitted only when flagged;
        // a normal alta passes null/empty circumstances and emits neither.
        if ($circumstances !== null) {
            if ($circumstances->subsanacion) {
                $alta->appendChild($this->sfElement($dom, 'Subsanacion', 'S'));
            }

            if ($circumstances->rechazoPrevio !== null) {
                $alta->appendChild($this->sfElement($dom, 'RechazoPrevio', $circumstances->rechazoPrevio->value));
            }
        }
```

(Keep the existing `NombreRazonEmisor` line — replace only the gap that follows it; do not duplicate the line.)

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/XmlBuilderTest.php`
Expected: PASS — the new amendment tests plus all existing registration tests (null circumstances ⇒ unchanged output).

- [ ] **Step 6: Commit**

```bash
git add src/Contracts/XmlBuilderContract.php src/Services/XmlBuilder.php tests/Unit/XmlBuilderTest.php
git commit -m "feat: emit Subsanacion/RechazoPrevio from RegistrationCircumstances (AID-137)"
```

### Task 6: `RegistryManager::createRegistry` gains circumstances; persists `subsanacion` / `rechazo_previo`

**Files:**
- Modify: `src/Services/RegistryManager.php`
- Test: `tests/Feature/RegistryManagerTest.php`

**Interfaces:**
- `RegistryManager::createRegistry(InvoiceContract $invoice, ?RegistrationCircumstances $circumstances = null): RegistryContract` — the optional param is forwarded to `$this->xmlBuilder->buildRegistrationXml($invoice, $chain, $circumstances)` and persisted onto the new `Registry` (`subsanacion`, `rechazo_previo`). A null circumstances persists `subsanacion=false`, `rechazo_previo=null` (the normal-alta path; existing callers unchanged). `amends_registry_id` is NOT set here — Task 9 sets it after `createRegistry` returns, so this method stays a pure chain-link builder.
- Consumes: `RegistrationCircumstances` (Task 2), `XmlBuilderContract::buildRegistrationXml` 3-arg (Task 5).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/RegistryManagerTest.php`. (The `beforeEach` mocks `XmlBuilderContract`; this test sets the `buildRegistrationXml` expectation to capture the circumstances arg.)

```php
describe('createRegistry circumstances', function () {
    it('forwards circumstances to the builder and persists them', function () {
        $invoice = Invoice::factory()->create();

        $this->hashGenerator->shouldReceive('generate')->andReturn(str_repeat('A', 64));
        $this->qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
        $this->qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
        $this->qrGenerator->shouldReceive('generatePng')->andReturn('png');

        $captured = null;
        $this->xmlBuilder
            ->shouldReceive('buildRegistrationXml')
            ->andReturnUsing(function ($inv, $chain, $circ = null) use (&$captured) {
                $captured = $circ;

                return '<xml/>';
            });

        $circumstances = new RegistrationCircumstances(
            subsanacion: true,
            rechazoPrevio: RechazoPrevioEnum::X,
        );

        $registry = $this->registryManager->createRegistry($invoice, $circumstances);
        $registry->refresh();

        expect($captured)->toBe($circumstances)
            ->and($registry->subsanacion)->toBeTrue()
            ->and($registry->rechazo_previo)->toBe(RechazoPrevioEnum::X);
    });

    it('defaults to a normal alta when no circumstances are given', function () {
        $invoice = Invoice::factory()->create();

        $this->hashGenerator->shouldReceive('generate')->andReturn(str_repeat('B', 64));
        $this->qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
        $this->qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
        $this->qrGenerator->shouldReceive('generatePng')->andReturn('png');
        $this->xmlBuilder->shouldReceive('buildRegistrationXml')->andReturn('<xml/>');

        $registry = $this->registryManager->createRegistry($invoice);
        $registry->refresh();

        expect($registry->subsanacion)->toBeFalse()
            ->and($registry->rechazo_previo)->toBeNull();
    });
});
```

Add the imports `use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;` and `use AichaDigital\LaraVerifactu\Support\RegistrationCircumstances;` at the top of the file (if not already present).

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/RegistryManagerTest.php --filter='createRegistry circumstances'`
Expected: FAIL — `createRegistry()` takes one argument / `subsanacion` is never persisted.

- [ ] **Step 3: Thread circumstances through `createRegistry`**

In `src/Services/RegistryManager.php`, add the import `use AichaDigital\LaraVerifactu\Support\RegistrationCircumstances;` and update the method signature + the two touch points (builder call and the `Registry::create([...])` array):

```php
    public function createRegistry(
        InvoiceContract $invoice,
        ?RegistrationCircumstances $circumstances = null
    ): RegistryContract {
        return DB::transaction(function () use ($invoice, $circumstances) {
            // ... unchanged: previousRegistry, hash, registryNumber, $chain ...

            $xml = $this->xmlBuilder->buildRegistrationXml($invoice, $chain, $circumstances);

            // ... unchanged: QR generation ...

            $registry = Registry::create([
                'invoice_id' => $invoice->id ?? null,
                'registry_number' => $registryNumber,
                'registry_date' => Carbon::now(),
                'registry_type' => RegistryTypeEnum::REGISTRATION->value,
                'subsanacion' => $circumstances?->subsanacion ?? false,
                'rechazo_previo' => $circumstances?->rechazoPrevio?->value,
                'hash' => $hash,
                'previous_hash' => $previousHash,
                'hash_generated_at' => $generatedAt->format('c'),
                'qr_url' => $qrUrl,
                'qr_svg' => $qrSvg,
                'qr_png' => $qrPng,
                'xml' => $xml,
                'status' => RegistryStatusEnum::PENDING->value,
                'submission_attempts' => 0,
            ]);

            event(new RegistryCreatedEvent($registry, $invoice));

            return $registry;
        });
    }
```

> Only three edits: the signature, the `use (...)` closure capture (`$circumstances`), the builder call, and the two new array keys. Leave the previous-registry/hash/QR logic untouched. `rechazo_previo` is persisted as the raw `->value` (the model's enum cast reads it back as `RechazoPrevioEnum`).

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/RegistryManagerTest.php --filter='createRegistry circumstances'`
Expected: PASS. Also run the full `RegistryManagerTest.php` to confirm the existing `createRegistry`/blockchain tests (which call the 2-arg form and mock `buildRegistrationXml` with `->andReturn(...)`) still pass — an optional trailing arg does not break a Mockery `shouldReceive` without arg constraints.

Run: `vendor/bin/pest tests/Feature/RegistryManagerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/RegistryManager.php tests/Feature/RegistryManagerTest.php
git commit -m "feat: thread RegistrationCircumstances through createRegistry (AID-137)"
```

### Task 7: `HashGenerator` `generate*FromParts` + thin wrappers (§8 part 1, pure refactor)

**Files:**
- Modify: `src/Contracts/HashGeneratorContract.php`
- Modify: `src/Services/HashGenerator.php`
- Test: `tests/Unit/HashGeneratorTest.php`

**Interfaces (spec §8, decided — option B strict, typed params NOT `array $parts`):**
- `generateRegistrationFromParts(string $issuerTaxId, string $numSerieFactura, string $fechaExpedicion, string $tipoFactura, string $cuotaTotal, string $importeTotal, ?string $previousHash, string $fechaHoraHusoGen): string` — `$fechaExpedicion` is already `d-m-Y`, `$cuotaTotal`/`$importeTotal` already `number_format(2,'.','')`, `$fechaHoraHusoGen` already ISO-8601 `format('c')`. It calls the existing private `buildChain()` with the exact AEAT field order, so the formula is never duplicated.
- `generateCancellationFromParts(string $issuerTaxId, string $numSerieFactura, string $fechaExpedicion, ?string $previousHash, string $fechaHoraHusoGen): string`.
- `generate(InvoiceContract,...)` and `generateCancellation(...)` become **thin wrappers** that compute the primitive parts and delegate. **No behavior change** — the existing 14 HashGeneratorTest assertions stay green unchanged.
- Consumed by Task 8 (`verifyRegistryHash` from persisted XML).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/HashGeneratorTest.php`:

```php
it('regenerates the registration hash from typed parts identical to generate()', function () {
    $invoice = createMockInvoice([
        'number' => 'F-2025-001',
        'issue_datetime' => Carbon::parse('2025-10-11 10:30:00'),
        'type' => InvoiceTypeEnum::COMPLETE,
        'total_amount' => '121.00',
        'total_tax_amount' => '21.00',
    ]);
    $generatedAt = Carbon::parse('2025-10-11T10:30:30+02:00');

    $fromInvoice = $this->generator->generate($invoice, 'PREVHASH', $generatedAt);

    $fromParts = $this->generator->generateRegistrationFromParts(
        issuerTaxId: $invoice->getIssuerTaxId(),
        numSerieFactura: 'F-2025-001',
        fechaExpedicion: '11-10-2025',
        tipoFactura: 'F1',
        cuotaTotal: '21.00',
        importeTotal: '121.00',
        previousHash: 'PREVHASH',
        fechaHoraHusoGen: $generatedAt->format('c'),
    );

    expect($fromParts)->toBe($fromInvoice)
        ->and($fromParts)->toMatch('/^[A-F0-9]{64}$/');
});

it('regenerates the cancellation hash from typed parts identical to generateCancellation()', function () {
    $issueDate = Carbon::parse('2025-10-11 10:30:00');
    $generatedAt = Carbon::parse('2025-10-11T11:00:00+02:00');

    $fromMethod = $this->generator->generateCancellation(
        'B12345678',
        'F-2025-001',
        $issueDate,
        'PREVHASH',
        $generatedAt,
    );

    $fromParts = $this->generator->generateCancellationFromParts(
        issuerTaxId: 'B12345678',
        numSerieFactura: 'F-2025-001',
        fechaExpedicion: '11-10-2025',
        previousHash: 'PREVHASH',
        fechaHoraHusoGen: $generatedAt->format('c'),
    );

    expect($fromParts)->toBe($fromMethod);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Unit/HashGeneratorTest.php --filter='from typed parts'`
Expected: FAIL with `Call to undefined method ... generateRegistrationFromParts()`.

- [ ] **Step 3: Add the typed methods and refactor the wrappers**

In `src/Services/HashGenerator.php`, replace the bodies of `generate()` and `generateCancellation()` so they delegate, and add the two `*FromParts` methods. The `buildChain()` call (the AEAT field order) now lives only in the `*FromParts` methods:

```php
    public function generate(
        InvoiceContract $invoice,
        ?string $previousHash = null,
        ?DateTimeInterface $generatedAt = null,
    ): string {
        try {
            $invoiceNumber = $invoice->getSerie()
                ? $invoice->getSerie() . $invoice->getNumber()
                : $invoice->getNumber();

            return $this->generateRegistrationFromParts(
                issuerTaxId: $invoice->getIssuerTaxId(),
                numSerieFactura: $invoiceNumber,
                fechaExpedicion: $invoice->getIssueDatetime()->format('d-m-Y'),
                tipoFactura: $invoice->getType()->value,
                cuotaTotal: $this->formatAmount($invoice->getTaxAmount()),
                importeTotal: $this->formatAmount($invoice->getTotalAmount()),
                previousHash: $previousHash,
                fechaHoraHusoGen: $this->formatTimestamp($generatedAt ?? now()),
            );
        } catch (\Throwable $e) {
            throw HashException::cannotGenerateHash($e->getMessage());
        }
    }

    /**
     * Registration fingerprint from already-formatted primitive parts. Inputs
     * must already be AEAT-formatted: fechaExpedicion as d-m-Y, cuota/importe
     * as 2-decimal dot strings, fechaHoraHusoGen as ISO-8601 with offset.
     * Calls buildChain() so the AEAT formula lives in exactly one place.
     */
    public function generateRegistrationFromParts(
        string $issuerTaxId,
        string $numSerieFactura,
        string $fechaExpedicion,
        string $tipoFactura,
        string $cuotaTotal,
        string $importeTotal,
        ?string $previousHash,
        string $fechaHoraHusoGen,
    ): string {
        $chain = $this->buildChain([
            'IDEmisorFactura' => $issuerTaxId,
            'NumSerieFactura' => $numSerieFactura,
            'FechaExpedicionFactura' => $fechaExpedicion,
            'TipoFactura' => $tipoFactura,
            'CuotaTotal' => $cuotaTotal,
            'ImporteTotal' => $importeTotal,
            'Huella' => $previousHash,
            'FechaHoraHusoGenRegistro' => $fechaHoraHusoGen,
        ]);

        return strtoupper(hash('sha256', $chain));
    }

    public function generateCancellation(
        string $issuerTaxId,
        string $invoiceNumber,
        DateTimeInterface $issueDate,
        ?string $previousHash = null,
        ?DateTimeInterface $generatedAt = null,
    ): string {
        try {
            return $this->generateCancellationFromParts(
                issuerTaxId: $issuerTaxId,
                numSerieFactura: $invoiceNumber,
                fechaExpedicion: $issueDate->format('d-m-Y'),
                previousHash: $previousHash,
                fechaHoraHusoGen: $this->formatTimestamp($generatedAt ?? now()),
            );
        } catch (\Throwable $e) {
            throw HashException::cannotGenerateHash($e->getMessage());
        }
    }

    /**
     * Cancellation fingerprint from already-formatted primitive parts.
     */
    public function generateCancellationFromParts(
        string $issuerTaxId,
        string $numSerieFactura,
        string $fechaExpedicion,
        ?string $previousHash,
        string $fechaHoraHusoGen,
    ): string {
        $chain = $this->buildChain([
            'IDEmisorFacturaAnulada' => $issuerTaxId,
            'NumSerieFacturaAnulada' => $numSerieFactura,
            'FechaExpedicionFacturaAnulada' => $fechaExpedicion,
            'Huella' => $previousHash,
            'FechaHoraHusoGenRegistro' => $fechaHoraHusoGen,
        ]);

        return strtoupper(hash('sha256', $chain));
    }
```

Keep `verify()`, `buildChain()`, `formatAmount()`, `formatTimestamp()` unchanged.

- [ ] **Step 4: Declare the methods on the contract**

In `src/Contracts/HashGeneratorContract.php`, add the two method signatures (mirror the bodies above; no implementation). This keeps the contract honest for Task 8, which calls them through `HashGeneratorContract`:

```php
    /**
     * Registration fingerprint from already-formatted primitive parts (used to
     * verify from persisted XML without the mutable Invoice).
     */
    public function generateRegistrationFromParts(
        string $issuerTaxId,
        string $numSerieFactura,
        string $fechaExpedicion,
        string $tipoFactura,
        string $cuotaTotal,
        string $importeTotal,
        ?string $previousHash,
        string $fechaHoraHusoGen,
    ): string;

    /**
     * Cancellation fingerprint from already-formatted primitive parts.
     */
    public function generateCancellationFromParts(
        string $issuerTaxId,
        string $numSerieFactura,
        string $fechaExpedicion,
        ?string $previousHash,
        string $fechaHoraHusoGen,
    ): string;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Unit/HashGeneratorTest.php`
Expected: PASS — the two new `*FromParts` tests plus all 14 existing tests (the wrappers reproduce the exact chain, so every `generate()`/`generateCancellation()` assertion is unchanged).

- [ ] **Step 6: Commit**

```bash
git add src/Contracts/HashGeneratorContract.php src/Services/HashGenerator.php tests/Unit/HashGeneratorTest.php
git commit -m "refactor: add HashGenerator generate*FromParts, wrappers delegate (AID-137 §8)"
```

### Task 8: `RegistryManager::verifyRegistryHash` from persisted XML, fail-loud, both registry types (§8 part 2)

**Files:**
- Modify: `src/Services/RegistryManager.php` (`verifyRegistryHash` only)
- Test: `tests/Feature/BlockchainReproducibilityTest.php`

**Interfaces:**
- `verifyRegistryHash(Registry $registry): bool` (private) is rewritten to extract the hash inputs from the registry's **persisted XML** via namespaced `sf:` XPath, plus the `previous_hash` / `hash_generated_at` columns, dispatch on `registry_type`, and call the matching `*FromParts` (Task 7). It **no longer reads `$registry->invoice`** (spec §8). FAIL-LOUD: null/unparseable XML or any missing node → return `false` (chain invalid), never fall back to the invoice. Covers BOTH RegistroAlta and RegistroAnulacion.
- Consumes: `HashGeneratorContract::generateRegistrationFromParts` / `generateCancellationFromParts` (Task 7).
- The `sf:` XPath targets are the element names the XML carries (confirmed in `XmlBuilder`): Alta = `IDEmisorFactura`, `NumSerieFactura`, `FechaExpedicionFactura`, `TipoFactura`, `CuotaTotal`, `ImporteTotal`, `FechaHoraHusoGenRegistro`; Anulación = `IDEmisorFacturaAnulada`, `NumSerieFacturaAnulada`, `FechaExpedicionFacturaAnulada`, `FechaHoraHusoGenRegistro`. The `sf` prefix binds to `XmlBuilder::SF_NS` (`.../SuministroInformacion.xsd`). Note the Alta's `IDEmisorFactura`/`NumSerieFactura`/`FechaExpedicionFactura` appear in BOTH `IDFactura` and (when chained) `RegistroAnterior`/`RegistroAnterior` — scope the XPath to the `RegistroAlta`/`RegistroAnulacion` direct children so the previous-record block is never read. Use a descendant query under `//sf:RegistroAlta/sf:IDFactura/sf:IDEmisorFactura` and `//sf:RegistroAlta/sf:CuotaTotal` (direct-child axis), not a bare `//sf:IDEmisorFactura`.

> **Why the test file changes:** `BlockchainReproducibilityTest` currently mocks `XmlBuilderContract` to return `<xml/>`. Under the new fail-loud verify, `<xml/>` has no `sf:` nodes → verify returns false → the existing "verifies blockchain integrity" test would break. The verify path now genuinely depends on real persisted XML, so this test must construct the `RegistryManager` with a **real `XmlBuilder`** (matching `BlockchainReproducibilityTest`'s intent: end-to-end reproducibility). The tamper test stays valid (a mutated `hash` still mismatches the XML-derived recompute).

- [ ] **Step 1: Rewrite the test setup to use a real XmlBuilder, add fail-loud cases**

Replace the `beforeEach` in `tests/Feature/BlockchainReproducibilityTest.php` so the builder is real (it needs company config + a QR mock only):

```php
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

    $this->registryManager = new RegistryManager(
        new HashGenerator,
        $qrGenerator,
        new XmlBuilder,
    );
});
```

Add the import `use AichaDigital\LaraVerifactu\Services\XmlBuilder;` at the top.

> The `Invoice::factory()` default must produce an XSD-valid RegistroAlta with the real builder (issuer tax id matching config company tax id is not required, but the invoice must satisfy the builder guards: F1 with a recipient, a valid breakdown, issue date ≥ 2024-10-28). Confirm the existing `Invoice::factory()` already does — `BlockchainReproducibilityTest` previously only mocked the builder, so the factory may need a recipient/breakdown. **Check `Invoice::factory()` + related factories first; if the default invoice does not build valid XML, use the same factory states the passing `RegistryManagerTest` end-to-end / conformance tests use** (e.g. an F1 with one 21% breakdown and a recipient). Do not invent fields — reuse an existing valid-invoice factory recipe from the green suite.

The existing two real tests ("persists the hash generation timestamp", "verifies blockchain integrity across registries created at different times", "detects tampering") stay as-is — they now exercise the real builder + the new XML-based verify end to end.

Add the fail-loud cases:

```php
it('fails verification when the persisted XML is missing', function () {
    $invoice = Invoice::factory()->create();
    $registry = $this->registryManager->createRegistry($invoice);

    // Null the XML the verify path depends on (simulating corruption).
    $registry->update(['xml' => null]);

    $result = $this->registryManager->verifyBlockchain();

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty();
});

it('fails verification when the persisted XML is unparseable', function () {
    $invoice = Invoice::factory()->create();
    $registry = $this->registryManager->createRegistry($invoice);

    $registry->update(['xml' => 'not-xml <<<']);

    $result = $this->registryManager->verifyBlockchain();

    expect($result['valid'])->toBeFalse();
});

it('verifies a cancellation registry hash from its persisted XML', function () {
    $invoice = Invoice::factory()->create();
    $this->registryManager->createRegistry($invoice);
    $this->registryManager->createCancellationRegistry($invoice);

    $result = $this->registryManager->verifyBlockchain();

    expect($result['valid'])->toBeTrue()
        ->and($result['errors'])->toBeEmpty();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/BlockchainReproducibilityTest.php`
Expected: FAIL — the current `verifyRegistryHash` reads `$registry->invoice` and ignores the XML, so the "missing XML" / "unparseable XML" cases still report valid (false negative for the new contract). The cancellation case may already pass via the invoice path; the point is the fail-loud cases.

- [ ] **Step 3: Rewrite `verifyRegistryHash` to read the persisted XML**

In `src/Services/RegistryManager.php`, replace the `verifyRegistryHash` method body with an XML-driven, fail-loud implementation. Add `use DOMDocument;` and `use DOMXPath;` at the top if not present.

```php
    /**
     * Rebuild and compare a registry's hash from its PERSISTED XML (spec §8).
     *
     * The hash inputs are read from the immutable historical XML via namespaced
     * (sf:) XPath plus the previous_hash / hash_generated_at columns — never from
     * $registry->invoice, which is mutable and would make verification lie after
     * an amendment feeds corrected data. Fail-loud: a null/unparseable XML or any
     * missing node returns false (chain invalid). Covers RegistroAlta and
     * RegistroAnulacion (both had the mutable-invoice bug).
     */
    private function verifyRegistryHash(Registry $registry): bool
    {
        $xml = $registry->xml;

        if ($xml === null || $xml === '') {
            return false;
        }

        $previousUseErrors = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($xml)) {
                return false;
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('sf', self::SF_NS);

            $generatedAt = $registry->hash_generated_at;

            if ($generatedAt === null) {
                return false;
            }

            if ($registry->registry_type === RegistryTypeEnum::CANCELLATION) {
                $issuer = $this->xpathValue($xpath, '//sf:RegistroAnulacion/sf:IDFactura/sf:IDEmisorFacturaAnulada');
                $numSerie = $this->xpathValue($xpath, '//sf:RegistroAnulacion/sf:IDFactura/sf:NumSerieFacturaAnulada');
                $fecha = $this->xpathValue($xpath, '//sf:RegistroAnulacion/sf:IDFactura/sf:FechaExpedicionFacturaAnulada');

                if ($issuer === null || $numSerie === null || $fecha === null) {
                    return false;
                }

                $expected = $this->hashGenerator->generateCancellationFromParts(
                    issuerTaxId: $issuer,
                    numSerieFactura: $numSerie,
                    fechaExpedicion: $fecha,
                    previousHash: $registry->previous_hash,
                    fechaHoraHusoGen: $generatedAt,
                );

                return hash_equals($expected, strtoupper($registry->hash));
            }

            $issuer = $this->xpathValue($xpath, '//sf:RegistroAlta/sf:IDFactura/sf:IDEmisorFactura');
            $numSerie = $this->xpathValue($xpath, '//sf:RegistroAlta/sf:IDFactura/sf:NumSerieFactura');
            $fecha = $this->xpathValue($xpath, '//sf:RegistroAlta/sf:IDFactura/sf:FechaExpedicionFactura');
            $tipo = $this->xpathValue($xpath, '//sf:RegistroAlta/sf:TipoFactura');
            $cuota = $this->xpathValue($xpath, '//sf:RegistroAlta/sf:CuotaTotal');
            $importe = $this->xpathValue($xpath, '//sf:RegistroAlta/sf:ImporteTotal');

            if ($issuer === null || $numSerie === null || $fecha === null
                || $tipo === null || $cuota === null || $importe === null) {
                return false;
            }

            $expected = $this->hashGenerator->generateRegistrationFromParts(
                issuerTaxId: $issuer,
                numSerieFactura: $numSerie,
                fechaExpedicion: $fecha,
                tipoFactura: $tipo,
                cuotaTotal: $cuota,
                importeTotal: $importe,
                previousHash: $registry->previous_hash,
                fechaHoraHusoGen: $generatedAt,
            );

            return hash_equals($expected, strtoupper($registry->hash));
        } catch (\Throwable) {
            return false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    /**
     * Read the trimmed text of the first node matching $query, or null when the
     * node is absent (fail-loud: a missing required node invalidates the hash).
     */
    private function xpathValue(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = $nodes->item(0)?->textContent;

        return $value !== null ? trim($value) : null;
    }
```

Add a private `SF_NS` constant to `RegistryManager` mirroring the builder's, so the XPath namespace binding is self-contained:

```php
    /** AEAT SuministroInformacion namespace — the sf: prefix used in persisted XML. */
    private const SF_NS = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';
```

> `hash_generated_at` is stored as the ISO-8601 string (`format('c')`), which is exactly the `fechaHoraHusoGen` the `*FromParts` methods expect — pass it through verbatim (no `Carbon::parse` → re-`format('c')` round-trip, which is what the old code did and is equivalent, but passing the stored string avoids any reformatting drift). The XML's `FechaHoraHusoGenRegistro` equals this same string, so reading the column is correct and cheaper than an extra XPath.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/BlockchainReproducibilityTest.php`
Expected: PASS — reproducibility across times, tamper detection, both fail-loud cases (null + unparseable XML), and the cancellation case all green.

Also run the broader chain/manager suites that touch verify:

Run: `vendor/bin/pest tests/Feature/RegistryManagerTest.php`
Expected: PASS — the `verifyBlockchain` describe block there uses `Registry::factory()` with `xml => '<xml></xml>'` and a mocked `hashGenerator->verify`. **Audit these:** the factory's default `xml` is `'<xml></xml>'` (no `sf:` nodes), and the manager-level `verifyBlockchain` tests previously relied on `hashGenerator->shouldReceive('verify')->andReturn(true)`. Since `verifyRegistryHash` no longer calls `verify()`, those mocks are dead and the `<xml></xml>` fixtures now fail-loud to `false`. **Update those `RegistryManagerTest` verifyBlockchain cases** to either (a) build real XML via a real builder (as in BlockchainReproducibilityTest), or (b) move the pure-chaining `previous_hash` assertions to fixtures whose `xml` carries valid `sf:` nodes. Prefer reusing the BlockchainReproducibilityTest real-builder setup; do not weaken the new fail-loud contract to keep a stale mock green.

- [ ] **Step 5: Commit**

```bash
git add src/Services/RegistryManager.php tests/Feature/BlockchainReproducibilityTest.php tests/Feature/RegistryManagerTest.php
git commit -m "feat: verify registry hash from persisted XML, fail-loud, both types (AID-137 §8)"
```

### Task 9: `InvoiceRegistrar::amendRejected` + `Verifactu` facade — five fail-loud guards + `amends_registry_id` + happy path

**Files:**
- Modify: `src/Services/InvoiceRegistrar.php`
- Modify: `src/Verifactu.php`
- Test: `tests/Feature/AmendRejectedTest.php` (new)

**Interfaces:**
- `InvoiceRegistrar::amendRejected(RegistryContract $rejectedRegistry, InvoiceContract $correctedInvoice, bool $submitToAeat = true): RegistryContract` — runs the five guards (fail-loud, in order), then `createRegistry($correctedInvoice, new RegistrationCircumstances(subsanacion: true, rechazoPrevio: RechazoPrevioEnum::X))`, sets `amends_registry_id = $rejectedRegistry->getAmendsRegistryId()`-target (the rejected id), signs, optionally submits. Mirrors `register()`'s `DB::transaction` shape.
- `Verifactu::amendRejected(...)` mirrors `register`/`cancel` — delegates to the registrar (no fake-mode branch needed for v1; if added, mirror `register`'s fake recording).
- Consumes: Tasks 1, 2, 4, 6. Guards read AID-257 data (`getStatus()` → `REJECTED`, `getAeatResponse()` → `{lineas:[{registro_duplicado}]}`), the persisted XML (`getXml()`), and the DB (double-amendment).

**The five guards (spec §3, fail-loud in order):**

1. **`getRegistryType() === REGISTRATION`** — a cancellation cannot be amended-by-rejection. Else `VerifactuException::make('amendRejected expects a REGISTRATION registry, got <type>')`.
2. **`getStatus() === REJECTED`** — reachable via AID-257. An `ACCEPTED`/other status → `VerifactuException::make('... only a REJECTED registration can be amended by rejection; for an accepted/registered key use the AID-209 subsanación flow')`.
3. **Rejection proves "not in AEAT"** — inspect `getAeatResponse()['lineas']`; if ANY line has `registro_duplicado === true` (duplicate-key ⇒ the key exists ⇒ `RechazoPrevio=X` would be re-rejected), fail loud: `VerifactuException::make('... rejection is a duplicate-key/already-registered code; the key exists in AEAT, so RechazoPrevio=X is invalid (AID-209)')`. A null/empty `aeat_response` also fails loud (cannot prove not-in-AEAT).
4. **`IDFactura` matches the rejected record's persisted historical XML** — parse `IDEmisorFactura`/`NumSerieFactura`/`FechaExpedicionFactura` from `$rejectedRegistry->getXml()` via namespaced `sf:` XPath (scoped to `//sf:RegistroAlta/sf:IDFactura/...`), compare against the corrected invoice using the **builder's convention** `getSerie().getNumber()` (NOT `getInvoiceNumber()`) and date `d-m-Y`. Fail loud if `getXml()` is null/empty or any node is missing, or any field differs. Compared against the immutable XML, never `getInvoice()`.
5. **No double amendment** — no registry (`withTrashed()`) already has `amends_registry_id = rejected.id`. Else `VerifactuException::make('... registry <id> has already been amended')`. Backed by the DB unique index (Task 3) for the concurrent race.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/AmendRejectedTest.php`. Use a real `XmlBuilder` so the rejected record's persisted XML carries real `sf:` nodes for guard 4, and a mocked `AeatClientContract` so submission is deterministic. Build the registrar from real services + the bound `RegistryManager`.

```php
<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;
use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use AichaDigital\LaraVerifactu\Support\AeatResponse;

beforeEach(function () {
    config()->set('verifactu.company.tax_id', '89890001K');
    config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
    config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
    config()->set('verifactu.system.vendor_nif', 'B70123456');
    config()->set('verifactu.system.name', 'LaraVerifactu');
    config()->set('verifactu.system.id', 'LV');
    config()->set('verifactu.system.version', '1.0');
    config()->set('verifactu.system.installation_number', '1');

    $qrGenerator = Mockery::mock(\AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract::class);
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

/**
 * Create a REJECTED initial registration whose persisted XML is real, plus the
 * Invoice it was built from. Returns [$rejected, $invoice].
 */
function rejectedRegistration(RegistryManager $manager): array
{
    $invoice = Invoice::factory()->create();
    $registry = $manager->createRegistry($invoice);
    $registry->update([
        'status' => RegistryStatusEnum::REJECTED->value,
        'aeat_response' => [
            'estado_envio' => 'Incorrecto',
            'lineas' => [[
                'estado_registro' => 'Incorrecto',
                'codigo' => '3002',
                'descripcion' => 'NIF del IDFactura no identificado',
                'registro_duplicado' => false,
            ]],
        ],
    ]);

    return [$registry->fresh(), $invoice];
}

it('amends a rejected registration with Subsanacion=S + RechazoPrevio=X', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);

    $this->aeatClient->shouldReceive('sendRegistration')->andReturn(
        new AeatResponse(success: true, code: 'CSV-OK', message: 'Correcto')
    );

    $amendment = $this->registrar->amendRejected($rejected, $invoice);
    $amendment->refresh();

    expect($amendment->getRegistryType())->toBe(RegistryTypeEnum::REGISTRATION)
        ->and($amendment->subsanacion)->toBeTrue()
        ->and($amendment->rechazo_previo)->toBe(RechazoPrevioEnum::X)
        ->and($amendment->amends_registry_id)->toBe($rejected->id)
        ->and($amendment->xml)->toContain('<sf:Subsanacion>S</sf:Subsanacion>')
        ->and($amendment->xml)->toContain('<sf:RechazoPrevio>X</sf:RechazoPrevio>');

    // Rejected record + its XML untouched.
    $rejected->refresh();
    expect($rejected->status)->toBe(RegistryStatusEnum::REJECTED)
        ->and($rejected->subsanacion)->toBeFalse();
});

it('chains the amendment after the last generated link, not the rejected record', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $this->aeatClient->shouldReceive('sendRegistration')->andReturn(new AeatResponse(success: true, code: 'CSV', message: 'Correcto'));

    // Create an INTERVENING normal registry for a different invoice so the chain
    // advances past the rejected record.
    $interveningInvoice = Invoice::factory()->create();
    $intervening = $this->registryManager->createRegistry($interveningInvoice);

    $amendment = $this->registrar->amendRejected($rejected, $invoice);
    $amendment->refresh();

    // The amendment must chain after the last generated record (the intervening
    // one), NOT after the rejected business record.
    expect($amendment->previous_hash)->toBe($intervening->hash)
        ->and($amendment->previous_hash)->not->toBe($rejected->hash);
});

it('guard 1: rejects amending a cancellation registry', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $rejected->update(['registry_type' => RegistryTypeEnum::CANCELLATION->value]);

    expect(fn () => $this->registrar->amendRejected($rejected->fresh(), $invoice))
        ->toThrow(VerifactuException::class);
});

it('guard 2: rejects amending a non-REJECTED registry', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $rejected->update(['status' => RegistryStatusEnum::ACCEPTED->value]);

    expect(fn () => $this->registrar->amendRejected($rejected->fresh(), $invoice))
        ->toThrow(VerifactuException::class);
});

it('guard 3: rejects when the rejection is a duplicate-key (key exists in AEAT)', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $rejected->update([
        'aeat_response' => [
            'estado_envio' => 'Incorrecto',
            'lineas' => [['codigo' => '3000', 'registro_duplicado' => true]],
        ],
    ]);

    expect(fn () => $this->registrar->amendRejected($rejected->fresh(), $invoice))
        ->toThrow(VerifactuException::class);
});

it('guard 3: rejects when lineas is empty (cannot prove key is absent from AEAT)', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $rejected->update([
        'aeat_response' => [
            'estado_envio' => 'Incorrecto',
            'lineas' => [],
        ],
    ]);

    expect(fn () => $this->registrar->amendRejected($rejected->fresh(), $invoice))
        ->toThrow(VerifactuException::class);
});

it('guard 3: rejects when a line is missing the registro_duplicado key (unknown shape)', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $rejected->update([
        'aeat_response' => [
            'estado_envio' => 'Incorrecto',
            'lineas' => [['codigo' => '3002', 'descripcion' => 'NIF no identificado']],
        ],
    ]);

    expect(fn () => $this->registrar->amendRejected($rejected->fresh(), $invoice))
        ->toThrow(VerifactuException::class);
});

it('guard 4: rejects when the corrected invoice IDFactura does not match the persisted XML', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);

    // A different invoice (different number) => IDFactura mismatch vs rejected XML.
    $other = Invoice::factory()->create(['number' => 'DIFFERENT-001']);

    expect(fn () => $this->registrar->amendRejected($rejected, $other))
        ->toThrow(VerifactuException::class);
});

it('guard 5: rejects a second amendment of the same rejected registry', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $this->aeatClient->shouldReceive('sendRegistration')->andReturn(new AeatResponse(success: true, code: 'CSV', message: 'Correcto'));

    $this->registrar->amendRejected($rejected, $invoice);

    expect(fn () => $this->registrar->amendRejected($rejected->fresh(), $invoice))
        ->toThrow(VerifactuException::class);
});

it('guard 5: rejects a second amendment even when the first amendment has been soft-deleted', function () {
    [$rejected, $invoice] = rejectedRegistration($this->registryManager);
    $this->aeatClient->shouldReceive('sendRegistration')->andReturn(new AeatResponse(success: true, code: 'CSV', message: 'Correcto'));

    $firstAmendment = $this->registrar->amendRejected($rejected, $invoice);

    // Soft-delete the first amendment — the withTrashed() guard must still see it.
    $firstAmendment->delete();

    expect(fn () => $this->registrar->amendRejected($rejected->fresh(), $invoice))
        ->toThrow(VerifactuException::class);
});
```

> A happy submit needs the CSV set: `markAsSubmitted` reads `$response->getCsv()`, which returns `$this->code`. The `AeatResponse::success(?array $data, ?string $message)` factory does NOT accept `code`, so use the constructor `new AeatResponse(success: true, code: 'CSV-OK', message: 'Correcto')` (confirmed against `src/Support/AeatResponse.php`). `Invoice::factory()->create()` is the valid-XML recipe (F1 + recipient + 21% breakdown — see `database/factories/InvoiceFactory.php`); guard 4 + the happy XML both require the rejected record's XML to build cleanly.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/AmendRejectedTest.php`
Expected: FAIL with `Call to undefined method ... amendRejected()`.

- [ ] **Step 3: Implement `amendRejected` on the registrar**

In `src/Services/InvoiceRegistrar.php`, add the imports `use AichaDigital\LaraVerifactu\Enums\RechazoPrevioEnum;`, `use AichaDigital\LaraVerifactu\Enums\RegistryTypeEnum;`, `use AichaDigital\LaraVerifactu\Support\RegistrationCircumstances;`, `use DOMDocument;`, `use DOMXPath;`. Add the method (mirrors `register()`'s transaction shape):

```php
    /** AEAT SuministroInformacion namespace for sf: XPath on persisted XML. */
    private const SF_NS = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';

    /**
     * Amend a rejected initial registration («ALTA POR RECHAZO», AID-137).
     *
     * Re-sends the corrected invoice as a NEW chain link with Subsanacion=S and
     * RechazoPrevio=X, linked to the rejected record via amends_registry_id. The
     * rejected record and its XML stay immutable. Five fail-loud guards (in order)
     * prove the operation is the «ALTA POR RECHAZO» variant before any XML is built.
     *
     * @throws VerifactuException
     */
    public function amendRejected(
        RegistryContract $rejectedRegistry,
        InvoiceContract $correctedInvoice,
        bool $submitToAeat = true
    ): RegistryContract {
        return DB::transaction(function () use ($rejectedRegistry, $correctedInvoice, $submitToAeat) {
            // Guard 1: only a REGISTRATION can be amended by rejection.
            if ($rejectedRegistry->getRegistryType() !== RegistryTypeEnum::REGISTRATION) {
                throw VerifactuException::make(
                    'amendRejected expects a REGISTRATION registry, got ' . $rejectedRegistry->getRegistryType()->value
                );
            }

            // Guard 2: status must be REJECTED (reachable via AID-257).
            if ($rejectedRegistry->getStatus() !== RegistryStatusEnum::REJECTED) {
                throw VerifactuException::make(
                    'amendRejected: only a REJECTED registration can be amended; status is '
                    . $rejectedRegistry->getStatus()->value
                    . ' (an accepted/registered key uses the AID-209 subsanación flow)'
                );
            }

            // Guard 3: the rejection must prove the key is NOT in AEAT. A
            // duplicate-key / already-registered line means the key exists, so
            // RechazoPrevio=X would be re-rejected.
            $this->assertRejectionProvesNotInAeat($rejectedRegistry);

            // Guard 4: the corrected invoice's IDFactura must match the rejected
            // record's persisted historical XML (immutable), using the builder's
            // getSerie().getNumber() convention.
            $this->assertIdFacturaMatchesPersistedXml($rejectedRegistry, $correctedInvoice);

            // Guard 5: no prior amendment of this rejected record (withTrashed).
            $alreadyAmended = Registry::withTrashed()
                ->where('amends_registry_id', $rejectedRegistry->getId() ?? null)
                ->exists();

            if ($alreadyAmended) {
                throw VerifactuException::make(
                    'amendRejected: registry ' . ($rejectedRegistry->getId() ?? '?') . ' has already been amended'
                );
            }

            // Build the new chained amendment registry with S + X circumstances.
            $registry = $this->registryManager->createRegistry(
                $correctedInvoice,
                new RegistrationCircumstances(
                    subsanacion: true,
                    rechazoPrevio: RechazoPrevioEnum::X,
                ),
            );

            if ($registry instanceof Registry) {
                $registry->update(['amends_registry_id' => $rejectedRegistry->getId()]);
            }

            $this->signRegistryXml($registry);

            if ($submitToAeat) {
                $this->submitToAeat($registry);
            }

            event(new InvoiceRegisteredEvent($correctedInvoice, $registry, $submitToAeat));

            return $registry;
        });
    }
```

> `RegistryContract::getId(): int|string|null` is defined in Task 4 alongside `getRegistryType()` and `getAmendsRegistryId()`. Guard 5 and the `amends_registry_id` assignment above call it directly.

Add the two private guard helpers:

```php
    /**
     * Guard 3 helper: the persisted AEAT rejection (AID-257) must show the key
     * is not in AEAT. Fail-loud conditions:
     *  - null/empty response → cannot prove not-in-AEAT.
     *  - lineas is not a non-empty array → unknown/malformed shape, cannot prove not-in-AEAT.
     *  - any line lacks the `registro_duplicado` key → incomplete shape, cannot prove not-in-AEAT.
     *  - any line has registro_duplicado === true → key exists in AEAT; RechazoPrevio=X invalid.
     */
    private function assertRejectionProvesNotInAeat(RegistryContract $rejected): void
    {
        $response = $rejected->getAeatResponse();

        if ($response === null) {
            throw VerifactuException::make(
                'amendRejected: the rejection carries no AEAT response metadata, so the key cannot be proven absent from AEAT'
            );
        }

        $lines = $response['lineas'] ?? null;

        if (! is_array($lines) || $lines === []) {
            throw VerifactuException::make(
                'amendRejected: the rejection lineas are empty or malformed — cannot prove the key is absent from AEAT'
            );
        }

        foreach ($lines as $line) {
            if (! is_array($line) || ! array_key_exists('registro_duplicado', $line)) {
                throw VerifactuException::make(
                    'amendRejected: a rejection line is missing the registro_duplicado key — cannot prove the key is absent from AEAT'
                );
            }

            if ($line['registro_duplicado'] === true) {
                throw VerifactuException::make(
                    'amendRejected: rejection is a duplicate-key/already-registered code; the key exists in AEAT, so RechazoPrevio=X is invalid (use the AID-209 flow)'
                );
            }
        }
    }

    /**
     * Guard 4 helper: the corrected invoice's IDFactura (IDEmisorFactura /
     * NumSerieFactura built as getSerie().getNumber() / FechaExpedicionFactura
     * d-m-Y) must equal the rejected record's persisted historical XML. Fail-loud
     * on null/empty XML or any missing node. Reads the immutable XML, never
     * getInvoice().
     */
    private function assertIdFacturaMatchesPersistedXml(
        RegistryContract $rejected,
        InvoiceContract $corrected
    ): void {
        $xml = $rejected->getXml();

        if ($xml === null || $xml === '') {
            throw VerifactuException::make('amendRejected: the rejected record has no persisted XML to match IDFactura against');
        }

        $previousUseErrors = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($xml)) {
                throw VerifactuException::make('amendRejected: the rejected record XML is unparseable');
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('sf', self::SF_NS);

            $xmlIssuer = $this->xpathText($xpath, '//sf:RegistroAlta/sf:IDFactura/sf:IDEmisorFactura');
            $xmlNumSerie = $this->xpathText($xpath, '//sf:RegistroAlta/sf:IDFactura/sf:NumSerieFactura');
            $xmlFecha = $this->xpathText($xpath, '//sf:RegistroAlta/sf:IDFactura/sf:FechaExpedicionFactura');

            if ($xmlIssuer === null || $xmlNumSerie === null || $xmlFecha === null) {
                throw VerifactuException::make('amendRejected: the rejected record XML is missing an IDFactura node');
            }

            $correctedNumSerie = $corrected->getSerie()
                ? $corrected->getSerie() . $corrected->getNumber()
                : $corrected->getNumber();

            if ($xmlIssuer !== $corrected->getIssuerTaxId()
                || $xmlNumSerie !== $correctedNumSerie
                || $xmlFecha !== $corrected->getIssueDatetime()->format('d-m-Y')) {
                throw VerifactuException::make(
                    'amendRejected: the corrected invoice IDFactura does not match the rejected record (the amendment must re-send the same key with corrected data)'
                );
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    private function xpathText(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = $nodes->item(0)?->textContent;

        return $value !== null ? trim($value) : null;
    }
```

- [ ] **Step 4: Add the facade method**

In `src/Verifactu.php`, add (mirroring `cancel()`; no fake-mode branch required for v1):

```php
    /**
     * Amend a rejected initial registration («ALTA POR RECHAZO», AID-137).
     */
    public function amendRejected(
        RegistryContract $rejectedRegistry,
        InvoiceContract $correctedInvoice,
        bool $submitToAeat = true
    ): RegistryContract {
        return $this->registrar->amendRejected($rejectedRegistry, $correctedInvoice, $submitToAeat);
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/AmendRejectedTest.php`
Expected: PASS — happy path (S+X, amends_registry_id, immutable rejected, chained after last link) plus all five guards throwing `VerifactuException`.

- [ ] **Step 6: Commit**

```bash
git add src/Services/InvoiceRegistrar.php src/Verifactu.php src/Contracts/RegistryContract.php src/Models/Registry.php tests/Feature/AmendRejectedTest.php
git commit -m "feat: add amendRejected (ALTA POR RECHAZO) with five fail-loud guards (AID-137)"
```

### Task 10: Full suite + Pint + PHPStan

**Files:** none (verification gate).

- [ ] **Step 1: Run the full test suite**

Run: `composer test`
Expected: all green. Pay special attention to the suites touched by the cross-cutting changes:
- `tests/Unit/XmlBuilderTest.php`, `tests/Feature/XmlBuilderConformanceTest.php`, `tests/Feature/XmlBuilderFailLoudTest.php` — the optional 3rd `buildRegistrationXml` arg must not alter existing (null-circumstances) output.
- `tests/Unit/HashGeneratorTest.php` — the wrapper refactor must reproduce every existing hash.
- `tests/Feature/RegistryManagerTest.php`, `tests/Feature/BlockchainReproducibilityTest.php` — the XML-based `verifyRegistryHash` rewrite must keep the chain/tamper tests green with real-builder fixtures (Task 8 Step 4 audit).
- `tests/Unit/EnumsTest.php` — new `RechazoPrevioEnum` block.

- [ ] **Step 2: Static analysis + format**

Run: `vendor/bin/pint src/Enums/RechazoPrevioEnum.php src/Support/RegistrationCircumstances.php src/Services/HashGenerator.php src/Services/RegistryManager.php src/Services/XmlBuilder.php src/Services/InvoiceRegistrar.php src/Verifactu.php src/Contracts/RegistryContract.php src/Contracts/XmlBuilderContract.php src/Contracts/HashGeneratorContract.php src/Models/Registry.php src/LaraVerifactuServiceProvider.php database/migrations/2026_06_24_000001_add_subsanacion_to_verifactu_registries_table.php && composer phpstan`
Expected: Pint `PASS`, PHPStan `[OK] No errors`.

> If PHPStan flags the `array<string, mixed>` access in `assertRejectionProvesNotInAeat` (`$response['lineas']`, `$line['registro_duplicado']`), it is reading an untyped `getAeatResponse()` shape — narrow with `is_array()` checks (already present) and, if needed, a local `@var` or an `array_key_exists` guard rather than a baseline entry. Regenerate the baseline ONLY for genuinely unavoidable false positives, never to silence a new real error (umbrella rule).

- [ ] **Step 3: Commit any pint/phpstan adjustments**

```bash
git add src/Enums/RechazoPrevioEnum.php src/Support/RegistrationCircumstances.php src/Services/HashGenerator.php src/Services/RegistryManager.php src/Services/XmlBuilder.php src/Services/InvoiceRegistrar.php src/Verifactu.php src/Contracts/RegistryContract.php src/Contracts/XmlBuilderContract.php src/Contracts/HashGeneratorContract.php src/Models/Registry.php src/LaraVerifactuServiceProvider.php database/migrations/2026_06_24_000001_add_subsanacion_to_verifactu_registries_table.php tests/Unit/EnumsTest.php tests/Unit/XmlBuilderTest.php tests/Unit/HashGeneratorTest.php tests/Unit/RegistrationCircumstancesTest.php tests/Feature/RegistryManagerTest.php tests/Feature/AmendRejectedTest.php tests/Feature/BlockchainReproducibilityTest.php tests/Feature/XmlBuilderConformanceTest.php tests/Feature/XmlBuilderFailLoudTest.php
git commit -m "chore: pint + phpstan for amend-by-rejection (AID-137)" || echo "nothing to commit"
```

---

## Self-Review

- **Spec coverage:** «ALTA POR RECHAZO» only (Subsanacion=S + RechazoPrevio=X, key provably not in AEAT) — Tasks 1/2/5/6/9. AID-209 variants out of scope (guards 2 + 3 point there). §8 verify-from-persisted-XML, fail-loud, both registry types — Tasks 7 + 8. New columns + `hasMigrations` registration + DB unique index — Task 3. `RegistryContract` accessors — Task 4 (+ `getId()` in Task 9). Immutable rejected record (no inverse column; derived from guard 5 + DB index) — Tasks 3 + 9. ✓
- **§8 fully pinned (no DESIGN RISK):** typed `generateRegistrationFromParts`/`generateCancellationFromParts` (NOT `array $parts`), wrappers delegate with no behavior change, `verifyRegistryHash` reads persisted XML via `sf:` XPath + columns, dispatches on `registry_type`, fail-loud on null/unparseable/missing-node, never reads `$registry->invoice`. ✓
- **No forward references:** each task only consumes earlier-task interfaces. Enum (1) → VO (2) → migration/model (3) → contract accessors (4) → builder (5) → manager createRegistry (6) → hash parts (7) → verify (8) → amendRejected (9) → gate (10). ✓
- **Type consistency:** `RechazoPrevioEnum {N,S,X}`, `RegistrationCircumstances(bool, ?RechazoPrevioEnum)`, `getRegistryType(): RegistryTypeEnum`, `getAmendsRegistryId(): ?int`, `getId(): int|string|null`, `amendRejected(RegistryContract, InvoiceContract, bool): RegistryContract` — used identically across tasks. ✓
- **One residual implementation decision (flagged inline, not blocking):** Task 8 Step 4 requires auditing the manager-level `verifyBlockchain` fixtures (stale `hashGenerator->verify` mocks + `<xml></xml>` fixtures) and rebuilding them with a real `XmlBuilder` — the plan prescribes reusing the `BlockchainReproducibilityTest` real-builder setup rather than weakening the fail-loud contract. This is a concrete instruction, not an open question.
- **Valid-invoice factory recipe:** Tasks 8 and 9 both depend on `Invoice::factory()` producing XSD-valid RegistroAlta XML through the real builder. The plan instructs copying the recipe from an existing green conformance/end-to-end test rather than inventing fields — the one remaining "look it up" step (the factory's exact valid state is not load-bearing to the design and lives in the green suite).
- **No placeholders:** every step has real code, real commands, and explicit expected output. ✓
