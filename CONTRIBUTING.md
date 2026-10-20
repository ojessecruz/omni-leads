# Contributing to Omni Leads

Thanks for your interest in contributing! Bug reports, fixes, new lead sources, translations and docs are all welcome.

## Before you start

- **Bugs:** open an issue with steps to reproduce, what you expected and what happened.
- **Features and larger changes:** open an issue first to discuss the idea. This avoids work on something that won't be merged.
- **New dependencies:** propose them in an issue before adding them to `composer.json` or `package.json`.
- **Security issues:** don't open a public issue. Report them privately through GitHub's *Security › Report a vulnerability*.

## Development setup

Follow [Running locally](README.md#running-locally) in the README. Always run `php`, `composer`, `npm` and `artisan` through `./vendor/bin/sail`.

## Workflow

1. Fork the repository and create a branch from `main` (e.g. `feat/hubspot-source`, `fix/csv-export-encoding`).
2. Keep each pull request focused on one change. Small PRs get reviewed faster.
3. Make sure the quality checks pass (below).
4. Open a pull request against `main` describing what changed and why. Add screenshots for UI changes.

`main` is protected: every pull request needs the `tests` check to pass and an approval from the maintainer ([CODEOWNERS](.github/CODEOWNERS)) before it can be merged. Pushing new commits after an approval requires a new review.

## Quality checks

```bash
./vendor/bin/sail artisan test --compact
./vendor/bin/sail bin pint --dirty
./vendor/bin/sail php vendor/bin/phpstan analyse --memory-limit=1G
```

CI runs the same checks on every pull request.

## Code conventions

The full conventions live in [`CLAUDE.md`](CLAUDE.md) and [`.ai/rules`](.ai/rules) (one file per area, e.g. `livewire.md`, `migrations.md`, `tests.md`). Read the rule for the area you're changing. The essentials:

- **Business rules go in `app/Domains`**, as plain PHP with no framework dependencies, so they can be tested without a database or queue. Jobs, listeners and Livewire components stay thin and call the domain.
- **New lead sources** are drivers: see [Code layout](README.md#code-layout) in the README.
- **Tests** use Pest. Every change needs tests, and every bug fix needs a test that fails without it. Test descriptions follow the existing style: `test('descrição em português')`.
- **Code comments** are in English.
- **Translations:** every new `__()` string goes into both `lang/pt_BR.json` and `lang/es.json` (English is the key). `LocaleTest` fails otherwise.
- **Views** use the design tokens from `resources/css` instead of raw colors, and every `@foreach` has a `wire:key`.

## License

By contributing, you agree that your contributions are licensed under the same license as the project.
