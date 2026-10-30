<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Models;

use Throwable;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Ichava\Enums\IconKind;
use Illuminate\Database\Eloquent\Collection;
use Simtabi\Laranail\Ichava\Support\Helpers;
use Simtabi\Laranail\Ichava\Support\AuditLogger;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Simtabi\Laranail\Ichava\Services\IconCacheService;
use Simtabi\Laranail\Ichava\Support\FtsLanguageHelper;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Simtabi\Laranail\Ichava\Services\SvgProcessingService;

/**
 * Eloquent model for icon metadata. SVG content is not stored, only metadata
 * for fast querying, plus a relative `path` resolved against the owning
 * package's base path. See README § "Icon Path Format" for the layout details.
 *
 * @property int $id
 * @property string $package
 * @property string $name
 * @property string $path Relative path from base_path
 * @property string|null $file_hash
 * @property array<array-key, mixed>|null $tags
 * @property array<array-key, mixed>|null $keywords
 * @property array<array-key, mixed>|null $search_text
 * @property array<array-key, mixed>|null $attributes
 * @property array<array-key, mixed>|null $metadata
 * @property IconKind $kind
 * @property array<string, array{path: string, file_hash: string}>|null $theme_variants
 * @property Carbon|null $file_modified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string $absolute_path
 * @property-read int|null $file_size
 * @property-read string|null $svg_content
 * @property-read string $render_version
 * @property-read string $icon_path
 * @property-read string|null $viewbox
 * @property-read string|null $width
 * @property-read string|null $height
 * @property-read IconTerm|null $primary_category
 * @property-read IconTerm|null $primary_variant
 * @property-read IconTerm|null $primary_subcategory
 * @property-read array<int, mixed> $category_slugs
 * @property-read array<int, mixed> $variant_slugs
 * @property-read Collection<int, IconTerm> $terms
 * @property-read Collection<int, IconTerm> $categories
 * @property-read Collection<int, IconTerm> $variants
 */
