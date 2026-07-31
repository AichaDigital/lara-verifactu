<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Models\Invoice;
use AichaDigital\LaraVerifactu\Models\Registry;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;

/**
 * AID-728 — the chain must not fork through the soft-delete axis.
 *
 * Registry uses SoftDeletes, so the global scope hides deleted rows from every
 * query that does not ask for them. Both functions holding the chain up queried
 * without withTrashed():
 *
 *   - getPreviousRegistry() — so after deleting head B, the next record chained
 *     against A instead. B and C then share a predecessor: a fork, exactly the
 *     state the VeriFactu chain exists to make impossible.
 *   - verifyBlockchain() — the tool whose job is to catch that — so it walked a
 *     chain that looked linear to it and reported valid.
 *
 * The path is not hypothetical: Invoice::delete() cascades to its registries
 * (src/Models/Invoice.php), and neither delete path takes the AID-258 chain lock.
 *
 * AID-710 put the fork test in CI and proved the chain cannot fork under
 * concurrent WRITES. That covers one axis. This covers the other.
 *
 * Deleting a registry is NOT forbidden here: that would be a reduction of a
 * supported capability. The chain now links over what EXISTED, and verification
 * reports a deleted link instead of hiding it.
 */
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

    $this->registryManager = new RegistryManager(new HashGenerator, $qrGenerator, new XmlBuilder);
});

it('chains the next link over a soft-deleted head, not around it', function () {
    $a = $this->registryManager->createRegistry(Invoice::factory()->create());
    $b = $this->registryManager->createRegistry(Invoice::factory()->create());

    // Delete the head of the chain.
    Registry::query()->whereKey($b->getId())->delete();

    $c = $this->registryManager->createRegistry(Invoice::factory()->create());

    // C must chain over B — what existed — not over A. Chaining over A would
    // leave B and C both declaring A as predecessor: a fork.
    expect($c->getPreviousHash())->toBe($b->getHash())
        ->and($c->getPreviousHash())->not->toBe($a->getHash());
});

it('reports a soft-deleted link instead of walking around it', function () {
    $this->registryManager->createRegistry(Invoice::factory()->create());
    $b = $this->registryManager->createRegistry(Invoice::factory()->create());
    $this->registryManager->createRegistry(Invoice::factory()->create());

    // Delete the MIDDLE link of a three-record chain.
    Registry::query()->whereKey($b->getId())->delete();

    $result = $this->registryManager->verifyBlockchain();

    // Before AID-728 this returned valid: excluding the deleted row made the
    // remaining two look like a well-formed chain. Green on a broken chain is
    // the worst possible failure mode for a compliance tool.
    expect($result['valid'])->toBeFalse()
        ->and(implode(' ', $result['errors']))->toContain('deleted');
});

it('keeps reporting a chain with no deleted links as valid', function () {
    $this->registryManager->createRegistry(Invoice::factory()->create());
    $this->registryManager->createRegistry(Invoice::factory()->create());
    $this->registryManager->createRegistry(Invoice::factory()->create());

    // The sensitivity check in reverse: the new error must not fire on a chain
    // that is genuinely intact, or it would be noise rather than a signal.
    expect($this->registryManager->verifyBlockchain()['valid'])->toBeTrue();
});

it('detects the fork a cascading invoice delete used to open', function () {
    $invoiceB = Invoice::factory()->create();

    $this->registryManager->createRegistry(Invoice::factory()->create());
    $this->registryManager->createRegistry($invoiceB);

    // Invoice::delete() cascades to its registries, taking no chain lock.
    $invoiceB->delete();

    $this->registryManager->createRegistry(Invoice::factory()->create());

    $result = $this->registryManager->verifyBlockchain();

    // The chain is walked over every link that ever existed, so the hash
    // sequence still lines up; what is reported is the deleted link itself.
    expect($result['valid'])->toBeFalse();
});
