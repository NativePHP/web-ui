# How EDGE components get rendered for the web

This document explains, end to end, how the same `Route::native()` screens that
ship to phones end up as interactive HTML in a browser. It's written for
someone new to the codebase — no prior knowledge of the web target assumed.

---

## 1. The big picture

On a device, an EDGE screen works like this: your `NativeComponent` (a PHP
class with a Blade view) renders into an **element tree** — a plain PHP array
describing every element (`column`, `text`, `button`, …) with its props,
children, and callback ids. The native runtime (SwiftUI on iOS, Compose on
Android) receives that tree and draws real native widgets.

The web target does the exact same first half — boot the component, run the
render, produce the element tree — but then hands the tree to a different
back end: instead of native widgets, **`WebRenderer` walks the tree and emits
HTML** (styled with Tailwind), and a small JavaScript runtime
(`edge-web.js`) makes it interactive by POSTing user events back to the
server, Livewire-style.

So the mental model is:

```
                     ┌────────────────────────┐
  Blade / PHP screen │ NativeComponent render │  (identical on every target)
                     └───────────┬────────────┘
                                 │  element tree (plain array)
              ┌──────────────────┼──────────────────┐
              ▼                  ▼                  ▼
        SwiftUI (iOS)      Compose (Android)   WebRenderer (this package)
                                                    │
                                                    ▼
                                               HTML string
                                                    │
                                                    ▼
                                        browser + edge-web.js runtime
```

**One important property: the server is stateless.** No component instance
lives between requests (unlike a device, where the screen object stays in
memory). Every request boots a fresh component, replays state from a signed
"snapshot" the browser echoes back, does its work, renders once, and throws
the instance away. This is the same trick Livewire uses.

---

## 2. Who does what (file map)

| File | Role |
|---|---|
| `src/WebServiceProvider.php` | Wires everything into Laravel. Registers routes and bindings. **Self-disables on device.** |
| `src/Protocol/WebScreenRunner.php` | The orchestrator. Handles the initial GET and every `/update` POST: boot → hydrate → dispatch → render. |
| `src/Protocol/EdgeSnapshot.php` | Seals/unseals component state with an HMAC so the browser can't tamper with it. |
| `src/Protocol/WebShell.php` | Builds the full `<html>` page: Tailwind, theme tokens, fonts, the state blob, and the inlined JS runtime. |
| `src/Protocol/EdgeEndpoint.php` | Derives per-app secret route paths from `APP_KEY` (e.g. `/_edge-3fa9c1d2/update`). |
| `src/Renderer/WebRenderer.php` | **The core of this doc**: element tree → HTML string. |
| `src/Renderer/HtmlRendererRegistry.php` | Lets UI plugins register HTML renderers for their own element types. |
| `src/Renderer/WebTheme.php` | Converts the mobile-ui Theme palette + fonts into CSS-usable values. |
| `src/Bridge/WebBridge.php` | Stands in for device APIs (dialogs, geolocation, …) via PHP drivers or queued client effects. |
| `src/Console/EdgeCssCommand.php` | `php artisan edge:css` — build-time Tailwind compile for production. |
| `resources/js/edge-web.js` | The browser runtime: event capture, POSTing updates, DOM morphing, SPA nav, effects, polls, virtual-list windowing, overlay focus management, error overlay. |

Architecture rule (enforced by tests in core): **`Renderer/` never imports
`Protocol/` or `Bridge/`**. The renderer is pure "tree in, HTML out", so a
future desktop shell could reuse it with a completely different transport.

---

## 3. The initial page load (a browser GET)

