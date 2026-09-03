<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Ichava\Models;

use Carbon\Carbon;
use RuntimeException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Simtabi\Laranail\Ichava\Services\IchavaLogger;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * IconTerm Model - Icon Categories and Variants Taxonomy
 *
 * Manages hierarchical taxonomy for icon categorization and variant classification.
 * Supports unlimited nesting depth through recursive CTE queries.
 *
 * Relationships:
 * - Hierarchical: parent-child term relationships
 * - Polymorphic: many-to-many with Icon model
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $type (category|variant)
 * @property string|null $package
 * @property int|null $parent_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read IconTerm|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<IconTerm> $children
 * @property-read \Illuminate\Database\Eloquent\Collection<Icon> $icons
 * @property-read int $icons_count Cached icon count
 * @property-read int $depth Term depth in hierarchy
 */
class IconTerm extends Model
{
    public const TYPE_CATEGORY = 'category';

    public const TYPE_VARIANT = 'variant';

    protected $table = 'ichava_icon_terms';

    /** @var array<int, string> Explicit allow-list (no mass-assignment of id / timestamps) */
    protected $fillable = [
        'type',
        'package',
        'name',
        'slug',
        'parent_id',
        'description',
    ];

    /**
     * Recursive descendant loader, supports unlimited depth.
     */
    public static function descendantsOf(int $termId): Collection
    {
        $rows = DB::select('
            WITH RECURSIVE term_tree AS (
                SELECT *
                FROM ichava_icon_terms
                WHERE id = ?

                UNION ALL

                SELECT t.*
                FROM ichava_icon_terms t
                INNER JOIN term_tree tt ON t.parent_id = tt.id
            )
            SELECT * FROM term_tree;
        ', [$termId]);

        return static::hydrate($rows);
    }

    /**
     * Get the parent term (hierarchical relationship)
     *
     * @return BelongsTo<IconTerm, IconTerm>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Get child terms (hierarchical relationship)
     *
     * @return HasMany<IconTerm>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Get all icons associated with this term
     *
     * @return MorphToMany<Icon>
     */
    public function icons(): MorphToMany
    {
        return $this->morphedByMany(
            Icon::class,
            'termable',
            'ichava_icon_termables',
            'term_id',
            'termable_id',
        )->withTimestamps();
    }

    /**
     * Filter terms by category type
     *
     * @param Builder<IconTerm> $query
     *
     * @return Builder<IconTerm>
     */
    public function scopeCategories(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_CATEGORY);
    }

    /**
     * Filter terms by variant type
     *
     * @param Builder<IconTerm> $query
     *
     * @return Builder<IconTerm>
     */
    public function scopeVariants(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_VARIANT);
    }

    /**
     * Filter terms by package name
     *
     * @param Builder<IconTerm> $query
     * @param string $package Package identifier
     *
     * @return Builder<IconTerm>
     */
    public function scopeInPackage(Builder $query, string $package): Builder
    {
        return $query->where('package', $package);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Order by name alphabetically
     *
     * @param Builder<IconTerm> $query
     *
     * @return Builder<IconTerm>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /**
     * Filter terms with icon counts
     *
     * @param Builder<IconTerm> $query
     *
     * @return Builder<IconTerm>
     */
    public function scopeWithIconCount(Builder $query): Builder
    {
        return $query->withCount('icons');
    }

    /**
     * Filter terms that have icons
     *
     * @param Builder<IconTerm> $query
     *
     * @return Builder<IconTerm>
     */
    public function scopeHasIcons(Builder $query): Builder
    {
        return $query->has('icons');
    }

    /**
     * Search terms by name or slug
     *
     * @param Builder<IconTerm> $query
     *
     * @return Builder<IconTerm>
     */
    public function scopeSearch(Builder $query, string $search): Builder
    {
        $like = '%' . $search . '%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('name', 'LIKE', $like)
                ->orWhere('slug', 'LIKE', $like);
        });
    }

    public function ancestors(): Collection
    {
        $accumulated = collect();
        $current = $this->parent;

        while ($current) {
            $accumulated->push($current);
            $current = $current->parent;
        }

        return $accumulated;
    }

    public function descendants(): Collection
    {
        return static::descendantsOf($this->id);
    }

    /**
     * Get the depth level of this term in the hierarchy
     */
    public function getDepth(): int
    {
        return $this->ancestors()->count();
    }

    /**
     * Get the full path of this term (e.g., "parent/child/current")
     */
    public function getPath(string $separator = '/'): string
    {
        $ancestors = $this->ancestors()->reverse();
        $path = $ancestors->pluck('slug')->push($this->slug);

        return $path->implode($separator);
    }

    /**
     * Check if this term is a root term (no parent)
     */
    public function isRoot(): bool
    {
        return is_null($this->parent_id);
    }

    /**
     * Check if this term has children
     */
    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    /**
     * Check if this term is a descendant of another term
     */
    public function isDescendantOf(int|self $term): bool
    {
        $termId = $term instanceof self ? $term->id : $term;

        return $this->ancestors()->contains('id', $termId);
    }

    /**
     * Check if this term is an ancestor of another term
     */
    public function isAncestorOf(int|self $term): bool
    {
        $termInstance = $term instanceof self ? $term : self::find($term);

        return $termInstance ? $termInstance->isDescendantOf($this) : false;
    }

    /**
     * Get siblings (terms with same parent)
     */
    public function siblings(): Collection
    {
        return self::where('parent_id', $this->parent_id)
            ->where('id', '!=', $this->id)
            ->get();
    }

    /**
     * Get icon count for this term
     */
    public function getIconCount(): int
    {
        return $this->icons()->count();
    }

    /**
     * Get icon count including descendants
     */
    public function getTotalIconCount(): int
    {
        $termIds = $this->descendants()->pluck('id')->push($this->id);

        return DB::table('ichava_icon_termables')
            ->whereIn('term_id', $termIds)
            ->where('termable_type', Icon::class)
            ->distinct('termable_id')
            ->count('termable_id');
    }

    protected static function booted(): void
    {
        // Prevent circular parent relationships
        self::saving(function (self $term): void {
            // Skip check if both are null (new term with no parent)
            if ($term->parent_id !== null && $term->id !== null && $term->parent_id === $term->id) {
                app(IchavaLogger::class)->error('Circular reference detected', null, [
                    'term_id'   => $term->id,
                    'parent_id' => $term->parent_id,
                    'slug'      => $term->slug,
                    'name'      => $term->name,
                    'package'   => $term->package,
                ]);
                throw new RuntimeException("A term cannot be its own parent (ID: {$term->id}, Slug: {$term->slug})");
            }

            // Prevent creating circular references
            if ($term->parent_id && $term->exists) {
                $parent = self::find($term->parent_id);
                if ($parent && $parent->isDescendantOf($term)) {
                    throw new RuntimeException('Cannot create circular parent-child relationship');
                }
            }
        });

        // Cascade update search text when term name changes
        self::updated(function (self $term): void {
            if ($term->isDirty('name')) {
                // Trigger FTS refresh for all icons with this term
                DB::statement(
                    'SELECT refresh_ichava_icons_search_text_for_term(?)',
                    [$term->id],
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'parent_id'  => 'integer',
        ];
    }
}
