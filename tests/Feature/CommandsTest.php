<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Commands\RegisterInvoiceCommand;
use AichaDigital\LaraVerifactu\Commands\RetryFailedCommand;
use AichaDigital\LaraVerifactu\Commands\StatusCommand;
use AichaDigital\LaraVerifactu\Commands\VerifyBlockchainCommand;
use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Models\Registry;
use Illuminate\Support\Facades\Artisan;

it('status command is defined', function () {
    expect(StatusCommand::class)->toBeString();
});

it('register invoice command is defined', function () {
    expect(RegisterInvoiceCommand::class)->toBeString();
});

it('retry failed command is defined', function () {
    expect(RetryFailedCommand::class)->toBeString();
});

it('verify blockchain command is defined', function () {
    expect(VerifyBlockchainCommand::class)->toBeString();
});

it('counts accepted-with-errors and exhausted registries in verifactu:status', function () {
    config()->set('verifactu.certificate.path', '/nonexistent/certificate.p12');

    Registry::factory()->create(['status' => RegistryStatusEnum::ACCEPTED_WITH_ERRORS]);
    Registry::factory()->count(2)->create(['status' => RegistryStatusEnum::EXHAUSTED]);

    Artisan::call('verifactu:status');
    $output = Artisan::output();

    expect($output)->toMatch('/Accepted With Errors Registries\s*\|\s*1\s/')
        ->toMatch('/Exhausted Registries\s*\|\s*2\s/');
});
