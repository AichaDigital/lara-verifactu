# Design — AID-259: migrate test suite from SQLite to MariaDB

- **Issue:** AID-259 (split out of AID-258; **blocks AID-258**)
- **Date:** 2026-06-25
- **Status:** approved design

## Problem

`tests/TestCase.php` configures the test database as `driver=sqlite,
database=:memory:`, and `run-tests.yml` only loads the `sqlite`/`pdo_sqlite`
extensions. The suite (~574 tests) therefore runs entirely against an engine that
is **not** the deployment target (MySQL/MariaDB), with two consequences:

1. **SQLite masks schema and type drift.** Column types, strict mode, date/JSON
   handling, and constraint behavior differ on MariaDB and are currently
   untested. This is the same failure mode as the larabill `.php`/`.stub`
   schema-divergence lesson: green tests against the wrong engine.
2. **SQLite cannot reproduce write concurrency.** It serializes all writes at the
   database-file level, so the chain-fork race that AID-258 must guard against
   literally cannot manifest. A concurrency test on SQLite proves nothing.

AID-259 makes the suite run on the real deployment engine so that (a) latent
drift surfaces and (b) AID-258's concurrency test has an engine that can actually
fork.

## Scope

**In scope — test infrastructure only:**

- Switch `tests/TestCase.php` from SQLite to the **`mariadb`** driver, env-driven.
- Add a **MariaDB 12.3 LTS** service to `run-tests.yml` across the full matrix
  (P8.3/8.4 × L12/L13).
- Fix the latent failures MariaDB surfaces that SQLite was masking.
- Drop `--parallel` (`precommit` → serial `@test`; remove the `test-parallel`
  script) and add `ext-pdo_mysql` to `require-dev`.
- Document the local-dev requirement (developer already runs a local MariaDB).

**Out of scope:**

- **No domain logic changes.** The chain-lock implementation is AID-258, layered
  on top of this once the suite runs on MariaDB.
- **No durability tuning.** Standard MariaDB config (production-faithful fsync per
  commit) was chosen deliberately over a fast test profile: fidelity to the
  deployment engine over inner-loop speed.
- **No SQLite in the test runtime or workflow.** Full replace, single engine —
  not a dual-engine matrix and not an opt-in escape hatch. **Production code is
  untouched:** migrations keep their existing driver branches (e.g.
  `database/migrations/2026_01_25_000001_consolidate_issue_datetime_in_verifactu_invoices.php:33`
  has an `if ($driver === 'sqlite')` branch for consumers who run SQLite in their
  own apps). Those branches stay; they simply lose test coverage under
  MariaDB-only — see Risks.
- **No `docker-compose.yml`** unless requested later. Env-driven config against the
  developer's existing local MariaDB plus the CI service container is sufficient.

## Decisions (from brainstorming)

| Decision | Choice | Rationale |
|---|---|---|
| Engine | MariaDB only (full replace) | Deployment target; "don't mix" |
| Version | MariaDB 12.3 LTS, floating `mariadb:12.3` tag | Current LTS (12.3.2 GA 2026-05-29, EOL Jun 2029). Floating tag tracks LTS patch/security updates → matches a patched-LTS production box (the fidelity choice). Pinning to `12.3.2` is a one-line change if strict CI reproducibility is ever needed. |
| Driver | Laravel `mariadb` driver | Dedicated driver in L11+, distinct from `mysql`; L12/L13 support it |
| Durability | Standard (no `my.cnf` tuning) | Fidelity to production over speed |
| CI shape | One `services:` block on the single `test` job; applies to all 4 matrix combos | Single axis, no matrix doubling; PHPStan/Pint stay DB-less |
| CI DB auth | Dedicated `MARIADB_USER`/`MARIADB_PASSWORD`, not root over TCP | Docker MariaDB root is localhost-bound unless `MARIADB_ROOT_HOST=%`; a granted user connects cleanly over the mapped TCP port (Codex P1) |
| Parallelism | Drop `--parallel`; `precommit` runs serial | Pest's Laravel parallel handler skips testbench packages → no per-worker DB suffix → shared-DB collisions on MariaDB. Quality over speed; serial cost imperceptible on modern hardware (Codex P1) |
| Latent failures | Fixed as part of this PR | The migration is the occasion to surface and fix them |

## Design

### `tests/TestCase.php`

