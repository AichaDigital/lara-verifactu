# Design — AID-259: migrate test suite from SQLite to MariaDB

- **Issue:** AID-259 (split out of AID-258; **blocks AID-258**)
- **Date:** 2026-06-25
- **Status:** approved design (v2: CI runs a two-engine matrix MariaDB 12.3 + MySQL
  8.4; local target is MariaDB 11.4 on port 3307; driver is env-driven)

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

- Switch `tests/TestCase.php` from SQLite to an **env-driven driver** (default
  `mariadb`), reading `DB_DRIVER`/`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/
  `DB_PASSWORD`.
- Make `run-tests.yml` a **two-engine matrix**: add a `db: [mariadb, mysql]` axis
  so all combos run on **MariaDB 12.3 LTS** and **MySQL 8.4 LTS**
  (P8.3/8.4 × L12/L13 × {mariadb, mysql} = 8 jobs). The repo is public, so
  GitHub-hosted Actions minutes are free — the doubling costs nothing.
- Fix the latent failures the real engines surface that SQLite was masking.
- Drop `--parallel` (`precommit` → serial `@test`; remove the `test-parallel`
  script) and add `ext-pdo_mysql` to `require-dev`.
- Document the local-dev requirement: the suite runs against the developer's
  local **MariaDB 11.4 on port 3307** (`DB_PORT=3307`).

**Out of scope:**

- **No domain logic changes.** The chain-lock implementation is AID-258, layered
  on top of this once the suite runs on MariaDB.
- **No durability tuning.** Standard MariaDB config (production-faithful fsync per
  commit) was chosen deliberately over a fast test profile: fidelity to the
  deployment engine over inner-loop speed.
- **No SQLite in the test runtime or workflow.** SQLite is fully removed as a test
  engine — no SQLite-in-the-matrix and no opt-in escape hatch. The CI matrix DOES
  cover two engines, but both are **real deployment engines** (MariaDB + MySQL),
  not a test/prod-engine mix. **Production code is untouched:** migrations keep
  their existing driver branches (e.g.
  `database/migrations/2026_01_25_000001_consolidate_issue_datetime_in_verifactu_invoices.php:33`
  has an `if ($driver === 'sqlite')` branch for consumers who run SQLite in their
  own apps). Those branches stay; they simply lose test coverage now that the
  suite runs only on MariaDB/MySQL — see Risks.
- **No `docker-compose.yml`** unless requested later. Env-driven config against the
  developer's existing local MariaDB plus the CI service container is sufficient.

## Decisions (from brainstorming)

| Decision | Choice | Rationale |
|---|---|---|
| Engine (local) | MariaDB 11.4 on port 3307 | Developer's local MariaDB; same engine family as CI's MariaDB 12.3 so Task-3 fixes transfer cleanly. (Local MySQL 8.0 on 3306 exists too but is not the local target.) |
| Engine (CI) | Two-engine matrix: MariaDB 12.3 + MySQL 8.4 | Both are real deployment engines the package ships to. Public repo → Actions free, so covering both costs nothing. SQLite fully dropped. |
| Versions | MariaDB `mariadb:12.3` LTS, MySQL `mysql:8.4` LTS | MariaDB 12.3.2 GA 2026-05-29 (EOL Jun 2029); MySQL 8.4 LTS. Floating minor tags track LTS patches = patched-LTS production parity. Pin to exact patch only if strict reproducibility is needed. |
| Driver | Env-driven `DB_DRIVER` (default `mariadb`; CI mysql-leg sets `mysql`) | Laravel's `mariadb` and `mysql` drivers differ in grammar; each engine must use its own driver to test faithfully |
| Durability | Standard (no `my.cnf` tuning), both engines | Fidelity to production over speed |
| CI shape | One `services:` block per job, image+healthcheck selected by `matrix.db` | 8 jobs total; PHPStan/Pint stay DB-less. Free on a public repo. |
| CI DB auth | Dedicated `MARIADB_USER`/`MARIADB_PASSWORD`, not root over TCP | Docker MariaDB root is localhost-bound unless `MARIADB_ROOT_HOST=%`; a granted user connects cleanly over the mapped TCP port (Codex P1) |
| Parallelism | Drop `--parallel`; `precommit` runs serial | Pest's Laravel parallel handler skips testbench packages → no per-worker DB suffix → shared-DB collisions on MariaDB. Quality over speed; serial cost imperceptible on modern hardware (Codex P1) |
| Latent failures | Fixed as part of this PR | The migration is the occasion to surface and fix them |

## Design

### `tests/TestCase.php`

`getEnvironmentSetUp()` sets the `testing` connection with an **env-driven driver**
so the same code runs on local MariaDB and on either CI engine:

- `DB_DRIVER` (default `mariadb`; CI's mysql-leg sets `mysql`)
- `DB_HOST` (default `127.0.0.1`)
- `DB_PORT` (default `3306` — the CI mapped service port; local dev sets `3307`
  for the local MariaDB instance)
- `DB_DATABASE` (default `verifactu_test`)
- `DB_USERNAME` / `DB_PASSWORD` (default `verifactu` / `secret`)

Task 1 landed the connection with a hardcoded `mariadb` driver; the `DB_DRIVER`
env read is added with the CI matrix so the mysql-leg selects its own driver.

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

The `test` job gains a database-engine axis and a per-engine service.

1. **Matrix engine axis.** Add `db: [mariadb, mysql]` to the strategy matrix →
   8 jobs (P8.3/8.4 × L12/L13 × {mariadb, mysql}).

2. **PHP extensions (line 38).** Replace `sqlite, pdo_sqlite` with `pdo_mysql`
   (both the `mariadb` and `mysql` Laravel drivers connect over PDO MySQL). Final:
   `dom, curl, libxml, mbstring, zip, pcntl, pdo, pdo_mysql, soap, openssl`.

3. **Timeout (line 12).** Raise `timeout-minutes: 5` → `15` per job. Measure
   actual duration and tighten if there is headroom.

4. **Per-engine service + env.** The image is selected by `matrix.db`; one env
   block carries both `MARIADB_*` and `MYSQL_*` keys (each image ignores the
   other's), and `DB_DRIVER` is set from `matrix.db`:

   ```yaml
   services:
     db:
       image: ${{ matrix.db == 'mysql' && 'mysql:8.4' || 'mariadb:12.3' }}
       env:
         MARIADB_DATABASE: verifactu_test
         MARIADB_USER: verifactu
         MARIADB_PASSWORD: secret
         MARIADB_ROOT_PASSWORD: root
         MYSQL_DATABASE: verifactu_test
         MYSQL_USER: verifactu
         MYSQL_PASSWORD: secret
         MYSQL_ROOT_PASSWORD: root
       ports:
         - 3306:3306
       options: >-
         --health-cmd="mysqladmin ping -h 127.0.0.1 -uroot -proot --silent"
         --health-interval=10s
         --health-timeout=5s
         --health-retries=10
   ```

   And at job level:

   ```yaml
   env:
     DB_DRIVER: ${{ matrix.db }}
     DB_HOST: 127.0.0.1
     DB_PORT: 3306
     DB_DATABASE: verifactu_test
     DB_USERNAME: verifactu
     DB_PASSWORD: secret
   ```

   `matrix.db` is exactly `mariadb` or `mysql` — also the Laravel driver name — so
   `DB_DRIVER: ${{ matrix.db }}` selects the right driver per leg. Both official
   images auto-create the dedicated `verifactu` user (host `%`) granted on
   `verifactu_test` from their `*_USER`/`*_PASSWORD` env, so the non-root TCP
   connection works on either engine. `mysqladmin ping` is the portable healthcheck
   (MariaDB ships `mysqladmin` too); root over TCP is unnecessary for the suite,
   which connects as `verifactu`.

The PHPStan and Pint workflows do not touch the database and get no service.

**Matrix coverage.** The `services:` block lives on the single `test` job, so
GitHub Actions starts a fresh, isolated DB container for **every** one of the eight
combos on its own runner VM — no shared service, no cross-combo port contention,
each job gets its own `127.0.0.1:3306`. The repo is public, so all these runner
minutes are free.

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
requires a reachable MariaDB. The local target is the developer's **MariaDB 11.4 on
port 3307** (a separate local MySQL 8.0 on 3306 is not the target). The package
documents (README/CONTRIBUTING) the one-time setup:

```bash
# One-time: create the test database + dedicated user on the local MariaDB (3307)
mariadb -uroot --port=3307 --protocol=tcp <<'SQL'
CREATE DATABASE IF NOT EXISTS verifactu_test;
CREATE USER IF NOT EXISTS 'verifactu'@'%' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON verifactu_test.* TO 'verifactu'@'%';
FLUSH PRIVILEGES;
SQL

