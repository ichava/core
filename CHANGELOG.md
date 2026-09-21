# Changelog

All notable changes to `ichava/core` follow [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- **The catalog sync workflow could only succeed on the first run after a merge.** It pushes to
  a single stable branch, `chore/sync-icon-sets`, so each run updates one pull request instead of
  opening a new one. `actions/checkout` fetches only the default branch, so there was no
  `refs/remotes/origin/chore/sync-icon-sets` for `--force-with-lease` to compare against, and git
  rejected the push with `! [rejected] ... (stale info)`.

  The pattern is the giveaway: it failed on 17, 18, 20 and 21 September and passed on the 19th.
  Creating a ref needs no lease, so the first run after the branch was merged away succeeded, and
  every run while the branch existed failed. "Stale info" reads like a race, and this was not one.

  The branch is now fetched into its remote-tracking ref before branching off `main`. The lease
  is kept rather than swapped for a plain `--force`: it still refuses to clobber a push that
  arrived after that fetch, which is the case it exists for.

- **The same workflow had stopped opening pull requests, silently.** Its guard was
  `gh pr view "$BRANCH"`, which resolves a branch to its most recent pull request **whatever its
  state**. Once the first one was merged, every later run matched that merged PR, printed
  "Pull request already open", skipped creation and exited 0.

  Worse than the push failure it sat next to: that one at least went red. This reported success
  while the catalog change stayed on the branch with nothing tracking it. It is now
  `gh pr list --head "$BRANCH" --state open`, which is the question being asked.

### Added

- **A carrier-grade NAT guard test at the level the guard actually runs.**
  `IconPackUpdateCheckerTest` now covers a pack whose `version_check_url` host resolves into
  `100.64.0.0/10`: the check reports `error` and `Http::assertNothingSent()` holds, so the
  refusal is pinned at the point a request would otherwise be made.

  `PublicIpBoundaryTest` pins the predicate, and `blocks a host that resolves to a private
  address` already pins that `checkOne()` consults it: dropping the `isPublicIp()` call from
  `isAllowedVersionCheckUrl()` fails five tests, that one included. What no test covered is
  `100.64.0.0/10` specifically at the boundary. It is the entry in `BLOCKED_V4` most likely to
  be removed by someone reading RFC 6598 space as public, and nothing outside the predicate
  tests would have noticed.

  The fixture gained `cgnat.test` rather than a literal address, because a literal one
  short-circuits `resolveHostIps()` before the resolver runs and would exercise a different path.

  **Mutation-checked:** removing `'100.64.0.0/10'` from `BLOCKED_V4` fails this test.

## [0.2.6] - 2026-09-21

### Fixed

- **`make:icon-package` generated a package that could not be installed.** Eight values in
  `stubs/icon-package/` had drifted from the five real packs, and nothing compared the two, so
  every scaffold since the drift began was born broken. The decisive one: the stub required
  `ichava/core: ^1.0`, a version that has never existed, and shipped no `repositories` block --
  so Composer consulted only Packagist, which answers 404 for every `ichava/*`. Resolution was
  impossible, not merely wrong.

  | Was | Now |
  |---|---|
  | `ichava/core: ^1.0` | `^0.2.5`, plus the four VCS `repositories` a pack needs |
  | `php: ^8.3` | `^8.4.1 \|\| ^8.5` |
  | `illuminate/support: ^10.0\|^12.0\|^13.0` | `^13.0` |
  | `laranail/package-tools` absent | required, since the generated provider imports it |
  | `Simtabi\Laranail\PackageTools\*` | `Simtabi\Laranail\Package\Tools\*` |
  | `ichava:update-<pack>-icons` | `ichava::<pack>-icons.update` |
  | `orchestra/testbench: ^8.0\|^10.0\|^11.0` | `^11.0` |
  | `pestphp/pest: ^2.0\|^3.0` | `^4.6 \|\| ^5.0` |

  The namespace and command-name entries are the instructive ones: both were fixed in the five
  real packs and never propagated here, so the scaffolder kept emitting conventions the estate
  had already retired. The bare `ichava:update-…` name in particular is exactly the flat-map
  collision the namespaced scheme exists to prevent.

### Added

- **Scaffolded packages now ship the four workflows every real pack has** -- `tests`,
  `code-quality`, `release` and `sync-upstream`. A pack generated before this had no CI at all,
  so its tests never ran anywhere.

- **`StubEstateParityTest` compares the stub against a real pack, not against literals.** It
  reads `flag-icons/composer.json` and its provider off disk and asserts the scaffolded output
  agrees. Hardcoded expectations are how all eight defects survived: a literal encodes the
  estate as it was the day it was written, then ages silently beside the thing it guards. The
  test skips when the sibling is absent, since CI clones one repo and a false red there would
  train people to ignore it.

## [0.2.5] - 2026-09-21

### Security

- **The version-check address guard judged addresses by notation, and missed most of the
  special-purpose registry.** `isPublicIp()` used PHP's
  `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` plus a `127.` prefix check. That pair
  covers RFC 1918, loopback and link-local — **169.254.169.254 cloud metadata was correctly
  blocked** — and admits everything else IANA marks special-purpose.

  Measured against the predicate rather than read off the flags, it accepted:
  `100.64.0.0/10` carrier-grade NAT, `192.0.0.0/24`, `198.18.0.0/15` benchmarking, all three
  TEST-NET documentation blocks, and `224.0.0.0/4` multicast.

  **Some of the gaps reached loopback.** Four IPv6 forms carry an IPv4 address inside them, and
  the old check judged the notation rather than the address: `64:ff9b::7f00:1` (NAT64),
  `2002:7f00:1::` (6to4) and `::7f00:1` (IPv4-compatible, RFC 4291 2.5.5.1) all mean
  **127.0.0.1** and all passed. `::ffff:127.0.0.1` happened to be caught; that it was, while
  its three siblings were not, is the sign the check was reading spelling rather than value.

  The IPv4-compatible form is the weakest of the four — deprecated since 2006, and most stacks
  will not route it — but `::a9fe:a9fe` is cloud metadata written in it, and a notation that
  carries a blocked value and is accepted anyway is the exact defect this change exists to
  remove.

  The predicate is now an explicit IANA special-purpose block list for v4 and v6, and the three
  IPv4-carrying IPv6 forms are unwrapped and judged as the address they carry, so the answer
  cannot depend on how an address is written.

  Exploiting it needed a malicious or compromised pack's `version_check_url`, and the
  loopback-reaching cases additionally needed NAT64 or 6to4 on the host — narrow, but the
  guard exists precisely because a pack's config is not trusted input.

  `tests/Unit/PublicIpBoundaryTest.php` pins 32 blocked addresses and 5 routable ones, and
  asserts that all **five** spellings of loopback answer identically. **Mutation-checked:**
  restoring the previous implementation fails 14 of them.

  > The count in that last assertion is load-bearing. The first version of this fix unwrapped
  > three notations and left `::7f00:1` accepted — which made "by value, not notation" false as
  > stated, while reading as though it were true. A claim about notation is only worth as much
  > as the enumeration behind it.

  > `fake_host_resolver()` in `IconPackUpdateCheckerTest` had to move off `192.0.2.0/24`. It
  > chose TEST-NET-1 deliberately, because the guard treated documentation space as routable
  > while it could never be a real destination — a neat trick that depended on this gap. With
  > the registry blocked, no address is both allowed and guaranteed-unroutable, so the fixture
  > now uses real public addresses and takes its inertness from the stub resolver and
  > `Http::fake()` instead of from the range.

### Added

- **`IconPackUpdateChecker` takes an optional host resolver** (constructor argument or
  `setHostResolver()`), so a test can say what a name resolves to without a working
  DNS server. Production leaves it unset and the system resolver is used exactly as
  before.

  The seam substitutes *what a name resolves to*, never *whether an address is
  allowed*. A literal address short-circuits before the resolver is consulted, and
  every address the resolver returns is still validated and still has to clear the
  public-IP check — so it cannot be used to reach a private host. Three tests pin
  that: a name resolving into a private range, a name resolving to nothing, and a
  literal `127.0.0.1` handed a resolver that answers everything with a public
  address. Each fails if the guard, the short-circuit, or the public-IP check is
  removed.

### Fixed

- **Eight unit tests no longer depend on live DNS.** The SSRF guard added in 0.2.4
  resolves `version_check_url` before any request is made, and `Http::fake()` does not
  intercept name resolution — so the guard failed closed and the tests failed with
  `null` versions for any contributor offline, in a sandbox, or behind a restrictive
  resolver. CI resolves fine, so nothing flagged it. The tests now inject a resolver
  and touch the network nowhere.

- **The cloud-metadata test now exercises the check it is named for.** Its fixture URL
  was `http://169.254.169.254/…`, so the scheme check rejected it and the address check
  never ran. It is `https://` now; the plain-`http` case is still covered by the
  non-https test.

## [0.2.4] - 2026-09-21

### Added

- **`release.yml`** — tag-driven, so a `v*.*.*` push publishes a release whose body is that
  version's CHANGELOG section rather than auto-generated notes, plus a CycloneDX SBOM of the
  runtime dependency tree.

  It **fails closed when the tagged version has no CHANGELOG section**. Both releases before
  this workflow existed were cut by hand against the API, and the second went wrong exactly
  there: a transport error killed the merge call, the script carried on, and a `v0.2.1` tag was
  published pointing at `v0.2.0`'s commit — a tag whose version had no section at all. That
  tag was deleted within the minute, but nothing except a person noticing stood in the way.

### Fixed

- **Sync icon sets catalog no longer pushes to `main`.** Branch protection rejects direct
  pushes (GH013), so the workflow now commits to the stable branch `chore/sync-icon-sets`
  and opens (or updates) a single pull request instead of one per run.

- **Catalog tests no longer assert hardcoded snapshot values.** The daily sync refreshes
  `icon-sets.json` (counts, versions), so exact assertions broke on every sync. The suite
  now checks the stable pins (`key`/`package`/`repository`) and only the shape of the
  synced fields, deriving expected versions from the loaded catalog.

- **Workflow tokens scoped to least privilege.** `tests.yml` and `code-quality.yml`
  ran with the repository default token; both now declare `permissions:
  contents: read`.

### Security

- **Post-sanitizer attributes now pass the sanitizer gate.** `process()` sanitized the
  file and then applied every caller-supplied attribute unchecked, so `onload`,
  `href="javascript:…"`, malicious `style` values and quote-breaking keys (via the
  defer `buildHtml()` path) survived into rendered output. Both sinks now enforce the
  same allow-list and value checks as file-content sanitization; legitimate `class`,
  `id`, sizing, `role`, `aria-*`, `data-*`, `title` and safe `style` values pass
  through unchanged.

- **Icon file reads are contained to the package directory.** A poisoned `path` row
  (`../…`, a symlink, or an absolute stored path) made `svg_content` and `file_size`
  read outside the package base directory. Both readers now resolve through
  `realpath()` and return `null` on escape, behaving exactly like a missing file;
  rows without a registered base keep the legacy absolute-path behaviour.

- **Off-document paint URLs are rejected everywhere.** The post-sanitizer gate checked
  `url()` targets on `style` only, so `fill`, `stroke`, `clip-path`, `mask` and
  `filter` values like `url(https://…)` survived into rendered output and would make
  viewers fetch an attacker URL. The fragment-only rule now applies to every
  attribute value, in file content as well as applied attributes; plain paint values
  and `url(#fragment)` references are unaffected.

- **Sanitizer policy flags are enforced, not just declared.** `stripComments`,
  `stripDoctype` and `stripEntities` existed in `svg-policy.json` with no PHP
  reader, so comments survived the main read path and entity references lingered.
  The sanitizer now honors all three, and the blocked-protocol list gains `blob:`,
  `filesystem:`, `jar:` and `data:text/plain`.

- **SVG driver loads are contained to the package directory.** `load()` only checked
  the path against its own directory, so any absolute path passed. Callers can now
  pin loads to a base directory — the registry passes each set's own — and escapes
  are rejected instead of read.

- **Debug render errors no longer leak paths.** The `app.debug` fallback embedded
  the exception message — including absolute filesystem paths — in an HTML comment.
  It now carries only the exception class.

- **Pack update checks no longer request arbitrary URLs.** The `version_check_url`
  from a pack's config was fetched with no validation, so a malicious pack could
  aim it at the local network. Only `https` URLs with publicly routable hosts are
  requested now, redirects are not followed, and anything else reports an error
  without sending.

## [0.2.3] - 2026-09-16

### Fixed

- **Readiness was decided against a schema that has never existed**, so `info status` reported
  `UNINITIALIZED` on a fully migrated and seeded database, and both auto-seed listeners — which
  gate on the same check — never fired.

  `IchavaLifecycleManager::hasMigrations()` required `category` and `svg_content` on
  `ichava_icons`. A category is a row in `ichava_icon_terms` reached through the polymorphic
  pivot, and `svg_content` is an accessor that reads the file from disk; the migration says so
  directly — *"File Information (NOT the content!)"*. The check therefore returned `false` on
  every install ever made, and `hasSeeds()` short-circuits on it, so that reported `false` too.

  Verified against a real application holding 138,480 icons: `Migrations ✅ / Seeds ✅ / READY`,
  where it previously said `NOT READY / UNINITIALIZED`. `tests/Feature/LifecycleReadinessTest.php`
  pins the check against the migration, so a column the schema lacks cannot be required again.

## [0.2.2] - 2026-09-16

### Fixed

- **`ichava::ichava-core.database migrate` never ran a migration.** `runMigrations()` passed
  `migrate` a literal `--path platform/ichava/ichava/database/migrations` — a layout from
  another project. `migrate --path` against a directory that does not exist runs nothing and
  **exits 0**, so the command printed its success outro, returned `0`, and created no tables.
  In every consuming application, for the whole life of the package.

  The path is resolved from the service's own file now, passed with `--realpath`, and a missing
  directory returns a failure code instead of a silent success.

  Nothing caught it because the suite runs migrations through Testbench, which loads the
  registered path directly and never calls this service. It surfaced by running the command in
  a real application and then reading `sqlite_master` rather than the exit code.
  `tests/Unit/MigrationPathTest.php` now refuses any relative `--path` literal in `src/`.

## [0.2.1] - 2026-09-16

### Fixed

- **Six call sites still invoked commands by the names `0.2.0` retired**, so the paths through
  them raised `CommandNotFoundException` at runtime: the `cleanup-logs` and `watch` scheduled
  tasks, both `ichava::ichava-core.database` calls inside the installer, the cache rebuild in
  `IconCacheService::rebuild()`, and the `argv` sniff in `AutoSeedIconsOnRegistration` that
  skips auto-seeding during an explicit database command — that one failed silently by simply
  never matching again.

  They are string literals that nothing type-checks, on paths this suite does not exercise;
  the first report came from `ichava/browser` running against the released `0.2.0` tag.
  `tests/Unit/CommandInvocationTest.php` now reads the source for invocation sites, because
  the defect is inert until the line runs and there is nothing to inspect at runtime before
  that.

## [0.2.0] - 2026-09-16

### Breaking

- **Artisan commands are now namespaced `ichava::ichava-core.<command>`, and the bare names are
  gone.** Includes `ichava:install`, added in #21 while this was in flight. The old names are
  **not** retained as aliases: an alias like `ichava:cache` is still a generic key in Artisan's
  flat command map, which is exactly the collision the namespaced name exists to prevent, and
  keeping it would make the convention decorative. `make:icon-package` additionally registered
  into Laravel's own `make:` namespace.
- **`ajaxray/ansikit` dropped from `require`.** Terminal output in `IchavaSeeder` now goes
  through the command it already held, so it honours `--quiet`, `--no-ansi` and redirection,
  which raw ANSI writes to STDOUT did not.

- **Config renamed: `config/core.php` → `config/ichava-core.php`, key `ichava.core.*` → `ichava.ichava-core.*`.** Republish with `php artisan vendor:publish --tag=ichava::ichava-core-config` and update every `config('ichava.core.*')` read, including host apps and sibling `ichava/*` packages. Published overrides at the old nested path are no longer loaded.

### Added

- **Tested support for SQLite, PostgreSQL, MySQL and MariaDB.** CI runs the suite against
  PostgreSQL 17, MySQL 8.4 and MariaDB 11.4 as service containers alongside the existing
  SQLite lane, and `tests/TestCase.php` reads `DB_CONNECTION` so the same suite targets any
  of the four locally. See `documentation/core/databases.md`.
- PHPStan static analysis (level 0) with `composer analyse` wired into CI.

### Fixed

- Nine commands registered bare generic slugs into Artisan's flat command map, where a second
  package claiming the same key replaces the first silently rather than colliding. All now
  carry vendor and slug, pinned by a test that reads the live console registry rather than
  `$signature` — the `::` name only survives because a trait writes it past Symfony's
  `validateName()`, so reading the property would pass against a registration that never took.
- The `make:icon-package` scaffolder stub emitted `#[AsCommand(name: 'ichava:update-…-icons')]`,
  so every pack generated from it reintroduced a bare name. It now emits
  `{{vendorKebab}}::{{kebabName}}-icons.update`.

- **Icon search was broken on PostgreSQL.** `FtsLanguageHelper` and `Icon::scopeFuzzySearch()`
  called `jsonb_array_elements_text()` against `tags`, `keywords` and `search_text`, which are
  `json` columns — `$table->json()` emits `json`, not `jsonb`, on PostgreSQL, and no implicit
  cast exists between the two, so the call resolved to no function at all. The migration's own
  trigger had always done this correctly. Five call sites now cast explicitly. Nothing caught it
  because the suite ran only on SQLite, which never reaches that code.
- `IconSetBuilder::all()` and `get('all')` addressed the same cache key, so one overwrote the
  other and a reader expecting a single icon payload was handed a map of them. Set keys and icon
  keys are now namespaced apart.
- `Icon::getPackageCounts()` passed a TTL as a third positional argument to a two-parameter
  `remember()`. PHP discards surplus arguments to a userland function without complaint, so the
  24-hour lifetime it asked for was never applied. `remember()` takes a `$ttl` parameter now and
  the call site passes it by name.
- SQLite test runs never enforced foreign keys, so the schema's cascading deletes went
  unexercised on the only driver that ran. `foreign_key_constraints` is on in the test harness.
- `tests/TestCase.php` set `ichava.cache_enabled`, `ichava.cache_driver` and `ichava.default_set`
  — bare keys that no code reads. Replaced with the live `ichava.ichava-core.default_set`.

- `IconSetBuilder::get()`/`all()` cached raw `IconData` objects, which Laravel 13 returns as `__PHP_Incomplete_Class` (`cache.serializable_classes` defaults to `false`). Every page refresh after the first failed with a `TypeError`. Payloads are now cached as plain arrays and rehydrated via `IconData::toArray()`/`fromArray()`; stale object entries are forgotten, rediscovered, and orphaned by a cache version bump (`v1` → `v2`).
- `<x-ichava::icon class="...">` silently dropped every Blade attribute. The compiled template calls `render()` before `withAttributes()` populates the bag, so the eagerly built SVG froze before `class` arrived (the fluent `ichava()->class()` path was unaffected). `IconComponent::render()` now returns a deferred `Htmlable` built in `toHtml()`, after the bag is set; direct callers can use the new `renderNow()` for an immediate string (note the `render()` signature change).
- Icon examples now use variant-prefixed Tabler paths (`outline/home`, `filled/home`). Bare `ichava/tabler-icons::home` does not resolve.
- `IconDiscoveryService` missing `IchavaException` import left 7 catch blocks dead.
- `SvgDriver` threw non-existent `IconRenderException`; now uses `IchavaException::renderFailed()` with chained previous exception.
- `PerformanceTimer` type-hint pointed at wrong `IchavaLogger` namespace.
- Facade imports (`DB`, `Schema`) made explicit in `IconPreferenceService`.
- `FtsLanguageHelper::getLanguages()` called an instance method statically.

### Changed

- Hardened CI workflows: concurrency groups, job timeouts, problem matchers, docs-only skip paths, and tidy composer scripts.
- CI branch triggers standardized on `main` only.

## [0.1.1] - 2026-09-02

Patch rather than a minor so that consumers on `^0.1` pick it up without a constraint change.
The additions are new public surface; nothing existing changed shape.

### Added

- `SvgProcessingService::renderFingerprint()` -- a short digest of everything that decides the
  output bytes for a given input file: the three allow-lists, the optimization level and a
  `RENDER_PIPELINE_VERSION` constant for code changes config cannot see.
- `Icon::render_version` -- `file_hash` combined with that fingerprint, identifying the exact
  bytes an icon renders to.

- `Support\SvgPolicy`, the reader for `resources/security/svg-policy.json` -- the single
  definition of what survives SVG sanitisation, shipped with the package and read by every
  runtime rather than duplicated into each one.
- 26 elements and 23 attributes the shipped policy stripped: `filter` and every `fe*`
  primitive, `pattern`, `textPath`, `switch`, `metadata`; `stroke-dasharray`,
  `stroke-dashoffset`, `stroke-miterlimit`, `transform-origin`, `patternUnits`,
  `patternTransform`, the `font-*` and text-layout attributes, `vector-effect`,
  `paint-order`, `shape-rendering`.

- An archetype rendering gate (`SvgArchetypeRegressionTest`) over real corpus icons, one per
  archetype rather than one per pack, with provenance in
  `tests/fixtures/archetypes/manifest.json`. Three rendering defects accumulated in this
  subsystem without detection because nothing rendered an icon in CI.

### Changed

- `SanitizesSvg` reads the reference attributes and the fragment pattern from the policy
  instead of its own constants. They were constants, so emptying `fragmentOnlyRefs` in the
  policy changed every client and nothing here. Not a hole, since the value check still ran,
  but it made a section of the policy decorative on the server.
- `ichava.core.svg.*` now derives from `resources/security/svg-policy.json` instead of
  being a second list of literals in `config/core.php`. A host can still publish the config
  and narrow it; what is no longer possible is the two drifting apart by accident. Note
  `config:cache` freezes the resolved arrays, so a policy edit needs `config:clear`.
- The SVG cache key is now `svg:{id}:{render_version}` rather than `svg:{id}:{file_hash}`.
  The cached value is the *processed* SVG -- ids namespaced, sizing normalised, allow-list
  applied -- so a file hash never identified it: widening the policy changes every icon while
  every file hash stays put. Existing cache entries are orphaned and repopulate on first read.

### Why

`ichava/browser` serves the SVG endpoint with `Cache-Control: immutable, max-age=31536000`,
which is a promise that a URL's bytes never change. It could not keep that promise on a URL
keyed by icon id. `render_version` is the token that makes the URL content-addressed, and it
has to cover the render policy as well as the file, or the next allow-list widening would ship
into a year of cached responses produced by the previous policy.

## [0.1.0] - 2026-08-31

First open-source release. The engine of the Ichava icon ecosystem: registry, models, seeder,
cache, SVG pipeline, Blade components, Artisan commands and the package scaffolder. Ships **zero
HTTP surface**, so `composer require ichava/core` alone gives a working headless icon engine; the
REST API and the browser SPA live in `ichava/browser`.

Earlier `v1.x` and `v2.x` tags existed on GitHub and were never published to Packagist. They are
withdrawn: the ecosystem restarts from a single `0.1.0` across every package. Nothing depended on
them.

### Added

- `IconRegistry` with runtime pack discovery, `register()`/`unregister()`, and registration
  events that drive automatic seeding and cache invalidation.
- Database layer: `Icon` and `IconTerm` models, migrations, and a chunked seeder that dispatches
  1,000-row jobs onto the `ichava-icons` queue with multi-level de-duplication and a cache lock
  against concurrent seeds.
- `<x-ichava::icon>` Blade component, the `@ichava_defs` and `@ichava_csp_nonce` directives, and a
  global helper.
- Artisan commands: `ichava:database`, `ichava:cache`, `ichava:info`, `ichava:job-status`,
  `ichava:check-icon-updates`, `ichava:watch-icon-files`, `ichava:cleanup-logs` and
  `make:icon-package`.
- `make:icon-package` scaffolder that walks `stubs/icon-package/` and substitutes mustache tokens
  in both file contents and path segments, so adding a file to the scaffold is a drop-in.
- Dedicated log channels (`ichava`, `ichava-icons`, `ichava-audit`), `AuditLogger` with a
  `SecurityAuditEvent` per record, and `SecurityNonce` for CSP.
- Path handling that accepts both `vendor/package::category/icon` and the dot form, normalised
  through a single `PathResolver`.

### Security

- **SVG served through the model is sanitised.** The `svg_content` accessor was a bare
  `File::get()`, so whatever a pack shipped reached consumers verbatim: including the
  `foreignObject`, `script` and `image` elements present in the shipped packs. The JSON path
  mattered most: `IconBrowserService` places the string into an API payload where no response
  header helps and the client injects it into the DOM. Sanitisation now happens at the accessor,
  the single point every consumer reads through, and the result is cached post-sanitisation. A
  file the sanitiser rejects yields an empty string rather than falling back to raw markup.
- **The sanitiser blocks by value rather than by name.** `href` and `xlink:href` survive as
  same-document fragments only; a `style` value keeps its declarations but any `url()` must target
  a fragment, and `behavior:`/`-moz-binding` are refused. Dangerous protocols are matched anywhere
  in a value, with whitespace and control characters collapsed first, rather than only at its
  start.
- `role` and `aria-*` survive, so an icon shipping `<title>`/`<desc>` keeps a reachable accessible
  name.

### Fixed

- **The package config now loads at the key the source reads.** The file was `config/ichava.php`
  while the package short name is `core`, so it merged at `ichava.core.ichava.*` while every read
  site used `config('ichava.*')`. All 68 returned `null` and fell through to hardcoded defaults,
  which left the entire shipped configuration inert: cache TTLs, `database.batch_size`, queue,
  logging, optimization, `custom-icons.sets`, `prefix`, the whole `security` block and every
  `ICHAVA_*` environment variable. The file is now `config/core.php` and the key is `ichava.core`.
- **Allow-lists are matched case-insensitively.** They are authored in SVG's own casing, and node
  names were compared lowercased, so `clipPath`, `linearGradient` and `radialGradient` could never
  match and were removed from every icon despite being allowed. Gradient icons lost their paint
  source and kept a dangling `fill="url(#g)"`.
- **Search no longer discards active filters.** Package, category and variant filters sat in an
  `else` branch behind `if (search)`, so selecting a package and then typing a query returned
  matches from every package.
- **Search runs on non-PostgreSQL drivers.** `scopeFuzzySearch`, documented as the fallback for
  exactly those drivers, used `jsonb_array_elements_text()`, which only PostgreSQL provides. Any
  search on SQLite or MySQL failed outright.
- `IconCacheService` referenced an undefined `$this->config` property in three methods.

### Requirements

- PHP `^8.4.1 || ^8.5`, `illuminate/support` `^13.0`.
- `laranail/package-tools`, `laranail/console` and `laranail/enumerator`, all `^0.1.0`. None is
  published on Packagist, so the package declares VCS repository entries for all three.
