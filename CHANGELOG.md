# Changelog

All notable changes to `ichava/core` follow [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- **`branch-alias` named the 0.3 series while `main` was already on 0.4.**
  `extra.branch-alias.dev-main` read `0.3.x-dev`. The convention is that it moves in the change
  that *starts* a series rather than when the release ships, so closing `0.4.0` left it a
  version behind.

  It resolved either way, which is exactly the problem. Every consumer now allows `^0.4` **and**
  `^0.3`, so a path or VCS consumer tracking `dev-main` was handed 0.4-series code wearing a 0.3
  label and nothing objected. The first consumer to drop its `^0.3` arm would have met it as a
  resolution failure naming `ichava/core`, which is the wrong package to go looking at.

## [0.4.0] - 2026-09-21

### Added

- **Every resource type a pack ships is registered, not just translations.**
  `Support\ServiceProvider::newPackage()` hand-rolled a directory check for
  translations alone. Written as a special case it did not generalise, which is
  why views and configs were never covered. `package-tools` already ships
  `loadAllResources()` for exactly this and handles six resource types, so core
  calls that rather than growing a second and a third conditional.

  Two things make the substitution non-obvious and both are pinned by tests:
  the base path has to be primed first, because `Package::$basePath` is empty
  until `registerPackage()` calls `setPathFrom()` on the line *after*
  `newPackage()` returns.

- **Core has its own translations, and a gate that keeps them honest.** It
  shipped no `resources/lang` at all and printed English from string literals.
  The directory now exists, is registered, and one command is migrated end to
  end.

  Core extends `PackageServiceProvider` directly, so it does **not** inherit the
  default-on registration `Support\ServiceProvider` gives the packs -- it calls
  `hasTranslations()` itself, with a comment saying why, because that asymmetry
  has already cost one defect class here.

  The durable part is the gate rather than the strings. A missing translation
  key does not throw in Laravel; it renders as the key. So the test sweeps
  `src/` for every `__('<ns>::…')` call site and fails on any key that hands
  itself back.

- **`php artisan about` reports the ecosystem and every installed pack.**
  `package-tools` has shipped `HasAbout` and `HasAboutSections` all along and
  nothing here called either, so an operator had no way to ask what was actually
  registered.

  Core contributes one section -- packs, total icons, cache driver and version,
  queue -- deliberately operational, because name, licence and homepage are
  `composer.json`'s job. Every pack gets its own section without asking, read
  from `IconRegistry` rather than a second reader: one pack in this estate
  shipped translations claiming MIT while its `config.json` said Commercial.

### Changed

- **A prefix flush a mutation check showed was uncovered is gone.** The
  generation counter already changes the database search key, so
  `IconCacheService::flushPrefix()` was code no assertion reached. It also
  reached too far, clearing icon SVG caches and directory fingerprints the
  method has no business discarding.

- **The README is a slim pointer, and the pages the standard requires exist.**
  The capability table, requirements list and quick example are relocated rather
  than dropped -- into `architecture.md`, `installation.md` and a new
  `getting-started.md`. `release.md` is new.

  **Two badge claims were false and are gone rather than fixed.**
  `ichava/core` is 404 on `repo.packagist.org`, so the registry-version badge
  rendered as an "invalid" pill while asserting the package is installable.

- **Shared pack documentation moved into recipes.** Four of the five icon packs
  carried the same three sentences about the update checker with the upstream
  name swapped, and four the same CDN framing. Two recipes absorb the shared
  half. The CDN page documents how to read `upstream.cdn` from a pack's
  `config.json` rather than reproducing URLs, because packs reproducing them is
  what let a version drift.

- **The changelog guard fires on a changelog.** It lived in `code-quality.yml`,
  which carries `paths-ignore: '*.md'` so Pint and PHPStan skip
  documentation-only changes. That filter applies to the whole workflow, so a
  changelog-only pull request -- which is what every release is -- ran nothing.
  Measured on `#63`: **0 checks**. The job now has its own workflow triggered on
  `CHANGELOG.md`, in all eight repos that had it behind that filter.

- **The component namespace keeps a bare slug, deliberately.** `ichava` is a
  bare generic slug in Blade's flat class-component map, the same class of claim
  corrected for browser's view hints. Here it is intended: packs register
  short-name components under one shared ecosystem prefix. Recorded so it is not
  re-discovered as drift, or "fixed" by accident inside unrelated work. Comment
  only; the registered value is unchanged.

- **The deferral note carries its command and date.** Its counts were overtaken
  by the docs decentralisation -- moving pages into the packages raised the
  per-package count and left `ichava/documentation` holding zero, so the note
  pointed a reader at the one place with nothing to find. It is 112 lines across
  the eight package repos, and the note now says how that was measured.

- **Three test gaps closed, all additive.** The view assertion stopped at
  `View::exists()`, which answers the finder without compiling anything -- a
  template that resolves and then fails to compile satisfied it. The licence
  assertion could not fail for the reason it exists, because both fixture packs
  declared MIT; they now disagree. And a non-pgsql syntax guard asserted its own
  precondition, failing the PostgreSQL lane for asking a question that does not
  apply there -- a precondition that cannot hold on every lane is a skip, not an
  assertion.

- **`IconDiscoveryService` composes three extracted actions.** Its public
  surface is unchanged and no caller moves.

  | Action | Was |
  |---|---|
  | `Actions\DiscoverInstalledPackages` | `scanComposerLock()` |
  | `Actions\CountSvgFiles` | `countSvgFiles()` + `countDirectSvgFiles()` |
  | `Actions\BuildIconUsageSyntax` | `getIconSyntax()` |

  Each injects `Illuminate\Filesystem\Filesystem` rather than reaching for the
  `File::` facade, which is the part that makes them assertable: 14 new **unit**
  tests cover behaviour that previously needed the application, a cache and a
  database standing up to reach.

  That mattered. `getIconSyntax()` carried F-4.2 -- a key read with no
  null-coalesce, warning on every call -- for the whole life of the method,
  inside a 653-line class nobody had a reason to open.

  > **Measured honestly:** `(Cache|DB|File|Icon)::` in `src/Services/` went 148
  > to 144, and 21 to 17 in this class. That is **not** the material drop the
  > proposal set as its gate, and the remaining 17 sit in the folder-tree and
  > streaming methods, which this change did not touch. The extraction is real
  > -- 653 to 587 lines, 14 tests where there were none, one latent bug closed
  > -- but the coupling argument is not yet proven, and further extraction
  > should be justified on its own before it proceeds.

### Fixed

- **Icon search ran the wrong query on three of four drivers, and clearing did
  not clear.** `IconDiscoveryService::executeSearchQuery()` hand-wrote raw
  full-text SQL -- `to_tsvector(…) @@ plainto_tsquery(…)` -- with no driver
  branch, so `searchIcons()` threw on SQLite, MySQL and MariaDB the moment the
  icons table existed. `Icon::scopeSearch()` has carried that decision and the
  portable `LIKE` fallback all along; the service bypassed the model and built
  its own. **The repair is a deletion** -- delegate to the scope, and have one
  query builder to be wrong in.

  The transform then called `$icon->getIconPath()`, declared on
  `IconDriverInterface` and never present on the model, so the database path
  threw on PostgreSQL too.

- **The PostgreSQL search clause referenced an alias nothing provided.** Every
  column in `buildComprehensiveSearchQuery()` read `i.name`, `i.id`,
  `i.package`, but nothing aliased the icons table to `i` -- `Icon::query()`
  emits `from "ichava_icons"`. Invalid SQL on the one driver it was written for.

  It survived because `scopeSearch()` had no caller in `src/`: the discovery
  service that should have used it hand-wrote its own query instead, leaving
  this branch unreachable. Removing that duplication is what first executed it.

- **A migrated command was still English, and the guard could not see it.** The
  literal guard watched `$this->` helpers only. That command does most of its
  talking through Laravel Prompts, which are free functions, so `intro()`,
  `outro()`, `note()`, `warning()` and both `table()` calls were invisible to
  it -- the file was reported clean while its summary table read
  `Metric | Count | Kept | Failed`.

  Eight sites migrated, sixteen keys added. The guard now also reads the file's
  own `use function Laravel\Prompts\x` imports, so importing a new prompt brings
  it under the guard instead of opening a hole.

- **Preference writes report whether they persisted.** `IconPreferenceService`
  took `IchavaSessionManager`, a final class that decides its own availability
  in its constructor. Under the default test harness it resolves the browser
  tier, so every write no-opped, every read returned the default, and any test
  asserting preference behaviour **passed vacuously**. The seam is now a
  `PreferenceStore` contract, bound to the same class in production and
  substitutable in a test -- which is what made the second finding testable at
  all: `set()` ignored whether the write landed.

- **Two SVG counters caught an exception they cannot receive.**
  `countSvgFiles()` and `countDirectSvgFiles()` wrapped their iterator in
  `catch (IchavaException $e)`. Neither `RecursiveDirectoryIterator` nor
  `scandir()` throws that -- an unreadable directory produces
  `UnexpectedValueException`, which extends `RuntimeException` exactly as
  `IchavaException` does and is therefore its **sibling**, not its subclass.

  The handler could not fire, so the failure it was written for escaped a method
  whose documented contract is to return `0`.

## [0.3.2] - 2026-09-21

### Added

- **A test pinning the note to the validator.** It asserts the thrown message names the three
  fields a three-field set is missing, *and* that `_note` mentions every one of the six. So if
  the required set changes, the message changes, the test fails, and the note is corrected with
  it -- rather than prose and behaviour drifting apart again in silence, which is what happened
  here. Mutation-checked: restoring the old note fails it.


- **`ichava/icon-sets-emoji` in the install catalog.** `icon-sets.json` listed two of the five
  packs. Two of the missing three are deliberately private -- offering `icon-sets-bundled` or
  `icon-sets-metronic` would list packs most users cannot install -- but the emoji pack is
  public, and its 10,567 icons were undiscoverable through
  `ichava::ichava-core.install`, which reads this file and nothing else.

  Written in the generator's exact encoding (`JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |
  JSON_UNESCAPED_UNICODE`, four-space indent) so the nightly sync appends rather than reflows.
  A first attempt with Python's two-space `json.dumps` produced a 47-insertion, 34-deletion diff
  for an eleven-line addition; this one is 13 insertions and no deletions.

  The snapshot fields are pinned to what the pack's own `config.json` declares, so the next sync
  is a no-op rather than an immediate correction.

### Fixed

- **`database refresh` and `database truncate` threw on SQLite.**
  `dropTables()` and `truncateTables()` suppress foreign keys around a bulk
  operation, and chose the statement with a two-way branch -- PostgreSQL, or
  everything else:

  ```php
  if (Helpers::dbDriverIsPgSql()) {
      DB::statement('SET session_replication_role = replica');
  } else {
      DB::statement('SET FOREIGN_KEY_CHECKS=0');   // <- also SQLite
  }
  ```

  This package supports **four** drivers and they do not agree on any one
  statement. SQLite answers that one with:

  ```
  SQLSTATE[HY000]: General error: 1 near "SET": syntax error
  ```

  So `dropTables()`, `freshMigration()` and `truncateTables()` -- all reachable
  from `DatabaseCommand` -- failed on a documented supported driver, and the one
  this suite runs by default.

  Now a four-way `match`: `session_replication_role` for pgsql,
  `FOREIGN_KEY_CHECKS` for mysql and mariadb, `PRAGMA foreign_keys` for sqlite.
  An unrecognised driver is logged and left alone rather than guessed at --
  skipping the toggle risks a foreign-key error on a drop, while sending the
  wrong statement guarantees a syntax error on every one.

  **Why nothing caught it.** None of the three methods had a test. The CI matrix
  covers pgsql, mysql and mariadb -- every driver for which the `else` branch
  happened to be correct -- and the SQLite lane never called them. A branch is
  only as good as the narrowest lane that exercises it.

  `tests/Feature/ForeignKeyToggleTest.php` covers all four: two cases run
  against whatever `DB_CONNECTION` names, so CI exercises three of them, and a
  dataset asserts the statement chosen per driver so one machine can check all
  four without having them installed.

  **Mutation-checked:** routing sqlite back into the MySQL arm fails 5 tests
  with the original error.


- **`icon-sets.json`'s `_note` told you to do something that breaks the catalog.** It said to
  add a set by appending `key`/`package`/`repository` and letting the nightly sync fill the
  rest. `IconSetCatalogService` rejects any set missing `title`, `icon_count` or `variants`,
  treating an empty string or empty array as missing, so a set added that way makes
  `ichava::ichava-core.install` throw until the sync runs -- up to a day.

  Proven rather than reasoned about: a catalog holding one three-field set fails with
  `Set at index 0 is missing required fields: title, icon_count, variants`.

  The note now names all six required fields, says to take the values from the pack's own
  `config.json`, and records that the file must be written with `JSON_PRETTY_PRINT |
  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` -- it is generated, and any other encoding
  reflows the whole thing. It also stops calling the command `ichava:install`, a name `V59`
  retired.


- **The docblock examples taught three things that are no longer true**, and one that never was.
  These are the examples a pack author copies, so they are interface, not commentary.

  | Was | Is |
  |---|---|
  | `<x-tabler-icons-icon …>` | `<x-icon-sets-tabler-icon …>` |
  | `loadBladeComponent(TablerIconComponent::class, 'tabler-icons')` | `loadBladeComponent(IconComponent::class, 'icon-sets-tabler')` |
  | `class TablerIconComponent extends IconComponent` | `class IconComponent extends BaseIconComponent` |

  The class name is the one that was never right. **Class short names in this ecosystem are
  constants** — every pack ships `IconComponent`, `IconsServiceProvider`, `IconsConstants`, and
  only the namespace varies — so an example naming a class `TablerIconComponent` taught a pack
  author to break the convention the whole family relies on, before and after the rebrand.

- **`CONTRIBUTING.md`'s local-override snippet pointed at three paths that no longer exist.**
  `../../laranail/packager` is two renames stale (`packager` became `package-tools`, and the tree
  gained a `packages/` level), and `../ichava` was this package before it was `core`. Verified by
  resolving each path from a pack directory rather than by reading them:

  ```
  ../../laranail/packager                   missing
  ../../../laranail/packages/package-tools  exists
  ../ichava                                 missing
  ../core                                   exists
  ```

## [0.3.1] - 2026-09-21

### Added

- **Icon packs now get their translations registered, by existing rather than by
  asking.** `laranail/package-tools` defaults `hasTranslations` to false, and no
  pack in this family ever called it -- so every `resources/lang` file shipped
  unreachable. That is why one pack could carry another pack's translations, and
  another could state a licence it does not hold, without a test going red.

  `Support\ServiceProvider::newPackage()` now switches it on when the package
  actually has a `resources/lang` directory. `newPackage()` rather than
  `packageRegistered()` on purpose: it runs before `configurePackage()`, so a
  pack can still opt out, and no pack overrides it -- whereas
  `packageRegistered()` is a documented extension point, and a child overriding
  it without `parent::` would silently lose its translations. That is the same
  quiet failure this change exists to end.

- **`IconRegistry` metadata carries localised strings and taxonomy labels.**
  `config.json` stays canonical; translations are an overlay on top. A pack's
  `labels` key exposes its `variants` / `categories` / `sets` display names,
  which `config.json` has no equivalent for, and groups a pack does not define
  are omitted.


- **`actionlint` runs on every pull request.** Nothing validated the workflow files at all:
  `release.yml` triggers only on `push: tags`, so a broken workflow was first observed as a
  release that refused to start — after the decision to release had been made.

  A YAML parse is not a substitute, and that is the sharp part. `yaml.safe_load` accepts a
  duplicate key and silently keeps the last one, so a double-applied patch that left
  `continue-on-error:` twice on a single step validated clean and would have failed only at tag
  time. `actionlint` rejects what Actions rejects.

  Checked against the defect rather than assumed: injecting that duplicate key, a typo'd step
  key, and an `if:` referencing a property that does not exist are all caught, while
  `yaml.safe_load` still parses the first of them without complaint.

### Changed

- **One containment implementation instead of two.** `SvgDriver::loadFromLocal()`
  and `IconWatcherService::extractIconData()` each carried their own
  `realpath()` boundary check. Two copies of a security check drift, and the one
  that drifts is the one nobody is looking at. Both now use
  `Traits\ContainsFilePaths`.

  Behaviour is unchanged. Neutering the shared check fails 5 tests across both
  consumers, which is what makes the extraction real rather than cosmetic.

### Fixed

- **The Blade-component conflict detector had never fired.**
  `IconRegistry::checkConflicts()` has three detectors; two worked. The third
  guarded on `$metadata['blade_component'] ?? null`, and **nothing in the
  codebase ever wrote that key** -- 4 reads, 0 writes -- so the guard read null
  every time and its whole branch was unreachable.

  That is the collision the global standard calls the headline risk: Blade keeps
  component aliases in a flat map, so a second package claiming one does not
  collide loudly, it silently replaces the first. The ecosystem had a detector
  for exactly that hazard and it had never run.

  `loadBladeComponent()` now reports the alias it registered to
  `IconRegistry::noteBladeComponent()`, and `fromDirectory()` surfaces it.

  **Recorded rather than derived, deliberately.** The alias comes from the short
  name a pack passes to `loadBladeComponent()` -- `'tabler-icons'` becomes
  `tabler-icons-icon` -- and no other piece of metadata carries that string. A
  derived value would agree with the real registration only by luck.

- **`IconDiscoveryService::getIconSyntax()` emitted a warning on every call.**
  The same absent key, read without a null-coalesce, so every invocation logged
  `Undefined array key "blade_component"` and the `component` usage hint was
  always null -- the discovery output never showed anyone how to use the Blade
  component it was describing.

- **The destructive default was the undiscoverable one.**
  `AutoUnseedOnUnregistration` reads
  `config('ichava.ichava-core.database.auto_unseed', true)`, so unseeding is on
  by default, but the key appeared nowhere in `config/ichava-core.php`. A
  consumer publishing the config found a switch for `auto_seed` -- which is off
  by default -- and none for this. Now shipped, with `ICHAVA_AUTO_UNSEED`.


- **Package titles and descriptions were read under a key nothing ever wrote.**
  Nine call sites in `IconBrowserService` and `IconDiscoveryService` read
  `$metadata['browser_metadata']['name' | 'description' | 'vendor']`.
  `IconRegistry` has never written a `browser_metadata` key -- 16 reads across
  this package and `ichava/browser`, **zero writes** -- so every one fell through
  its `??` default. Consumers got the package slug where a title belonged and an
  empty string where a description belonged.

  Nothing failed, because every read had a fallback that looked plausible. A
  fallback is only a safety net if something notices you are standing in it.

  The values were there the whole time, one level up: `IconRegistry` writes
  `name`, `description` and `vendor` at the top of its metadata array. The reads
  now use them.

  This is also what makes the translation overlay visible: with the right key
  read, a locale switch reaches consumers, since the registry applies
  translations on read.

  **Mutation-checked:** restoring `browser_metadata` fails the discovery
  assertion, which is the one that would have caught this originally.

  > `ichava/browser` has seven more of these reads and is fixed separately.


- **The overlay is applied when metadata is read, not when a pack registers.**
  Resolving it eagerly looked obvious and was wrong twice over:

  `fromDirectory()` runs from a pack's `bootingPackage()`, which `package-tools`
  fires **before** `bootPackageTranslations()`. A `trans()` call there does not
  merely miss -- `Translator::load()` caches the empty result under
  `$loaded[$namespace][$group][$locale]`, `isLoaded()` answers true from then
  on, and the namespace registered moments later is never consulted again. One
  premature lookup poisons that key for the rest of the request. The loader
  itself was fine throughout: `$loader->load('en', 'icons', $ns)` returned the
  data correctly while `__()` kept handing back the key.

  And the registry is a singleton built once at boot, while locale is
  per-request -- eager resolution would have served whichever locale was active
  during boot to every request afterwards.

  `tests/Feature/PackTranslationRegistrationTest.php` covers both. It reads the
  live translator (`Lang::getLoader()->namespaces()`) and the built `Package`,
  never provider source: grepping a provider proves how registration was
  written, not what the framework ended up holding -- and this mechanism turns
  on a default applied elsewhere, with no call at the call site to grep for.

  **Mutation-checked:** removing the default fails 5 of the 7; returning the
  overlay to registration time fails 3.


- **A failed SBOM download no longer takes the whole release down.** `release.yml` generates the
  SBOM before it publishes, and the Syft installer fetches its checksums from GitHub's
  release-asset CDN. On 2026-09-21 that answered `504` for about twenty minutes, failing the job
  four times *before* the publish step — so the tag existed with no release behind it, which is
  the drift the release table exists to catch, produced by the release machinery itself.

  Two changes. The step now retries once after 45 seconds, which covers a single transient `504`
  — the common case. And a second failure no longer fails the job: the release publishes without
  the asset and emits a `::warning::` naming the re-run.

  **The two failure states are not equally bad, and that asymmetry is the whole design.** A
  release missing an attachment is repaired by re-running this workflow, which re-attaches it. A
  tag with no release persists silently until a person notices. Preferring the recoverable one
  is worth the loss of "every release always carries an SBOM" as an absolute.

  `fail_on_unmatched_files: false` is now stated on the publish step. It is already the action's
  default, but the point of this change is that a missing SBOM must not fail the publish, so it
  should not rest on a default a future reader has to know.

### Security

- **The icon watcher now enforces realpath containment, matching `SvgDriver`.**
  `IconWatcherService::extractIconData()` already refused a symlinked file and an oversized
  one, but nothing checked that the resolved path stayed inside the package's own base
  directory. `SvgDriver::loadFromLocal()` has done that all along, so the read path and the
  watch path disagreed about what "inside the package" meant.

  **This closes a gap in the method, not a live escape.** `File::allFiles()` leaves Symfony
  Finder's `followLinks` off, so a symlinked directory is never descended into and the scan
  cannot presently hand `extractIconData()` a file from outside the tree. The guard earns its
  place because the method is reachable independently of `scanDiskIcons()`, and because the
  rule now sits where the read happens rather than resting on a caller that is safe by
  default. A test pins Finder's behaviour, so enabling `followLinks` later changes that
  pairing deliberately instead of silently.

  **Mutation-checked:** removing the containment block fails exactly the two new rejection
  tests -- a file reached through a symlinked directory, and a `..` traversal -- and leaves
  the other four green.

## [0.3.0] - 2026-09-21

### Removed

- **`ichava::ichava-core.make:icon-package`, the scaffolder, and the `stubs/` tree.**
  They now live in [`ichava/icon-package-scaffolder`](https://github.com/ichava/icon-package-scaffolder),
  a dev-only package. The replacement command is:

  ```bash
  composer require --dev ichava/icon-package-scaffolder
  php artisan ichava::icon-package-scaffolder.make
  ```

  **This is the whole reason for the major-series bump.** Removing a registered command is
  breaking for anything that invoked it, and a `0.x` caret pins the minor, so `^0.2` will not
  resolve to `0.3.0`: consumers move deliberately rather than by accident. Nothing else about
  core changed -- the engine, the registry, the Blade base and every other command are as they
  were in `0.2.7`.

  What core keeps is what packs actually consume. `Support\ServiceProvider`, `IconRegistry`,
  the seeder and the SVG pipeline are untouched, so an installed pack is unaffected by this
  release; only the act of *creating* a new pack moved.

  The extraction was gated on generating byte-identical output. Scaffolding from the new
  package produces the same 24 files (single-set) and 25 files (multi-variant) as this
  command did, compared with `diff -r`, so a pack scaffolded after the move is indistinguishable
  from one scaffolded before it.

- **`tests/Feature/MakeIconPackageCommandTest.php` and `tests/Feature/StubEstateParityTest.php`.**
  The parity guard moved to the new package with the stub tree it guards. It belongs beside the
  stubs: core no longer has a stub tree to be wrong about, and a guard that outlives the thing
  it guards is the failure mode that guard exists to prevent.

- **The `flag-icons` sibling clone from `tests.yml`.** It existed only so the parity guard could
  read a real pack's manifest off disk. That guard is gone from this repo, so the clone is too;
  it moved to the new package's `tests.yml` unchanged.

### Added

- **A `suggest` entry naming the replacement package and command**, so `composer suggest`
  answers "where did `make:icon-package` go" without a changelog archaeology trip.

- **`ichava/icon-package-scaffolder: ^0.1.0` as a `require-dev`**, with the VCS `repositories`
  entry it needs. Nothing in this ecosystem is on Packagist, so the constraint alone would be
  inert -- Composer would consult Packagist, get a 404, and fail to resolve.

  This landed after the package was published and tagged, not with the removal. A `require-dev`
  on a package that does not exist yet is not a dependency, it is a red build, and it would have
  blocked a deletion that was independently verified. Verified by resolving it for real against
  the VCS repositories before adding the line, rather than by assuming a tag is enough.

### Changed

- **`CommandNamingTest`'s dataset lost the scaffolder row**, since the command is gone. The
  `make:` squatting guard is kept rather than deleted with it: it asserts about the live
  registry rather than about a class this package still ships, so it now also catches a consumer
  installing a scaffolder that registers the bare name -- the case core can no longer see for
  itself.

## [0.2.8] - 2026-09-21

### Fixed

- **Search terms match literally.** `%` and `_` in a query acted as `LIKE` wildcards, widening
  results and forcing full-table scans. Both search scopes now escape them — along with the
  escape character itself — using an explicit `ESCAPE '!'` clause.

  Two drivers dictate that shape. SQLite has no default escape character, so without the
  clause the escape byte would match literally and the fix would silently not apply there.
  And the escape character cannot be a backslash: inside a MySQL string literal a backslash is
  itself an escape, so `ESCAPE '\'` is unterminated and every search fails with a 1064 syntax
  error. Caught by the MySQL and MariaDB lanes while SQLite and PostgreSQL stayed green.

- **The icon watcher rejects symlinks and oversized files.** Extraction read whatever the scan
  found, with no size cap and following symlinks — so a symlink inside a watched directory
  pointed the reader anywhere on disk, and an arbitrarily large file was read whole into memory.
  Both are now refused, using the same `max_file_size` cap as the SVG driver.

  The refusal is per file, not per scan: `extractIconData()` throws, and the loop in
  `scanPackageIcons()` catches, logs a warning and carries on. One bad file is skipped rather
  than taking the whole directory's scan down with it.

## [0.2.7] - 2026-09-21

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

- **`make:icon-package` scaffolded a forbidden `docs/README.md` index.** The standard is one
  README per repo: the index is the package README's own docs section, and a standalone
  `docs/README.md` duplicates it and then drifts out of step with it. None of the five packs in
  the estate has one, so every scaffolded pack diverged from the estate on its first commit.

  The stub is deleted and both of its lists are relocated into `README.md.stub` under a
  `## Pack-specific docs` section, matching the shape `flag-icons` already uses — the three
  pack pages, and the cross-references into the shared documentation repo. Nothing is lost;
  deleting the index without moving its links would have left `docs/variants.md`,
  `docs/customization.md` and `docs/attribution.md` scaffolded but unreachable.

  It dates to `Initial release`, the same commit as the `ichava/core: ^1.0` constraint fixed in
  0.2.6 — the ninth member of that drift set rather than a regression from it.

  **Mutation-checked, both directions:** restoring the index fails the new parity case, and
  stripping the README links fails it too. The second is the one that matters, because the
  obvious fix is a bare deletion that silently orphans three pages.

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
