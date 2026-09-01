<?php

declare(strict_types=1);

use Simtabi\Laranail\Ichava\Constants\IchavaConstants;
use Simtabi\Laranail\Ichava\Constants\JsonConfigConstants;

describe('IchavaConstants', function () {
    it('exposes the expected cache TTL constants', function () {
        expect(IchavaConstants::DEFAULT_CACHE_TTL)->toBe(3600);
        expect(IchavaConstants::PRODUCTION_CACHE_TTL)->toBe(86400);
    });

    it('exposes the path-validation limits', function () {
        expect(IchavaConstants::MAX_PATH_LENGTH)->toBeInt()->toBeGreaterThan(0);
        expect(IchavaConstants::MAX_ICON_NAME_LENGTH)->toBeInt()->toBeGreaterThan(0);
        expect(IchavaConstants::MAX_PATH_SEGMENT_LENGTH)->toBeInt()->toBeGreaterThan(0);
        expect(IchavaConstants::MAX_NESTING_DEPTH)->toBeInt()->toBeGreaterThan(0);
    });

    it('exposes file size, queue, log and rate-limit limits', function () {
        expect(IchavaConstants::MAX_SVG_FILE_SIZE)->toBeInt()->toBeGreaterThan(0);
        expect(IchavaConstants::MIN_SVG_FILE_SIZE)->toBeInt()->toBeGreaterThan(0);
        expect(IchavaConstants::QUEUE_TIMEOUT)->toBeInt()->toBeGreaterThan(0);
        expect(IchavaConstants::QUEUE_RETRIES)->toBeInt()->toBeGreaterThan(0);
        expect(IchavaConstants::QUEUE_STAGGER_DELAY)->toBeInt()->toBeGreaterThanOrEqual(0);
        expect(IchavaConstants::PROGRESS_TTL)->toBeInt()->toBeGreaterThan(0);
        expect(IchavaConstants::LOG_RETENTION_DAYS)->toBeInt()->toBeGreaterThan(0);
        expect(IchavaConstants::PERFORMANCE_THRESHOLD_MS)->toBeInt()->toBeGreaterThan(0);
        expect(IchavaConstants::BROWSER_PER_PAGE)->toBeInt()->toBeGreaterThan(0);
        expect(IchavaConstants::BROWSER_MAX_PER_PAGE)->toBeInt()
            ->toBeGreaterThanOrEqual(IchavaConstants::BROWSER_PER_PAGE);
        expect(IchavaConstants::RATE_LIMIT_BROWSER)->toBeInt()->toBeGreaterThan(0);
        expect(IchavaConstants::RATE_LIMIT_API)->toBeInt()->toBeGreaterThan(0);
    });

    it('exposes the default SVG asset path', function () {
        expect(IchavaConstants::SVG_ASSETS_PATH)->toBe('resources/assets/svg');
        expect(IchavaConstants::PROVIDER_LEVELS_UP)->toBe(3);
    });
});

// JsonConfigConstants drives package-metadata accessors off config.json. We
// stand up a tiny anonymous-class fixture pointing at a tmp dir so every
// reader gets exercised without depending on a real icon package.

