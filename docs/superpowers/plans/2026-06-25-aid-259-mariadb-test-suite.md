# AID-259: Migrate Test Suite to MariaDB — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the SQLite `:memory:` test database with MariaDB 12.3 (the real deployment engine) across local dev and CI, so latent schema drift surfaces and AID-258's concurrency test has an engine that can fork.

**Architecture:** Keep the testbench connection name `testing` but change its driver+params to `mariadb`, env-driven. CI gains a MariaDB service container (one `services:` block covers all four matrix combos). Parallel testing is dropped (unsafe on a shared DB). Latent failures MariaDB surfaces are fixed inline; real domain bugs are escalated, not silenced.

**Tech Stack:** PHP 8.3/8.4, Laravel 12/13, orchestra/testbench, Pest, MariaDB 12.3 LTS, GitHub Actions.

## Global Constraints

- **PHP:** 8.3+ · **Laravel:** 12.* and 13.* (CI matrix P8.3/8.4 × L12/L13 × db:{mariadb,mysql} = 8 jobs).
- **Engines:** local target MariaDB 11.4 on port **3307**; CI runs both MariaDB 12.3 (`mariadb:12.3`) and MySQL 8.4 (`mysql:8.4`). No SQLite in test runtime or workflow. Production migration code (driver-conditional `sqlite` branches) stays untouched.
- **Driver:** env-driven `DB_DRIVER` (default `mariadb`; CI mysql-leg sets `mysql`).
- **CI DB auth:** dedicated user `verifactu` / `secret` on database `verifactu_test`, host `%` — never root over TCP.
- **Durability:** standard engine config, no tuning.
- **No `--parallel`:** `precommit` runs serial `@test`.
- **Artifacts in English.** Commit messages English; end every commit with `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
- **Scope fence:** NO domain logic changes (chain-lock is AID-258).

---

### Task 1: Switch the test connection to MariaDB + declare `ext-pdo_mysql`

**Files:**
- Modify: `tests/TestCase.php:29-37` (the `getEnvironmentSetUp` connection config)
- Modify: `composer.json` (`require-dev` → add `ext-pdo_mysql`)
- Precondition (local, one-time, not a repo change): create the `verifactu_test` DB + `verifactu` user

**Interfaces:**
- Produces: a `testing` connection on driver `mariadb`, reading `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` from env with the CI defaults. All later tasks (and the existing suite) run against it.

- [ ] **Step 1: Create the local test database and user (one-time precondition)**

```bash
mariadb -uroot -p <<'SQL'
CREATE DATABASE IF NOT EXISTS verifactu_test;
CREATE USER IF NOT EXISTS 'verifactu'@'%' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON verifactu_test.* TO 'verifactu'@'%';
FLUSH PRIVILEGES;
SQL
```

- [ ] **Step 2: Add `ext-pdo_mysql` to `require-dev`**

In `composer.json`, inside `"require-dev"`, add the extension key (alphabetical among any other `ext-*`; if none exist, add it as the first entry):

```json
"ext-pdo_mysql": "*",
```

- [ ] **Step 3: Verify composer accepts it and the extension is loaded**

Run: `php -m | grep -i pdo_mysql && composer update --no-interaction 2>&1 | tail -3`
Expected: `pdo_mysql` printed; composer update completes with no platform-requirement error.

- [ ] **Step 4: Switch the `testing` connection to MariaDB (env-driven)**

Replace `tests/TestCase.php:29-37` (the whole `getEnvironmentSetUp` body) with:

```php
    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'mariadb',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'verifactu_test'),
            'username' => env('DB_USERNAME', 'verifactu'),
            'password' => env('DB_PASSWORD', 'secret'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
    }
```

The connection name stays `testing`; only its driver and params change. `DB_CONNECTION` is irrelevant (the default is pinned to `testing`).

- [ ] **Step 5: Run the suite to establish the connection and capture the failure baseline**

Run: `vendor/bin/pest 2>&1 | tail -40`
Expected: the suite **connects** to MariaDB (no `could not find driver`, no `Connection refused`, no `Access denied`). Tests may FAIL — that is the latent-failure baseline MariaDB surfaces. Record the count and the names of failing tests for Task 3. If the connection itself fails, stop and fix the connection (driver/host/credentials) before proceeding.

- [ ] **Step 6: Commit the connection switch**

```bash
git add tests/TestCase.php composer.json
git commit -m "test: run suite on MariaDB instead of SQLite :memory: (AID-259)

Switch the testbench 'testing' connection driver to mariadb (env-driven, CI
defaults verifactu/secret on verifactu_test) and declare ext-pdo_mysql in
require-dev so a missing extension fails at composer install. Connection name
unchanged. Latent failures surfaced by the real engine are fixed in follow-up
steps of this branch.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Drop `--parallel` from the package

