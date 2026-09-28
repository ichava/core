# Parked code

Code with no caller, moved out of `src/` rather than deleted so it can be restored from a file instead of from history.

Nothing here is autoloaded, tested or shipped: `composer.json` maps only `src/`, `.gitattributes` marks `/.parked` `export-ignore`, and Pint and the test suite never look inside a dot-directory. A parked helper that gains a caller moves back into `src/` in its own commit, with a test.

## `Commands/BaseCommandHelpers.php`

Moved verbatim from `src/Commands/BaseCommand.php` on 2026-09-26, before `BaseCommand` was rebuilt on `laranail/console`'s widgets. Each caller count was measured that day with:

```bash
grep -rE "(->|::)NAME\(" src tests
```

| Original path | Name | Callers | Why parked |
|---|---|---:|---|
| `src/Commands/BaseCommand.php` | `displayBoxedHeader` | 0 | No command used it; `note()` is called directly. |
| `src/Commands/BaseCommand.php` | `displayWarning` | 0 | No command used it; `warning()` is called directly. |
| `src/Commands/BaseCommand.php` | `displayOutro` | 0 | A one-line alias for `outro()`. |
| `src/Commands/BaseCommand.php` | `displayTable` | 0 | A one-line alias for `table()`. |
| `src/Commands/BaseCommand.php` | `displayKeyValues` | 0 | No command used it. |
| `src/Commands/BaseCommand.php` | `displayStatusRow` | 0 | Superseded by `laranail/console`'s `CheckList`. |
| `src/Commands/BaseCommand.php` | `confirmDestructive` | 0 | Superseded by `laranail/console`'s `ConfirmsDestructiveActions`, whose method of the same name it would otherwise shadow. |
| `src/Commands/BaseCommand.php` | `confirmOperation` | 0 | No command used it. |
| `src/Commands/BaseCommand.php` | `withProgress` | 0 | No command used it; `progress()` is called directly. |
| `src/Commands/BaseCommand.php` | `displayDivider` | 0 | No command used it. |
| `src/Commands/BaseCommand.php` | `outputIfNotQuiet` | 0 | No command used it. |
| `src/Commands/BaseCommand.php` | `outputIfVerbose` | 0 | No command used it. |
| `src/Commands/BaseCommand.php` | `tryWithSpinner` | 0 | No command used it. |
| `src/Commands/BaseCommand.php` | `displayKeyValue` | 1 | Its one caller is `displayKeyValues`, parked above; parked with it so that helper still reads whole. |
| `src/Commands/BaseCommand.php` | `withSpinner` | 1 | Its one caller is `tryWithSpinner`, parked above; parked with it for the same reason. |

The last two counts are 1 because the grep sees the call inside the parked caller; both were 0 outside it.

## Seeding, support and service members

Parked on 2026-09-26, in the same change that moved seeding onto
`laranail/package-tools` and `laranail/db-tools`. Measured with the same grep
over `src` and `tests`, and additionally over `origin/main` of every other ichava
package (icon-browser, the five packs, the scaffolder and its stubs,
react-browser). None of them references any of these.

| Original path | Name | Callers | Parked to | Why |
|---|---|---:|---|---|
| `src/Support/JobProgressTracker.php` | whole class | 0 writers | `Support/JobProgressTracker.php` | Its writers were never called, so `job-status` read progress nothing wrote. Seeding now writes package-tools' `SeederRunTracker`, and `job-status` reads that. |
| `src/Enums/CacheDriver.php` | whole enum | 0 (tests only) | `Enums/CacheDriver.php` | Referenced only by its own test. |
| `src/Support/Seeder/IchavaSeeder.php` | `getStatus`, `cancel`, `displayJobInstructions` | 0 | `Support/Seeder/IchavaSeederMethods.php` | No caller. `getStatus`/`cancel` wrap `Bus::findBatch()`, which a caller can use directly. |
| `src/Support/Helpers.php` | `sanitizePath`, `ICHAVA_PGSQL_LANGUAGES`, `ICHAVA_PGSQL_DEFAULT_LANGUAGE` | 0 (tests only) | `Support/HelpersMembers.php` | No caller in any package. |
| `src/Support/PathResolver.php` | `resolveConfigOrDefault`, `ensureFile` | 0 | `Support/PathResolverMethods.php` | No caller. `PathResolver::resolvePackagePath()`, which five packs, icon-browser and the scaffolder stub use, is unchanged. |
| `src/Services/InformationService.php` | `formatFileSize` | 0 | `Services/InformationServiceMethods.php` | No caller; `FileSize::format()` is the one byte formatter. |
| `src/Services/DatabaseOperationsService.php` | `countIconsInDirectory` | 0 | `Services/DatabaseOperationsServiceMethods.php` | No caller (the live callers use `IconRegistry`'s, which delegates to `CountSvgFiles`). |

## `Services/IconBrowserServiceMethods.php`

Moved verbatim from `src/Services/IconBrowserService.php` on 2026-09-28, after
[core#104](https://github.com/ichava/core/pull/104) rebuilt `buildIconTree()` on the database
taxonomy. Measured that day with `grep -rE "(->|::)NAME\(" src tests`, counting calls from
outside each member's own body, and across the estate's `origin/main` (icon-browser, the five
packs, the scaffolder, react-browser), which had none:

| Original path | Name | Callers | Why parked |
|---|---|---:|---|
| `src/Services/IconBrowserService.php` | `getPackageConfig` | 0 | Only the old disk-walking tree read it; labels come from the registry now. |
| `src/Services/IconBrowserService.php` | `scanFolderTree` | 0 | Replaced by the database taxonomy in #104. Called only itself. |
| `src/Services/IconBrowserService.php` | `countSvgFilesRecursive` | 0 | Called only by `scanFolderTree`. |
| `src/Services/IconBrowserService.php` | `countSvgFilesInDirectory` | 0 | No caller before #104 either. |
| `src/Services/IconBrowserService.php` | `countIconsInFolder` | 0 | No caller before #104 either. |

`tests/IconTreeTest.php` moved with them: it only exercised `scanFolderTree` by reflection.

The tests that covered only parked code moved with it, into `tests/`: the
`CacheDriver` enum cases and `Helpers::sanitizePath`. Whole classes keep their
original namespace, so restoring one is a `git mv`.
