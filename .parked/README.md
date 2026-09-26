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