**Files:**
- Modify: `composer.json` (`scripts`: remove `test-parallel`; `precommit` → `@test`)

**Interfaces:**
- Consumes: nothing. Independent of Task 1's test outcome.
- Produces: a serial `precommit`; no `test-parallel` script.

- [ ] **Step 1: Remove the `test-parallel` script and make `precommit` serial**

In `composer.json` `"scripts"`: delete the line `"test-parallel": "vendor/bin/pest --parallel",` and change the `precommit` array entry `"@test-parallel"` to `"@test"`. Resulting `precommit`:

```json
"precommit": [
    "@format",
    "@phpstan",
    "@test"
],
```

- [ ] **Step 2: Verify composer.json is valid and precommit is serial**

Run: `composer validate --no-check-publish && composer run-script --list | grep -E 'precommit|test-parallel'`
Expected: `composer.json is valid`; `precommit` listed; `test-parallel` **absent**.

- [ ] **Step 3: Commit**

```bash
git add composer.json
git commit -m "test: drop --parallel; precommit runs serial (AID-259)

pest --parallel collides on a shared MariaDB: Pest's Laravel parallel handler
skips testbench packages, so per-worker DB suffixing never engages and workers
race migrate:fresh on one database. Remove test-parallel and run precommit
serial. Per-worker DB suffixing is a deferred follow-up.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Fix the latent failures MariaDB surfaces (iterative, until green)

**Files:**
- Modify: whichever test/migration/factory files the baseline (Task 1, Step 5) flagged. Unknown until run; the watchlist below names the likely sites in this repo.

**Interfaces:**
- Consumes: the `testing`→mariadb connection from Task 1 and the baseline failure list.
- Produces: full suite green locally on MariaDB.

This task is a loop, not a fixed edit, because the failure set is data-dependent. The method is fixed.

- [ ] **Step 1: Re-confirm the current baseline**

Run: `vendor/bin/pest 2>&1 | tee /tmp/aid259-baseline.txt | tail -30`
Read the FAIL list. If already green, skip to Step 5.

- [ ] **Step 2: Categorize each failing test**

For each failure, decide one of two kinds:
- **Portability** (fix inline): MySQL strict mode (no implicit zero-dates, NOT NULL without default), `utf8mb4` index key-length (191/64-char limits), date/timestamp precision and formatting, `TINYINT(1)` booleans, JSON column comparisons, ordering differences (SQLite vs MariaDB default sort), `LIKE` case-sensitivity/collation, auto-increment vs explicit ids.
- **Real domain bug** SQLite was masking (escalate, do NOT silence): a constraint, type, or logic error that is genuinely wrong, not a test-engine artifact.

Repo watchlist (check these first if they fail):
- `database/migrations/2026_01_25_000001_consolidate_issue_datetime_in_verifactu_invoices.php` — the `else` branch now runs on MariaDB (`UPDATE ... SET issue_datetime = TIMESTAMP(issue_date, issue_time)`), and `->change()` makes the column NOT NULL. Verify both run cleanly on MariaDB 12.3.
- Tests exercising the install command / `migrate` inside a test (DDL implicit-commit breaks transaction rollback — see Step 4).
- Any factory/seed asserting SQLite-style date strings or insertion order.

- [ ] **Step 3: Fix one category of portability failures, then re-run**

Apply the minimal portability fix (in the test or the schema, per category). Re-run only the affected file first:
Run: `vendor/bin/pest <path/to/affected/test> 2>&1 | tail -20`
Expected: that file PASSES. Repeat Step 3 per category until the watchlist + portability failures are clear.

- [ ] **Step 4: Handle migration-running tests (DDL implicit-commit)**

For any test that runs migrations/schema changes itself (e.g. the install-command test), it cannot rely on per-test transaction rollback on MariaDB (DDL commits implicitly). Make such tests own their cleanup (e.g. ensure `RefreshDatabase`/`migrate:fresh` in setup, or explicit teardown), not transaction isolation. Re-run that test:
Run: `vendor/bin/pest <path/to/install/command/test> 2>&1 | tail -20`
Expected: PASS, and PASS again on a second consecutive run (proves no leaked state).

- [ ] **Step 5: If any failure is a real domain bug, STOP and escalate**

Do not edit domain code to make the suite green. Write a one-paragraph note (test name, what MariaDB enforces that SQLite did not, why it looks like a real bug) and surface it to the user for a decision before continuing. Surfacing such a bug is a success of this migration.

- [ ] **Step 6: Confirm the full suite is green**

Run: `vendor/bin/pest 2>&1 | tail -15`
Expected: all tests PASS on MariaDB. Record final pass count and the before/after delta vs the baseline.

- [ ] **Step 7: Commit the fixes**

```bash
git add -A
git commit -m "test: fix latent failures surfaced by MariaDB (AID-259)

