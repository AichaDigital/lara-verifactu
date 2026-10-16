# UPGRADE 1.4.x → 1.5.0

## Hold abandonment is now ERROR, not PENDING (AID-1673)

Since 1.4.1, `SubmitRegistryToAeatJob` gave up after exhausting
`retry.max_queue_holds` (default 60 holds ≈ 10 minutes) leaving the registry
`PENDING`. With `retry.resend_pending` off (the default) nothing ever picked it
up again: an issued invoice whose predecessor sat in `ERROR` for longer than
the hold budget was never submitted.

Since 1.5.0 the give-up **marks the registry `ERROR`** with
`aeat_error = "submission scheduling abandoned after exhausting the queue hold
budget"`. The default sweeper (`verifactu:retry-failed` / its job) selects
`ERROR` rows, so the record is recovered without enabling `resend_pending`.

**What changes for you:**

- `PENDING` registries abandoned by the job are now `ERROR`. The marking only
  touches `PENDING` rows — the only state a scheduling abandonment creates:
  `EXHAUSTED` stays a deliberate budget crossing (recovered via
  `--include-exhausted`) and an at-budget `ERROR` stays the budget rule. If you
  have dashboards or queries keyed on `status`, expect abandoned rows to show
  up under `error` from now on.
- `ERROR` semantics widen: it now also covers "the submission could not be
  scheduled" (hold budget exhausted), not only "the agency refused or the
  transport failed". The cause is readable in `aeat_error`.
- The marking does NOT consume a submission attempt: the submission never
  left for the agency. A registry one attempt short of `EXHAUSTED` stays a
  recoverable `ERROR` (the gate caught an earlier draft that spent the
  budget and stranded such rows).
- `resend_pending` keeps its exact meaning (AID-1645): rows created `PENDING`
  while submission was off are still only swept when you enable it. The
  abandonment marking does not leak into that selection.

## The retry sweeper obeys the chain order (AID-1673)

`InvoiceRegistrar::retryFailed()` (the engine behind `verifactu:retry-failed`
and `RetryFailedRegistriesJob`) previously sent candidates oldest-first with no
chain awareness: it kept sending after an earlier candidate failed, never
checked a record's predecessor, and took only its own `verifactu:retry-failed`
lock — so it could overlap a running `SubmitRegistryToAeatJob`.

Since 1.5.0 the sweeper applies the same guarantees as the job:

- It holds the `fiscal_verification_queue` lock for the whole pass, bounded to
  80% of `verifactu.lock.timeout` so it cannot outrun the lease across
  candidates. The guarantee assumes `verifactu.lock.timeout` comfortably above
  the AEAT client timeout (defaults: 300 s lease, 30 s client) — a single
  submission is bounded by the client timeout, not by the lease. A busy lock
  skips the pass entirely (nothing attempted) — the next scheduled run picks
  the work up; the command reports it with a zero `Skipped` row in the table.
  The cache store must be shared across processes, as `OverlapLockStore`
  already requires for the job.
- A candidate whose chain predecessor (the registry its `previous_hash` points
  at) is not filed at the agency is **skipped**, never sent. Guard and job
  share one implementation (`RegistryManager::hasUnfiledChainPredecessor()`).
- The pass **stops at the first submission failure** — every later candidate
  is left for a later pass.

**What changes for you:**

- The `skipped` counter of `retryFailed()`'s result now means "selected but not
  attempted": guard skips, candidates left behind a stop or a lease-bounded
  early stop, or — when the sequential lock is busy — a whole skipped pass (all
  counters zero). `verifactu:retry-failed` prints it as a `Skipped` row.
- A pass can do less work than before when the chain head is stuck: that is the
  guarantee working, not a regression. The stuck record itself stays in
  `ERROR`/`EXHAUSTED` and is retried on a later pass once its predecessor is
  filed.

## Version

New config key: none since 1.4.1's `retry.max_queue_holds` (still default 60).
No schema change. No public API removal — `retryFailed()`'s signature and
result shape are unchanged; `InvoiceRegistrar::markSchedulingAbandoned()` and
`RegistryManager::hasUnfiledChainPredecessor()` are additive.