`getEnvironmentSetUp()` sets the default connection to `mariadb`, with all
connection parameters read from env so the same code works locally and in CI:

- `DB_HOST` (default `127.0.0.1`)
- `DB_PORT` (default `3306`)
- `DB_DATABASE` (default `verifactu_test`)
- `DB_USERNAME` / `DB_PASSWORD`

`RefreshDatabase`/`LazilyRefreshDatabase` migrate once, then wrap each test in a
transaction and roll back. This holds for **DML** tests. **Caveat (MariaDB DDL
implicit-commit, Codex P2):** unlike SQLite, MariaDB commits implicitly on DDL, so
a test that itself runs migrations or schema changes inside the per-test
transaction does **not** roll back cleanly. The install-command test
(`VerifactuInstallCommand:63` calls `migrate`) is the known case; such tests must
own their cleanup rather than rely on transaction isolation. The
`hasMigrations([...])` migrations now execute against MariaDB, so any non-portable
migration syntax surfaces here.

### CI (`run-tests.yml`)

Three concrete changes to the existing single `test` job (which runs all four
matrix combinations P8.3/8.4 × L12/L13):

1. **PHP extensions (line 38).** Replace `sqlite, pdo_sqlite` with `pdo_mysql`
   (the Laravel `mariadb` driver connects over PDO MySQL). Without it the
   connection fails before migrations run. Final list:
   `dom, curl, libxml, mbstring, zip, pcntl, pdo, pdo_mysql, soap, openssl`.

2. **Timeout (line 12).** Raise `timeout-minutes: 5` → `15`. The job runs
   `composer require` + `composer update` (full dependency resolution) **and** the
   574-test suite on a real engine with standard durability; 5 minutes is a CI
   time-bomb even when the code is correct. Acceptance includes measuring actual
   duration before/after and tightening if there is comfortable headroom.

3. **Service container.** Add a MariaDB service to the job, exact config:

   ```yaml
   services:
     mariadb:
       image: mariadb:12.3
       env:
         MARIADB_DATABASE: verifactu_test
         MARIADB_USER: verifactu
         MARIADB_PASSWORD: secret
         MARIADB_ROOT_PASSWORD: root
       ports:
         - 3306:3306
       options: >-
         --health-cmd="healthcheck.sh --connect --innodb_initialized"
         --health-interval=10s
         --health-timeout=5s
         --health-retries=5
   ```

   The job exports the matching connection env so `TestCase` reaches the service as
   the **dedicated user** (root is not reachable over TCP without
   `MARIADB_ROOT_HOST=%`): `DB_CONNECTION=mariadb`, `DB_HOST=127.0.0.1`,
   `DB_PORT=3306`, `DB_DATABASE=verifactu_test`, `DB_USERNAME=verifactu`,
   `DB_PASSWORD=secret`. The MariaDB entrypoint auto-creates `MARIADB_USER` with
   host `%` and grants it on `MARIADB_DATABASE`, so the TCP connection works.

The PHPStan and Pint workflows do not touch the database and get no service.

**Matrix coverage.** The `services:` block lives on the single `test` job, so
GitHub Actions starts a fresh, isolated MariaDB container for **every** matrix
combination (all four of P8.3/8.4 × L12/L13) on its own runner VM. No shared
service, no cross-combo port contention — each job gets its own `127.0.0.1:3306`.

### Test parallelism

`composer.json` ships `test-parallel` (`pest --parallel`) and `precommit` runs it.
On SQLite `:memory:` each worker gets an isolated in-process database; on a shared
MariaDB that isolation is gone. Pest's Laravel parallel handler skips packages when
`Orchestra\Testbench\TestCase` is present
(`vendor/pestphp/pest/src/Plugins/Parallel/Handlers/Laravel.php:46-55`), so
Laravel's per-worker database suffixing never engages — workers would collide on
`verifactu_test` and race `migrate:fresh`.

**Decision: drop `--parallel`.** `precommit` runs the serial `@test`; the
`test-parallel` script is removed (not left silently broken). Quality over speed;
on modern hardware the serial run is imperceptible. Per-worker DB suffixing is a
possible future enhancement (Follow-ups), not part of this migration.

### PHP extensions and dependencies

`require-dev` gains `ext-pdo_mysql` so a missing extension fails fast at
`composer install`, not at first test run (Codex P2). CI installs `pdo_mysql` via
the extensions change above. No `ext-sqlite` requirement is added.

