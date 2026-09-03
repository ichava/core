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
use Illuminate\Database\Eloquent\Collection;
use Simtabi\Laranail\Ichava\Support\Helpers;
use Simtabi\Laranail\Ichava\Support\AuditLogger;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Simtabi\Laranail\Ichava\Services\IconRegistry;
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
 * @property array|null $tags
 * @property array|null $keywords
 * @property array|null $search_text
 * @property array|null $attributes
 * @property array|null $metadata
 * @property Carbon|null $file_modified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string $absolute_path
 * @property-read int|null $file_size
 * @property-read string|null $svg_content
 * @property-read string $render_version
 * @property-read string $icon_path
 * @property-read IconTerm|null $primary_category
 * @property-read IconTerm|null $primary_variant
 * @property-read array $category_slugs
 * @property-read array $variant_slugs
 * @property-read Collection|IconTerm[] $terms
 * @property-read Collection|IconTerm[] $categories
 * @property-read Collection|IconTerm[] $variants
 */
final class Icon extends Model
{
    use HasFactory;

    /** @var string */
    protected $table = 'ichava_icons';

    /** @var array<int, string> */
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

        if ($variant) {
            $query->variant($variant);
        }

        return $query->first();
    }

    /**
     * Get package counts (cached)
     */
    public static function getPackageCounts(): array
    {
        return app(IconCacheService::class)->remember(
            'icons.counts.packages',
            fn () => self::query()
                ->selectRaw('package, COUNT(*) as count')
                ->groupBy('package')
                ->pluck('count', 'package')
                ->toArray(),
            60 * 24, // 24 hours
        );
    }

    /***********************************************************************
     * Relationships
     **********************************************************************/

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

    public function categories(?string $package = null): MorphToMany
    {
        $query = $this->terms()->where('type', IconTerm::TYPE_CATEGORY);

        return $package ? $query->where('package', $package) : $query;
    }

    public function variants(?string $package = null): MorphToMany
    {
        $query = $this->terms()->where('type', IconTerm::TYPE_VARIANT);

        return $package ? $query->where('package', $package) : $query;
    }

    /**
     * Full-text search (multilingual; includes categories and variants).
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        if ($query->getConnection()->getDriverName() === 'pgsql') {
            $languages = FtsLanguageHelper::getLanguages();
            $iconMorphType = addslashes(self::class);

            $whereClause = FtsLanguageHelper::buildComprehensiveSearchQuery($iconMorphType);
            $bindings = array_fill(0, count($languages), $search);

            return $query->whereRaw($whereClause, $bindings);
        }

        return $this->scopeFuzzySearch($query, $search);
    }

    /**
     * Fuzzy search (fallback for non-PostgreSQL)
     */
    public function scopeFuzzySearch(Builder $query, string $search): Builder
    {
        $like = '%' . $search . '%';

        /*
         * This is the path every non-PostgreSQL driver takes -- `scopeSearch` delegates
         * here whenever the driver is not pgsql -- yet it was written entirely in
         * PostgreSQL-only SQL. `jsonb_array_elements_text()` does not exist on SQLite or
         * MySQL, and the `keywords` and `tags` scopes default to enabled, so any search on
         * those drivers failed with "no such table: jsonb_array_elements_text".
         *
         * The bug was invisible to whoever wrote it, because on PostgreSQL this function is
         * never reached.
         *
         * Elsewhere the jsonb form is kept: it matches array ELEMENTS, so searching "nav"
         * cannot match the literal characters of a different key. The portable branch is a
         * LIKE over the encoded JSON, which is looser but is what these drivers can express
         * without a query per element.
         */
        $isPostgres = $query->getConnection()->getDriverName() === 'pgsql';

        return $query->where(function (Builder $q) use ($like, $isPostgres): void {
            $q->where('name', 'LIKE', $like);

            if (FtsLanguageHelper::isScopeEnabled('keywords')) {
                $isPostgres
                    ? $q->orWhereRaw('EXISTS (SELECT 1 FROM jsonb_array_elements_text(keywords) AS kw WHERE kw LIKE ?)', [$like])
                    : $q->orWhere('keywords', 'LIKE', $like);
            }

            if (FtsLanguageHelper::isScopeEnabled('tags')) {
                $isPostgres
                    ? $q->orWhereRaw('EXISTS (SELECT 1 FROM jsonb_array_elements_text(tags) AS tag WHERE tag LIKE ?)', [$like])
                    : $q->orWhere('tags', 'LIKE', $like);
            }

            if (FtsLanguageHelper::isScopeEnabled('categories') || FtsLanguageHelper::isScopeEnabled('variants')) {
                $q->orWhereHas('terms', fn (Builder $termQuery) => $termQuery->where('name', 'LIKE', $like));
            }

            if (FtsLanguageHelper::isScopeEnabled('package_name')) {
                $q->orWhere('package', 'LIKE', $like);
            }
        });
    }

    /**
     * Filter by package
     */
    public function scopePackage(Builder $query, string|array $package): Builder
    {
        return is_array($package)
            ? $query->whereIn('package', $package)
            : $query->where('package', $package);
    }

    /**
     * Filter by category term
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
     */
    public function scopeWhereHasTag(Builder $query, string|array $tags): Builder
    {
        $tags = (array) $tags;

        if ($query->getConnection()->getDriverName() === 'pgsql') {
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
     */
    public function scopeWhereHasKeyword(Builder $query, string|array $keywords): Builder
    {
        $keywords = (array) $keywords;

        if ($query->getConnection()->getDriverName() === 'pgsql') {
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
     */
    public function scopeRecentlyModified(Builder $query): Builder
    {
        return $query->orderByDesc('file_modified_at');
    }

    /**
     * Filter by file hash (for duplicate detection)
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
        $this->metadata = $metadata;
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
        $attrs = $this->attributes ?? [];

        return data_get($attrs, $key, $default);
    }

    /**
     * Set SVG attribute value in JSON with dot notation support
     */
    public function setSvgAttribute(string $key, mixed $value): void
    {
        $attrs = $this->attributes ?? [];
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

    /***********************************************************************
     * Model Events
     **********************************************************************/

    protected static function booted(): void
    {
        self::created(function (self $icon): void {
            // Auto-attach categories from path on creation
            if (config('ichava.core.database.auto_sync', true)) {
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
            'file_modified_at' => 'datetime',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
        ];
    }

    /**
     * Get SVG viewBox attribute from JSON
     */
    protected function viewbox(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => ($this->attributes ?? [])['viewbox'] ?? null,
            set: fn (?string $value): array => [
                'attributes' => array_merge(
                    $this->attributes ?? [],
                    ['viewbox' => $value],
                ),
            ],
        );
    }

    /**
     * Get SVG width attribute from JSON
     */
    protected function width(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => ($this->attributes ?? [])['width'] ?? null,
            set: fn (?string $value): array => [
                'attributes' => array_merge(
                    $this->attributes ?? [],
                    ['width' => $value],
                ),
            ],
        );
    }

    /**
     * Get SVG height attribute from JSON
     */
    protected function height(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => ($this->attributes ?? [])['height'] ?? null,
            set: fn (?string $value): array => [
                'attributes' => array_merge(
                    $this->attributes ?? [],
                    ['height' => $value],
                ),
            ],
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
     * Get file size (computed from filesystem)
     */
    protected function fileSize(): Attribute
    {
        return Attribute::make(
            get: fn (): ?int => File::exists($this->absolute_path)
                ? File::size($this->absolute_path)
                : null,
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
     */
    protected function svgContent(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                if (! File::exists($this->absolute_path)) {
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
                $cacheKey = 'svg:' . $this->id . ':' . $this->render_version;

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
                return app(IconCacheService::class)->remember(
                    $cacheKey,
                    function (): string {
                        $raw = File::get($this->absolute_path);

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
                            $raw = $svg->namespaceIds($raw, $this->path);

                            /*
                             * Hand sizing back to the component. An icon shipping
                             * both a viewBox and hard width/height ignores the size
                             * it is asked for; 6,146 tabler, 11,646 bundled and 239
                             * metronic icons ship that pair. An icon with neither is
                             * reported rather than dropped -- it still renders, it
                             * just has no intrinsic size to scale against.
                             */
                            $raw = $svg->normaliseSizing($raw, function (string $reason): void {
                                app(AuditLogger::class)->warning('svg.unusable_sizing', [
                                    'path'    => $this->path,
                                    'package' => $this->package,
                                    'reason'  => $reason,
                                ]);
                            });

                            return $svg->process($raw, [], false);
                        } catch (Throwable $e) {
                            /*
                             * A file the sanitiser rejects is not served raw as a
                             * fallback. Failing closed is the point: the rejection means
                             * it could not be made safe.
                             */
                            app(AuditLogger::class)->warning('svg.sanitiser_rejected', [
                                'path'    => $this->path,
                                'package' => $this->package,
                                'reason'  => $e->getMessage(),
                            ]);

                            return '';
                        }
                    },
                );
            },
        );
    }

    /**
     * Get icon path for ichava() helper
     * Format: package::category/name:variant
     */
    protected function iconPath(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $path = $this->package . '::';

                if ($category = $this->primary_category) {
                    $path .= $category->slug . '/';
                }

                $path .= $this->name;

                if ($variant = $this->primary_variant) {
                    $path .= ':' . $variant->slug;
                }

                return $path;
            },
        );
    }

    /**
     * Get primary category
     */
    protected function primaryCategory(): Attribute
    {
        return Attribute::make(
            get: fn (): ?IconTerm => $this->categories()
                ->whereNull('parent_id')
                ->first() ?? $this->categories()->first(),
        );
    }

    /**
     * Get primary variant
     */
    protected function primaryVariant(): Attribute
    {
        return Attribute::make(
            get: fn (): ?IconTerm => $this->variants()->first(),
        );
    }

    /**
     * Get all category slugs
     */
    protected function categorySlugs(): Attribute
    {
        return Attribute::make(
            get: fn (): array => $this->categories()->pluck('slug')->toArray(),
        );
    }

    /**
     * Get all variant slugs
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

        return $packageData['base_path'] ?? $packageData['path'] ?? '';
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
}
