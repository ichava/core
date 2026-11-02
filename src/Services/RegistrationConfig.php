<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Services;

use Closure;
use Throwable;
use Simtabi\Laranail\Ichava\Support\Helpers;
use Simtabi\Laranail\Ichava\Contracts\IconSetInterface;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;

/**
 * Registration Configuration Builder
 *
 * Fluent API for configuring icon package registration.
 */
class RegistrationConfig
{
    protected string $name;

    protected IconRegistry $registry;

    protected bool $registered = false;

    // Configuration
    protected ?string $path = null;

    protected ?string $prefix = null;

    protected ?string $displayName = null;

    protected ?string $vendor = null;

    protected ?string $description = null;

    protected ?string $version = null;

    /** @var array<int, string> */
    protected array $variants = [];

    /** @var array<int|string, mixed> */
    protected array $categories = [];

    protected ?string $providerClass = null;

    protected ?IconSetInterface $iconSet = null;

    /** @var array<string, mixed> */
    protected array $metadata = [];

    // Callbacks
    protected ?Closure $onBefore = null;

    protected ?Closure $onAfter = null;

    protected ?Closure $onSuccess = null;

    protected ?Closure $onError = null;

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

            // Build icon set if not provided
            if ($this->iconSet === null) {
                $this->iconSet = IconSetBuilder::make($this->name)
                    ->setBasePath($path)
                    ->prefix($this->prefix ?? $this->name);

                if (! empty($this->variants)) {
                    $this->iconSet->withVariants($this->variants);
                }

                if (! empty($this->categories)) {
                    $this->iconSet->withCategories(true);
                }
            }

            // Build metadata
            $metadata = array_merge([
                'package_name'   => $this->name,
                'name'           => $this->displayName ?? $this->name,
                'description'    => $this->description ?? '',
                'vendor'         => $this->vendor ?? '',
                'version'        => $this->version ?? '1.0.0',
                'base_path'      => $this->path,
                'icon_set_name'  => $this->name,
                'provider_class' => $this->providerClass,
                'prefix'         => $this->prefix ?? $this->name,
                'total'          => $this->registry->countIconsInDirectory($path),
            ], $this->metadata);

            // Register
            $this->registry->registerIconSet($this->name, $this->iconSet, $metadata);

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

    /**
     * Mark as registered (internal use).
     *
     * Returns the config so the call can sit in a `return` -- it returned void
     * until IconRegistry::registerIconSet() did exactly that, and a second
     * registration of one set in a request threw a TypeError.
     */
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
}
