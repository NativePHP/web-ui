# Livewire-parity feature map

Planning doc — nothing here is built yet. Maps each Livewire feature we
want onto the EDGE architecture: where it lives, what exists already,
what's new, and in what order to build.

## The one rule that shapes everything

**If a component author writes it, it must behave identically on device
and web.** That means almost every feature below is a *core*
(`nativephp/mobile`) feature first, with mobile-web contributing only
transport and HTML. A web-only `validate()` would fork the programming
model — the exact thing EDGE exists to avoid.

Consequence: most of this work is core PRs + mobile-ui PRs, with small
web-plugin PRs riding behind them. Web can prototype first (it's the
cheapest target to iterate on), but the API contract gets designed as a
core contract from day one.

Where things live:

| Layer | Owns |
|---|---|
| core (`nativephp/mobile`) | Author-facing API: `validate()`, error bag, form objects, upload value object, attributes (`#[Validate]`, `#[Url]`, …), dispatch-cycle semantics |
| mobile-ui | Element vocabulary: `file_input`, paginator, error display slots (inputs already have `label` / `supporting` / `is_error`) |
| mobile-web (this repo) | Wire transport, HTML renderings, browser drivers, snapshot carriage |

---

## 1. Form validation — build first

Everything else leans on this (uploads validate, forms wrap it).

**What exists:** nothing in core — no `validate()`, no error bag. On the
display side, mobile-ui inputs already have `is_error` + `supporting`
props, and the web renderer already styles them.

**Core (new):**
- `$this->validate($rules)` and `validateOnly($prop)` wrapping Laravel's
  Validator; `#[Validate('required|email')]` on public props as sugar.
- `ValidationException` caught by the dispatch cycle (device run loop AND
  web runner): abort the handler, keep state, proceed to render — the
  frame after a failed validation just renders with errors set.
- An error bag on the component, exposed to Blade as `$errors`
  (ViewErrorBag-shaped so existing Blade muscle memory works).
- Real-time validation falls out for free: `updatedX()` hooks already
  fire on `native:model` sync — `updatedEmail() { $this->validateOnly('email'); }`.

**~~Core render-time auto-wiring~~ — built, then REMOVED (2026-08-08,
Shane's call):** the Livewire model is what devs know. Nothing renders
without explicit `@error`/`@nativeError` markup or `error`/`supporting`
attributes; a failed validation never touches an element's props. See
design-validation.md §5 for the reasoning (locked-in presentation,
un-suppressible tint).

**Web (small):** carry the error bag in the sealed snapshot so errors
survive *unrelated* subsequent updates (Livewire persists them too;
device parity — a persistent instance naturally keeps them). Dehydrate as
plain `{prop: [messages]}`.

**Decision needed:** none blocking — this design has an obvious shape.

## 2. File uploads — BUILT + verified in xclone (revised: no new author API)

The author-facing API already exists and must not be duplicated:
`Camera::pickImages()` (gallery, images/videos, multiple) → the
`MediaSelected` event; `Camera::getPhoto()` / `recordVideo()` for
capture; `File::move()/copy()` for doing something with the result; the
camera plugin's `CameraPreview` on top. The gap is purely that none of
it works in a browser.

**Web work (all in this repo + small core touch):**
1. **Client drivers for `Camera.*`** — the same ClientEffect +
   result-event pattern geolocation uses. `Camera.PickImages` → hidden
   `<input type="file" accept=… multiple>`, pick piped through the
   existing `window.EdgeUpload`, `MediaSelected` dispatched back.
   `Camera.GetPhoto` → `<input type="file" capture="environment">`
   (mobile browsers open the camera natively for that).
2. **Path normalization** — device events carry real filesystem paths;
   the web driver's payload carries `{path, signature}` descriptors.
   The server resolves + verifies them (`EdgeUpload::validatePath()`)
   while handling the update, so the author's listener receives a real
   readable temp path on BOTH targets. Same handler code everywhere.
3. **Extras, in order of value:** signed preview-URL route → XHR upload
   progress (`data-edge-uploading`) → done.

**Deliberately not building:** a `file_input` element, a
`TemporaryUpload` value object — the facade + events ARE the API.
Later, if apps need arbitrary-file picking (PDFs…), that's a method on
the existing `File` facade, not a new system. Validation-rule sugar for
picked paths is an optional nicety, not load-bearing.

**Discovered while building (2026-08-07):**
- The `{type:'native_event'}` result-return path was previously DROPPED
  server-side (dispatch only resolved callback ids) — now handled in
  `WebScreenRunner::update()`; this also fixed geolocation/dialog
  result events, not just camera.
- The fluent chain works on web in ALL forms, including `$this`-bound
  closures (`->onSuccess(function ($e) { $this->… })` — everything in
  one method). The carrier trick in `NativeCallbacks::register()`:
  fire-time rebinding to the live component means the binding never
  needs to survive serialization, so `$this` closures are rebound to a
  throwaway `stdClass` and serialized (code + `use` vars) into the
  durable cache tier. Bonus: closures are now kill-resilient on device
  too. New `onSuccess()` sugar on `HandlesNativeCallbacks` names the
  success event generically. Constraints: serializable `use` vars, no
  eval'd closures, persistent cache store on web (file/redis, not
  `array`). Method-name strings and `#[On(Event::class)]` also work.
- Core's bridge README still shows the stale `#[On('native:FQCN')]`
  form — `On`'s constructor now adds the prefix itself; pass the bare
  event class. Worth a core docs fix.
- Signed file route BUILT: `<native:image :src="$absolutePath">` is now
  target-identical — native loads the file path directly; WebRenderer
  rewrites storage/app paths to HMAC-signed `/file?p=…&s=…` URLs via an
  injected resolver (renderer stays transport-free). Only storage/app
  files sign; tampered/out-of-tree requests 404.
