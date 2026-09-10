# nativephp/mobile-web

**PRIVATE — do not publish.** The EDGE web render target: the same
`Route::native()` screens an app ships to phones, rendered as HTML in a
browser from the identical Blade source.

## How it plugs in

Core (`nativephp/mobile`) knows only the `Native\Mobile\Edge\Contracts\WebRunner`
contract: a browser GET on a native route resolves it from the container
(404 when unbound). This package's `WebServiceProvider` (Laravel
auto-discovered) binds it and registers the web routes. On device
(`NATIVEPHP_RUNNING`) the provider self-disables — installing this
package changes nothing about the native app.

## Layout

- `src/Renderer/` — tree → HTML (`WebRenderer`, `WebTheme`,
  `HtmlRendererRegistry` for plugin-owned element renderings). Never
  imports Protocol or Bridge (arch-tested in core's suite), so a future
  desktop shell can reuse it with its own transport.
- `src/Protocol/` — the stateless Livewire-style machinery: sealed HMAC
  snapshots, APP_KEY-hashed endpoints, uploads, the page shell.
- `src/Bridge/` — `WebBridge` + driver registry: PHP drivers or queued
  client effects standing in for device APIs.
- `src/Console/` — `edge:css` build-time Tailwind compilation, `edge:watch`
  live updates for browsers (see `docs/live-updates.md`).
- `src/Replay/` — time-travel viewer for recorded tree sessions
  (`TreeRecorder` itself lives in core; devices record too).
- `resources/js/edge-web.js` — client runtime: keyed DOM morph, event
  queue, SPA nav, effects, polls, lazy boot, uploads, virtual-list
  windowing, overlay focus trap/Escape/scroll-lock, error overlay.

## Installing in an app

Path repository + `composer require nativephp/mobile-web:@dev` — run
composer in the **consuming app**, never in this repo (see
`~/Herd/plugins/CLAUDE.md`).

Tests live in the core repo's suite (`tests/Feature/Edge/WebUpdateTest.php`
etc.) via a dev-only autoload of this package's `src/`.
