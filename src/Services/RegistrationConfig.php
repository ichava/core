<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Closure;
use Throwable;
use Simtabi\Laranail\Ichava\Support\Helpers;
use Simtabi\Laranail\Ichava\Data\PackDefinition;
use Simtabi\Laranail\Ichava\Support\Pack\PackBuilder;
use Simtabi\Laranail\Ichava\Contracts\IconSetInterface;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

/**
 * Registration Configuration Builder
 *
 * Fluent API for configuring icon package registration.
 */
final class RegistrationConfig
{
    private string $name;

    private IconRegistry $registry;

    private bool $registered = false;

    // Configuration
    private ?string $path = null;

    private ?string $prefix = null;

    private ?string $displayName = null;

    private ?string $vendor = null;

    private ?string $description = null;

    private ?string $version = null;

    /** The directory of the config.json fromConfigFile() read, whose whole config the set registers with. */
    private ?string $configDirectory = null;

    /** @var array<int, string> */
    private array $variants = [];

    /** @var array<int|string, mixed> */
    private array $categories = [];

    private ?string $providerClass = null;

    private ?IconSetInterface $iconSet = null;

    /** @var array<string, mixed> */
    private array $metadata = [];

    // Callbacks
    private ?Closure $onBefore = null;

    private ?Closure $onAfter = null;

    private ?Closure $onSuccess = null;

    private ?Closure $onError = null;

    public function __construct(string $name, IconRegistry $registry)
    {
        $this->name = $name;
        $this->registry = $registry;
    }

    public function fromDirectory(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    public function fromConfigFile(string $path): static
    {
        // Load config.json using centralized helper
        $config = Helpers::loadConfigJson(dirname($path));
        $this->configDirectory = dirname($path);

        $this->path = dirname($path) . '/files';
        $this->prefix = self::configString($config, 'config', 'icon_prefix');
        $this->displayName = self::configString($config, 'package', 'title');
        $this->description = self::configString($config, 'package', 'description');
        $this->vendor = self::configString($config, 'config', 'vendor');
        $this->version = self::configString($config, 'package', 'version');

        return $this;
    }

    public function useIconSet(IconSetInterface $set): static
    {
        $this->iconSet = $set;

        return $this;
    }

    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    public function name(string $name): static
    {
        $this->displayName = $name;

        return $this;
    }

    public function vendor(string $vendor): static
    {
        $this->vendor = $vendor;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function version(string $version): static
    {
        $this->version = $version;

        return $this;
    }

    /**
     * @param array<int, string> $variants
     */
    public function withVariants(array $variants): static
    {
        $this->variants = $variants;

        return $this;
    }

    /**
     * @param array<int|string, mixed> $categories
     */
    public function withCategories(array $categories): static
    {
        $this->categories = $categories;

        return $this;
    }

    public function providedBy(string $class): static
    {
        $this->providerClass = $class;

        return $this;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): static
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    public function onBefore(Closure $callback): static
    {
        $this->onBefore = $callback;

        return $this;
    }

    public function onAfter(Closure $callback): static
    {
        $this->onAfter = $callback;

        return $this;
    }

    public function onSuccess(Closure $callback): static
    {
        $this->onSuccess = $callback;

        return $this;
    }

    public function onError(Closure $callback): static
    {
        $this->onError = $callback;

        return $this;
    }

    /**
     * Execute registration
     */
    public function register(): static
    {
        return $this->execute();
    }

    /**
     * Internal execution method
     */
    public function execute(): static
    {
        if ($this->registered) {
            return $this;
        }

        // Fire onBefore
        if ($this->onBefore) {
            ($this->onBefore)($this);
        }

        try {
            // Nothing to register without a directory. `setBasePath()` and
            // `countIconsInDirectory()` take a string, so this used to surface
            // as a TypeError, caught below like any other failure.
            $path = $this->path ?? throw IchavaException::invalidConfiguration(
                "no icon directory was given for '{$this->name}'",
            );

            // One registration path (C-03): this builds the same definition
            // fromDirectory() does, from the fields set on this object, so a
            // set registered here carries the full metadata.
            $this->registry->register($this->definition($path), $this->iconSet);
            $this->registered = true;

            // Fire onSuccess
            if ($this->onSuccess) {
                ($this->onSuccess)($this);
            }

            // Fire onAfter
            if ($this->onAfter) {
                ($this->onAfter)($this);
            }
        } catch (Throwable $e) {
            // Fire onError
            if ($this->onError) {
                ($this->onError)($e);
            }

            throw $e;
        }

        return $this;
    }

    public function markRegistered(): static
    {
        $this->registered = true;

        return $this;
    }

    /**
     * Check if registered
     */
    public function isRegistered(): bool
    {
        return $this->registered;
    }

    /**
     * Get configuration name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * A string from one section of a parsed config.json, or '' when absent.
     *
     * @param array<string, mixed> $config
     */
    private static function configString(array $config, string $section, string $key): string
    {
        $values = $config[$section] ?? null;
        $value = is_array($values) ? ($values[$key] ?? null) : null;

        return is_string($value) ? $value : '';
    }

    /**
     * Mark as registered (internal use).
     *
     * Returns the config so the call can sit in a `return` -- it returned void
     * until IconRegistry::registerIconSet() did exactly that, and a second
     * registration of one set in a request threw a TypeError.
     */
    /**
     * The definition this configuration describes. $path is the directory of
     * SVGs; when it is named `files`, its parent is the set's base directory.
     */
    private function definition(string $path): PackDefinition
    {
        $pack = $this->configDirectory !== null
            ? PackBuilder::fromDirectory($this->configDirectory)->named($this->name)
            : PackBuilder::make($this->name, $path);

        foreach ($this->variants as $variant) {
            $pack->variant((string) $variant);
        }

        return $pack
            ->prefix($this->prefix ?? $this->name)
            ->about('title', $this->displayName ?? $this->name)
            ->when($this->description !== null, fn (PackBuilder $pack) => $pack->about('description', (string) $this->description))
            ->when($this->version !== null, fn (PackBuilder $pack) => $pack->about('version', (string) $this->version))
            ->when($this->categories !== [], fn (PackBuilder $pack) => $pack->categories())
            ->when($this->providerClass !== null, fn (PackBuilder $pack) => $pack->providedBy((string) $this->providerClass))
            ->when($this->vendor !== null && $this->vendor !== '', fn (PackBuilder $pack) => $pack->metadata(['vendor' => $this->vendor]))
            ->metadata($this->metadata)
            ->build();
    }
}