### Local development

Because `tests/Pest.php:8` applies `TestCase` to the whole suite, `composer test`
requires a reachable MariaDB. The package documents (README/CONTRIBUTING) the
one-time setup against the developer's local MariaDB:

```bash
# One-time: create the test database and a dedicated user (mirrors CI)
mariadb -uroot -p <<'SQL'
CREATE DATABASE IF NOT EXISTS verifactu_test;
CREATE USER IF NOT EXISTS 'verifactu'@'%' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON verifactu_test.* TO 'verifactu'@'%';
FLUSH PRIVILEGES;
SQL

# Per-shell (or persisted in a local, git-ignored env): point the suite at it
export DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3306 \
       DB_DATABASE=verifactu_test DB_USERNAME=verifactu DB_PASSWORD=secret

composer test
```

`RefreshDatabase`/`LazilyRefreshDatabase` migrate and roll back per test for DML,
so no manual reset is needed between normal runs (the DDL implicit-commit caveat
above applies to migration-running tests). Drop/recreate `verifactu_test` only if
a migration set changes incompatibly. Local credentials match the CI defaults
(`verifactu`/`secret`). `composer test` is serial — `test-parallel` is removed
(see Test parallelism).

### Handling latent failures

MariaDB is expected to surface failures SQLite masked. The migration is **done
when the full suite is green on MariaDB**. The fixes split into two kinds:

- **Test/schema portability** (column types, strict mode, date precision, JSON,
  constraint ordering): fixed inline as part of this PR.
- **A real domain bug** that SQLite was hiding: isolated and raised with the user
  before any change — not silenced to make the suite green. (Surfacing such a bug
  is a success of the migration, not a blocker to paper over.)

## Testing

This issue *is* a testing-infra change, so "testing" here means the acceptance bar:

- Full suite green on MariaDB 12.3, locally and across all four CI matrix combos.
- CI connects as the dedicated `verifactu` user (not root) over TCP — proves the
  service auth is correct, not a localhost-socket false positive.
- `composer precommit` (serial) passes; `test-parallel` no longer present.
- `ext-pdo_mysql` declared in `require-dev`; no SQLite references remain in
  `TestCase.php` or the workflow.
- A diff of pass/fail counts before vs after, with every newly-surfaced failure
  either fixed (portability) or escalated (real bug).

## Risks

- **Unknown count of latent failures.** Could be zero, could be a handful. Bounded
  by the suite size and isolated to test/schema portability in the common case.
- **CI time increase.** MariaDB with standard durability is slower than
  `:memory:`. Accepted trade-off; bounded by keeping a single service per job and
  not tuning away the deployment-faithful behavior. Mitigated by the raised
  15-minute timeout; actual duration measured at implementation.
- **SQLite-branch coverage gap.** Driver-conditional `sqlite` branches in
  migrations (the `consolidate_issue_datetime` data migration) stay in production
  for SQLite consumers but are no longer exercised by the MariaDB-only suite. This
  is the symmetric cost of single-engine testing (the MySQL branch was untested
  under SQLite before). Accepted: AEAT Verifactu is Spain B2B, where MySQL/MariaDB
  is the realistic consumer engine. Not expanding scope to a multi-engine matrix.
- **Migration-running tests lose transaction isolation.** MariaDB DDL
  implicit-commit (Codex P2) breaks per-test rollback for tests that run
  migrations (install command). Handled explicitly during implementation, not
  assumed away.
- **Loss of parallel test speed.** Dropping `--parallel` makes `precommit` serial
  on a slower engine. Accepted (quality over speed; imperceptible on modern
  hardware); per-worker DB suffixing deferred to Follow-ups.

## Follow-ups

- **AID-258** rides on this: chain-lock (`withChainLock` singleton-row + forced
  write) + a real two-connection concurrency test, now runnable on the MariaDB
  suite.
- **Per-worker DB suffixing** to restore `pest --parallel` on MariaDB: wire the
  `ParallelTesting` token into the testbench connection
  (`verifactu_test_${TEST_TOKEN}`), create/drop per worker. Deferred — needs a
  custom hook since Pest's Laravel parallel handler skips testbench packages.
- A `docker-compose.yml` for contributor parity, if the package later takes
  external contributors.
