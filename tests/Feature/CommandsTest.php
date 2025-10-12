<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Commands\RegisterInvoiceCommand;
use AichaDigital\LaraVerifactu\Commands\RetryFailedCommand;
use AichaDigital\LaraVerifactu\Commands\StatusCommand;
use AichaDigital\LaraVerifactu\Commands\VerifyBlockchainCommand;

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