Core's router only knows one thing about the web: the
`NativeRouteFallback` contract. When a plain browser GETs a native route
(and we're not running on a device), core resolves that contract from the
container — this package's `WebServiceProvider` binds it to
`WebScreenRunner`. If this package isn't installed, that resolution fails
and the route 404s. That's the whole integration seam.

`WebScreenRunner::screen()` then does, in order:

1. **Resolve the route.** `NativeRouter::resolve($path)` gives back route
   params and the layout, same as on device.

2. **Boot the component** (`boot()`):
   - Enables a `WebBridge` so any native API call the screen makes
     (`Dialog::toast()`, `Geolocation::get()`, …) has somewhere to go.
   - Registers three **passthrough attributes** with core's element
     collector: `class` → `web_class`, `style` → `web_style`, `web` →
     `web_icon`. This is how your raw Tailwind classes survive into the
     tree — on device those attributes are parsed into native styling, but
     the *raw strings* also ride along in the props so the web renderer can
     hand them straight to the browser.
   - Sets the platform to Android. Sounds odd, but it's deliberate: Android
     icon names are Material Symbols names, and the web page loads the
     Material Symbols font — so `:android` icon variants render for free.
   - Instantiates your component class, sets params, attaches a router
     (so `currentUri()`-driven chrome like tab highlighting works), and
     calls `mount()`.

3. **Render one frame.** `renderTree()` runs the component's normal render
   cycle (`renderToElement()`) and calls `Element::toArray()` — producing
   the wire tree: nested arrays of
   `{id, type, props, children, on_press, layout, style, flags}`.

4. **Tree → HTML.** `WebRenderer::render($tree)` (section 4 below).

5. **Build the state blob.** Component class, URI, params, the **sealed
   snapshot** (section 6), advisory poll intervals, and the lazy flag.

6. **Wrap it in a page.** `WebShell::page()` returns a full HTML document:

```html
<!doctype html>
<html>
<head>
  <!-- Tailwind: built stylesheet if `edge:css` ran, browser-JIT CDN otherwise -->
  <!-- theme palette as --color-theme-* CSS variables (light + dark) -->
  <!-- Material Symbols font, @font-face rules for resources/fonts -->
</head>
<body>
  <div id="edge-root">   ← the rendered HTML lives here </div>
  <script type="application/json" id="edge-state"> {…state…} </script>
  <script> /* edge-web.js, inlined */ </script>
</body>
</html>
```

The browser paints it instantly — it's just server-rendered HTML.

---

## 4. WebRenderer: element tree → HTML

`WebRenderer::render(array $node)` is one big recursive `match` on the
node's `type`. Each element type maps to an HTML emitter:

```php
'column'    => static::container($node, 'div', 'flex flex-col', $ctx),
'row'       => static::container($node, 'div', 'flex flex-row items-center', $ctx),
'text'      => static::text($node, $p, $ctx),
'button'    => static::button($node, $p),
'toggle'    => static::toggle($node, $p),
// …about 50 more…
```

Three lookup tiers, checked in order:

1. **Plugin renderers first.** If `HtmlRendererRegistry::resolve($type)`
   returns a closure, it wins — even over a built-in type. This mirrors how
   native renderer registries work: a UI plugin owns its element vocabulary
   on *every* render target (section 9).
2. **Built-in `match` arms** for core types.
3. **Unknown fallback**: an unknown type *with children* renders as a plain
   `flex flex-col` div (so layout survives); an unknown *leaf* renders
   nothing (it's presumably a mobile-only concept like a haptic marker).
   Again identical philosophy to the native registries.

### What every emitted element carries

Look at a typical output:

```html
<button type="button"
        data-edge-id="7"
        data-edge-press="184292001"
        aria-label="Save"
        class="inline-flex items-center … bg-theme-primary text-theme-on-primary px-4 py-2.5 rounded-lg mt-4">
  Save
</button>
```

- **`data-edge-id`** — the node's tree id, from `idAttr()`. Every element
  gets one. It's the **morph key**: when the server sends fresh HTML after
  an update, the JS runtime matches old and new nodes by this id so DOM
  nodes are *reused* rather than replaced (focus, caret position, CSS
  transitions, and scroll positions all survive). See section 7.
- **`data-edge-press="184292001"`** — a **callback id**. This is not a DOM
  thing; it's the server-side id of the PHP callback (your
  `native:press="save"` handler). The JS runtime sends this number back on
  click, and the server dispatches the matching method. There's a whole
  family of these: `data-edge-change`, `data-edge-toggle`,
  `data-edge-checkbox`, `data-edge-slider`, `data-edge-select`,
  `data-edge-radio`, `data-edge-tab`, `data-edge-submit`,
  `data-edge-dismiss`, `data-edge-long-press`, `data-edge-navigate`,
  `data-edge-back`.
- **`aria-label`** — the element's `a11y_label` prop (what becomes
  `accessibilityLabel` / `contentDescription` on device) passes straight
  through, and it rides on `idAttr()` so every emitter gets it for free.