final class Icon extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /** The table, as a literal so it can be interpolated into raw SQL. */
    public const TABLE = 'ichava_icons';

    /**
     * The morph alias core registers for this model, and so the value every
     * `termable_type` row holds. A literal so raw search SQL can quote it.
     */
    public const MORPH_ALIAS = 'icon';

    /** @var string */
    protected $table = self::TABLE;

    /** @var list<string> */
    protected $fillable = [
        'package',
        'name',
        'path',
        'file_hash',
        'tags',
        'keywords',
        'search_text',
        'attributes',
        'metadata',
        'kind',
        'theme_variants',
        'file_modified_at',
    ];

    /**
     * Default values for JSON columns. Keeps them as valid JSON when a row
     * is inserted without those keys explicitly populated.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'tags'       => '[]',
        'keywords'   => '[]',
        'attributes' => '{}',
        'metadata'   => '{}',
        'kind'       => 'icon',
    ];

    /***********************************************************************
     * Static Query Methods
     **********************************************************************/

    /**
     * Find icon by package and name
     */
    /**
     * Prepare attribute value for bulk database operations (upsert, insert)
     *
     * This helper ensures JSON attributes are properly cast for bulk operations
     * where Eloquent's automatic casting doesn't apply.
     *
     * @param string $key Attribute name
     * @param mixed $value Value to cast
     *
     * @return mixed Properly formatted value for database storage
     */
    public static function prepareAttributeForDatabase(string $key, mixed $value): mixed
    {
        // Defensive: Ensure arrays
        if (in_array($key, ['tags', 'keywords', 'search_text']) && ! is_array($value)) {
            $value = is_string($value) ? [$value] : [];
        }

        // Use a temporary instance to use the model's casting logic
        $instance = new self;
        $instance->setAttribute($key, $value);

        // Get the value as it would be stored (with casts applied)
        return $instance->getAttributes()[$key];
    }

    /**
     * Find an icon by package name and icon name
     */
    public static function findByName(string $package, string $name, ?string $variant = null): ?self
    {
        $query = self::where('package', $package)->where('name', $name);

        if ($variant !== null && $variant !== '') {
            $query->variant($variant);
        }

        return $query->first();
    }

    /**
     * Get package counts (cached)
     *
     * @return array<array-key, mixed>
     */
    public static function getPackageCounts(): array
    {
        $counts = app(IconCacheService::class)->remember(
            'icons.counts.packages',
            fn () => self::query()
                ->selectRaw('package, COUNT(*) as count')
                ->groupBy('package')
                ->pluck('count', 'package')
                ->toArray(),
            ttl: 60 * 60 * 24, // 24 hours, in seconds
            expects: 'array',
        );

        return is_array($counts) ? $counts : [];
    }

    /**
     * A stored file path as the registry keys it: relative to the pack's `files/`
     * root, forward slashes, no `.svg`. `files/outline/home.svg` → `outline/home`.
     */
    public static function registryKey(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $files = strpos('/' . $path, '/files/');

        if ($files !== false) {
            $path = substr('/' . $path, $files + strlen('/files/'));
        }

        return (string) preg_replace('/\.svg$/i', '', ltrim($path, '/'));
    }

    /***********************************************************************
     * Relationships
     **********************************************************************/

    /** @return MorphToMany<IconTerm, $this> */
    public function terms(): MorphToMany
    {
        return $this->morphToMany(
            IconTerm::class,
            'termable',
            'ichava_icon_termables',
            'termable_id',
            'term_id',
        )->withTimestamps();
    }

    /** @return MorphToMany<IconTerm, $this> */
    public function categories(?string $package = null): MorphToMany
    {
        $query = $this->terms()->where('type', IconTerm::TYPE_CATEGORY);

        return $package ? $query->where('package', $package) : $query;
    }

    /** @return MorphToMany<IconTerm, $this> */
    public function variants(?string $package = null): MorphToMany
    {
        $query = $this->terms()->where('type', IconTerm::TYPE_VARIANT);

        return $package ? $query->where('package', $package) : $query;
    }

    /**
     * Full-text search (multilingual; includes categories and variants).
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeSearch(Builder $query, string $search, ?string $language = null): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        if (self::driverOf($query) === 'pgsql') {
            // A chosen language replaces the configured set, after the guard
            // has reduced it to a known configuration the database has.
            $languages = $language !== null && $language !== ''
                ? [app(FtsLanguageHelper::class)->guardLanguage($language)]
                : FtsLanguageHelper::getLanguages();
            $whereClause = FtsLanguageHelper::buildComprehensiveSearchQuery(self::MORPH_ALIAS, self::TABLE, $languages);

            $bindings = array_fill(0, count($languages), $search);

            return $query->whereRaw($whereClause, $bindings);

        }

        return $this->scopeFuzzySearch($query, $search);
    }

    /**
     * Fuzzy search (fallback for non-PostgreSQL)
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeFuzzySearch(Builder $query, string $search): Builder
    {
        /*
         * This is the path every non-PostgreSQL driver takes -- `scopeSearch` delegates
         * here whenever the driver is not pgsql. It has been broken twice by the same
         * two portability traps, both now owned by laranail/db-tools' PortableQuery
         * and pinned there against PostgreSQL, MySQL, MariaDB and SQLite:
         *
         * - the LIKE escape character is `!`, because a backslash is itself an escape
         *   inside a MySQL string literal and SQLite has no default one;
         * - these columns are `json`, not `jsonb` (`$table->json()` emits `json` on
         *   PostgreSQL, with no implicit cast), so array elements are matched with
         *   `json_array_elements_text(col::json)`. The other drivers match a LIKE over
         *   the encoded JSON -- looser, but no query per element.
         */
        return $query->where(function (Builder $q) use ($search): void {
            $q->whereLiteralLike('name', $search);

            if (FtsLanguageHelper::isScopeEnabled('keywords')) {
                $q->orWhereJsonArrayLiteralLike('keywords', $search);
            }

            if (FtsLanguageHelper::isScopeEnabled('tags')) {
                $q->orWhereJsonArrayLiteralLike('tags', $search);
            }

            if (FtsLanguageHelper::isScopeEnabled('categories') || FtsLanguageHelper::isScopeEnabled('variants')) {
                $q->orWhereHas('terms', fn (Builder $termQuery) => $termQuery->whereLiteralLike('name', $search));
            }

            if (FtsLanguageHelper::isScopeEnabled('package_name')) {
                $q->orWhereLiteralLike('package', $search);
            }
        });
    }

    /**
     * Filter by package
     *
     * @param Builder<self> $query
     * @param string|array<array-key, string> $package
     *
     * @return Builder<self>
     */
    public function scopePackage(Builder $query, string|array $package): Builder
    {
        return is_array($package)
            ? $query->whereIn('package', $package)
            : $query->where('package', $package);
    }

    /**
     * Filter by kind: icons or illustrations.
     *
     * `Icon::query()->ofKind(IconKind::Illustration)` or `->ofKind('illustration')`.
     * A string that names no kind matches nothing rather than everything, so a
     * typo cannot widen a query.
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeOfKind(Builder $query, IconKind|string $kind): Builder
    {
        $resolved = $kind instanceof IconKind ? $kind : IconKind::fromConfig($kind);

        return $resolved === null
            ? $query->whereRaw('1 = 0')
            : $query->where('kind', $resolved->value);
    }

    /**
     * Filter by category term
     *
     * @param Builder<self> $query
     * @param string|array<array-key, string> $category
     *
     * @return Builder<self>
     */
    public function scopeCategory(Builder $query, string|array $category): Builder
    {
        return $query->whereHas('terms', function (Builder $termQuery) use ($category): void {
            $termQuery->where('type', IconTerm::TYPE_CATEGORY);

            if (is_array($category)) {
                $termQuery->whereIn('slug', $category);
            } else {
                $termQuery->where('slug', $category);
            }
        });
    }

    /**
     * Filter by variant term
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeVariant(Builder $query, string $variant): Builder
    {
        return $query->whereHas(
            'terms',
            fn (Builder $termQuery) => $termQuery->where('type', IconTerm::TYPE_VARIANT)
                ->where('slug', $variant),
        );
    }

    /**
     * Filter by JSON array contains (tags or keywords)
     * Works with both PostgreSQL (json_array_elements) and MySQL (JSON_CONTAINS)
     *
     * @param Builder<self> $query
     * @param string|array<array-key, string> $tags
     *
     * @return Builder<self>
     */
    public function scopeWhereHasTag(Builder $query, string|array $tags): Builder
    {
        $tags = (array) $tags;

        if (self::driverOf($query) === 'pgsql') {
            return $query->where(function (Builder $q) use ($tags): void {
                foreach ($tags as $tag) {
                    $q->orWhereRaw('EXISTS (SELECT 1 FROM json_array_elements_text(tags::json) AS tag WHERE tag = ?)', [$tag]);
                }
            });
        }

        // MySQL fallback
        return $query->where(function (Builder $q) use ($tags): void {
            foreach ($tags as $tag) {
                $q->orWhereJsonContains('tags', $tag);
            }
        });
    }

    /**
     * Filter by JSON array contains (keywords)
     *
     * @param Builder<self> $query
     * @param string|array<array-key, string> $keywords
     *
     * @return Builder<self>
     */
    public function scopeWhereHasKeyword(Builder $query, string|array $keywords): Builder
    {
        $keywords = (array) $keywords;

        if (self::driverOf($query) === 'pgsql') {
            return $query->where(function (Builder $q) use ($keywords): void {
                foreach ($keywords as $keyword) {
                    $q->orWhereRaw('EXISTS (SELECT 1 FROM json_array_elements_text(keywords::json) AS kw WHERE kw = ?)', [$keyword]);
                }
            });
        }

        // MySQL fallback
        return $query->where(function (Builder $q) use ($keywords): void {
            foreach ($keywords as $keyword) {
                $q->orWhereJsonContains('keywords', $keyword);
            }
        });
    }

    /**
     * Order by most recent file modification
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeRecentlyModified(Builder $query): Builder
    {
        return $query->orderByDesc('file_modified_at');
    }

    /**
     * Filter by file hash (for duplicate detection)
     *
     * @param Builder<self> $query
     *
     * @return Builder<self>
     */
    public function scopeByHash(Builder $query, string $hash): Builder
    {
        return $query->where('file_hash', $hash);
    }

    /**
     * Check if icon has a specific category
     */
    public function hasCategory(string $slug): bool
    {
        return in_array($slug, $this->category_slugs, true);
    }

    /**
     * Check if icon has a specific variant
     */
    public function hasVariant(string $slug): bool
    {
        return in_array($slug, $this->variant_slugs, true);
    }

    /**
     * Get metadata value with dot notation support
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return data_get($this->metadata ?? [], $key, $default);
    }

    /**
     * Set metadata value with dot notation support
     */
    public function setMetadata(string $key, mixed $value): void
    {
        $metadata = $this->metadata ?? [];
        data_set($metadata, $key, $value);
        $this->metadata = is_array($metadata) ? $metadata : [];
    }

    /**
     * Add tag to JSON array (if not exists)
     */
    public function addTag(string $tag): void
    {
        $tags = $this->tags ?? [];
        if (! in_array($tag, $tags, true)) {
            $tags[] = $tag;
            $this->tags = $tags;
        }
    }

    /**
     * Remove tag from JSON array
     */
    public function removeTag(string $tag): void
    {
        $tags = $this->tags ?? [];
        $this->tags = array_values(array_filter($tags, fn ($t) => $t !== $tag));
    }

    /**
     * Add keyword to JSON array (if not exists)
     */
    public function addKeyword(string $keyword): void
    {
        $keywords = $this->keywords ?? [];
        if (! in_array($keyword, $keywords, true)) {
            $keywords[] = $keyword;
            $this->keywords = $keywords;
        }
    }

    /**
     * Remove keyword from JSON array
     */
    public function removeKeyword(string $keyword): void
    {
        $keywords = $this->keywords ?? [];
        $this->keywords = array_values(array_filter($keywords, fn ($kw) => $kw !== $keyword));
    }

    /**
     * Check if icon has a specific tag
     */
    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags ?? [], true);
    }

    /**
     * Check if icon has a specific keyword
     */
    public function hasKeyword(string $keyword): bool
    {
        return in_array($keyword, $this->keywords ?? [], true);
    }

    /**
     * Get SVG attribute value from JSON with dot notation support
     */
    public function getSvgAttribute(string $key, mixed $default = null): mixed
    {
        return data_get($this->svgAttributes(), $key, $default);
    }

    /**
     * Set SVG attribute value in JSON with dot notation support
     */
    public function setSvgAttribute(string $key, mixed $value): void
    {
        $attrs = $this->svgAttributes();
        data_set($attrs, $key, $value);
        $this->setAttribute('attributes', $attrs);
    }

    /***********************************************************************
     * Helper Methods
     **********************************************************************/

    /**
     * Refresh PostgreSQL search text
     */
    public function refreshSearchText(): void
    {
        if (! Helpers::dbDriverIsPgSql()) {
            return;
        }

        DB::statement('SELECT refresh_ichava_icon_search_text(?)', [$this->id]);
        $this->refresh();
    }

    /**
     * Attach a category term
     */
    public function attachCategory(string $categorySlug, ?string $package = null): void
    {
        $package = $package ?? $this->package;

        $term = IconTerm::where('type', IconTerm::TYPE_CATEGORY)
            ->where('slug', $categorySlug)
            ->where('package', $package)
            ->first();

        if ($term) {
            $this->terms()->syncWithoutDetaching([$term->id]);
        }
    }

    /**
     * Attach a variant term
     */
    public function attachVariant(string $variantSlug, ?string $package = null): void
    {
        $package = $package ?? $this->package;

        $term = IconTerm::where('type', IconTerm::TYPE_VARIANT)
            ->where('slug', $variantSlug)
            ->where('package', $package)
            ->first();

        if ($term) {
            $this->terms()->syncWithoutDetaching([$term->id]);
        }
    }

    /**
     * Sync categories from filesystem path
     */
    public function syncCategoriesFromPath(): void
    {
        $relativePath = str_replace($this->getBasePath(), '', dirname($this->path));
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $relativePath));

        if (empty($parts)) {
            return;
        }

        $parts = array_values(array_filter($parts, fn ($p) => $p !== 'files'));

        $currentParentId = null;
        foreach ($parts as $categorySlug) {
            $term = IconTerm::where('type', IconTerm::TYPE_CATEGORY)
                ->where('slug', $categorySlug)
                ->where('package', $this->package)
                ->where('parent_id', $currentParentId)
                ->first();

            if ($term) {
                $this->terms()->syncWithoutDetaching([$term->id]);
                $currentParentId = $term->id;
            }
        }
    }

    /**
     * The themes this icon has a twin for, e.g. `['dark']`.
     *
     * A theme twin (`scene-dark.svg` beside `scene.svg`, declared by the
     * pack's category) is a variant of this icon, not an item of its own.
     *
     * @return list<string>
     */
    public function themes(): array
    {
        return array_keys($this->themeVariantMap());
    }

    public function hasTheme(string $theme): bool
    {
        return isset($this->themeVariantMap()[$theme]);
    }

    /**
     * The served SVG of one theme twin, sanitised exactly as the icon is, or
     * null when this icon has no twin for that theme or its file is missing.
     */
    public function themeSvgContent(string $theme): ?string
    {
        $twin = $this->themeVariantMap()[$theme] ?? null;

        if ($twin === null) {
            return null;
        }

        $path = $this->containedPath($twin['path']);

        if ($path === null || ! File::exists($path)) {
            return null;
        }

        $version = mb_substr(hash(
            'sha256',
            $twin['file_hash'] . '|' . app(SvgProcessingService::class)->renderFingerprint(),
        ), 0, 16);

        return $this->processedSvg($path, $twin['path'], 'svg:' . $this->id . ':' . $theme . ':' . $version);
    }

    /***********************************************************************
     * Model Events
     **********************************************************************/

    protected static function booted(): void
    {
        self::created(function (self $icon): void {
            // Auto-attach categories from path on creation
            if (config('ichava.ichava-core.database.auto_sync', true)) {
                $icon->syncCategoriesFromPath();
            }
        });

        self::updated(function (self $icon): void {
            // Refresh search text if name or path changed
            if ($icon->isDirty(['name', 'path'])) {
                $icon->refreshSearchText();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags'             => 'array',
            'keywords'         => 'array',
            'search_text'      => 'array',
            'attributes'       => 'array',
            'metadata'         => 'array',
            'kind'             => IconKind::class,
            'theme_variants'   => 'array',
            'file_modified_at' => 'datetime',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
        ];
    }

    /**
     * Get SVG viewBox attribute from JSON
     *
     * @return Attribute<string|null, string|null>
     */
    protected function viewbox(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->svgAttributeString('viewbox'),
            set: fn (?string $value): array => ['attributes' => $this->withSvgAttribute('viewbox', $value)],
        );
    }

    /**
     * Get SVG width attribute from JSON
     *
     * @return Attribute<string|null, string|null>
     */
    protected function width(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->svgAttributeString('width'),
            set: fn (?string $value): array => ['attributes' => $this->withSvgAttribute('width', $value)],
        );
    }

    /**
     * Get SVG height attribute from JSON
     *
     * @return Attribute<string|null, string|null>
     */
    protected function height(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->svgAttributeString('height'),
            set: fn (?string $value): array => ['attributes' => $this->withSvgAttribute('height', $value)],
        );
    }

    /**
     * Get absolute path (builds from relative path + package base_path)
     *
     * Handles two distinct icon package structures:
     *
     * 1. CORE ICHAVA (Multi-set packages):
     *    - Structure: vendor/ichava/resources/assets/svg/{set-name}/files/{category}/icon.svg
     *    - base_path: /path/to/vendor/ichava/resources/assets/svg
     *    - Stored path: {set-name}/files/{category}/icon.svg
     *    - Example: test-icons/files/test-icons/check.svg
     *
     * 2. STANDARD PACKAGES (Single-set packages):
     *    - Structure: vendor/package-name/resources/assets/svg/files/{category}/icon.svg
     *    - base_path: /path/to/vendor/package-name/resources/assets/svg
     *    - Stored path: files/{category}/icon.svg
     *    - Example: files/iconpark/stretching-o.svg
     *
     * @return Attribute<string, never>
     */
    protected function absolutePath(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                // Backward compatibility: If path is already absolute, return as-is
                if ($this->isAbsolutePath($this->path)) {
                    return $this->path;
                }

                // Build absolute path: base_path + relative_path
                // Works for both core (with set-name/) and standard (with files/) packages
                $packageBasePath = $this->getPackageBasePath();
                $absolutePath = $packageBasePath . DIRECTORY_SEPARATOR . ltrim($this->path, '/\\');

                // Normalize directory separators for cross-platform compatibility
                return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolutePath);
            },
        );
    }

    /**
     * Resolve the stored path against the package base directory, contained.
     *
     * Returns null when the resolved file would escape the package base
     * directory through traversal, a symlink, or an absolute stored path.
     * Missing files resolve to the candidate itself, preserving the
     * missing-file behaviour of the fileSize and svgContent readers, as do
     * rows whose package has no registered base directory to contain
     * against (legacy absolute stored paths).
     */
    protected function containedAbsolutePath(): ?string
    {
        return $this->containedPath($this->path);
    }

    /**
     * Resolve any stored path of this icon's package against its base
     * directory, contained, as containedAbsolutePath() does for the icon's
     * own path.
     */
    protected function containedPath(string $storedPath): ?string
    {
        $packageBasePath = $this->getPackageBasePath();

        $candidate = $this->isAbsolutePath($storedPath)
            ? $storedPath
            : $packageBasePath . DIRECTORY_SEPARATOR . ltrim($storedPath, '/\\');

        $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);

        // No registered base directory: nothing to contain against, keep the
        // legacy behaviour (absolute stored paths). Note realpath('') resolves
        // to the working directory rather than failing, so the empty base
        // must be excluded explicitly.
        $realBase = $packageBasePath !== '' ? realpath($packageBasePath) : false;

        if ($realBase === false) {
            return $candidate;
        }

        $realCandidate = realpath($candidate);

        if ($realCandidate === false) {
            return $candidate;
        }

        if ($realCandidate === $realBase
            || ! str_starts_with($realCandidate, rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realCandidate;
    }

    /**
     * Get file size (computed from filesystem)
     *
     * @return Attribute<int|null, never>
     */
    protected function fileSize(): Attribute
    {
        return Attribute::make(
            get: function (): ?int {
                $path = $this->containedAbsolutePath();

                return $path !== null && File::exists($path) ? File::size($path) : null;
            },
        );
    }

    /**
     * A token identifying the exact bytes this icon renders to.
     *
     * Two inputs, and both are required. The file's own hash covers an upstream
     * asset refresh; `SvgProcessingService::renderFingerprint()` covers the
     * policy and pipeline that turn that file into what is actually served. A
     * URL carrying this token can be cached `immutable` honestly: change either
     * half and the token changes, so the old URL is simply never requested
     * again rather than being served stale.
     *
     * Falls back to hashing the path when `file_hash` is null, which keeps the
     * token stable and non-null for rows seeded before hashing existed. Those
     * rows do not self-invalidate on a content change -- the fallback is a
     * placeholder, not a content hash -- so seeding should populate
     * `file_hash`.
     *
     * @return Attribute<string, never>
     */
    protected function renderVersion(): Attribute
    {
        return Attribute::make(
            get: fn (): string => mb_substr(hash(
                'sha256',
                ($this->file_hash ?? md5((string) $this->path))
                    . '|'
                    . app(SvgProcessingService::class)->renderFingerprint(),
            ), 0, 16),
        );
    }

    /**
     * Get SVG content (cached)
     *
     * @return Attribute<string|null, never>
     */
    protected function svgContent(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $path = $this->containedAbsolutePath();

                if ($path === null || ! File::exists($path)) {
                    return null;
                }

                /*
                 * Keyed on the render version, not the file hash alone.
                 *
                 * The cached value is the *processed* SVG -- ids namespaced,
                 * sizing normalised, policy applied -- so a file hash does not
                 * identify it. Widening the allow-list changes every icon while
                 * leaving every file hash untouched, and the old key would have
                 * gone on serving bytes produced by a policy that no longer
                 * existed, making the widening look like it had not worked.
                 */
                return $this->processedSvg($path, $this->path, 'svg:' . $this->id . ':' . $this->render_version);
            },
        );
    }

    /**
     * The path `ichava()`, `<x-ichava::icon>` and the registry resolve:
     * `vendor/package::<file path inside the pack, without .svg>`.
     *
     * It is built from the stored file path, because that is what the registry
     * keys icons by. It used to be assembled from the primary category and
     * variant terms as `package::category/name:variant`, which no resolver
     * accepts: the registry refuses a `:` in an icon name, so every icon with a
     * variant (all of tabler) produced a path that threw, and a pack nested
     * deeper than one folder (bundled's `iconpark/...`) lost the folders between
     * its category and its name. The terms are kept as the fallback for a row
     * with no stored path, without the variant suffix.
     */
    /** @return Attribute<string, never> */
    protected function iconPath(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                if (is_string($this->path) && $this->path !== '') {
                    return $this->package . '::' . self::registryKey($this->path);
                }

                $category = $this->primary_category?->slug;

                return $this->package . '::' . ($category ? $category . '/' : '') . $this->name;
            },
        );
    }

    /**
     * Get primary category
     *
     * @return Attribute<IconTerm|null, never>
     */
    protected function primaryCategory(): Attribute
    {
        return Attribute::make(
            get: function (): ?IconTerm {
                $term = $this->categories()->whereNull('parent_id')->first() ?? $this->categories()->first();

                return $term instanceof IconTerm ? $term : null;
            },
        );
    }

    /**
     * Get the subcategory: the nested category (a category term with a
     * parent) the icon sits in, below its top-level one. Null when the icon
     * is filed only at the top level.
     *
     * Reads the loaded `terms` when they carry `parent_id` (the grid page
     * loads them so), and queries otherwise.
     *
     * @return Attribute<IconTerm|null, never>
     */
    protected function primarySubcategory(): Attribute
    {
        return Attribute::make(
            get: function (): ?IconTerm {
                $terms = $this->relationLoaded('terms') ? $this->getRelation('terms') : null;

                if ($terms instanceof Collection
                    && $terms->every(fn (mixed $t): bool => $t instanceof IconTerm && array_key_exists('parent_id', $t->getAttributes()))) {
                    $term = $terms->first(fn (IconTerm $t): bool => $t->type === IconTerm::TYPE_CATEGORY && $t->parent_id !== null);

                    return $term instanceof IconTerm ? $term : null;
                }

                $term = $this->categories()->whereNotNull('parent_id')->first();

                return $term instanceof IconTerm ? $term : null;
            },
        );
    }

    /**
     * Get primary variant
     *
     * @return Attribute<IconTerm|null, never>
     */
    protected function primaryVariant(): Attribute
    {
        return Attribute::make(
            get: function (): ?IconTerm {
                $term = $this->variants()->first();

                return $term instanceof IconTerm ? $term : null;
            },
        );
    }

    /**
     * Get all category slugs
     *
     * @return Attribute<array<int, mixed>, never>
     */
    protected function categorySlugs(): Attribute
    {
        return Attribute::make(
            get: fn (): array => $this->categories()->pluck('slug')->toArray(),
        );
    }

    /**
     * Get all variant slugs
     *
     * @return Attribute<array<int, mixed>, never>
     */
    protected function variantSlugs(): Attribute
    {
        return Attribute::make(
            get: fn (): array => $this->variants()->pluck('slug')->toArray(),
        );
    }

    /**
     * Get base path for package
     */
    protected function getBasePath(): string
    {
        return $this->getPackageBasePath();
    }

    /**
     * Get package base path from IconRegistry
     */
    protected function getPackageBasePath(): string
    {
        $packageRegistry = app(IconRegistry::class);
        $packages = $packageRegistry->all();

        $packageData = $packages[$this->package] ?? [];

        $path = $packageData['base_path'] ?? $packageData['path'] ?? '';

        return is_string($path) ? $path : '';
    }

    /**
     * Check if path is absolute
     */
    protected function isAbsolutePath(string $path): bool
    {
        // Unix absolute path starts with /
        if (Str::startsWith($path, '/')) {
            return true;
        }

        // Windows absolute path (C:\ or similar)
        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $path)) {
            return true;
        }

        return false;
    }

    /** The driver behind a query: the builder's connection, typed as the concrete class. */
    /** @param Builder<self> $query */
    private static function driverOf(Builder $query): string
    {
        /** @var \Illuminate\Database\Connection $connection */
        $connection = $query->getConnection();

        return $connection->getDriverName();
    }

    /**
     * The theme twins with a usable path, keyed by theme.
     *
     * @return array<string, array{path: string, file_hash: string}>
     */
    private function themeVariantMap(): array
    {
        $variants = $this->theme_variants;

        if (! is_array($variants)) {
            return [];
        }

        $map = [];

        foreach ($variants as $theme => $twin) {
            if (is_string($theme) && is_array($twin) && is_string($twin['path'] ?? null) && $twin['path'] !== '') {
                $map[$theme] = ['path' => $twin['path'], 'file_hash' => is_string($twin['file_hash'] ?? null) ? $twin['file_hash'] : ''];
            }
        }

        return $map;
    }

    /**
     * The served form of one SVG file of this icon, cached under `$cacheKey`.
     *
     * Every file this icon serves goes through here, so a theme twin is
     * namespaced, normalised and sanitised exactly as the icon itself is.
     */
    private function processedSvg(string $path, string $storedPath, string $cacheKey): ?string
    {
        /*
         * Sanitise here, at the single point every consumer reads through.
         *
         * This used to be a bare `File::get()`. The SVG endpoint's own comment
         * claimed the content "has been sanitised by SvgProcessingService" and
         * added nosniff plus a restrictive CSP as defence in depth -- but nothing
         * had sanitised it, so those headers were the only defence, and they only
         * apply to that one route.
         *
         * The path that mattered more is JSON: `IconBrowserService` puts
         * `svg_content` straight into an API payload, where no response header
         * helps, and the client injects it into the DOM. A pack shipping a hostile
         * file reached the browser verbatim -- and packs do contain
         * `foreignObject`, `script` and `image` elements today.
         *
         * The result is cached post-sanitisation, so this costs one pass per icon
         * per cache lifetime rather than one per request.
         */
        $content = app(IconCacheService::class)->remember(
            $cacheKey,
            function () use ($path, $storedPath): string {
                $raw = File::get($path);

                try {
                    $svg = app(SvgProcessingService::class);

                    /*
                     * Namespace ids before sanitising, and inside the cache,
                     * so it costs one pass per icon per cache lifetime.
                     *
                     * SVG ids are page-scoped in practice. 2,157 files in the
                     * corpus share `_Transparent_Rectangle_` and 261 share
                     * `Layer_1`; render two of them together and the second
                     * icon's `url(#Layer_1)` resolves to the first one's
                     * definition, silently. The browser renders 60+ at once.
                     * The prefix is derived from the path, not the content, so
                     * it survives an upstream refresh and stays deterministic
                     * for the content-addressed cache (CR1).
                     */
                    $raw = $svg->namespaceIds($raw, $storedPath);

                    /*
                     * Hand sizing back to the component. An icon shipping
                     * both a viewBox and hard width/height ignores the size
                     * it is asked for; 6,146 tabler icons alone ship that
                     * pair. An icon with neither is
                     * reported rather than dropped -- it still renders, it
                     * just has no intrinsic size to scale against.
                     */
                    $raw = $svg->normaliseSizing($raw, function (string $reason) use ($storedPath): void {
                        app(AuditLogger::class)->warning('svg.unusable_sizing', [
                            'path'    => $storedPath,
                            'package' => $this->package,
                            'reason'  => $reason,
                        ]);
                    });

                    /*
                     * Opt-in (B7): a monochrome icon that hardcodes black
                     * vanishes on a dark background. Off by default; the flag
                     * is part of render_version, so turning it on re-keys this
                     * cache entry by itself.
                     */
                    if ($svg->convertsMonochromeToCurrentColor()) {
                        $raw = $svg->monochromeToCurrentColor($raw);
                    }

                    return $svg->process($raw, [], false);
                } catch (Throwable $e) {
                    /*
                     * A file the sanitiser rejects is not served raw as a
                     * fallback. Failing closed is the point: the rejection means
                     * it could not be made safe.
                     */
                    app(AuditLogger::class)->warning('svg.sanitiser_rejected', [
                        'path'    => $storedPath,
                        'package' => $this->package,
                        'reason'  => $e->getMessage(),
                    ]);

                    return '';
                }
            },
            expects: 'string',
        );

        return is_string($content) ? $content : null;
    }

    /**
     * The decoded `attributes` JSON column.
     *
     * Not `$this->attributes`: on an Eloquent model that property is the row's raw columns, so
     * reading `viewbox` from it always gave null and writing merged every column into the JSON.
     *
     * @return array<array-key, mixed>
     */
    private function svgAttributes(): array
    {
        $value = $this->getAttribute('attributes');

        return is_array($value) ? $value : [];
    }

    /** One `attributes` JSON value as a string, or null when absent or not a scalar. */
    private function svgAttributeString(string $key): ?string
    {
        $value = $this->getSvgAttribute($key);

        return is_scalar($value) ? (string) $value : null;
    }

    /** The `attributes` column with one key replaced, encoded for a mutator's raw write. */
    private function withSvgAttribute(string $key, ?string $value): string
    {
        return (string) json_encode([...$this->svgAttributes(), $key => $value]);
    }
}