Real-engine portability fixes (strict mode / types / dates / ordering / index
lengths) plus explicit cleanup for migration-running tests where MariaDB DDL
implicit-commit defeats transaction rollback. Full suite green on MariaDB 12.3.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

(If no fixes were needed — suite was green immediately — skip this commit and note "MariaDB surfaced 0 latent failures" in the PR description.)

---

### Task 4: Make CI a two-engine matrix (MariaDB 12.3 + MySQL 8.4) + env-driven driver

**Files:**
- Modify: `tests/TestCase.php` (driver becomes `env('DB_DRIVER', 'mariadb')`)
- Modify: `.github/workflows/run-tests.yml`

**Interfaces:**
- Consumes: the green-on-MariaDB local suite from Task 3.
- Produces: CI running the suite on BOTH MariaDB 12.3 and MySQL 8.4 across the 8-combo matrix.

- [ ] **Step 1: Make the TestCase driver env-driven**

In `tests/TestCase.php`, change the driver line inside the `testing` connection from `'driver' => 'mariadb',` to:

```php
            'driver' => env('DB_DRIVER', 'mariadb'),
```

Everything else in that connection block is unchanged.

- [ ] **Step 2: Confirm local (MariaDB) is still green with the env-driven driver**

Run: `DB_DRIVER=mariadb DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=verifactu_test DB_USERNAME=verifactu DB_PASSWORD=secret vendor/bin/pest 2>&1 | tail -5`
Expected: same green result as the end of Task 3.

- [ ] **Step 3: Add the engine axis + db to the job name + raise timeout**

In `.github/workflows/run-tests.yml`: add `db: [mariadb, mysql]` to `strategy.matrix`; change `timeout-minutes: 5` → `15`; and add the engine to the job `name:` so the 8 jobs are distinguishable, e.g.:

```yaml
    name: P${{ matrix.php }} - L${{ matrix.laravel }} - ${{ matrix.db }} - ${{ matrix.stability }}
```

- [ ] **Step 4: Add the per-engine service + job env (sibling of `strategy`/`steps`)**

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
    env:
      DB_DRIVER: ${{ matrix.db }}
      DB_HOST: 127.0.0.1
      DB_PORT: 3306
      DB_DATABASE: verifactu_test
      DB_USERNAME: verifactu
      DB_PASSWORD: secret
```

The image is chosen by `matrix.db`; one env block carries both `MARIADB_*` and `MYSQL_*` (each image ignores the other's). `matrix.db` doubles as the Laravel driver name, so `DB_DRIVER: ${{ matrix.db }}` selects the right driver. Both images auto-create `verifactu`@`%` granted on `verifactu_test`; `mysqladmin ping` is the portable healthcheck.

- [ ] **Step 5: Swap the PHP extensions (line 38)**

Replace `sqlite, pdo_sqlite` with `pdo_mysql`:

```yaml
          extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, pdo_mysql, soap, openssl
```

- [ ] **Step 6: Validate the workflow YAML**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/run-tests.yml')); print('YAML OK')"`
Expected: `YAML OK`.

- [ ] **Step 7: Commit and push**

```bash
git add tests/TestCase.php .github/workflows/run-tests.yml
git commit -m "ci: two-engine test matrix (MariaDB 12.3 + MySQL 8.4) (AID-259)

Add db:[mariadb,mysql] matrix axis (8 jobs); per-engine service image chosen by
matrix.db with both MARIADB_*/MYSQL_* env and a portable mysqladmin-ping
healthcheck; env-driven DB_DRIVER; swap sqlite/pdo_sqlite for pdo_mysql; timeout
5->15. Public repo, so the extra runners are free.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
git push -u origin abdelkarim/aid-259-lara-verifactu-migrate-test-suite-from-sqlite-to-mariadb
```

- [ ] **Step 8: Open the PR and watch all 8 combos**

Run: `gh pr create --fill --base main 2>/dev/null; gh pr checks --watch`
Expected: `run-tests` SUCCESS for all 8 (P8.3/8.4 × L12/L13 × {mariadb, mysql}), plus PHPStan and GitGuardian. Note durations.

- [ ] **Step 9: Fix MySQL-only failures surfaced in CI (sub-loop, if any)**

If mysql-leg jobs fail where the mariadb legs pass, the failures are MySQL-8.4-specific portability (stricter `sql_mode`, reserved words, default collation, `ONLY_FULL_GROUP_BY`). Apply the same categorize → fix → escalate method as Task 3, push, and re-watch until all 8 are green. Real domain bugs → escalate to the user, do not silence.