- **`class`** — two halves concatenated by `cls()`:
  the emitter's *base classes* (the arm's opinion of what a button looks
  like: theming, padding, flex) plus the **author's raw Tailwind string**
  (`web_class` — whatever `class="mt-4"` you wrote in Blade).

### The `web_class` passthrough (why raw Tailwind works)

This is the "React Native Web" idea: your Blade `class="..."` string isn't
parsed into anything on the web target — it's shipped to the browser as-is
and *real Tailwind* resolves it (either the browser-JIT build in dev or the
`edge:css`-compiled sheet in production). Before passthrough,
`webClass()` does two small rewrites:

1. **`web:` variant.** Just like `ios:` / `android:`, you can write
   `web:max-w-lg` — native parsers drop it as an unknown platform, and here
   the prefix is stripped so the class applies. (In the `?_platform=mobile`
   dev preview the whole class is dropped instead, mirroring device.)
2. **Bracket units.** Native arbitrary values are unitless
   (`w-[300]` means 300dp). CSS needs a unit, so `w-[300]` becomes
   `w-[300px]` — *except* for prefixes that are genuinely unitless in real
   Tailwind (`z-[10]`, `opacity-[0.5]`, `scale-[1.2]`, … see the
   `UNITLESS` constant).

There's also **`web_style`** — a raw `style="..."` passthrough. It exists
for *runtime-computed* values like `style="width: {{ $battery }}%"`. Why not
`w-[{{ $battery }}%]`? Because the production CSS build (`edge:css`) scans
your *source files* for class names at build time — a class generated at
runtime would never be in the compiled sheet. Inline styles sidestep that.

### The Tailwind build: `php artisan edge:css`

Two modes, decided by one file's existence:

- **No built sheet** (`public/vendor/edge/app.css` absent): the page
  loads the Tailwind browser CDN and JIT-compiles classes live. Every
  class always works; there's an external CDN dependency and a runtime
  compile cost. This is the right mode for local development.
- **Built sheet present**: `php artisan edge:css` scans the app's
  source for class names and compiles a static stylesheet (theme
  tokens included); the shell serves it with an mtime cache-buster and
  skips the CDN entirely. This is the production mode.

**The trap**: the built sheet is a snapshot. Add a new Tailwind class
to a blade afterwards and the markup is right but the CSS rule doesn't
exist — the element renders silently unstyled (the class attribute is
there, so string-matching tests still pass). Nothing warns you.
**Re-run `edge:css` after view or theme changes, or delete the built
sheet during development** to stay on the JIT. Same reason
runtime-computed values need `style=""` (`web_style`) instead of
interpolated classes: the build-time scan can never see them.

### Theming

Emitters never hardcode colors. They use `bg-theme-primary`,
`text-theme-on-surface`, `border-theme-outline-variant`, etc. Those resolve
to `--color-theme-*` CSS variables that `WebShell` emits in the `<head>`,
sourced from the app's mobile-ui `Theme` (with a Material-ish fallback when
mobile-ui isn't installed). Dark mode is a `prefers-color-scheme` media
query overriding the same variables — which is exactly why emitters avoid
*inline* colors: an inline hex would be the light-mode value and would
never flip in dark mode. (The one exception: absolutely-positioned nodes
like FABs, where native layout coordinates and explicit colors are
translated to inline CSS via `absoluteCss()`.)

