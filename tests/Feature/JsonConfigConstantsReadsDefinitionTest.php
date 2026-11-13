<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\Pack\PackBuilder;
use Simtabi\Laranail\Ichava\Tests\Fixtures\BasePack\Constants\IconsConstants;

/*
 * Proposal 2. A pack's constants class read its config.json directly, so an
 * override written by PackBuilder was invisible to it. It now reads the set's
 * registered definition, and the file only before the set registers.
 */

beforeEach(fn () => IconsConstants::clearCache());
afterEach(fn () => IconsConstants::clearCache());

it('reads the registered definition, overrides included', function (): void {
    $dir = dirname(__DIR__) . '/fixtures/BasePack/resources/assets/svg';

    expect(IconsConstants::getPrefix())->not->toBe('overridden');

    app(IconRegistry::class)->register(PackBuilder::fromDirectory($dir)->prefix('overridden')->variant('ghost')->build());

    expect(IconsConstants::getPrefix())->toBe('overridden')
        ->and(IconsConstants::hasVariant('ghost'))->toBeTrue();
});
