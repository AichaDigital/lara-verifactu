<?php

declare(strict_types=1);

return [
    'preset' => 'laravel',

    'exclude' => [
        'vendor',
        'build',
        'dist',
        'tests/Pest.php',
        '.phpunit.result.cache',
    ],

    'add' => [],

    'remove' => [],

    'config' => [],

    'requirements' => [
        'min-quality' => 80,
        'min-complexity' => 80,
        'min-architecture' => 80,
        'min-style' => 80,
    ],
];
