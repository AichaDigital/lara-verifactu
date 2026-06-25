# AID-259: Migrate Test Suite to MariaDB — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the SQLite `:memory:` test database with MariaDB 12.3 (the real deployment engine) across local dev and CI, so latent schema drift surfaces and AID-258's concurrency test has an engine that can fork.

**Architecture:** Keep the testbench connection name `testing` but change its driver+params to `mariadb`, env-driven. CI gains a MariaDB service container (one `services:` block covers all four matrix combos). Parallel testing is dropped (unsafe on a shared DB). Latent failures MariaDB surfaces are fixed inline; real domain bugs are escalated, not silenced.

**Tech Stack:** PHP 8.3/8.4, Laravel 12/13, orchestra/testbench, Pest, MariaDB 12.3 LTS, GitHub Actions.

## Global Constraints

- **PHP:** 8.3+ · **Laravel:** 12.* and 13.* (CI matrix P8.3/8.4 × L12/L13).
- **Engine:** MariaDB 12.3 LTS only (`mariadb:12.3` image). No SQLite in test runtime or workflow. Production migration code (driver-conditional `sqlite` branches) stays untouched.
- **CI DB auth:** dedicated user `verifactu` / `secret` on database `verifactu_test`, host `%` — never root over TCP.
- **Durability:** standard MariaDB config, no `my.cnf` tuning.
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

### Task 4: Update the CI workflow (extensions, timeout, MariaDB service, env)

**Files:**
- Modify: `.github/workflows/run-tests.yml`

**Interfaces:**
- Consumes: the green local suite from Task 3.
- Produces: CI running the suite on MariaDB across all four matrix combos.

- [ ] **Step 1: Raise the job timeout (line 12)**

Change `timeout-minutes: 5` to `timeout-minutes: 15`.

- [ ] **Step 2: Add the MariaDB service and job env under the `test` job**

Add, at the `test` job level (sibling of `timeout-minutes` / `strategy` / `steps`):

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
    env:
      DB_HOST: 127.0.0.1
      DB_PORT: 3306
      DB_DATABASE: verifactu_test
      DB_USERNAME: verifactu
      DB_PASSWORD: secret
```

The `services:` block applies to every matrix combination; each runner gets its own fresh MariaDB on `127.0.0.1:3306`. The dedicated `verifactu` user (created by the entrypoint with host `%` and granted on `verifactu_test`) is reachable over TCP; root is not.

- [ ] **Step 3: Swap the PHP extensions (line 38)**

Replace `sqlite, pdo_sqlite` with `pdo_mysql` in the `extensions:` list. Result:

```yaml
          extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, pdo_mysql, soap, openssl
```

- [ ] **Step 4: Validate the workflow YAML locally**

Run: `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/run-tests.yml')); print('YAML OK')"`
Expected: `YAML OK`.

- [ ] **Step 5: Commit and push to trigger CI**

```bash
git add .github/workflows/run-tests.yml
git commit -m "ci: run tests on MariaDB 12.3 service across the matrix (AID-259)

Add a mariadb:12.3 service (dedicated verifactu user, healthcheck) and DB_* env
to the test job; swap sqlite/pdo_sqlite for pdo_mysql; raise timeout 5->15 to
absorb service boot + composer update + real-engine suite. One services block
covers all four P8.3/8.4 x L12/L13 combos.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
git push -u origin abdelkarim/aid-259-lara-verifactu-migrate-test-suite-from-sqlite-to-mariadb
```

- [ ] **Step 6: Verify CI is green on all four matrix combos and measure duration**

Run: `gh pr create --fill --base main 2>/dev/null; gh pr checks --watch` (or watch the run if the PR already exists)
Expected: `run-tests` SUCCESS for P8.3-L12, P8.3-L13, P8.4-L12, P8.4-L13, plus PHPStan and GitGuardian. Note the job duration; if comfortably under 15 min, optionally tighten `timeout-minutes` in a follow-up.

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

The suite runs against MariaDB (the deployment engine), not SQLite. One-time setup:

```bash
mariadb -uroot -p <<'SQL'
CREATE DATABASE IF NOT EXISTS verifactu_test;
CREATE USER IF NOT EXISTS 'verifactu'@'%' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON verifactu_test.* TO 'verifactu'@'%';
FLUSH PRIVILEGES;
SQL
```

Then point the suite at it (defaults match CI; override per your local instance):

```bash
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=verifactu_test \
       DB_USERNAME=verifactu DB_PASSWORD=secret
composer test
```

`composer test` runs serially (parallel testing is intentionally disabled — see AID-259).
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

- Full suite green on MariaDB 12.3 locally and across all four CI matrix combos.
- CI connects as `verifactu` (not root) over TCP.
- `test-parallel` removed; `precommit` serial; `ext-pdo_mysql` in `require-dev`.
- No SQLite in `tests/TestCase.php` or the workflow; production migration `sqlite` branches untouched.
- Before/after pass-count delta recorded in the PR; any real domain bug escalated, not silenced.
- PR opened against `main`; AID-259 unblocks AID-258.