### Icons

`<native:icon name="…">` renders as a **Material Symbols** ligature span —
Android icon names are already Material names and pass through; SF Symbols
(dotted names like `chevron.down`) go through a best-effort translation
table (`SF_TO_MATERIAL`), with unknown names degrading to a neutral circle
rather than giant raw ligature text. If the author wrote `web="arrow-up"`,
that names a **heroicon** instead and the SVG is inlined from
`blade-ui-kit/blade-heroicons` when the app has it installed — the
web-native icon set wins over the Material fallback.

### Context (`$ctx`) — how parents talk to distant children

The renderer is stateless recursion, but some children need parent info:
a `radio` needs its group's `name`/selected value/callback, a `tab` needs
its index and which tab is selected. Parents put that in the `$ctx` array
they pass down (`$ctx['radio']`, `$ctx['tabs']`), children read it out.
That's the entire "state management" of the renderer.

### Escaping

**Everything** user-visible goes through `WebRenderer::e()`
(`htmlspecialchars`). If you write an emitter and interpolate a prop
without `e()`, you've written an XSS. The one deliberate raw spot is
inlined heroicon SVG (trusted package files on disk).

---

## 5. The interactivity loop (what happens when you click)

The page has no framework — just `edge-web.js` (inlined, ~800 lines, zero
dependencies). It works by **event delegation**: single listeners on
`document` for `click`, `change`, `input`, `keydown`, `pointerdown` that
look for the nearest `data-edge-*` ancestor of the event target. Because
delegation happens at the document level, morphed-in HTML needs no
re-binding — new elements just work.

A click on our Save button becomes:

```json
POST /_edge-3fa9c1d2/update
{
  "component": "App\\Native\\SettingsScreen",
  "uri": "/settings",
  "params": {},
  "snapshot": { "data": { …sealed state… }, "checksum": "hmac…" },
  "event": { "type": 0, "callback_id": 184292001 }
}
```

(`type: 0` is `PRESS` — the numeric event types mirror
`NativeComponent::dispatch()`'s wire enums; text changes are type 2,
toggles 3, and so on.)

Server side, `WebScreenRunner::update()`:

1. **Reads the raw body** (`$request->getContent()`, *not* `input()` —
   Laravel's `TrimStrings`/`ConvertEmptyStringsToNull` middleware would
   corrupt snapshot values).
2. **Unseals the snapshot first.** Everything security-relevant
   (component class, uri, params, props, callback maps) lives *inside* the
   HMAC-sealed unit; the top-level POST keys are ignored as untrusted
   echoes. Any tamper → 419 before anything else happens.
3. **Boots a fresh component** — but *without* calling `mount()`. The
   snapshot already carries the state `mount()` produced on the first
   request; re-running it would re-charge its cost (queries, API calls) on
   every click.
4. **Rehydrates the public props** from the snapshot onto the instance
   (`applySnapshot()`), reversing the typed dehydration (section 6).
5. **Rebuilds the callback registry** by re-registering each expression
   from the snapshot's `callbacks` map. This works without a render frame
   because callback ids are **content-addressed** — fnv1a32 hashes of the
   expression string — so re-registering `"save"` reproduces exactly the
   id baked into the client's DOM. No render-order dependence.
6. **Dispatches the event** through the component's normal
   `dispatch()` — your `save()` method runs, mutates public props, maybe
   sets a navigation intent.
7. **Renders one frame** and responds:

```json
{
  "html": "…entire fresh screen HTML…",
  "snapshot": { …new sealed state… },
  "effects": [ …queued client effects… ],
  "polls": [ …refreshed poll intervals… ]
}
```

(or `{"redirect": "/somewhere"}` / `{"back": true}` if the handler
navigated.)

Back in the browser, the runtime stores the new snapshot and **morphs** the
fresh HTML into `#edge-root`.

