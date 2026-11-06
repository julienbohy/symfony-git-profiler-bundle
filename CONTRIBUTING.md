# Contributing

Thanks for your interest in improving GitProfilerBundle! Contributions are welcome.

## Getting started

```bash
git clone https://github.com/julienbohy/symfony-git-profiler-bundle
cd git-profiler-bundle
composer install
```

## Running the tests and quality checks

```bash
vendor/bin/phpunit      # test suite
composer phpstan        # PHPStan, level max (src + tests)
composer cs             # PHP CS Fixer, dry-run with diff (@Symfony + risky rules)
composer cs-fix         # apply the coding standard
composer infection      # mutation testing of src/Git/Parser (needs pcov or xdebug)
```

The CI runs the test suite on PHP 8.3 to 8.5 against Symfony 6.4 to 8.x, validates `composer.json`
(`composer validate --strict --no-check-publish`), runs PHPStan, PHP CS Fixer and Infection on PHP 8.3, and runs the
suite against git 2.11.0 (the minimum supported version, built from source). A pull request is merged only once the
CI is green.

Test fixtures that create temporary repositories must stay compatible with git 2.11: no `git init -b`, `git switch`
or `git restore` — use `GitRepositoryInitializer` from `tests/Support`.

## Pull requests

- Branch off `main` and open a pull request against it.
- Keep the change focused; add or update tests for any behavior change.
- Follow the existing commit style — prefixes such as `feat:`, `fix:`, `refactor:`, `docs:`.
- Make sure the full suite passes locally before requesting a review.

## Reporting bugs & requesting features

Use the [issue tracker](https://github.com/julienbohy/symfony-git-profiler-bundle/issues) and pick the matching
template. A minimal reproduction (Git state, Symfony version, PHP version) makes bugs much faster to fix.
