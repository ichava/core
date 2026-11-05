<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Testing;

use SplFileInfo;
use FilesystemIterator;
use Composer\Semver\Semver;
use PHPUnit\Framework\Assert;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Symfony\Component\Yaml\Yaml;
use Simtabi\Laranail\Ichava\Support\SvgInspector;

/**
 * The rules every icon pack holds, written once (P-03, P-04).
 *
 * Each method is one rule, asserted with PHPUnit's Assert so core takes no
 * Pest dependency. A pack keeps its own, thin, named test files that call
 * these, so its test names and counts survive. Every method that reads files
 * asserts how many it read, so a glob that stops matching fails rather than
 * passing on nothing. `$root` is the pack's root directory.
 *
 * Requires composer/semver and symfony/yaml, which every pack has in require-dev.
 */
final class PackConformance
{
    /** composer.json `require` is exactly `php` and `ichava/core`. */
    public static function requiresOnlyCore(string $root): void
    {
        $require = array_keys(self::composer($root)['require'] ?? []);
        sort($require);

        Assert::assertSame(['ichava/core', 'php'], $require, 'A pack requires php and ichava/core, and nothing else.');
    }

    /** composer.json declares `ichava-icon-set`, the type core discovers packs by. */
    public static function declaresPackType(string $root): void
    {
        Assert::assertSame('ichava-icon-set', self::composer($root)['type'] ?? null);
    }

    /** No file under src/ names a Simtabi\Laranail namespace but core's, nor icon-browser's. */
    public static function importsOnlyCore(string $root, int $minFiles = 4): void
    {
        $offenders = [];
        $files = self::files($root . '/src', 'php');

        foreach ($files as $file) {
            preg_match_all('/Simtabi\\\\+Laranail\\\\+(\w+)(?:\\\\+(\w+))?/', (string) file_get_contents($file), $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                if ($match[1] !== 'Ichava' || ($match[2] ?? '') === 'IconBrowser') {
                    $offenders[] = basename($file) . ': ' . $match[0];
                }
            }
        }

        Assert::assertGreaterThanOrEqual($minFiles, count($files), 'Too few src/ files were read.');
        Assert::assertSame([], $offenders);
    }

    /** phpstan.neon.dist sets exactly one level, `max`. */
    public static function analysesAtLevelMax(string $root): void
    {
        $path = $root . '/phpstan.neon.dist';
        Assert::assertFileExists($path);

        $count = preg_match_all('/^\s*level:\s*(\S+)\s*$/m', (string) file_get_contents($path), $matches);

        Assert::assertSame(1, $count, 'phpstan.neon.dist must set exactly one level.');
        Assert::assertSame('max', $matches[1][0], 'phpstan.neon.dist must analyse at level max.');
    }

    /** PHPStan runs with no baseline file and no baseline include. */
    public static function analysesWithoutBaseline(string $root): void
    {
        Assert::assertFileDoesNotExist($root . '/phpstan-baseline.neon');
        Assert::assertStringNotContainsString('baseline', (string) file_get_contents($root . '/phpstan.neon.dist'), 'phpstan.neon.dist must not include a baseline.');
    }

    /** Every `ichava/*` constraint in composer.json admits $version. */
    public static function siblingConstraintsAdmit(string $root, string $version = '0.1.0'): void
    {
        $composer = self::composer($root);
        $found = 0;

        foreach (['require', 'require-dev'] as $section) {
            foreach ($composer[$section] ?? [] as $package => $constraint) {
                if (str_starts_with((string) $package, 'ichava/')) {
                    $found++;
                    Assert::assertTrue(Semver::satisfies($version, (string) $constraint), "{$section}.{$package} is '{$constraint}', which {$version} does not satisfy.");
                }
            }
        }

        Assert::assertGreaterThan(0, $found, 'composer.json names no ichava/* constraint, so nothing was checked.');
    }

