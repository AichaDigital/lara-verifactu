<?php

declare(strict_types=1);

use AichaDigital\LaraVerifactu\Enums\RegistryStatusEnum;
use AichaDigital\LaraVerifactu\Exceptions\VerifactuException;
use AichaDigital\LaraVerifactu\Models\Registry;

/**
 * AID-1626 — an accepted-with-errors record is a verdict: the agency
 * registered it, so it is sealed like SENT/ACCEPTED (AID-220). An exhausted
 * record is not a verdict: nothing was filed, so it behaves like ERROR.
 */
it('refuses to delete a registry accepted with errors', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::ACCEPTED_WITH_ERRORS]);

    expect(fn () => $registry->delete())->toThrow(VerifactuException::class, 'cannot be deleted');
});

it('lets an exhausted registry be deleted, like an error one', function () {
    $registry = Registry::factory()->create(['status' => RegistryStatusEnum::EXHAUSTED]);

    $registry->delete();

    expect(Registry::find($registry->id))->toBeNull();
});
