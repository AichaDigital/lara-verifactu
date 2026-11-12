<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\EndpointResolverContract;
use AichaDigital\LaraVerifactu\Jobs\ProcessInvoiceRegistrationJob;
use AichaDigital\LaraVerifactu\Jobs\RetryFailedRegistriesJob;
use AichaDigital\LaraVerifactu\Jobs\SubmitRegistryToAeatJob;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Tests\Fixtures\Activation\ActivationHarness;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * AID-1907 §4.3 (O4): queued work while disabled ends in a logged no-op.
 * No send, no attempt, no outcome write, no lock, no release/hold, no fail().
 */

beforeEach(function () {
    ActivationHarness::configureIssuer();
    config()->set('verifactu.aeat.environment', 'sandbox');

    $manager = ActivationHarness::registryManager();
    $this->invoice = Invoice::factory()->create();
    $this->registry = $manager->createRegistry($this->invoice);

    // Counts every build of the concrete AEAT client through the package
    // binding: its factory resolves the endpoint first.
    $this->endpointBuilds = 0;
    $real = app(EndpointResolverContract::class);
    app()->instance(EndpointResolverContract::class, new class($real, $this) implements EndpointResolverContract
    {
        public function __construct(private EndpointResolverContract $real, private object $test) {}

        public function resolve(string $environment, string $certificateType): string
        {
            $this->test->endpointBuilds++;

            return $this->real->resolve($environment, $certificateType);
        }
    });

    $this->warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        if ($event->message === 'verifactu.disabled_noop') {
            $this->warnings[] = $event;
        }
    });
});

dataset('disabled jobs', [
    'ProcessInvoiceRegistrationJob' => [fn () => new ProcessInvoiceRegistrationJob($this->invoice->id), 'invoice_id'],
    'SubmitRegistryToAeatJob' => [fn () => new SubmitRegistryToAeatJob($this->registry->id), 'registry_id'],
    'RetryFailedRegistriesJob' => [fn () => new RetryFailedRegistriesJob, null],
]);

it('returns normally without lock, release, failure, client or row change, and logs one skipped_disabled warning', function (Closure $make, ?string $subjectKey) {
    config()->set('verifactu.enabled', false);
    $before = ActivationHarness::registrySnapshot();

    $job = $make->call($this)->withFakeQueueInteractions();

    // A cache spy: the job must not reach the lock machinery at all.
    Cache::spy();

    app()->call([$job, 'handle']);

    Cache::shouldNotHaveReceived('lock');
    Cache::shouldNotHaveReceived('store');

    // Killed mutation: $this->release(10) before the disabled return.
    $job->assertNotReleased();
    $job->assertNotFailed();
    $job->assertNotDeleted();

    expect($this->endpointBuilds)->toBe(0)
        ->and(ActivationHarness::registrySnapshot())->toBe($before)
        ->and($this->warnings)->toHaveCount(1);

    $context = $this->warnings[0]->context;
    expect($this->warnings[0]->level)->toBe('warning')
        ->and($context['job'])->toBe($job::class)
        ->and($context['reason'])->toBe('disabled')
        ->and($context['outcome'])->toBe('skipped_disabled');

    if ($subjectKey !== null) {
        expect($context)->toHaveKey($subjectKey);
    }
})->with('disabled jobs');

it('is consumed by a real worker on the database driver, not failed', function () {
    config()->set('verifactu.enabled', false);
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'connection' => 'testing',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
    ]);
    config()->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs']);

    SubmitRegistryToAeatJob::dispatch($this->registry->id)->onConnection('database');
    $queued = DB::table('jobs')->first();
    expect($queued)->not->toBeNull();

    // The job picks its queue from verifactu.queue.name: listen where it is.
    $this->artisan('queue:work', ['connection' => 'database', '--queue' => $queued->queue, '--once' => true, '--stop-when-empty' => true])
        ->assertSuccessful();

    expect(DB::table('jobs')->where('id', $queued->id)->exists())->toBeFalse()
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($this->warnings)->toHaveCount(1)
        ->and($this->warnings[0]->context['registry_id'])->toBe($this->registry->id);
});
