# Serve icons from a CDN

*How-to guide.* Skip vendoring a pack's SVGs and serve them from a public CDN instead.

## Why you might

An icon pack ships its SVGs inside the Composer install. For the large packs that is real weight — tens of megabytes of files your application never individually references. Serving them from a CDN trades a dependency on your own deployment for a dependency on someone else's uptime, which is a good trade for a prototype and a deliberate one for production.

The packs make this possible by **publishing their CDN URL templates as data**, so tooling can read them rather than hard-coding a vendor's URL shape.

## Read the templates from the pack, not from a README

Every pack ships `resources/assets/svg/config.json` with an `upstream.cdn` block:

```php
$config = json_decode(
    file_get_contents(base_path('vendor/ichava/icon-sets-tabler/resources/assets/svg/config.json')),
    true,
);

$templates = $config['upstream']['cdn'];
// ['jsdelivr' => 'https://cdn.jsdelivr.net/npm/@tabler/icons@{version}/icons/{variant}/{name}.svg', ...]
```

Substitute the placeholders to get a URL:

```php
$url = str_replace(
    ['{version}', '{variant}', '{name}'],
    [$config['upstream']['current_version'], 'outline', 'home'],
    $templates['jsdelivr'],
);
```

**Read `{version}` from `current_version` in the same file rather than typing a version in.** That is the whole reason the templates are parameterised: a hardcoded version goes stale at the next upstream sync, and a URL that resolves to the wrong release fails in the most expensive way — it returns a perfectly valid icon that is not the one the pack vendored.

## Placeholders differ per pack, and so do their meanings

`{version}` is common to all of them. Everything else is the pack's own vocabulary — `{variant}` and `{name}`, or `{ratio}` and `{code}`, or `{codepoint}` — and each pack's README documents what its own placeholders accept. Consult the pack, not this page.

> **A placeholder that is not `{version}` may not take `current_version` either.** Where a pack mirrors the same assets across several upstreams, the GitHub tag and the registry version can be different strings for the same release — the pack then publishes a separate field and its template names that field instead. Substitute exactly the placeholders the template contains, and if one is left unsubstituted in your output, that is the template telling you it wanted a value you did not supply.

## Not every pack has one

A pack that vendors a one-off snapshot of a commercial product publishes **no** `cdn` block, because there is no public CDN to point at. `upstream.cdn` being absent is a fact about that pack's upstream, not an omission to work around.

```php
$templates = $config['upstream']['cdn'] ?? [];
```

## The trade-offs, stated plainly

- **Versions are pinned by you, not by the pack.** Once you build URLs yourself, upgrading the pack no longer moves the icons your pages load.
- **Sanitisation does not apply.** Core's SVG sanitiser runs over icons it reads from disk. An icon fetched by the browser from a third-party CDN never passes through it.
- **The database is not involved.** Search, terms and preferences all work from seeded rows, so CDN-served icons are outside the registry's knowledge.

For most applications the vendored path — install the pack, seed it, use `<x-ichava::icon>` — is the right default. This recipe is for when the weight genuinely matters.

## See also

- [Check pack updates](check-pack-updates.md)
- [Use an icon pack](use-an-icon-pack.md)
- [Seed pack icons](seed-pack-icons.md)

---

[← Docs index](../../README.md#documentation)
