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
- Document the local-dev requirement (developer already runs a local MariaDB).

**Out of scope:**

- **No domain logic changes.** The chain-lock implementation is AID-258, layered
  on top of this once the suite runs on MariaDB.
- **No durability tuning.** Standard MariaDB config (production-faithful fsync per
  commit) was chosen deliberately over a fast test profile: fidelity to the
  deployment engine over inner-loop speed.
- **No SQLite anywhere.** Full replace, single engine — not a dual-engine matrix
  and not an opt-in escape hatch.
- **No `docker-compose.yml`** unless requested later. Env-driven config against the
  developer's existing local MariaDB plus the CI service container is sufficient.

## Decisions (from brainstorming)

| Decision | Choice | Rationale |
|---|---|---|
| Engine | MariaDB only (full replace) | Deployment target; "don't mix" |
| Version | MariaDB 12.3 LTS (`mariadb:12.3`) | Current LTS (12.3.2, May 2026, EOL Jun 2029) |
| Driver | Laravel `mariadb` driver | Dedicated driver in L11+, distinct from `mysql`; L12/L13 support it |
| Durability | Standard (no `my.cnf` tuning) | Fidelity to production over speed |
| CI shape | One MariaDB service on each existing matrix job | Single axis, no matrix doubling; PHPStan/Pint stay DB-less |
| Latent failures | Fixed as part of this PR | The migration is the occasion to surface and fix them |

## Design

### `tests/TestCase.php`

`getEnvironmentSetUp()` sets the default connection to `mariadb`, with all
connection parameters read from env so the same code works locally and in CI:

- `DB_HOST` (default `127.0.0.1`)
- `DB_PORT` (default `3306`)
- `DB_DATABASE` (default `verifactu_test`)
- `DB_USERNAME` / `DB_PASSWORD`

`RefreshDatabase` semantics are unchanged: migrations run once, each test runs in
a transaction and rolls back. The `hasMigrations([...])` migrations now execute
against MariaDB, so any non-portable migration syntax surfaces here.

### CI (`run-tests.yml`)

Each of the four matrix jobs (P8.3/8.4 × L12/L13) gains a `mariadb:12.3` service
with a health check; the `DB_*` env vars point the suite at it. The service uses
default durability. The PHPStan and Pint workflows do not touch the database and
get no service.

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

- Full suite green on MariaDB 12.3, locally and across the CI matrix.
- No SQLite references remain in `TestCase.php` or the workflow.
- A diff of pass/fail counts before vs after, with every newly-surfaced failure
  either fixed (portability) or escalated (real bug).

## Risks

- **Unknown count of latent failures.** Could be zero, could be a handful. Bounded
  by the suite size and isolated to test/schema portability in the common case.
- **CI time increase.** MariaDB with standard durability is slower than
  `:memory:`. Accepted trade-off; bounded by keeping a single service per job and
  not tuning away the deployment-faithful behavior.

## Follow-ups

- **AID-258** rides on this: chain-lock (`withChainLock` singleton-row + forced
  write) + a real two-connection concurrency test, now runnable on the MariaDB
  suite.
- A `docker-compose.yml` for contributor parity, if the package later takes
  external contributors.
