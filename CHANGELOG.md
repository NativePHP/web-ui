# Changelog

All notable changes to `nativephp/web-ui` are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

First public alpha, installable from `main`. Renders `Route::native()`
screens as HTML in a browser from the same Blade source the mobile app
ships.

- Stateless Livewire-style protocol: sealed HMAC snapshots, APP_KEY-hashed
  endpoints, uploads with progress.
- Client runtime (`edge-web.js`): keyed DOM morph, event queue, SPA nav,
  effects, polls, lazy boot, virtual-list windowing, overlay focus trap.
- `edge:css` build-time Tailwind compilation and `edge:watch` live updates.
- Browser drivers standing in for device APIs, with per-action loading and
  dirty tracking.
- Time-travel replay viewer for recorded tree sessions.
