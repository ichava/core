<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Contracts\IconSetVariantInterface;

/**
 * IconSetVariantInterface::getPath() is nullable, and both shapes satisfy it.
 *
 * The two enums are declared here rather than under tests/fixtures/: that
 * directory is lower case while the Tests\Fixtures namespace is not, which only
 * resolves on a case-insensitive filesystem.
 */
enum VariantPathContractPathlessCategory: string implements IconSetVariantInterface
{
    case GENERAL = 'general';

    public function getValue(): string
    {
        return $this->value;
    }

    public function isDefault(): bool
    {
        return true;
    }

    // A category that groups whole sets has no directory of its own.
    public function getPath(): ?string
    {
        return null;
    }

    public function getClass(): string
    {
        return 'demo-' . $this->value;
    }
}

enum VariantPathContractDirectoryVariant: string implements IconSetVariantInterface
{
    case OUTLINE = 'outline';

    public function getValue(): string
    {
        return $this->value;
    }

    public function isDefault(): bool
    {
        return true;
    }

    // The narrower `string` every pack declared before the interface widened.
    public function getPath(): string
    {
        return '/icons/' . $this->value;
    }

    public function getClass(): string
    {
        return 'demo-' . $this->value;
    }
}

it(description: 'declares getPath() as nullable', closure: function () {
    $type = (new ReflectionMethod(IconSetVariantInterface::class, 'getPath'))->getReturnType();

    expect($type)->toBeInstanceOf(ReflectionNamedType::class);
    expect($type->getName())->toBe('string');
    expect($type->allowsNull())->toBeTrue();
});

it(description: 'accepts an implementation with no directory', closure: function () {
    expect(VariantPathContractPathlessCategory::GENERAL)->toBeInstanceOf(IconSetVariantInterface::class);
    expect(VariantPathContractPathlessCategory::GENERAL->getPath())->toBeNull();
});

it(description: 'still accepts an implementation declaring the narrower string type', closure: function () {
    expect(VariantPathContractDirectoryVariant::OUTLINE)->toBeInstanceOf(IconSetVariantInterface::class);
    expect(VariantPathContractDirectoryVariant::OUTLINE->getPath())->toBe('/icons/outline');
});
