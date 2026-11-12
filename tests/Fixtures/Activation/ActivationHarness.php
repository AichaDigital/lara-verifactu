<?php

declare(strict_types=1);

namespace AichaDigital\LaraVerifactu\Tests\Fixtures\Activation;

use AichaDigital\LaraVerifactu\Contracts\ActivationContract;
use AichaDigital\LaraVerifactu\Contracts\AeatClientContract;
use AichaDigital\LaraVerifactu\Contracts\CertificateManagerContract;
use AichaDigital\LaraVerifactu\Contracts\QrGeneratorContract;
use AichaDigital\LaraVerifactu\Services\HashGenerator;
use AichaDigital\LaraVerifactu\Services\InvoiceRegistrar;
use AichaDigital\LaraVerifactu\Services\RegistryManager;
use AichaDigital\LaraVerifactu\Services\XmlBuilder;
use Illuminate\Support\Facades\DB;
use Mockery;

/**
 * Shared setup of the AID-1907 activation tests.
 */
final class ActivationHarness
{
    public static function configureIssuer(): void
    {
        config()->set('verifactu.company.tax_id', '89890001K');
        config()->set('verifactu.company.name', 'Empresa Ejemplo SL');
        config()->set('verifactu.system.vendor_name', 'AichaDigital SL');
        config()->set('verifactu.system.vendor_nif', 'B70123456');
        config()->set('verifactu.system.name', 'LaraVerifactu');
        config()->set('verifactu.system.id', 'LV');
        config()->set('verifactu.system.version', '1.0');
        config()->set('verifactu.system.installation_number', '1');
    }

    public static function registryManager(): RegistryManager
    {
        $qrGenerator = Mockery::mock(QrGeneratorContract::class);
        $qrGenerator->shouldReceive('generateUrl')->andReturn('https://example.test/qr');
        $qrGenerator->shouldReceive('generateSvg')->andReturn('<svg/>');
        $qrGenerator->shouldReceive('generatePng')->andReturn('png-binary');

        return new RegistryManager(new HashGenerator, $qrGenerator, new XmlBuilder);
    }

    /**
     * Bind a registrar over the given client, and return it.
     */
    public static function bindRegistrar(RegistryManager $manager, AeatClientContract $client): InvoiceRegistrar
    {
        $registrar = new InvoiceRegistrar(
            $manager,
            Mockery::mock(CertificateManagerContract::class),
            $client,
        );

        app()->instance(InvoiceRegistrar::class, $registrar);
        app()->instance(AeatClientContract::class, $client);

        return $registrar;
    }

    public static function script(ScriptedActivation $activation): ScriptedActivation
    {
        app()->instance(ActivationContract::class, $activation);

        return $activation;
    }

    /**
     * Every column of every registry, keyed by id: the "nothing changed" oracle.
     *
     * @return array<int|string, array<string, mixed>>
     */
    public static function registrySnapshot(): array
    {
        return DB::table('verifactu_registries')->orderBy('id')->get()
            ->mapWithKeys(fn (object $row): array => [$row->id => (array) $row])
            ->all();
    }
}
