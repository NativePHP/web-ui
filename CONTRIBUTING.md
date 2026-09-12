# Contributing to nativephp/web-ui

Thanks for helping out. This package is in **alpha**: the wire format, the
`WebRunner` contract surface, and the renderer registry are all still moving.
Small, focused pull requests land fastest.

## Before you start

- Read `docs/how-web-rendering-works.md` first. It explains the tree → HTML
  pipeline and where each layer's responsibility ends.
- Behaviour a component author writes against belongs in core
  (`nativephp/mobile`); this package contributes only transport and HTML. If a
  change needs both, open the core PR first and link it.
- Open an issue before starting anything that touches `src/Protocol/` (the
  snapshot and endpoint scheme) so we can agree on the wire-level change.

## Local setup

This package cannot be developed in isolation: it requires `nativephp/mobile`
and its tests run inside the core suite.

1. Clone `nativephp/mobile` and this repo as **siblings**, so this repo sits at
   `../plugins/nativephp/web` relative to core. Core's dev autoload maps
   `Native\Mobile\Edge\Web\` to that path.
2. To try changes in a real app, add this repo to the app's `composer.json` as
   a `path` repository and require `nativephp/web-ui:@dev` **from the app**.

Do **not** run `composer install` or `composer update` inside this repo. It
creates a nested `vendor/nativephp/mobile` that breaks the iOS build of any
app consuming this checkout. If you need Pint locally, install it globally:

```
composer global require laravel/pint
```

## Running tests

Tests for this package live in the core repo under `tests/Feature/Edge/` and
`tests/Unit/Edge/` (files prefixed `Web`). From the core checkout:

```
vendor/bin/pest --filter Web
```

Core's architecture tests also assert that `src/Renderer/` never imports
`Protocol` or `Bridge`. Keep it that way; the renderer is meant to be reusable
by a future desktop shell with its own transport.

## Code style

Laravel Pint, default preset. CI runs `pint --test` on every PR.

```
pint
```

## Pull requests

- One concern per PR. A rename and a behaviour change are two PRs.
- Add or update a test in the core suite for any behaviour change. Link the
  core PR from this one.
- Update `docs/` when you change how something works, not just what it does.
- Add a line to `CHANGELOG.md`.
- Mention the consuming app you verified against (browser and, if relevant,
  device) in the PR description.

## Reporting bugs

Include the `nativephp/mobile` version, the browser, and a minimal Blade
screen that reproduces the issue. For anything security-related, see
`.github/SECURITY.md` and email instead of opening an issue.