- Storage/File parity BUILT: mobile-web registers the same Laravel disks
  core registers on device (`mobile_public` → storage/app/public, `temp`
  → edge-tmp, mirroring NativeServiceProvider::registerFilesystems), and
  `File.Move`/`File.Copy` got real web drivers — previously the File
  facade REPORTED success on web while doing nothing (unhandled bridge
  method answers null; the facade's fallthrough returned true). The
  blessed persistence pattern is now
  `Storage::disk('mobile_public')->path(…)` + `File::copy(…)` — identical
  author code on both targets.
- Remaining extra not yet built: XHR upload progress.

## 3. Cheap wins — batch third

- **`native:confirm`** (wire:confirm): core parses a `confirm="…"`
  attribute on pressables into a prop; device renderers show a native
  dialog; web intercepts before `enqueue()` using the existing
  `showAlert()`. Tiny on every layer.
- **Flash messages:** core `flash()` helper writing screen-scoped data
  that survives one navigation — device already passes `navigate(uri,
  data)` payloads; web rides the session (cookie already travels on SPA
  nav fetches). Mostly a convention + docs.
- **Loading states** (wire:loading/wire:target): web already emits
  `data-edge-busy` (root), `data-edge-loading` + `disabled` (origin).
  Add the callback id to the busy root (`data-edge-busy="<cbId>"`) so
  CSS can target *which* action is in flight. Pure web, no core change.
  Document the Tailwind recipes.
- **Dirty tracking** (wire:dirty): pure JS — compare live input values
  against server-rendered values, set `data-edge-dirty`. No core change.

## 4. Bigger, later

- **Form objects** (Livewire `Form` classes): core class with props +
  rules + `fill()`/`reset()`; dehydrates as a nested prop bag in the
  snapshot. Wait until validation has settled.
- **`#[Url]` / query-string binding:** web is easy
  (`history.replaceState` sync). The hard part is defining what it means
  on device (route params? ignored?) — don't ship until that's answered.
- **Pagination:** mostly falls out of existing machinery (paginator in
  a prop + press callbacks) + a mobile-ui paginator element + `#[Url]`
  for page state.
- **Partial/region re-rendering** (islands): payload-size optimization —
  every update currently re-sends the whole screen. Design only when it
  measurably hurts; the morph makes it invisible to authors, so it can
  land any time without API changes.
- **Real-time / websockets — not an immediate need.** The device story
  already exists: `nativephp/mobile-vibe` (Pusher protocol via native
  SDKs, `Vibe::channel()->on(...)` in PHP). Web parity, when wanted, is
  a browser driver for the *same Vibe API* (pusher-js/Echo in the
  browser feeding the component's listeners, triggering an update) — not
  a new mechanism, and not `wire:stream`-style SSE. Polls cover current
  needs.

## Author JS on the web target

Author-written JS **is allowed on web** — it just has to be thought
about differently for mobile: on device, JS-shaped needs route through
elements (mobile-ui vocabulary) or plugins (e.g. vibe), so web JS is a
web-enhancement layer, not the place screen behavior lives. A screen
should still *work* on device without it.

What exists today: `window.EdgeDrivers` (override/add client effect
drivers) and page-level scripts loaded before the runtime. What doesn't
exist yet is a sanctioned way for an app to ship its own JS with a
screen — design item, not urgent:

- app-level: a `WebShell` hook to inject app script/asset tags (cheap,
  probably first);
- screen-level: a `@web`-guarded script slot or element, if per-screen
  behavior turns out to be wanted.

## Open questions (decide before building #2)

1. **Upload dev API shape:** element + `native:model` binding (proposed,
   Livewire-like) vs imperative bridge API (`FilePicker::open()` →
   event, camera-like). Element feels right for forms; the bridge API
   may still be wanted for programmatic flows. Could ship both on the
   same `TemporaryUpload` plumbing.
2. **Where `TemporaryUpload` lives** — core needs it (validation rules,
   author code touches it on device too), but the web signing scheme is
   this repo's. Likely: contract + value object in core, per-target
   backends.
3. **Error-bag snapshot shape** — flat `{prop: [messages]}` vs carrying
   the failed rule names too (needed only if native renderers want
   per-rule styling).
4. **Does auto-wiring errors into `supporting` clobber** an
   author-provided supporting text on error, or append? (Livewire
   equivalent: replaces. Recommend: replaces while errored.)

## Suggested sequencing

Each phase is shippable alone; tests ride core's suite as today.

1. ~~Validation core + web snapshot carriage + explicit-only error display
   errors~~ — DONE (core PRs #301/#302, tested).
2. ~~Uploads via the existing Camera facade~~ — DONE (web drivers,
   native_event seam, signed file serving, Storage/File parity).
   Remaining: XHR upload progress.
3. **Error-display audit** — DONE (2026-08-08). The premise that most
   elements "already had" error slots was wrong: only TEXT inputs
   displayed errors, on every target. Select/DatePicker/Checkbox/
   RadioGroup now render is_error/supporting on iOS + Android + web and
   accept the attrs in PHP (mobile-ui branch `feat/error-display-audit`,
   unpushed; native halves NOT yet device-built — verify on a real
   build). Slider/Toggle/Chip/ButtonGroup deliberately skipped: rarely
   validated, same pattern applies when needed.
4. Cheap-wins batch (flash, loading-target, dirty; confirm demoted to
   backlog — it's four lines of userland Dialog code).
5. Form objects, then `#[Url]` + pagination once device semantics are
   agreed.
