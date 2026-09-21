[← Docs](../../README.md#documentation)

# Seeding pack icons

*How-to guide.*

Seeding builds a database index of every icon in every installed pack. The renderer and the search API both read from this index, so a seeded ecosystem is faster and supports full-text search.

## When to seed

Seed once after install, then again when you add or update a pack. The seeder is idempotent: running it twice is safe (it skips icons whose `file_hash` is unchanged).

## Seed all installed packs

```bash
php artisan ichava::ichava-core.database seed
```

## Seed one pack

```bash
php artisan ichava::ichava-core.database seed --package=ichava/icon-sets-tabler
```

## Force re-seed (ignore change detection)

```bash
php artisan ichava::ichava-core.database seed --update
```

Use this only if you suspect the index has drifted from the source files.

## Run synchronously vs, via queue

By default the seeder dispatches chunks of 1,000 icons onto the `ichava-icons` queue. For small packs or local development you can run inline:

```bash
php artisan ichava::ichava-core.database seed --package=ichava/icon-sets-tabler --sync
```

For 100,000-icon packs (`ichava/icon-sets-bundled`), keep the queue mode and run a worker:

```bash
php artisan queue:work --queue=ichava-icons --memory=512 --timeout=300
```

## Refresh from scratch

```bash
php artisan ichava::ichava-core.database refresh --force
```

This drops Ichava tables and re-runs migrations + seeding. Destructive, only on dev or if you really want a clean slate.

## See also

- [Database seeding deep dive](seed-the-database.md), multi-level dedup, change detection, Horizon
- [Artisan commands reference](../tools/artisan-commands.md)
- [Troubleshooting](../troubleshooting.md)

---

[← Docs index](../../README.md#documentation)
