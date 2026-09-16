# Contributing to Ichava

First off, thank you for considering contributing to Ichava! It's people like you that make Ichava such a great tool.

## Code of Conduct

This project and everyone participating in it is governed by our Code of Conduct. By participating, you are expected to uphold this code.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the existing issues to avoid duplicates. When creating a bug report, please include as many details as possible:

- **Use a clear and descriptive title**
- **Describe the exact steps to reproduce the problem**
- **Provide specific examples** (code snippets, screenshots, etc.)
- **Describe the behavior you observed** and what behavior you expected
- **Include your environment details** (PHP version, Laravel version, OS, etc.)

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion, please include:

- **Use a clear and descriptive title**
- **Provide a detailed description** of the suggested enhancement
- **Explain why this enhancement would be useful**
- **List any alternative solutions** you've considered

### Pull Requests

1. Fork the repo and create your branch from `main`
2. If you've added code, add tests that cover your changes
3. If you've changed APIs, update the documentation
4. Ensure the test suite passes (`php artisan test`)
5. Make sure your code follows PSR-12 coding standards (`vendor/bin/pint`)
6. Write clear, descriptive commit messages

#### Pull Request Process

1. Update the README.md or documentation with details of changes if applicable
2. Ensure all tests pass and the coverage floor is met (see "Coverage ratchet" below).
3. Your PR will be reviewed by maintainers
4. Once approved, your PR will be merged

#### Coverage ratchet

Core CI runs `vendor/bin/pest --coverage --min=N` where `N` is a floor
the suite currently meets. The number lives in
`.github/workflows/tests.yml` next to the test command. The rules:

- **Only goes up.** Once CI is green at `N`, the next person to push
  coverage stably above `N + 5` bumps the floor by +5pp. Never lower
  the floor without a release-note explaining why.
- **Target: 80%.** That's the ecosystem-wide goal. Hardening rounds
  should focus on uncovered Services / Support / Traits paths first.
- **Excluded from coverage**: `src/Providers`, `src/Commands`,
  `src/Jobs`, `src/Listeners`, `src/View`, `src/Facades`. These are
  integration-tested via Feature suite + Testbench boot, not via
  direct unit instantiation. See `phpunit.xml.dist` source/exclude.

## Development Setup

```bash
# Clone your fork
git clone https://github.com/YOUR-USERNAME/ichava.git ichava
cd ichava

# Install dependencies
composer install

# Run tests
vendor/bin/pest

# Run code style fixer
vendor/bin/pint
```

### Local monorepo development

If you're working on `ichava/ichava` and one or more sibling icon packages (`ichava/tabler-icons`, `ichava/bundled-icons`, `ichava/metronic-icons`), or on `laranail/packager`, at the same time, the published `composer.json` references the upstream git URLs (`github.com/laranail/packager`, `github.com/ichava/ichava`). For a checked-out monorepo layout you'll want each consumer to read from your local copy instead of the network.

**Per-developer override (not committed):**

```bash
# From inside any sibling package directory (e.g, ichava-tabler-icons):
composer config repositories.local-laranail path ../../laranail/packager
composer config repositories.local-ichava-core path ../ichava
composer update
```

This adds entries to `composer.json` that should NOT be committed. To remove afterwards:

```bash
composer config --unset repositories.local-laranail
composer config --unset repositories.local-ichava-core
```

**GitHub auth for fresh installs:**

If `composer install` fails with `Failed to clone … Authentication failed`, your environment has a stale or unauthorised GitHub token. Either clear it or refresh:

```bash
# Clear stale token (use this if the repo is public)
composer config --global --unset github-oauth.github.com

# Or set a personal access token (needed for private repos)
composer config --global github-oauth.github.com <your-personal-access-token>
```

## Coding Standards

- **PSR-12**: We follow PSR-12 coding standards
- **Strict Types**: Always use `declare(strict_types=1);`
- **Type Hints**: Use type hints for all parameters and return types
- **PHPDoc**: Document all public methods with PHPDoc blocks
- **Tests**: Write tests for all new features and bug fixes
- **Security**: Always consider security implications

## Testing

We use Pest PHP for testing:

```bash
# Run all tests
vendor/bin/pest

# Run specific test file
vendor/bin/pest tests/Unit/SvgSanitizerTest.php

# Run with coverage
vendor/bin/pest --coverage
```

### Testing against a database driver

The suite defaults to in-memory SQLite so the inner loop needs no service running. Point it
at any other driver with the standard Laravel environment variables:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=ichava_test DB_USERNAME=ichava DB_PASSWORD=secret \
vendor/bin/pest
```

`DB_CONNECTION` accepts `sqlite`, `pgsql`, `mysql` and `mariadb`.

**A green SQLite run is not evidence for the other three.** It cannot be: SQLite never
reaches the PostgreSQL full-text path, does not enforce index-length limits, and spells
conflict resolution differently. The PostgreSQL search path shipped broken for exactly that
reason -- it called `jsonb_array_elements_text()` on `json` columns, which resolves to no
function, and no test had ever executed it.

CI runs the full matrix on every pull request: SQLite on PHP 8.4 and 8.5, then PostgreSQL 17,
MySQL 8.4 and MariaDB 11.4 as service containers. If you touch anything that reaches the
database, run at least one server driver locally before opening the PR.


## Commit Message Guidelines

We follow [Conventional Commits](https://www.conventionalcommits.org/):

```
feat: add icon preloading support
fix: resolve path traversal vulnerability
docs: update installation instructions
test: add svg sanitizer security tests
refactor: consolidate cache management
perf: optimize icon discovery
```

## Project Structure

```
ichava/
├── src/
│   ├── Commands/      # Artisan commands (ichava:database, ichava:cache, etc.)
│   ├── Constants/     # Shared constants
│   ├── Contracts/     # Interfaces
│   ├── Data/          # DTOs (IconData, IconPath)
│   ├── Drivers/       # SvgDriver (file loading + rendering)
│   ├── Enums/         # PHP enums
│   ├── Events/        # Domain events
│   ├── Exceptions/    # IchavaException + factories
│   ├── Facades/       # IchavaFacade
│   ├── Http/          # Middleware, Controllers (API + Web)
│   ├── Ichava.php     # Facade backing class
│   ├── Jobs/          # Queue jobs (SeedIconsJob)
│   ├── Listeners/     # Event listeners
│   ├── Models/        # Eloquent models (Icon, IconTerm)
│   ├── Providers/     # IchavaServiceProvider
│   ├── Services/      # IconRegistry, IchavaLogger, caches, etc.
│   ├── Support/       # ServiceProvider base, IchavaRegistrar, helpers
│   ├── Traits/        # Reusable traits
│   └── View/          # Blade components
├── config/          # ichava.php
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── resources/
│   ├── assets/svg/    # Bundled icon sets (test-icons, ui-icons)
│   ├── lang/
│   └── views/
├── routes/          # web.php, api.php
└── tests/
    ├── Feature/
    ├── Integration/
    └── Unit/
```

## Questions?

Feel free to open an issue with the "question" label or reach out to the maintainers.

---

Thank you for contributing! 🎉
