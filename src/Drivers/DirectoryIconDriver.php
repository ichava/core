<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Drivers;

use Illuminate\Support\Facades\File;
use Simtabi\Laranail\Ichava\Data\IconData;
use Simtabi\Laranail\Ichava\Exceptions\IchavaException;
use Simtabi\Laranail\Ichava\Contracts\IconDriverInterface;

/**
 * Lists, checks and renders the icons in one directory of SVG files, by their
 * path under it (`ui/check` → `ui/check.svg`), with no registry, pack or
 * database involved. Rendering goes through the bound IconDriverInterface
 * (SvgDriver), so the same containment checks, size limit and sanitiser apply.
 */
final class DirectoryIconDriver
{
    /** @var array<string, mixed> */
    private array $config = [];

    private string $iconPath = '';

    public function __construct(
        private readonly IconDriverInterface $driver,
    ) {}

    /**
     * Every icon under the directory, by path without `.svg`.
     *
     * @return list<string>
     */
    public function all(): array
    {
        if ($this->iconPath === '' || ! File::isDirectory($this->iconPath)) {
            return [];
        }

        $names = [];

        foreach (File::allFiles($this->iconPath) as $file) {
            if (strtolower($file->getExtension()) === 'svg') {
                $relative = str_replace('\\', '/', $file->getRelativePathname());
                $names[] = substr($relative, 0, -4);
            }
        }

        sort($names);

        return $names;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @throws IchavaException when the icon does not exist or cannot be rendered
     */
    public function render(string $name, array $attributes = []): string
    {
        if (! $this->has($name)) {
            throw IchavaException::iconNotFound($name, "No such icon under {$this->iconPath}");
        }

        $name = trim($name, '/');
        $slash = strrpos($name, '/');

        return $this->driver->render(
            new IconData(
                name: $slash === false ? $name : substr($name, $slash + 1),
                path: $this->file($name),
                variant: null,
                category: $slash === false ? null : substr($name, 0, $slash),
                set: is_string($this->config['set'] ?? null) ? $this->config['set'] : 'directory',
            ),
            $attributes,
            $this->iconPath,
        );
    }

    /**
     * Whether an icon exists. A name that climbs out of the directory never does.
     */
    public function has(string $name): bool
    {
        if ($this->iconPath === '' || str_contains($name, '..') || str_contains($name, "\0")) {
            return false;
        }

        return File::isFile($this->file(trim($name, '/')));
    }

    /**
     * @param array<string, mixed> $config Driver options; `set` names the set in IconData
     */
    public function setConfig(array $config): static
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    public function setIconPath(string $path): static
    {
        $this->iconPath = rtrim($path, '/\\');

        return $this;
    }

    public function getIconPath(): string
    {
        return $this->iconPath;
    }

    private function file(string $name): string
    {
        return $this->iconPath . '/' . $name . '.svg';
    }
}
