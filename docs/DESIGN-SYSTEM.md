# BizFlow — Design System

The rules this product's UI follows. Every screen — owner app and public booking —
reads as one product. If a change breaks a rule here, it is a bug in the change.

---

## 1. Colour — every colour means something

| Token | Role | Never used for |
|---|---|---|
| `mulberry-700` (buttons, links, active nav) | Brand + primary action | Status, decoration |
| `mulberry-50` | Filled brand tints (selected chips, icon wells) | Page background |
| `gray-50 … gray-900` | ALL neutrals: text, borders, backgrounds | — |
| `amber` | Needs attention (pending, deposit unpaid) | — |
| `sky` | Confirmed / informative | — |
| `emerald` | Done, success | — |
| `red` | Broken: cancelled, no-show, validation errors, required `*`, destructive buttons | Decoration, gradients |

**Banned tones** (enforced by `scripts/check-design.sh`, including the marketing
pages): `rose`, `pink`, `indigo`, `violet`, `purple`, `fuchsia`, `slate`, `zinc`.
They are historical accidents from three different design generations. `gray` is
the only neutral scale; `mulberry` is the only brand scale.

The ban list exists because mulberry sits far from every status colour — a blue,
teal, or pink brand would muddle with sky/rose. Do not introduce a second accent.

## 2. Components — never hand-write a control

| You want | You write |
|---|---|
| A page (authenticated screens) | `<x-page title="…" subtitle="…">` with `<x-slot:actions>` |
| A panel / list container | `<x-card>` (flush); `<x-card padded>` for forms & text blocks |
| Any button | `<x-button variant="…" size="md\|sm\|bare">` — variants: `primary`, `secondary`, `danger`, `outline-danger`, `ghost`, `soft`, `link` (mulberry text), `underline` / `underline-danger` (quiet table actions) |
| An icon-only control | `<x-icon-button label="…">svg</x-icon-button>` — label is mandatory |
| A status pill | `<x-status-badge :status="$model->status">Label</x-status-badge>` |
| A form field | `<x-input-label>` + `<x-text-input>` / `<x-select-input>` / `<x-checkbox>` / `<x-textarea>` + `<x-input-error>` |
| An add/edit modal | `<x-form-modal>` (server state owns visibility, see its comments) |
| A save confirmation | `<x-toast />` + the `TenantScreen::toast()` helper |

`<x-primary-button>` / `<x-secondary-button>` / `<x-danger-button>` are shims
over `<x-button>` for Breeze-era call sites. New code uses `<x-button>`.

**Raw `<button>`, `<input>`, or a hand-written button class list in a page =
review NAK.** If the component can't do it, extend the component — or, when a
control is genuinely bespoke (tab strips, colour swatches, clipboard panels,
the compact schedule inputs), wrap it in a documented exception:

```blade
{{-- design-allow:start — one line: WHY this is not a component. --}}
<button …>
{{-- design-allow:end --}}
```

The guard honours the region and reviewers see the reason. New `design-allow`
regions need a real justification; "I was in a hurry" isn't one.

**Gotcha when writing Alpine into component tags:** Blade claims `:foo=` as a
prop binding and `@foo` as a directive, so pass Alpine's long forms
(`x-bind:aria-expanded=`, `x-on:click=`) as component attributes.

## 3. Shape & size tokens

| Token | Value | Used by |
|---|---|---|
| `rounded-card` | 14px | Every card/panel outer corner (x-card) |
| `rounded-lg` | 8px | Controls: buttons, inputs, chips |
| `rounded-full` | — | Avatars, step dots, pills |
| `shadow-card` | 1px whisper | Cards at rest |
| `shadow-pop` | 8/24 | Floating things (toasts, popovers) |
| `min-h-touch` | 44px | **Everything tappable.** Not negotiable. |

Anything that isn't a card, control, or circle keeps its own radius only inside
a component file, never in a page.

## 4. Type scale

| Style | For |
|---|---|
| `text-2xl font-bold tracking-tight text-gray-900` | Page title (`x-page` owns this) |
| `text-sm text-gray-500` | Page subtitle, helper text |
| `text-lg font-semibold text-gray-900` | Modal title, card section title |
| `text-sm` / `text-xs text-gray-500` | Body / meta in lists and tables |
| `tabular-nums` | Every number in a column (durations, prices, counts) |

One font family: **Instrument Sans** (`font-sans` is already it; `font-display`
is mapped to the same stack until a real display face is chosen and loaded).

## 5. Density

- Owner app is **dense**: `space-y-4`, `px-4 py-3` rows, 12–14px meta.
- Public booking stays **calmer**: wider padding, bigger step headings, one job
  per screen. Same tokens, more air — the customer should not feel the back-office
  density.

## 6. Motion

All animation lives in `resources/css/app.css` as named utilities
(`animate-fade-in-up`, `motion-card`, …). Pages do not define `@keyframes`
or inline `<style>` — two pages used to, and those styles are frozen debt:
fold anything new into app.css.

`prefers-reduced-motion` handling is mandatory for any new looping animation —
copy the pattern at the bottom of app.css.

## 7. Accessibility (already load-bearing, keep it)

- Skip-link, `focus-visible:ring-2` on all controls, `aria-live` on toasts.
- 44px touch targets (`min-h-touch`) — the owner uses this one-handed, mid-service.
- `text-base` on inputs under `sm:` (stops iOS zoom-jumping the page).
- Red/green is never the *only* signal — status pills always carry a word.

## 8. Guard

```bash
bash scripts/check-design.sh          # exit 1 on violations
bash scripts/check-design.sh --allowlist-file=none   # strictest
```

Run it before committing UI changes (and see `composer test`). The script checks:
banned colour tones, raw `<button>`/`<input>` in converted pages, and
`rounded-[...]` arbitrary radii outside component files.