### The keyed morph

`swap()` doesn't do `innerHTML = html` — that would nuke focus, caret
position, scroll offsets, and any CSS transition mid-flight. Instead
`morphChildren()` reconciles old DOM against new HTML level by level:

- Nodes are matched **by `data-edge-id`** (the tree id from section 4);
  unkeyed nodes fall back to positional matching against compatible
  siblings.
- Matched nodes are *reused*: attributes are diffed and patched
  (`syncAttrs`), children recursed, and live form state mirrored
  (`syncFormState` — with the rule that a currently-*focused* input's value
  is never overwritten, so the server can't fight you mid-keystroke).
- New nodes are adopted wholesale; old nodes that matched nothing are
  removed.
- A safety net re-focuses by key (and restores caret + scroll) if the
  focused element did get replaced.

### The event queue

Only **one update request is in flight at a time**. Further events queue
FIFO and flush in order — so a fast toggle-toggle-click can't arrive out of
order or race each other. Rapid text changes for the same input coalesce in
the queue (only the latest value matters), on top of a client-side debounce
(150 ms for `live` mode, configurable for `debounce` mode; `blur`/`lazy`
modes only send on the change event). While a request is in flight,
`<html>` carries `data-edge-busy`, the originating button gets `disabled`,
and non-input origins get `data-edge-loading` — all hooks you can style.

---

### When an update fails

The client reads the error body and shows the most useful thing it can:
Laravel's debug JSON (`message`, `exception`, `file:line`, trace)
rendered as a readable overlay; an HTML error page (proxy 502, nginx 413)
shown in a **sandboxed iframe** (no scripts run); a production-shaped
`{message}` as a toast. Only one overlay at a time — a failing poll timer
re-fires every interval, and the guard keeps it from stacking overlays.
Escape or the close button dismisses and restores focus. 419
(tampered/stale seal or expired CSRF) still clears the queue and reloads,
since a fresh page fixes both.

## 6. The sealed snapshot (how a stateless server keeps state)

Since no component instance survives between requests, the state has to
live in the browser between clicks — but the browser is untrusted. The
solution is Livewire's: hand the browser an **opaque, signed blob** it can
only echo back verbatim.

`EdgeSnapshot::seal()` wraps:

```
{ data: {
    component:       FQCN of the screen,
    uri, params:     route identity,
    props:           typed-dehydrated public properties,
    callbacks:       screen-owned  expression → id map,
    childCallbacks:  child-component-owned expression → id map,
    nav:             navigation configs,
  },
  checksum: HMAC-SHA256(canonical-JSON(data), APP_KEY) }
```

Details worth knowing:

- **One checksum over everything** — no field can be tampered with
  independently. Change one prop byte and `unseal()` aborts 419.
- **Canonicalization** guards against innocent JSON drift through the
  browser: keys are sorted (JS reorders integer-like object keys) and
  integral floats collapse to ints (JS serializes `72.0` as `72`).
- **Typed props.** Scalars/arrays pass through; backed enums, datetimes,
  Eloquent models, and Collections become tagged `{__edge: …}` markers
  that `hydrate()` reverses — against **hard class checks**, so a
  snapshot can never instantiate an arbitrary class (defense in depth on
  top of the HMAC).
- **Eloquent models travel as key + class only** (`{__edge:'model',
  class, key}`), never as attributes — no hidden-attribute leak, and
  `hydrate()` refetches fresh from the DB on every request. Two
  consequences to internalize: *unsaved* changes to a model prop don't
  survive a request (persist before render or keep raw attributes in an
  array prop — unsaved models throw on dehydrate), and a row deleted
  between requests throws a clear error. Homogeneous
  `Eloquent\Collection` props travel as one `{__edge:'models', class,
  keys}` marker and refetch with a single `findMany`, original order
  preserved, deleted rows silently dropped (the item is just gone next
  frame).
- **`#[Locked]` props** need no special web handling — the seal *is* the
  enforcement, since the client can't modify anything inside it.
- The 419 path is graceful client-side: the runtime clears its queue,
  shows a toast, and reloads the page (a fresh page fixes both a stale
  seal and an expired CSRF token).

### Child components

Nested `<native:*>` component tags are the one wrinkle: children mount
*during* a render frame and register callbacks in their **own** per-child
registries, not the screen's. So the snapshot also carries a merged
`childCallbacks` map, and on update, if the event's callback id isn't
screen-owned but *is* in that map, the runner performs one extra "mounting
render" (output discarded) so the children exist and have re-registered
their ids — then dispatches normally. Content-addressed ids are what make
this correct: each child re-derives exactly the ids already baked into the
client's DOM.

---

## 7. The bridge: device APIs in a browser

Screens call native APIs (`Dialog::alert()`, `Geolocation::get()`, …).
On web those calls hit `WebBridge`, which resolves each method through
(in precedence order):

1. **Scripted responses** (`respondTo()`) — used by tests and by the
   `?_platform=mobile` preview (which scripts `Device.GetInfo` to claim
   it's an Android device).
2. **PHP drivers** (`WebDriverRegistry`) — run server-side and return the
   native envelope directly (e.g. SecureStorage backed by the encrypted
   session).
3. **Client effects** — the method is queued as `{method, params}` into a
   per-request buffer, drained into the response's `effects` array, and
   *performed in the browser* by `edge-web.js`. Built-in client drivers
   cover `Dialog.Alert`/`Toast`, `Device.Vibrate`, `Browser.Open*`,
   `Share.Url`, and the `Geolocation.*` family. Apps can add or override
   drivers via `window.EdgeDrivers['My.Method'] = async (params, ctx) => {…}`.
4. **Nothing registered** — recorded as unhandled, answers `null`, and
   `nativephp_can()` reports `false` so screens can feature-detect.

**Async results** (a dialog button press, a geolocation fix, a picked
file) come back the same way they do on device — as a native *event*, not
a return value: the queued effect carries a correlation `id` and the
event's FQCN; the client driver performs the browser API, then dispatches
`{type: 'native_event', event, payload}` through the normal update queue.
`WebScreenRunner::update()` routes it to the component's `#[On]`
listeners (the same path as the device loop's `EVENT_NATIVE`) and
re-renders. Two web-specific rules apply:

- The payload is **client-authored** — listeners must treat its fields
  with form-input trust.
- **File paths are verified, not trusted**: `EdgeUpload::
  resolvePayloadPaths()` rewrites `{path, signature}` pairs (top-level
  and in `files` entries) to real absolute temp paths only when the
  HMAC verifies, and nulls everything else. A listener therefore reads
  a genuinely usable path on both targets, and a browser can never
  point one at an arbitrary server file.

**Camera on web**: `Camera::pickImages()` / `getPhoto()` /
`recordVideo()` are client effects — the browser opens a file picker
(`capture="environment"` makes mobile browsers open the camera), uploads
through `window.EdgeUpload`, and reports `MediaSelected` / `PhotoTaken`
/ `VideoRecorded` (or the matching cancel events) with signed
descriptors. The fluent chain works on web in ALL its forms — including
the everything-in-one-method closure style:

```php
Camera::pickImages('image')->onSuccess(function (MediaSelected $media) {
    $this->attachedImage = $media->files[0]['path'] ?? null;
});
```

The binding never needs to survive the request boundary, because
`fireNativeCallback` rebinds every closure to the LIVE component (full
class scope) at fire time — so `NativeCallbacks` rebinds `$this`-using
closures to a throwaway carrier, serializes code + captured `use` vars
into the durable cache tier, and the next request's fresh component
picks it up. Method-name strings (`->mediaSelected('onMedia')`) and
`#[On(MediaSelected::class)]` listeners work too. Requirements for the
closure form on web: captured `use` vars must be serializable, the
closure can't live in eval'd code, and the app needs a persistent cache
store (file/redis — not `array`).

---

## 8. The extra protocol features

- **SPA navigation.** Any `data-edge-navigate` click (tabs, nav items,
  `top_bar_action` urls) is fetched with an `X-Edge-Nav: 1` header; the
  server answers `{html, state, title}` instead of a full page, and the
  client morphs it in, swaps its whole in-memory state, and pushes a
  history entry (with View Transitions when the browser supports them).
  Any failure — non-EDGE route, auth redirect off-app, network error —
  falls back to a normal full page load. Back buttons (`data-edge-back`)
  use real browser history.

- **Polling (`#[Poll]` / `native:poll`).** The server advertises the
  distinct intervals in an advisory `polls` list (deliberately *outside*
  the seal — a forged tick can only run methods you already declared to
  run on a timer). The client arms one timer per interval; each tick
  enqueues a `{type: 'poll'}` protocol event through the normal queue (so
  polls never race user events), the server runs every `#[Poll]` method
  and re-renders. Ticks are *skipped*, not stacked, while a request is in
  flight, and timers stop while the tab is hidden. The interval list
  refreshes after every update, because Blade `native:poll` timers are
  conditional markup.

- **Virtual lists (`<native:virtual-list>`).** PHP only ever emits the
  rows inside `[window_from..window_to]`; on web, two aggregate spacer
  divs sized `estimated_row_height × hidden-row-count` stand in for the
  off-window slots, so the scrollbar reflects the full list and scroll
  offsets map to absolute indices. The container carries the windowing
  contract as `data-edge-vl-*` attributes; `edge-web.js` watches scroll
  (rAF-throttled), and when the viewport drifts within half an `overscan`
  of the rendered window's edge it requests a new window. The request is
  the same wire event native uses — the `on_window_change` callback
  (kind `virtual_window` server-side) riding the TEXT event format with
  `"from,to"` as the text. Rapid scrolling coalesces in the event queue
  like any text input; the response re-renders the slice, the spacers
  resize, and the morph preserves the scroll position.

- **Lazy screens (`#[Lazy]`).** The GET serves `placeholder()` *without
  running `mount()`* (mount is the slow part being deferred), with an
  empty sealed snapshot. The client immediately POSTs `{type: 'lazy'}`;
  that update's boot *does* run `mount()`, renders the real frame, and the
  response swaps it in along with the real snapshot and poll intervals.

- **Overlay accessibility.** `modal` / `bottom_sheet` render with
  `role="dialog" aria-modal="true"` on the panel and a
  `data-edge-overlay` marker on the backdrop. After every morph the
  runtime reconciles overlay state: a newly-opened dialog captures focus
  (first focusable element, or the panel itself), the trigger element is
  remembered and re-focused when the dialog closes, page scroll is
  locked (`overflow: hidden` on `<html>`) while any overlay is open, Tab
  is trapped inside the topmost overlay, and Escape fires the same
  `SHEET_DISMISS` callback as a backdrop click — but only when the
  overlay is dismissible.

- **Loading, dirty, and upload-progress affordances** (pure client,
  zero wire changes):
  - `<html data-edge-busy="<callbackId>">` while an update is in
    flight — the id lets CSS target *which* action is loading
    (`html[data-edge-busy="123"] .save-spinner { … }`); the origin
    element still gets `disabled`/`data-edge-loading`.
  - `data-edge-dirty` on any form control whose live value differs from
    the last server-rendered one (compared against the `default*`
    properties). Self-cleaning: the morph strips it on every server
    sync, because a synced value *is* the server value again.
  - Upload progress: `EdgeUpload(file, { onProgress })` plus an
    `edge-upload-progress` CustomEvent on `document`
    (`{loaded, total, percent}`) and `data-edge-uploading` on `<html>`
    for pure-CSS progress affordances. `EdgeUpload` now rides XHR (the
    one thing fetch still can't do); its resolve/reject contract is
    unchanged.

- **Uploads.** `window.EdgeUpload(fileOrList)` POSTs multipart to the
  upload endpoint; files land in `storage/app/edge-tmp` and come back as
  HMAC-signed `{path, signature}` pairs that server code re-verifies via
  `EdgeUpload::validatePath()` — a path alone is never trusted. Temp files
  self-prune after 24 h.

- **Security posture.** Update/upload/font routes all live under an
  `APP_KEY`-derived prefix (`/_edge-<hash8>/…`, per Livewire v4's
  hardening) so scanners can't target well-known endpoints; the real path
  is embedded in the page's `#edge-state`. CSRF applies via the `web`
  middleware group. And on an actual device (`NATIVEPHP_RUNNING`), the
  entire provider self-disables — installing this package changes nothing
  about the native app.

---

## 9. Adding an HTML rendering for your own element type

If a UI plugin registers an element type with core's `ElementRegistry`, it
should register the HTML rendering here too — core's `WebRenderer` doesn't
know plugin vocabularies:

```php
use Native\Mobile\Edge\Web\Renderer\HtmlRendererRegistry;
use Native\Mobile\Edge\Web\Renderer\WebRenderer;

HtmlRendererRegistry::register('rating_stars',
    fn (array $node, array $ctx): string =>
        '<div'.WebRenderer::idAttr($node)
            .' class="'.WebRenderer::cls($node, 'flex flex-row gap-1').'">'
            .WebRenderer::children($node, $ctx)
        .'</div>'
);
```

Rules of thumb for a renderer closure:

- Always start the element with `WebRenderer::idAttr($node)` — it emits
  `data-edge-id` (the morph key) *and* the `aria-label` passthrough.
- Build the class attribute with `WebRenderer::cls($node, '…base…')` so
  the author's `web_class` string is appended and press-cursor styling is
  handled.
- If the element is pressable, add `WebRenderer::pressAttrs($node, $isButton)`.
- Escape every prop you interpolate with `WebRenderer::e()`.
- Recurse with `WebRenderer::children($node, $ctx)`.
- Use `bg-theme-*` / `text-theme-*` tokens, never hardcoded colors —
  inline hexes break dark mode.
- Registrations override built-ins, so a plugin can also replace a core
  type's rendering wholesale.

Do it from your plugin's service provider boot, guarded on the class
existing (the web package is optional):

```php
if (class_exists(HtmlRendererRegistry::class)) {
    HtmlRendererRegistry::register('rating_stars', …);
}
```

---

## 10. Gotchas checklist

- **Never run composer in this repo** — see `~/Herd/plugins/CLAUDE.md`.
  Install into a consuming app via a path repository.
- Dynamic Tailwind classes built at runtime (`w-[{{ $x }}%]`) won't exist
  in a production `edge:css` build — use `style=""` (`web_style`) for
  runtime-computed values.
- A STATIC class added after the last `edge:css` run is just as invisible:
  the built sheet is a snapshot, and a missing rule fails silently (markup
  right, paint wrong). Re-run the build after view changes, or delete
  `public/vendor/edge/app.css` in development to use the live JIT.
- Bracket values are **dp-style unitless** (`w-[300]` → `300px`); don't
  write `w-[300px]` in shared Blade or the native parser will choke on it.
- Public props must be scalars, arrays, backed enums, datetimes,
  Eloquent models/collections, or Support Collections. Models travel as
  key + refetch: unsaved attribute changes on a public model prop do
  **not** survive a request, and mixed-class Eloquent collections throw.
- The snapshot is opaque to the client. If you're debugging the JS side,
  never mutate `S.snapshot` — any change 419s.
- Unknown element types render as a bare flex column (with children) or
  nothing (leaf). If your plugin's element "disappears" on web, you forgot
  to register its HTML renderer.
- `?_platform=mobile` on any screen URL previews the mobile rendering in
  the browser (`@mobile`/`@web` resolve as on-device, `web:` classes drop).
