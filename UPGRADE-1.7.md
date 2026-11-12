# Upgrading to lara-verifactu 1.7

1.7 adds a package-wide activation switch, `verifactu.enabled` (AID-1907). **It is off by default, and activating Verifactu is your responsibility.**

**Without the key, 1.7 refuses to register and to send.** A consumer that upgrades and sets nothing gets `VerifactuDisabledException` from `register()`, `cancel()`, `amendRejected()` and `sendBatch()`. Set `VERIFACTU_ENABLED=true` to keep the behaviour of 1.6.

**This ships as a minor version only under the owner's signed exception of 2026-11-08** (record `2026-11-08-excepcion-firmada-lara-verifactu-aid-1907`). A call that succeeds in 1.6 for a consumer that set no key now refuses, which would otherwise make this a major version. The exception must be reviewed no later than 2026-11-23.

## 1. Turn the switch on in the same upgrade

```dotenv
VERIFACTU_ENABLED=true
```

Accepted values: `true`/`false`, `1`/`0`, `on`/`off`, `yes`/`no`, in any case. Anything else, including an empty value (`VERIFACTU_ENABLED=`), is a configuration error: it is never read as off nor as on, and every write throws `ConfigurationException`.

A configuration file published by an older release does not need the key: when it is missing, the package reads it as `false`, and the environment variable still applies.

## 2. Reload the configuration and restart the workers

The switch is read from the configuration the process booted with.

- **Cached configuration:** rebuild it with `php artisan config:cache`, or run `php artisan config:clear` if you do not cache it. A changed `.env` has no effect until then.
- **Long-lived workers** (queue workers, Horizon, Octane) keep the configuration they booted with. `php artisan queue:restart` only signals them to exit; a process monitor (Supervisor or equivalent) must start the new processes. Check that the workers really were replaced.

## 3. Check the effective state

```bash
php artisan verifactu:status
```

The first row, `Enabled`, shows `Yes`, `No` or `Invalid`. When it is not `Yes`, the command prints a warning. `verifactu:install` prints the same warning.

From code, ask the package instead of reading its configuration: `Verifactu::enabled()`. The answer reflects the switch only; it does not promise that the certificate or the environment is configured.

## 4. What the package does while disabled

- **`register()`, `cancel()`, `amendRejected()`, `sendBatch()`** throw `VerifactuDisabledException`. No registry is created, nothing is chained, nothing is sent and no event fires. Your invoices are never touched: you keep invoicing normally.
- **`batchRegister()`** throws before its loop; it never reports the batch as N failures.
- **`submitToAeat()`** throws. The registry stays as it was, with its attempts untouched.
- **`verifactu:register`** prints the disabled message and exits with failure, also with `--all` when there is nothing to register.
- **`verifactu:retry-failed`** sends nothing, prints a warning and exits with success, whatever the state of its lock.
- **Queued jobs** (`ProcessInvoiceRegistrationJob`, `SubmitRegistryToAeatJob`, `RetryFailedRegistriesJob`) return without sending, locking, retrying or failing, and log one `verifactu.disabled_noop` warning. **The queue then deletes the job: it is consumed, not paused.** The registry it pointed to stays as it was.
- **Reads keep working:** `Verifactu::status()`, `qr()`, `validateChain()`, `enabled()`, `verifactu:status`, `verifactu:verify-blockchain`, `verifactu:test-connection` and `VerifyBlockchainIntegrityJob`.

`Verifactu::fake()` ignores the switch and `enabled()` returns `true` under it, so existing tests keep passing. Use `Verifactu::fake(honourSwitch: true)` to test your own "package disabled" branch.

## 5. Pending registries are not resent automatically

Turning the switch back on changes nothing retroactively. Registries left `pending` and jobs consumed while disabled are not resent on their own. To resend them, set `VERIFACTU_RETRY_RESEND_PENDING=true` (`retry.resend_pending`) and run `php artisan verifactu:retry-failed`.

## 6. No backfill of invoices issued while disabled

Invoices issued while the switch was off never get a registry automatically. Whether they must be registered is your legal call; if so, register them explicitly.

## 7. Series order after re-enabling, or on a mid-year adoption

In native mode the queued path (`ProcessInvoiceRegistrationJob`) refuses an invoice when an earlier invoice of the same series and fiscal year has no registry. An invoice issued while the package was off is such a gap: **after re-enabling, the queued path refuses every later invoice of that series** with a `RuntimeException`, until the gap is registered. The same applies when you adopt Verifactu in the middle of a year with earlier unregistered invoices in a series. This behaviour is unchanged from 1.6.

Your options:

- register the earlier invoices explicitly (`Verifactu::register()`, never automatic);
- open a new series;
- register through the facade path, which does not apply this check.

The definitive fix belongs to a later major version (AID-1929).

## 8. A send interrupted by the switch

If a registry is created while the package reads enabled and its send then runs where the package reads disabled (for example, the registry was created by a worker that booted enabled and the send is attempted from a request that booted disabled), the registry stays **persisted as `pending`, not submitted**, with its attempts untouched. The caller gets `VerifactuDisabledException`, never `AeatException`. Once a send has started, the switch does not interrupt it, and the agency's answer is recorded as usual. Recover pending registries as in section 5. Restarting the workers (section 2) removes the window.
