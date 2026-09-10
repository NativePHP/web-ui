# Live updates (hot reload on the web target)

Run the watch command you already run for the simulator; the browser
follows along. Save a file and every open tab re-renders the screen it is
showing — same save, both surfaces, one command.

```bash
php artisan native:watch android   # simulator + every open browser tab
php artisan edge:watch             # browser only (no device needed)
```

Nothing runs when you aren't watching: no socket, no timer, no requests.

## Why a server at all

A phone can't see your working copy, so `native:watch` pushes files to it
over adb/devicectl and pokes it to reload. A browser has the opposite
problem — the server rendering it is already reading the files you're
editing, but HTTP can't tell it anything; it only answers questions it was
asked.

The watch command has no channel a browser could join (Android drops a
signal file on the device, iOS pokes a TCP server *on* the device), so
this package brings up its own beside it: a small Workerman WebSocket
server, `resources/hot/reload-server.php`. It watches the same source
paths and broadcasts `{"type":"reload","file":"…"}` to every connected
tab.

Installing the package is the opt-in — `WebServiceProvider` listens for
`native:watch` starting and brings the server up alongside it, then takes
it down when the command exits. No core changes.

## What a tab does with it

The page's `#edge-state` carries `hot: {ws, endpoint, token, interval}`
when a watcher is live, and nothing at all when one isn't. Given a `ws`
port the client opens a socket and makes **zero** requests until something
changes. On a reload broadcast it re-fetches the current screen as JSON
(`X-Edge-Nav: 1`) and morphs it in, exactly like an SPA navigation.

That's a **fresh `mount()`**, deliberately — the same thing the device
gets when the watcher pushes a file, and reusing the old snapshot would
carry state built by code that no longer exists. The keyed morph is what
stops it feeling like a reload: scroll position, focus, caret and running
CSS transitions all survive.

Worth knowing:

- **Broken code doesn't strand the page.** A fatal shows in the error
  overlay and the connection stays live; the next save re-renders the
  screen and dismisses it. No full page load, so you never land on a dead
  Ignition page with no runtime left to recover with.
- **User actions win.** A re-render waits for an in-flight update to land.
  While it waits it latches `hotPending`, which stands the `#[Poll]`
  timers down — on a polling screen the update queue is never idle, and a
  re-render that waited politely for an idle queue would never run at all.
- **Hidden tabs stay current.** A socket costs nothing while backgrounded,
  so unlike the polling fallback it isn't paused.
- **Restarting the watcher revives open tabs.** On disconnect the client
  retries (backing off to 10s); a refused connection to a local port
  reaches no server and costs nothing. On reconnect it re-renders once, to
  catch up on whatever changed while it was down.
- **A tab opened *before* the watcher started needs one refresh.** With no
  watcher there was no port to publish, so the page armed nothing. This is
  the price of "silent unless developing" — the alternative is speculative
  socket attempts, which Chrome logs as console errors on every try.

## The HTTP fallback

Some tabs can't hold that socket — an `https://` page refusing `ws://`, a
proxy that won't upgrade. Those fall back to polling `{prefix}/hot`, which
answers a change token:

```
1786470996 . 227 . 0
newest mtime  files  last signal
```

The mtime half is a fingerprint of the watched paths (~1.3ms for a few
hundred files); the client compares it to the token its current frame was
rendered with. It's used **only after a socket has actually failed**, and
it stops by itself when the reply says `watching: false`.

The route deliberately sits outside the `web` middleware group: a
session-backed poll would take the session lock on every tick and
serialize your real updates behind it.

## Not this feature

If you're looking at a busy network tab, check what the requests are.
`#[Poll]` screens post an update per interval per tab — the demo
`Counter` declares `#[Poll(110)]`, which is about **nine `/update` posts a
second**, and has nothing to do with live updates. Live updates over the
socket are zero requests until you save.

## Configuration

| Key | Default | Meaning |
| --- | --- | --- |
| `nativephp-web.hot` | *(unset)* | `false` disables entirely; `true` forces on without a watcher. Unset = on only while watching. |
| `nativephp-web.hot_autostart` | `true` | Whether `native:watch` brings the reload server up with it. |
| `nativephp-web.hot_port` | `3010` | Base port; the first free one from here is used. |
| `nativephp-web.hot_host` | `127.0.0.1` | Loopback by default. Widen to view the site from a phone on the LAN. |
| `nativephp-web.hot_interval` | `750` | Poll cadence (ms) for the HTTP fallback only. |
| `nativephp-web.hot_paths` | app, routes, config, database/migrations, resources/{views,fonts,css} | Watched source paths. |

Files whose mtime tracks traffic rather than source (`.sqlite`, `.log`,
`.lock`, dotfiles) are excluded from both the fingerprint and the server's
change detection. Including one puts the browser in a reload loop: the
request writes a session, the session bumps the file, the token moves.

## `edge:watch`

For web-only sessions, and for one job the device watcher can't do:
rebuilding the **built** Tailwind sheet. Once
`public/vendor/edge/app.css` exists, `WebShell` serves it instead of the
browser CDN, so a view that introduces a new utility class renders
unstyled until `edge:css` runs again. `edge:watch` reruns it on
`.blade.php` changes (`--no-css` to opt out, `--css` to force it when no
sheet exists yet, `--no-socket` to serve browsers over HTTP polling
instead). Its console mirrors `native:watch`: sticky footer, `r` to reload
every tab, `c` to clear, `q` to quit.

## State file

`storage/framework/edge-hot.json`, written by whichever watcher is live:

```jsonc
{
  "hb":   1786473209839,   // heartbeat — freshness IS the feature switch
  "ws":   3010,            // where tabs connect
  "pid":  56721,           // Workerman MASTER (signalling a worker just respawns it)
  "ts":   1786473232427,   // last change, for the HTTP fallback's token
  "file": "app/NativeComponents/Counter.php"
}
```

`hb` and `ts` are separate keys on purpose: a heartbeat must not read as a
change, or every beat would be a reload.