describe('JsonConfigConstants accessors', function () {
    beforeEach(function () {
        $this->tmpDir = sys_get_temp_dir().'/ichava-jcc-'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
        file_put_contents($this->tmpDir.'/config.json', json_encode([
            'package' => [
                'name' => 'myorg/test-icons',
                'title' => 'Test Icons',
                'description' => 'Test fixture',
                'version' => '1.2.3',
                'license' => 'MIT',
                'type' => 'icon-set',
            ],
            'config' => [
                'icon_prefix' => 'tic',
                'defaults' => [
                    'class' => 'tic-icon',
                    'stroke_width' => 1,
                    'attributes' => ['fill' => 'none'],
                ],
            ],
            'metadata' => [
                'homepage' => 'https://example.com',
                'repository' => 'https://github.com/myorg/test-icons',
                'data' => [
                    'variants' => [
                        'outline' => ['name' => 'Outline', 'description' => 'Outline variant', 'default' => true],
                        'filled' => ['name' => 'Filled', 'description' => 'Solid'],
                    ],
                    'categories' => [
                        'general' => ['name' => 'General', 'default' => true],
                        'arrows' => ['name' => 'Arrows'],
                    ],
                ],
            ],
            'upstream' => [
                'source' => ['type' => 'npm', 'package' => '@myorg/test-icons'],
                'current_version' => '1.2.3',
                'version_check_url' => 'https://registry.npmjs.org/@myorg/test-icons/latest',
                'cdn' => [
                    'jsdelivr' => 'https://cdn.jsdelivr.net/npm/@myorg/test-icons@{version}/{name}.svg',
                ],
                'update_command' => [
                    'type' => 'npm',
                    'package' => '@myorg/test-icons',
                ],
                'additional_sources' => [
                    ['name' => 'mirror', 'type' => 'github', 'owner' => 'myorg', 'repo' => 'test-icons-mirror'],
                ],
            ],
        ]));
    });

    afterEach(function () {
        @unlink($this->tmpDir.'/config.json');
        @rmdir($this->tmpDir);
        // Anonymous classes from previous tests stay loaded; clear the
        // base class's per-class cache so each test sees a clean slate.
        JsonConfigConstants::clearCache();
    });

    it('returns vendor / package / vendor-package strings', function () {
        $tmpDir = $this->tmpDir;
        $stub = new class($tmpDir) extends JsonConfigConstants
        {
            public static string $dir = '';

            public function __construct(string $dir)
            {
                self::$dir = $dir;
            }

            protected static function getConfigPath(): string
            {
                return self::$dir;
            }
        };

        expect($stub::getVendorName())->toBe('myorg');
        expect($stub::getPackageName())->toBe('test-icons');
        expect($stub::getVendorPackage())->toBe('myorg/test-icons');
        expect($stub::getPrefix())->toBe('tic');
        expect($stub::getDefaultVariant())->toBe('outline');
        expect($stub::getDefaultCategory())->toBe('general');
        expect($stub::getCategories())->toBe(['general', 'arrows']);
        expect($stub::getVariants())->toContain('outline');
        expect($stub::getVariants())->toContain('filled');
        expect($stub::getVariantsWithMetadata())->toHaveKey('outline');
        expect($stub::hasVariant('outline'))->toBeTrue();
        expect($stub::hasVariant('does-not-exist'))->toBeFalse();
        expect($stub::getTitle())->toBe('Test Icons');
        expect($stub::getDescription())->toBe('Test fixture');
        expect($stub::getVersion())->toBe('1.2.3');
        expect($stub::getLicense())->toBe('MIT');
        expect($stub::getType())->toBe('icon-set');
        expect($stub::getHomepage())->toBe('https://example.com');
        expect($stub::getRepository())->toBe('https://github.com/myorg/test-icons');
        expect($stub::getGitHubRepo())->toBe('myorg/test-icons');
        expect($stub::getDefaultClass())->toBe('tic-icon');
        expect($stub::getDefaultStrokeWidth())->toBe(1);
        expect($stub::getDefaultAttributes())->toBe(['fill' => 'none']);

        // Upstream block accessors -- replaces the legacy config.updater path.
        expect($stub::hasUpstream())->toBeTrue();
        expect($stub::getUpstreamCurrentVersion())->toBe('1.2.3');
        expect($stub::getUpstreamVersionCheckUrl())->toBe('https://registry.npmjs.org/@myorg/test-icons/latest');
        expect($stub::getUpstreamCdnUrls())->toHaveKey('jsdelivr');
        expect($stub::getUpstreamUpdateCommand())->toMatchArray(['type' => 'npm', 'package' => '@myorg/test-icons']);
        expect($stub::getUpstreamAdditionalSources())->toHaveCount(1);
        expect($stub::getUpstreamAdditionalSources()[0]['name'])->toBe('mirror');
    });
});
