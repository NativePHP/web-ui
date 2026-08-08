# Design: form validation for EDGE components

Status: **draft for review** — nothing built. Companion to
`livewire-parity-map.md` (phase 1). Most of this lands in core
(`nativephp/mobile`); mobile-ui and mobile-web get small follow-ups.

## Goals

- `$this->validate()` in a handler behaves like every Laravel dev
  expects, **identically on device and web**.
- Inline errors on inputs with zero extra markup (better than Livewire,
  because our inputs have structured error slots — `is_error` /
  `supporting` — where HTML has loose DOM).
- `$errors` available in Blade, shaped like Laravel's `ViewErrorBag`, so
  `@error('email')` muscle memory works.
- Real-time (per-keystroke) validation with no new wire machinery —
  the `native:model` sync path already exists.

Non-goals for v1: Form objects (separate phase), `TemporaryUpload` rules
(uploads phase), custom Rule objects beyond what Laravel's validator
already accepts (they just work), per-rule styling metadata on the wire.

## Author API (what a screen looks like)

```php
use Native\Mobile\Attributes\Validate;

class RegisterScreen extends NativeComponent
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|min:8')]
    public string $password = '';

    public array $tags = [];          // rules can also live in rules()

    protected function rules(): array
    {
        return ['tags.*' => 'string|max:20'];
    }

    public function save(): void
    {
        $validated = $this->validate();   // attribute + rules() merged

        // ...or ad-hoc, Livewire-style:
        // $validated = $this->validate(['email' => 'required|email']);

        User::create($validated);
        $this->navigate('/welcome');
    }
}
```

```blade
<native:column class="gap-4 p-6">
    {{-- error appears inline automatically: is_error + supporting --}}
    <native:text-input native:model.blur="email" label="Email" />
    <native:text-input native:model="password" label="Password" secure />

    {{-- classic Blade also works --}}
    @error('email')
        <native:text class="text-red-500">{{ $message }}</native:text>
    @enderror

    <native:button label="Create account" native:press="save" />
</native:column>
```

Behavior: `save()` calls `validate()`; on failure the handler aborts at
that line, state is untouched, the frame re-renders with errors set, and
the email input shows "The email field is required." in its supporting
slot, error-styled. On device and web identically.

## Core design

### 1. The trait: `Concerns\ValidatesProps` on `NativeComponent`

- `validate(?array $rules = null, array $messages = [], array $attributes = []): array`
  — builds the data array from `getPublicProperties()`, merges rule
  sources (below), runs Laravel's `Validator`, throws Laravel's own
  `Illuminate\Validation\ValidationException` on failure, returns the
  validated subset on success. Passing `$rules` validates only those.
- `validateOnly(string $prop)` — one property (supports wildcards:
  `validateOnly('tags.0')` matches a `tags.*` rule), replaces only that
  field's errors, leaves the rest of the bag alone.
- `addError(string $key, string $message)` / `resetValidation(?string $key = null)`
  / `getErrorBag(): MessageBag` — manual control, Livewire-compatible
  names.
- Rule sources, merged in this order (later wins on key collision):
  `#[Validate]` attributes on public props → `rules()` method → the
  `$rules` argument. `messages()` / `validationAttributes()` methods for
  customization, mirroring Livewire.
- Rule OBJECTS (`Rule::in()`, `Password::min()`, custom `ValidationRule`
  classes, closures) work anywhere rules are arrays — `rules()` and
  inline `validate([...])`. They can't appear inside `#[Validate]`
  because PHP attribute arguments must be compile-time constants (same
  limitation as Livewire).
- `validate()` also accepts a **FormRequest class-string** and harvests
  its `rules()`/`messages()`/`attributes()` — one definition shared by
  an HTTP controller and a screen. Harvesting only: the request is
  instantiated bare, `authorize()` is never called, and `rules()` must
  not touch `$this->input()/route()/user()`. (Livewire deliberately
  rejected full FormRequest integration over exactly that HTTP
  coupling — this is the uncontroversial subset.)

Bag storage: a `MessageBag` property on the component. Deliberately NOT
a public prop (it must not be author-assignable or hit the generic
snapshot path) — it's internal state with its own carriage (below).

### 2. `#[Validate]` attribute — `Native\Mobile\Attributes\Validate`

`#[Validate('required|email')]` or `#[Validate(['required', 'email'], as: 'email address')]`.
Sits alongside `Computed`/`Poll`/`Lazy` in `src/Attributes/`.

**Attribute rules auto-run on model sync** (Livewire parity): at the end
of `__syncProperty()`, if the synced prop has attribute rules, run
`validateOnly($prop)` catching the exception (a failed live validation
must not abort the sync — the value stays, the error shows). This gives
per-keystroke validation for free on both targets, respecting the
author's `native:model` modifier (`.blur` = validate on blur, `.live` =
per keystroke, debounce = debounced). `rules()`-method rules do NOT
auto-run on sync — that's the author's opt-out lever (attribute = eager,
method = on-demand), same split Livewire ended up with.

### 3. Dispatch-cycle handling (the parity-critical part)

`ValidationException` must be caught at every event entry point, with
identical semantics: **handler aborts, component state stays, the frame
renders, errors are set on the bag.**

- Shared guard in core: a small `runGuarded(callable)` on
  `NativeComponent` that catches `ValidationException`, stores
  `$e->validator->errors()` into the bag, and swallows. Both device
  entry points (UI events from the bridge, native events) route their
  handler invocation through it.