---

### Task 5: Document the local MariaDB setup

**Files:**
- Modify: `README.md` (or `CONTRIBUTING.md` if the repo has one) — testing section

**Interfaces:**
- Consumes: the connection defaults from Task 1.
- Produces: a documented local-dev path so a contributor (or future you) can run the suite.

- [ ] **Step 1: Add a "Running the tests" section**

Add to the testing/development docs:

````markdown
## Running the tests

The suite runs against **MariaDB** (a deployment engine), not SQLite. The local
target is your MariaDB instance (this repo's dev box runs it on port 3307).
One-time setup:

```bash
mariadb -uroot --port=3307 --protocol=tcp <<'SQL'
CREATE DATABASE IF NOT EXISTS verifactu_test;
CREATE USER IF NOT EXISTS 'verifactu'@'%' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON verifactu_test.* TO 'verifactu'@'%';
FLUSH PRIVILEGES;
SQL
```

Point the suite at it (CI uses the same values but `DB_PORT=3306`):

```bash
export DB_DRIVER=mariadb DB_HOST=127.0.0.1 DB_PORT=3307 \
       DB_DATABASE=verifactu_test DB_USERNAME=verifactu DB_PASSWORD=secret
composer test
```

CI additionally runs the full suite against MySQL 8.4. `composer test` is serial
(parallel testing is intentionally disabled — see AID-259).
````

- [ ] **Step 2: Verify the docs render and the commands match the spec**

Run: `grep -n "verifactu_test" README.md`
Expected: the setup block is present and the credentials match Task 1 / CI.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: document MariaDB-based local test setup (AID-259)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Done criteria (whole branch)

- Full suite green locally on MariaDB 11.4, and across all 8 CI combos (P8.3/8.4 × L12/L13 × {MariaDB 12.3, MySQL 8.4}).
- CI connects as `verifactu` (not root) over TCP on both engines; driver is env-driven (`DB_DRIVER`).
- `test-parallel` removed; `precommit` serial; `ext-pdo_mysql` in `require-dev`.
- No SQLite in `tests/TestCase.php` or the workflow; production migration `sqlite` branches untouched.
- Before/after pass-count delta recorded in the PR; any real domain bug escalated, not silenced.
- PR opened against `main`; AID-259 unblocks AID-258.

---

## Execution addendum — DB lifecycle deadlock (2026-06-26)

Running the suite on the real engine surfaced a hang that SQLite `:memory:` had
masked for months — the whole point of this migration. Two distinct latent bugs:

1. **Migration `down()` ordering (MySQL/MariaDB error 1553).** In
   `2026_06_24_000001_add_subsanacion...`, `down()` dropped the unique index
   before the FK that depends on it. SQLite allowed it silently; the real engines
   reject it. Fix: `dropForeign(['amends_registry_id'])` before
   `dropUnique('verifactu_registries_amends_unique')`.

2. **Test DB lifecycle / metadata-lock deadlock.** One connection left a
   transaction open (`Sleep`) while another ran `DROP TABLE` for the refresh,
   blocking on an exclusive metadata lock. With MySQL's default `lock_wait_timeout`
   (1 year) this hung indefinitely (~18 min observed). Root cause: inconsistent
   refresh strategy (`LazilyRefreshDatabase` only on Feature) + redundant
   `loadMigrationsFrom` in `beforeEach` + `executionOrder=random`.

**Lifecycle fix applied (this scope):**

- `RefreshDatabase` uniform across the suite (`tests/Pest.php`); `Unit` no longer
  touches the DB (`defineDatabaseMigrations` gated to Feature).
- `executionOrder` → `default` (deterministic, reproducible).
- `tearDown` disconnects all connections after `parent::tearDown()` (testbench
  rebuilds the app per test; stale connections accumulate otherwise).
- `SET SESSION lock_wait_timeout=5, innodb_lock_wait_timeout=5` on the test
  connection — turns any future hang into a fast, reproducible failure.
- Redundant `loadMigrationsFrom` removed from Feature `beforeEach` hooks.
- Driver env-driven (`DB_DRIVER`) for the MySQL CI leg.

**Verification (2026-06-26):** full suite green on both engines — 574 passed,
7 skipped, 1315 assertions. Local MariaDB 3307 took 568s vs MySQL 3306 62s; the
9× gap is `innodb_flush_method=O_DIRECT` on macOS (no real O_DIRECT support),
local-only, not a CI concern (Linux containers handle O_DIRECT efficiently).

**Over-engineering cleanup deferred to AID-261** (not in this scope).
