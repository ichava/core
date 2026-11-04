<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Support;

use Simtabi\Laranail\Ichava\Data\BulkResult;
use Simtabi\Laranail\Ichava\Data\BulkOptions;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Simtabi\Laranail\Ichava\Support\Pack\PackBuilder;

/**
 * Registers several icon sets under one base directory, through
 * {@see IconRegistry::registerMany()} (Proposal 3).
 *
 * The fluent front of `PackServiceProvider::registerBulkIconSets()`. Each key of
 * the map given to configure() is a sub-directory of the base path holding a
 * config.json; its value is that set's overrides, in the shape
 * {@see PackBuilder::withOptions()} reads (`['prefix' => 'fa']`). An empty map
 * registers every such sub-directory.
 *
 * ```php
 * IchavaRegistrar::register('acme/icons')
 *     ->basePath($svg)
 *     ->trackStatistics()
 *     ->enableLogging()
 *     ->configure(['fontawesome' => ['prefix' => 'fa'], 'bootstrap' => []]);
 * ```
 */
final class IchavaRegistrar
{
    private string $name;

    private string $basePath = '';

    /** Prepended to each set's directory name to form its prefix, when the set names none. */
    private string $packagePrefix = '';

    /** Appended to each set's directory name to form its prefix, when the set names none. */
    private string $iconSetSuffix = '';

    /** The Blade namespace each set's component alias is recorded under: `{namespace}-{set}`. */
    private string $bladeNamespace = '';

    private string $providerClass = '';

    private string $vendor = '';

    /** @var array<string, array<string, mixed>> */
    private array $iconSets = [];

    private bool $trackStats = false;

    private bool $logging = false;

    private ?BulkResult $result = null;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Start a bulk registration, named for the events and logs it produces.
     */
    public static function register(string $name): static
    {
        return new self($name);
    }

    public function basePath(string $path): static
    {
        $this->basePath = $path;

        return $this;
    }

    public function packagePrefix(string $prefix): static
    {
        $this->packagePrefix = $prefix;

        return $this;
    }

    public function iconSetSuffix(string $suffix): static
    {
        $this->iconSetSuffix = $suffix;

        return $this;
    }

    public function bladeNamespace(string $namespace): static
    {
        $this->bladeNamespace = $namespace;

        return $this;
    }

    public function providerClass(string $class): static
    {
        $this->providerClass = $class;

        return $this;
    }

    /** A label for the vendor of the sets, carried in the log line. */
    public function vendor(string $vendor): static
    {
        $this->vendor = $vendor;

        return $this;
    }

    /**
     * Register the sets. Values are each set's overrides; an empty map
     * registers every config-bearing sub-directory of the base path.
     *
     * @param array<string, array<string, mixed>> $iconSets directory name => overrides
     */
    public function configure(array $iconSets): static
    {
        $this->iconSets = $iconSets;
        $this->process();

        return $this;
    }

    /** Keep the run's BulkResult, for statistics(). */
    public function trackStatistics(): static
    {
        $this->trackStats = true;

        return $this;
    }

    /**
     * Log the run's counts. Called before configure() the registry logs the
     * run; called after, the result already recorded is logged now.
     */
    public function enableLogging(): static
    {
        if (! $this->logging && $this->result !== null) {
            app(IchavaLogger::class)->info($this->summary($this->result));
        }

        $this->logging = true;

        return $this;
    }

    /** The run's counts, when trackStatistics() was called; null otherwise or before configure(). */
    public function statistics(): ?BulkResult
    {
        return $this->trackStats ? $this->result : null;
    }

    private function process(): void
    {
        if ($this->basePath === '' || ! is_dir($this->basePath) || ! app()->bound(IconRegistry::class)) {
            return;
        }

        $root = rtrim($this->basePath, '/\\');
        $builders = $this->iconSets === []
            ? PackBuilder::discover($root)
            : array_map(
                fn (string $set): PackBuilder => PackBuilder::fromDirectory($root . DIRECTORY_SEPARATOR . $set),
                array_map(strval(...), array_keys($this->iconSets)),
            );

        foreach ($builders as $builder) {
            $this->shape($builder, basename($builder->basePath()));
        }

        $this->result = app(IconRegistry::class)->registerMany($builders, new BulkOptions(name: $this->name));

        if ($this->logging) {
            app(IchavaLogger::class)->info($this->summary($this->result));
        }
    }

    /** Apply the registrar-wide settings, then the set's own overrides, which win. */
    private function shape(PackBuilder $builder, string $set): void
    {
        $builder
            ->providedBy($this->providerClass !== '' ? $this->providerClass : self::class)
            ->when($this->packagePrefix !== '' || $this->iconSetSuffix !== '', fn (PackBuilder $pack) => $pack->prefix($this->packagePrefix . $set . $this->iconSetSuffix))
            ->when($this->bladeNamespace !== '', fn (PackBuilder $pack) => $pack->component("{$this->bladeNamespace}-{$set}"))
            ->withOptions($this->iconSets[$set] ?? []);
    }

    private function summary(BulkResult $result): string
    {
        return sprintf(
            'IchavaRegistrar [%s] (%s): registered %d set(s), skipped %d, failed %d.',
            $this->name,
            $this->vendor !== '' ? $this->vendor : 'unknown vendor',
            ...array_values($result->counts()),
        );
    }
}