# Per-shell (or persisted in a local, git-ignored env): point the suite at MariaDB
export DB_DRIVER=mariadb DB_HOST=127.0.0.1 DB_PORT=3307 \
       DB_DATABASE=verifactu_test DB_USERNAME=verifactu DB_PASSWORD=secret

composer test
```

The only local override vs the CI defaults is `DB_PORT=3307` (CI's MariaDB service
is on 3306). `RefreshDatabase`/`LazilyRefreshDatabase` migrate and roll back per
test for DML, so no manual reset is needed between normal runs (the DDL
implicit-commit caveat above applies to migration-running tests). `composer test`
is serial — `test-parallel` is removed (see Test parallelism).

### Handling latent failures

The real engines are expected to surface failures SQLite masked. The migration is
**done when the full suite is green on both MariaDB and MySQL**. The fixes split
into two kinds:

- **Test/schema portability** (column types, strict mode, date precision, JSON,
  constraint ordering): fixed inline as part of this PR.
- **A real domain bug** that SQLite was hiding: isolated and raised with the user
  before any change — not silenced to make the suite green. (Surfacing such a bug
  is a success of the migration, not a blocker to paper over.)

## Testing

This issue *is* a testing-infra change, so "testing" here means the acceptance bar:

- Full suite green locally on MariaDB 11.4, and across all eight CI combos
  (P8.3/8.4 × L12/L13 × {MariaDB 12.3, MySQL 8.4}).
- CI connects as the dedicated `verifactu` user (not root) over TCP on both
  engines — proves the service auth is correct, not a localhost-socket false
  positive.
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
  for SQLite consumers but are no longer exercised once the suite runs only on
  MariaDB/MySQL. Accepted: AEAT Verifactu is Spain B2B, where MySQL/MariaDB is the
  realistic consumer engine — and the CI matrix now covers both of those.
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
