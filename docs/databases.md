# Database support

*Reference.*

Ichava runs on **SQLite, PostgreSQL, MySQL and MariaDB**, and every release is tested against all
four. Pick whichever your application already uses; only full-text search differs between them.

## Supported drivers

| Driver | Tested against | Search | Notes |
|---|---|---|---|
| `sqlite` | file and `:memory:` | `LIKE` | Foreign keys must be enabled (see below). |
| `pgsql` | PostgreSQL 17 | **Full-text**, `tsvector` + GIN | Trigger-maintained `search_text_plain`, BRIN and covering indexes. |
| `mysql` | MySQL 8.4 | `LIKE` | InnoDB, `utf8mb4`. |
| `mariadb` | MariaDB 11.4 | `LIKE` | InnoDB, `utf8mb4`. |

Nothing in the package requires a specific driver. `ichava/core` reads
`config('database.default')` like any other Laravel code and adapts.

## PostgreSQL gets full-text search; the others get `LIKE`

This is the one behavioural difference, and it is deliberate rather than an omission.

On PostgreSQL the migration installs a trigger that maintains a `search_text_plain` column, plus a
GIN index over `to_tsvector(...)` per configured language. `Icon::scopeSearch()` uses it, so search
is stemmed, ranked and indexed.

Every other driver takes `Icon::scopeFuzzySearch()`, a `LIKE` across name, keywords, tags, package
and related term names. It is correct and it is what those drivers can express portably, but it is
not indexed, so it degrades on very large corpora.

> MySQL and MariaDB do have `FULLTEXT` indexes. They are not used here because their default
> minimum token length (`innodb_ft_min_token_size`, 3) silently drops shorter queries, and many
> icon names are shorter than that. A search that quietly returns nothing is worse than one that
> scans. If you need indexed search at MySQL scale, PostgreSQL is the supported answer today.

Search scopes (`keywords`, `tags`, `categories`, `variants`, `package_name`) are configurable and
apply to both paths. See [Configuration](configuration.md).

## The JSON columns are `json`, not `jsonb`

`tags`, `keywords`, `search_text`, `attributes` and `metadata` are all `json` columns, because
Laravel's `$table->json()` emits `json` on PostgreSQL and the portable equivalent elsewhere.

If you write custom SQL against them on PostgreSQL, use the **`json_*`** function family and cast
explicitly:

```sql
-- correct
SELECT json_array_elements_text(keywords::json)

-- errors: no implicit cast exists from json to jsonb, so this resolves to no function
SELECT jsonb_array_elements_text(keywords)
```

This is not a style preference. PostgreSQL registers no implicit `json` → `jsonb` cast, so the
second form fails at parse time with *function ... does not exist*.

## SQLite needs foreign keys turned on

SQLite does not enforce foreign keys unless asked, and Laravel only asks when the key is present in
the connection config. The schema uses cascading deletes, so without this a removed term leaves its
pivot rows behind:

```php
'sqlite' => [
    'driver'   => 'sqlite',
    'database' => database_path('database.sqlite'),
    'foreign_key_constraints' => true,
],
```

## MySQL and MariaDB index limits

The widest index in the schema is `uniq_icon_terms_type_slug_package`, over `type` (120),
`slug` (380) and `package` (260). At `utf8mb4` that is 3040 bytes against InnoDB's 3072-byte limit
for `DYNAMIC` row format — it fits, with little room.

Two consequences:

- **Use `DYNAMIC` row format**, the default on MySQL 8 and MariaDB 10.3+. On the older `COMPACT`
  format the limit is 767 bytes and the migration fails.
- **Do not widen those three columns** without re-checking the arithmetic.

## Running the test suite against a driver

The suite defaults to in-memory SQLite so the inner loop needs no service running:

```bash
vendor/bin/pest
```

Point it at anything else with the standard Laravel environment variables:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=ichava_test DB_USERNAME=ichava DB_PASSWORD=secret \
vendor/bin/pest
```

CI runs the full matrix on every pull request: SQLite on PHP 8.4 and 8.5, then PostgreSQL 17,
MySQL 8.4 and MariaDB 11.4 as service containers. A change that only works on one driver does not
merge.

---

**See also**

- [Installation](installation.md)
- [Configuration](configuration.md)
- [Database seeding](recipes/seed-the-database.md)
- [Troubleshooting](troubleshooting.md)

---

[← Docs index](../README.md#documentation)
