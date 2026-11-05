# Contributing

Thanks for your interest in improving GitProfilerBundle! Contributions are welcome.

## Getting started

```bash
git clone https://github.com/julienbohy/symfony-git-profiler-bundle
cd git-profiler-bundle
composer install
```

## Running the tests

```bash
vendor/bin/phpunit
```

The CI runs the test suite on PHP 8.3 and 8.4 and validates `composer.json`
(`composer validate --strict --no-check-publish`). A pull request is merged only once the CI is green.

## Pull requests

- Branch off `main` and open a pull request against it.
- Keep the change focused; add or update tests for any behavior change.
- Follow the existing commit style — prefixes such as `feat:`, `fix:`, `refactor:`, `docs:`.
- Make sure the full suite passes locally before requesting a review.

## Reporting bugs & requesting features

Use the [issue tracker](https://github.com/julienbohy/symfony-git-profiler-bundle/issues) and pick the matching
template. A minimal reproduction (Git state, Symfony version, PHP version) makes bugs much faster to fix.