- Web: `WebScreenRunner::update()` wraps its `dispatch($event)` call in
  the same guard (it delegates to component internals via `scoped()`,
  so it reuses the core guard — no web-specific catch logic).
- `mount()` is NOT guarded: a validation failure during mount is a
  programming error, let it throw.
- Any other exception still propagates (the web error overlay / device
  crash reporting handle those — validation is the only *control-flow*
  exception).

Bag lifecycle: `validate()` replaces the whole bag; `validateOnly($p)`
replaces only `$p`'s entries; a *successful* `validate()`/`validateOnly`
clears what it covered. Errors otherwise persist across frames — device
gets this free (persistent instance), web via snapshot carriage.

### 4. `$errors` in Blade

Every view-data merge site (`view()`, `fromView()`, `fromViewPartial()`,
the layout path — they all do `array_merge($this->getPublicProperties(), $data)`)
additionally injects `'errors' => $viewErrorBag` (a `ViewErrorBag`
wrapping the component's bag as the default bag) **unless the key is
already present**. Centralize in one `viewData()` helper since the merge
is currently copy-pasted across ~5 sites. Child components inject their
OWN bag into their own views — error scope = component instance.

### 5. Auto-wiring errors into bound inputs

Two-part mechanism, both generic (no per-element code):

1. **Precompiler** (`compileNativeModel()`): also emit
   `model-prop="<name>"` so every `native:model`-bound element carries
   its property name into the collector. (One line; the attribute is
   also independently useful — e.g. future devtools.)
2. **Collector/render**: when an element carries `model_prop` and the
   active component's bag has errors for that key, inject
   `is_error: true` and `supporting: <first message>` into its props.
   Author-provided `is_error`/`supporting` win (explicit beats
   injected); while errored, the injected message REPLACES an
   author-default supporting text (decision from the parity map).

Because injection happens at the wire-tree level, it works for core
inputs, mobile-ui inputs, and any plugin element that adopts the
`is_error`/`supporting` prop convention — which this design promotes
from "mobile-ui convention" to documented wire vocabulary. Elements
that don't render those props simply ignore them (native registries and
WebRenderer both already pass unknown props through harmlessly).

## Web-target carriage (this repo — deliberately small)

- **Snapshot**: add an `errors` key to the sealed data
  (`{prop: [messages]}`), captured after render, restored into the bag
  before dispatch on the next update. Inside the HMAC seal like
  everything else — a client can't forge or clear errors. Empty bag =
  empty object, negligible size.
- **Renderer**: nothing. `is_error`/`supporting` already render.
- **`edge-web.js`**: nothing. Errors arrive as ordinary re-rendered
  HTML; the morph patches them in. A future nicety (focus the first
  errored input after a failed submit) is a follow-up, not v1.

## Styling errors (dev contract — Shane-confirmed 2026-08-08)

The auto-injected display is a DEFAULT, never a cage. Guaranteed knobs,
most custom first:

1. `@error('field')` in Blade — arbitrary custom UI, any styling, any
   placement; the injected display never interferes with it.
2. Per-element suppression: author-set `supporting` (including `""`)
   and `error` attrs beat injection via the extraProps merge order
   (covered by the merge-order test). Control tint + custom-placed
   message is a supported combination.
3. Theme tokens (`destructive`, `on-surface-variant`) restyle the
   built-in display app-wide; `@nativeError('field', '#hex')` per use.
4. No markup at all → the consistent Material-style default.

Any future change that breaks one of these layers is a regression, not
a redesign.

## Decisions made here (flag if you disagree)

1. Laravel's own `ValidationException` — not a custom one. Authors can
   `throw ValidationException::withMessages([...])` from anywhere in a
   handler and it Just Works.
2. Attribute rules auto-validate on sync; `rules()` rules don't.
3. Bag is internal state with dedicated snapshot carriage, not a public
   prop.
4. Injection replaces `supporting` while errored; reverts when cleared.
5. `is_error`/`supporting`/`model_prop` become documented wire-level
   props, not mobile-ui-private ones.
6. Error scope is the component instance (child components have their
   own bags), matching callback ownership.

## Open questions

1. `#[Validate(as: '...')]` and `message:` sugar in v1, or start with
   rules-only and add sugar when asked? (Lean: rules-only v1.)
2. Should a failed **live** validation on `.live` mode validate on every
   keystroke or debounce the *validation* even when sync is live?
   (Lean: validate whenever sync fires — the sync modifier IS the
   cadence control.)
3. Web-only: after a failed submit, scroll-to/focus first errored input?
   (Lean: v1.1 with a `data-edge-error` attr on errored inputs.)

## Rollout

Three PRs, independently shippable, in order:

1. **core**: `ValidatesProps` trait + `#[Validate]` + guarded dispatch +
   `$errors` injection + `model-prop` emission + prop injection at the
   collector. Tests: unit (bag semantics, rule merging) + feature
   (dispatch aborts, frame shows injected props, sync auto-validation,
   child-component scoping).
2. **mobile-web** (this repo): snapshot `errors` carriage + restore.
   Tests ride core's web suite (update cycle: failed validate → same
   response carries errored HTML + sealed errors; next update round-trips
   the bag; tamper still 419s).
3. **mobile-ui**: audit inputs so every form element honors
   `is_error`/`supporting` consistently (most already do); docs.

Docs for authors land with PR 1 (core docs) — per the standing note that
mobile-ui features keep merging without docs.