    /** The sync caller calls the reusable sync-pack workflow pinned to one 40-hex commit. */
    public static function syncCallerIsPinned(string $root): void
    {
        $job = self::syncCaller($root)['jobs']['sync'] ?? [];
        $uses = (string) ($job['uses'] ?? '');

        Assert::assertMatchesRegularExpression('#^ichava/icon-sets-sync/\.github/workflows/sync-pack\.yml@[0-9a-f]{40}$#', $uses);
        Assert::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) ($job['with']['toolkit-ref'] ?? ''));
        Assert::assertSame(explode('@', $uses)[1], $job['with']['toolkit-ref']);
    }

    /** The sync caller runs on one schedule and a manual dispatch, never on push. */
    public static function syncCallerTriggers(string $root): void
    {
        $on = self::syncCaller($root)['on'] ?? null;

        Assert::assertIsArray($on);
        Assert::assertSame(['schedule', 'workflow_dispatch'], array_keys($on));
        Assert::assertCount(1, (array) $on['schedule']);
    }

    /** The sync caller grants only what the called workflow needs. */
    public static function syncCallerPermissions(string $root): void
    {
        $workflow = self::syncCaller($root);

        Assert::assertSame(['contents' => 'read'], $workflow['permissions'] ?? null);
        Assert::assertSame(['contents' => 'read', 'issues' => 'write'], $workflow['jobs']['sync']['permissions'] ?? null);
        Assert::assertSame(['sync'], array_keys($workflow['jobs'] ?? []));
    }

    /** Every workflow declares a top-level `permissions:` block; at least $minFiles are read. */
    public static function workflowsDeclarePermissions(string $root, int $minFiles): void
    {
        $dir = $root . '/.github/workflows';
        $files = array_merge(glob($dir . '/*.yml') ?: [], glob($dir . '/*.yaml') ?: []);
        $missing = [];

        foreach ($files as $file) {
            if (preg_match('/^permissions:/m', (string) file_get_contents($file)) !== 1) {
                $missing[] = basename($file);
            }
        }

        Assert::assertGreaterThanOrEqual($minFiles, count($files), 'Too few workflow files were read.');
        Assert::assertSame([], $missing, 'Workflows without a top-level permissions block.');
    }

    /** No SVG under $dir has text directly under `<svg>` or `<g>`; at least $minFiles are read. */
    public static function noStrayTextNodes(string $dir, int $minFiles): void
    {
        self::eachSvg($dir, $minFiles, SvgInspector::strayTextNodes(...), 'Stray text nodes');
    }

    /** Every SVG under $dir is one core's sanitiser leaves as it is; at least $minFiles are read. */
    public static function svgPolicyClean(string $dir, int $minFiles): void
    {
        self::eachSvg($dir, $minFiles, SvgInspector::policyViolations(...), 'SVGs the policy would change');
    }

    /**
     * @param callable(string): list<string> $inspect
     */
    private static function eachSvg(string $dir, int $minFiles, callable $inspect, string $message): void
    {
        $files = self::files($dir, 'svg');
        $offenders = [];

        foreach ($files as $file) {
            $found = $inspect((string) file_get_contents($file));

            if ($found !== []) {
                $offenders[] = substr($file, strlen(rtrim($dir, '/')) + 1) . ' => ' . implode(' | ', $found);
            }
        }

        Assert::assertGreaterThanOrEqual($minFiles, count($files), 'Too few SVG files were read.');
        Assert::assertSame([], $offenders, $message);
    }

    /**
     * @return list<string>
     */
    private static function files(string $dir, string $extension): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === $extension) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<string, mixed>
     */
    private static function composer(string $root): array
    {
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        return is_array($composer) ? $composer : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function syncCaller(string $root): array
    {
        $path = $root . '/.github/workflows/sync-upstream.yml';
        Assert::assertFileExists($path);
        $workflow = Yaml::parseFile($path);

        return is_array($workflow) ? $workflow : [];
    }
}
