<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('registers the ichava::ichava-core.install command', function () {
    expect(Artisan::all())->toHaveKey('ichava::ichava-core.install');
});

it('fails for an unknown icon set', function () {
    $exit = Artisan::call('ichava::ichava-core.install', ['set' => 'no-such-set', '--no-interaction' => true]);

    expect($exit)->not->toBe(0);
});
