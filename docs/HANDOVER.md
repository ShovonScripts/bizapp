# BizFlow — Engineering Handover

**Last updated:** 2026-09-10
**Repo:** `C:\xampp\htdocs\bizapp` (local XAMPP)
**Status:** pre-launch, locally proven. No paying clients. No production
deployment yet.

---

## 0. How to use this document

This is the operative reference for anyone — human or AI agent — picking this
project up cold. It is written to be read top to bottom once, then used as a
lookup.

Three conventions used throughout:

- **Verified** means someone ran it and saw the result. The evidence is named.
- **Assumed** means it is believed correct from reading code or docs, but has
  never been executed. Treat it as a hypothesis.
- **Trap** means it has already cost real time once. Section 7 collects these.
  Read that section before writing code; it is the highest-value part of this
  document and every entry in it is a bug that already happened.

Two warnings about the environment this project is developed in, because they
change how you should behave:

1. **The AI agent working on this codebase cannot run anything.** No PHP, no
   Composer, no npm, no outbound network. Every file it produces is unrun and
   must be handed over with that stated plainly. Static review is the only
   defence available to it, which is why the trap list exists and why the test
   suite matters more than usual.
2. **Commands are executed by a human on a different machine.** Always specify
   whether a command runs on the **local XAMPP terminal** or the **cPanel
   server**. This has been confused before and cost a debugging session.

An earlier planning set (`00-README`, `01-master-plan`,
`02-database-schema-architecture`, `03-boss-proposal-EN`, `04-pricing-gtm-gdpr`
and a `phase1/` folder) was produced before the code existed. **Those files are
not in this repository.** Where they disagree with this document, this document
wins, because it was written against the code as it actually stands.

---

## 1. The product

BizFlow is a multi-tenant SaaS for UK local businesses, sold on monthly
subscription. The chosen launch niche is **salon and barber shops**.

What it does for the owner: keeps their customer list, their service menu and
their staff; runs an appointment diary; shows a dashboard of today's bookings,
takings and no-shows; and — the part that justifies the subscription —
**automatically sends each customer a reminder the day before their
appointment**, so fewer of them forget to turn up.

Reminders are the product. Everything else is the data those reminders need.
A salon that loses two no-shows a week to a reminder has paid for the software
several times over, and that is the entire commercial argument.

Channel priority is **Telegram first, WhatsApp second**, then email as a free
fallback and SMS as a paid option. Telegram is sequenced first because it is
free, needs no approval process, and can be built and tested today; WhatsApp
requires Meta business verification, which is blocked on a third party (see
section 9).

---

## 2. Who does what

**The boss** is based in the UK. He brought the idea and he owns the commercial
side: market access, sales, and the client relationships. Critically he is also
the only route to a **registered UK company entity**, which Meta requires for
WhatsApp business verification.

**Shovon** is in Bangladesh (timezone Asia/Dhaka) and does all technical work.
He runs every command; nothing in this project is executed by anyone else.

**The AI agent** writes code, reviews it statically, and designs. It cannot
execute. See section 0.

Working language: **Bangla for technical discussion and guidance, English for
anything a client or an app user will read.** Owner-facing copy in the app is
British English.

What makes this project unusual and worth taking seriously: distribution is
already solved. The hardest part of a small SaaS — finding the first customers —
is the boss's job and he has the access. The technical work is therefore the
binding constraint, not the marketing.

---

## 3. Current status

### What works today, verified by running it

- `php artisan migrate:fresh --seed` completes cleanly on local MySQL, producing
  a demo salon (8 services, 3 staff, 12 customers, ~10 weeks of appointment
  history with roughly a 1-in-8 no-show rate, and 5 appointments tomorrow so the
  reminder planner has something to find).
- `php artisan test` → **352 passed** (2026-09-10). This is the current
  known-good baseline; it replaces 345/1031 assertions (2026-09-09), which
  replaced 338, which replaced 229. There are 24 test files and 326 `test_*`
  methods; the run reports 352 because several tests use data providers.
- All five owner-facing screens render and work: dashboard, appointment diary,
  customers, services, staff.
- Telegram account linking works end to end locally (deep link → `/start` →
  `telegram_chat_id` stored), driven by `php artisan telegram:poll`.
- The mobile UI pass was checked in a browser on 2026-09-09: card layouts on all
  three list screens, the bottom tab bar, and the double-booking warning
  scrolling itself into view all behave correctly.

- **The end-to-end reminder walkthrough completed on 2026-09-10.** A booking made
  for 2026-09-11 12:00 BST (stored `11:00:00` UTC, independently verified against
  tzdata) was planned, queued and dispatched, and the reminder arrived on a real
  phone via `@auto_jis_bot`. This was the last unproven link in the chain: the
  product now demonstrably does the one thing it is sold for.

### What is built but not yet proven end to end

Nothing in the core loop. The remaining unproven ground is **everything about
running on a server**: cron actually firing `schedule:run`, the Telegram webhook
replacing local polling, and the app surviving shared hosting. See Phase 4.

### Test suite shape

| Area | Files | Notes |
|---|---|---|
| Screens | `AppointmentScreenTest` (50), `CustomerScreenTest` (35), `StaffScreenTest` (25), `ServiceScreenTest` (21), `DashboardTest` (20) | Livewire/Volt component tests |
| Domain | `CustomerTest` (25), `PhoneTest` (12), `QuietHoursTest` (12), `TemplateRendererTest` (8) | |
| Messaging | `MessageDispatcherTest` (20), `TelegramLinkingTest` (18), `ReminderPlannerTest` (17), `MessageQueueCommandTest` (7) | |
| Isolation | `TenantIsolationTest` (18), `TenantScreenAccessTest` (4) | The security net |
| Breeze | `Auth/*`, `ProfileTest` | Framework's own, kept |

---

## 4. Stack and versions

```
php                    ^8.2
laravel/framework      ^12.0
livewire/livewire      ^3.6.4
livewire/volt          ^1.7.0
laravel/breeze         ^2.4     (dev — installed with the Livewire/Volt stack)
phpunit/phpunit        ^11.5    (NOT Pest — there is no tests/Pest.php)
tailwindcss            ^3.1     compiled via postcss.config.js
vite                   ^7.0
```

Alpine.js is **not** a separate dependency — it ships inside Livewire's bundle.
This limits which Alpine plugins are available; see the trap in section 7.6.

Databases: **MySQL** locally through XAMPP; **SQLite `:memory:`** in tests, pinned
by `phpunit.xml` so no test can reach his development data.

`APP_NAME="BizFlow"`. `APP_TIMEZONE` stays **UTC** — see section 7.3.

---

## 5. Architecture

### 5.1 Multi-tenancy

One database, a `business_id` column on every tenant-owned table, and a global
Eloquent scope. There is deliberately no per-tenant database and no schema
switching; at the scale this product is aiming for that would be complexity
without a payoff.

The moving parts:

- `App\Support\Tenant` — a static holder with `set()`, `forget()`, `for()`,
  `id()`, `check()`.
- `App\Models\Concerns\BelongsToBusiness` — the trait carrying the global scope
  and auto-filling `business_id` on create.
- `Tenant::id()` resolves from the authenticated user.

Three facts about this that matter more than they look:

1. **`business_id = null` means super-admin.** Consequently, any code path that
   creates a `User` without a `business_id` silently mints an account that can
   read every client's data. Breeze's stock `/register` did exactly this and was
   fixed; Laravel's stock `DatabaseSeeder` did too and was replaced.
2. **When `Tenant::id()` is null the scope adds no WHERE clause at all** —
   verified in the scope source, not assumed. This is why the dashboard can hang
   queries off `$business->appointments()` safely with or without a tenant, and
   why an unauthenticated request to a tenant screen would expose everything.
   Every tenant route must stay behind `auth`.
3. **A tenant isolation failure is a reportable UK GDPR breach**, not merely a
   bug. Treat any change touching the scope as security-critical.

Authorisation for "is this row mine?" is `findOrFail()`. Inside the global scope
another business's id does not exist, so it 404s. No policy is needed for the
ownership question.

`TenantScreenAccessTest` inverts the burden of proof: it walks every
parameterless GET route, skipping guest-only routes and framework prefixes, and
asserts a super-admin gets 403, a guest is redirected and an owner gets 200. A
new screen that forgets `guardTenant()` fails immediately. A genuinely
business-agnostic page must be added to `NON_TENANT_ROUTES` by hand. The test
has a self-check asserting the route walk still finds `customers`/`services`/
`staff`, because an empty discovery list would make every other assertion pass.

`dashboard` is on that allowlist permanently and on purpose — see 5.4.

### 5.2 Data model

```
businesses     ─┬─ users            (business_id null = super-admin)
                ├─ customers        soft deletes
                ├─ services
                ├─ staff_members
                ├─ appointments     soft deletes
                ├─ channel_connections
                └─ scheduled_messages
```

**businesses** — `name`, `slug` (unique), `niche` (default `salon`),
`subscription_status` (default `trialing`), `timezone` (default
`Europe/London`), `currency` (GBP), contact fields, `logo_path`, `settings`
(JSON), `trial_ends_at`. Soft deletes.

`settings` is where per-business configuration lives until there is a settings
screen: `quiet_hours.from` (default `21:00`), `quiet_hours.to` (default
`08:00`), `default_channel` (default `telegram`), `invoice_prefix`. Read them
through `Business::setting()` / `quietHours()` / `defaultChannel()`, never
directly.

**customers** — `name`, `phone` (E.164), `email`, `whatsapp_number`,
`telegram_chat_id`, `telegram_link_token` (globally unique), `preferred_channel`
(default `telegram`), `marketing_consent` + `consent_at` + `consent_source`,
`unsubscribed_at`, `notes`, `tags` (JSON), `last_visit_at`, `total_spend`. Soft
deletes. `unique(business_id, phone)`; `telegram_chat_id` is **indexed but not
unique**, because one phone can legitimately be a customer of two businesses.

Consent is never a plain column write. Turning it on goes through
`recordConsent($source)` so the evidence travels with it. Turning it off clears
`marketing_consent` only and must **not** set `unsubscribed_at` — that field
means "the customer asked us to stop" and it also silences appointment
reminders. An owner tidying their marketing list must not accidentally stop the
reminders they are paying for.

Reachability is channel-aware and this is load-bearing:
`hasContactRoute()` (PHP) and `scopeWithContactRoute()` (SQL) must never
disagree, because a dashboard number that disagrees with what actually sends is
worse than no number. `CustomerTest` holds a matrix asserting exactly that.
`unreachableReason()` returns the owner-facing explanation.

**services** — `name`, `description`, `duration_minutes`, `price`, `active`,
`sort_order`. No soft deletes.

**staff_members** — `name`, `phone`, `color` (calendar colour), `active`,
`sort_order`, optional `user_id`. No soft deletes.

Because `appointments.service_id` and `staff_member_id` are `nullOnDelete`,
deleting a service or staff member that has history blanks out *what was sold*
and *who did the work*, quietly rewriting last quarter's figures. Both screens
therefore delete only when `appointments()->withTrashed()->exists()` is false,
and otherwise set `active = false` and say so. `active` is the retire
mechanism; Delete is only offered when the booking count is zero.

**appointments** — `customer_id`, nullable `service_id` and `staff_member_id`,
`starts_at`, `ends_at`, `status`, `price`, `notes`, `source`, `reminded_at`.
Soft deletes.

```php
PENDING | CONFIRMED | COMPLETED | CANCELLED | NO_SHOW
REMINDABLE = [PENDING, CONFIRMED]              // what gets a reminder
BLOCKING   = [PENDING, CONFIRMED, COMPLETED]   // what counts as a clash
```

Price is frozen onto the appointment at booking time, so changing a service's
price later does not rewrite history.

**channel_connections** — `business_id` + `channel` (unique together),
`credentials`, `status`, `verified_at`, `error`, `meta`. This is how a business
gets its own branded bot instead of the platform-wide one; the driver prefers a
connection row over config. `credentials` **must** use the `encrypted:json`
cast and the model keeps it in `$hidden`.

**scheduled_messages** — the outbox. `customer_id`, `channel`, `template_key`,
`payload` (JSON, the rendered body plus its variables), `send_at`, `status`,
`attempts`, `sent_at`, `error`, and a `related_type` / `related_id` morph back
to whatever caused it.

```php
PENDING | SENT | FAILED | CANCELLED | SKIPPED
```

### 5.3 The messaging engine

Three commands, deliberately split because they fail differently:

```
messages:plan      reads the DB, writes rows to the outbox      (cheap)
messages:queue     prints the outbox for a human                (read-only)
messages:dispatch  sends what is due                            (hangs, times out, rate limits)
```

Splitting planning from sending means a slow provider delays sending without
also stopping tomorrow's reminders from being worked out.

The scheduler (`routes/console.php`) is driven by **one** cPanel cron entry
firing `schedule:run` every minute. `app:heartbeat` runs every minute,
`messages:plan` every five minutes, `messages:dispatch` every minute. Every one
of them has `withoutOverlapping()`, which is not optional: without it a slow
dispatch run still going when the next minute starts would message the same
customers twice.

**The planning window.** A reminder is aimed at 24 hours before the appointment
(`offset_minutes: 1440`). The planner looks 12 hours forward
(`plan_horizon_hours`) and 6 hours back (`catch_up_hours`), so in terms of
appointment **start** times it is planning for anything **18 to 36 hours
ahead**. Nothing closer than 2 hours away is ever planned
(`min_notice_minutes`), because someone booking two hours out is already on
their way and a "reminder" seconds after saving reads as a bug.

For a reminder to become sendable *immediately* in a manual test, the
appointment must be **18–24 hours ahead**. `messages:plan` prints the window
when a run finds nothing, because four zeros is otherwise indistinguishable
from a broken planner.

**Everything the planner decided is re-checked at send time** — consent,
appointment status, quiet hours, minimum notice. The `AppointmentObserver` is a
convenience, not the load-bearing part.

**Quiet hours** default to 21:00–08:00 in business local time. For a
Europe/London business that is **02:00–13:00 Asia/Dhaka**, which means testing
during a Bangladeshi morning will correctly defer everything and look broken.
`from === to` means "no quiet hours".

**Drivers.** `MessagingManager::DRIVERS` maps channels to driver classes and
currently contains **only** `telegram`. WhatsApp, SMS and email are deliberately
absent so that choosing an unsupported channel produces a visible "not available
yet" row rather than a message that pends forever.

`SendResult` has three states — sent, retryable failure, permanent failure — and
the distinction is the whole point. `TelegramBotDriver::classify()` maps
Telegram's errors onto them: 429 and 5xx are retryable; 401 (bad or revoked
token), 403 (customer blocked the bot), "chat not found", and any other 4xx are
permanent. Retrying a blocked bot three times is three wasted runs and a
reminder that was never going to arrive.

Retry policy: `max_attempts: 3`, backoff 5 then 20 minutes. Deliberately short —
a reminder is worthless once the appointment has started. Batch size 50 per run,
because shared hosting kills long processes.

**Telegram linking.** A bot cannot message anyone who has not sent it `/start`,
so each customer gets a per-customer deep link
`https://t.me/<bot_username>?start=<token>`. The Customers screen mints the
token once — regenerating it would silently break a link already sent — and
shows a copy panel. **The app sends nothing**; the owner forwards the link
themselves. A used token stays with the chat that used it, and the refusal
message leaks no names.

Tapping a link never undoes an unsubscribe: under PECR a tap is not consent.
`/stop` unsubscribes every customer record on that chat id, across all
businesses.

Locally, updates are read with `php artisan telegram:poll` because a webhook
needs public HTTPS. In production `telegram/webhook` receives them; it is the
**only** route exempted from CSRF, and what replaces CSRF is a secret header
compared with `hash_equals` before the body is even looked at.

### 5.4 The UI layer

Every screen is a **Volt single-file component** — PHP class and Blade template
in one file under `resources/views/livewire/`.

| Route | Component | Purpose |
|---|---|---|
| `/dashboard` | `dashboard` | Today's figures; the screen the boss demos |
| `/appointments` | `appointments.index` | The diary; the hardest screen |
| `/customers` | `customers.index` | List, search, filters, Telegram invites |
| `/services` | `services.index` | The service menu |
| `/staff` | `staff.index` | Staff and their calendar colours |

`app/Support/BusinessSnapshot.php` owns every dashboard figure, not the
component. It takes a `Business` and never reads `Tenant`, so console commands
can reuse the same numbers with no request and no logged-in user. **Nothing is
cached**: an owner who marks a booking "Done" and sees takings unchanged
concludes the app is broken.

The dashboard is the one screen without `guardTenant()`. It branches on
`Tenant::check()` instead — owner sees their figures, super-admin sees platform
counts and never a row from inside a business. The reason is that the navigation
shows the logo and Dashboard links to everyone, so a 403 here would strand an
admin on a page whose every link also 403s. **Do not copy `dashboard.blade.php`
as the template for a new screen; copy `appointments`.**

**Shared components** (`resources/views/components/`):

- `form-modal` — the add/edit shell. Bottom sheet on a phone, centred on
  desktop. Header, scrolling body, pinned footer, so the submit button is always
  on screen. No dismiss-on-backdrop: a stray edge tap discarding a half-typed
  booking is worse than one extra tap on Cancel. Exposes `dropKeyboard()`.
- `toast` — confirmation banner. Requires `x-data="{ toast: null }"` and the
  `x-on:toast.window` handler on the component's root element.
- `text-input` / `select-input` — carry the 44px minimum height and the 16px
  font floor in one place.
- `primary-button` / `secondary-button` — same height, brand colour on primary.

**Mobile-first decisions**, made explicitly and still governing:

1. **Phone usability comes first.** Salon owners work standing behind a chair.
   44px tap targets everywhere via a custom `min-h-touch` utility; the three
   list screens render as cards below `sm:` with the table kept for desktop; the
   booking form is a bottom sheet; there is a bottom tab bar.
2. **Brand is a thin pass only** — own mark instead of the Laravel logo, one
   brand colour (a custom `mulberry` scale, chosen so it never collides with the
   amber/sky/emerald/red status semantics), restyled primary button. Explicitly
   *not* a design system.
3. **The diary stays a list**, just thumb-friendly. A tappable time-grid was
   offered and deferred until pilot feedback. Do not rebuild it unprompted.

---

## 6. Non-negotiable rules

These are not style preferences. Each one exists because breaking it either
leaks a client's data, breaks UK law, or sends a real message to a real stranger.
If a change appears to require breaking one of these, stop and raise it.

### Secrets

1. **A bot token never appears in any file except `.env`.** Not in a migration,
   a seeder, a test, a config default, a comment, or `.env.example`.
   `.env.example` carries empty keys only.
2. **Never print `.env` contents.** `.env.example` only. If a token has been
   pasted into a chat, transcript or issue, it is compromised — revoke it at
   @BotFather and issue a new one. This has already happened once.
3. `channel_connections.credentials` uses the `encrypted:json` cast and stays in
   `$hidden`. Never store a client's token in plaintext.
4. `.env` lives outside the webroot on any server. `APP_DEBUG=false` in
   production.

### Tenancy

5. **No registration or seeding path may produce a super-admin.** Any `User`
   created without a `business_id` is one.
6. Tenant routes stay behind `auth`. The global scope filters nothing when there
   is no tenant.
7. Never put `BelongsToBusiness` on `User` — it would filter the very lookup
   that establishes who the tenant is.

### Sending safety

8. **`phpunit.xml` pins `MESSAGING_DRIVER=log`.** No test may ever send a real
   message. Do not remove this, and do not let a test override it.
9. **Every demo or test phone number uses Ofcom's reserved drama range
   `07700 900xxx`.** These numbers are guaranteed unallocated, so a
   misconfigured send cannot reach a real person.
10. The demo seeder must never run in production. The seeded password
    (`password`) is a local fixture and must never reach a live deployment.
11. The credentials panel on the welcome page stays behind `@env('local')`.
    Do not relax that gate.

### Web surface

12. The CSRF exemption list in `bootstrap/app.php` stays limited to
    `telegram/webhook`. Nothing else is ever added.
13. `/heartbeat` stays token-protected via `hash_equals`, or is deleted before
    real clients exist. It currently 404s when `HEARTBEAT_TOKEN` is blank, which
    is the intended fail-closed behaviour.

### GDPR / PECR

14. Consent must be logged with a source and a timestamp, never set as a bare
    boolean.
15. Opt-out must work and must be honoured on every channel, and unsubscribing
    from marketing must not silence transactional reminders.
16. Data export, data deletion, and a retention purge are required before the
    first paying client. They are not built yet (section 10).
17. **Logs and console output must never contain bot tokens, phone numbers, chat
    ids, or raw Telegram update bodies.**

---

## 7. Traps

Every entry here is a bug that already happened, or a landmine confirmed by
reading the vendored source. This is the section to reread before writing code.

### 7.1 Livewire and Volt

- **A Volt file's `use` imports apply to the PHP half only, not the Blade half.**
  The template must fully qualify: `\App\Support\Channel::label(...)`,
  `\App\Models\Appointment::REMINDABLE`. A short name in the template throws at
  render time, and no static check catches it.
- **`wire:key` must be unique within a whole component.** The three list screens
  render each record twice — once as a mobile card, once as a desktop table row
  — so the keys are prefixed differently (`customer-card-{id}` and
  `customer-row-{id}`). Duplicate keys make Livewire's DOM morphing swap the
  wrong nodes and edit the wrong record.
- **Livewire's DOM morphing never re-runs `x-data`.** This is why `form-modal`
  keeps `show` state on the server behind `@if ($showForm)` rather than in
  Alpine, and why Breeze's stock `<x-modal>` was not reused.
- `wire:model.live.debounce.300ms` on search boxes. Plain `.live` fires a
  round trip per keystroke.
- Volt components resolve views through `VoltServiceProvider`; adding a screen
  means a route entry, not a controller.

### 7.2 Tenancy

- `Rule::unique()` and `Rule::exists()` build their own query and **bypass the
  global scope**. Always add `->where('business_id', $businessId)` by hand, or
  one salon's duplicate check silently consults another's data.
- `Tenant::id()` returning null disables the scope entirely. Console commands
  and tinker therefore touch every business — convenient for maintenance, fatal
  in a request.
- There is no `Tenant::business()` — the class only exposes `set`, `forget`,
  `for`, `id`, `check`. From inside a screen use the `TenantScreen` trait's
  `$this->business()` (protected), alongside `$this->guardTenant()` and
  `$this->toast()`.

### 7.3 Time and timezones

- **`APP_TIMEZONE` stays UTC.** All timestamps are stored UTC and converted for
  display via `Business::toLocal()`. Changing the app timezone to fix a display
  bug corrupts the comparison arithmetic in the planner.
- Three timezones are in play at once and they are routinely confused:
  business local (`Europe/London`), the developer's (`Asia/Dhaka`, UTC+6), and
  storage (UTC). **A London quiet-hours window of 21:00–08:00 is 02:00–13:00 in
  Dhaka.** Testing a send during a Bangladeshi morning defers correctly and
  looks broken.
- Europe/London has DST; Asia/Dhaka does not. The offset is 5 hours in summer
  and 6 in winter. Never hardcode it.

### 7.4 Messaging

- **`ReminderPlanner::alreadyPlanned()` blocks on `pending`, `sent` and `failed`
  only.** `cancelled` is re-plannable — that is why the observer cancels rather
  than deletes. `skipped` is *revivable*: `revivableSkipped()` loads the existing
  row and `write()` rewrites it in place, so the appointment is reconsidered on
  every run without the queue filling up with copies of the same note.
- **`failed` still blocks, on purpose.** A permanent failure — a customer who
  blocked the bot — would otherwise be retried every five minutes for as long as
  the booking sits in the window. Retrying transient failures is the dispatcher's
  job and it already has a backoff for it. Clearing a genuinely failed row is a
  manual act by whoever fixed the cause.
- **Reading a `messages:plan` tally.** `skipped: 1` on every run is the fixed
  behaviour, not a loop; the row's `error` column says what is missing. Repeated
  `already_planned: 1` with no reminder ever arriving means a `failed` row, and
  that one does need clearing by hand.
- A reminder can only be planned *and immediately sendable* when the appointment
  is **18–24 hours ahead**. Outside that it is either not yet in the horizon or
  already past its send time.
- `MessagingManager::DRIVERS` contains only `telegram`. Any other channel is a
  visible "not supported" result by design, not an oversight to be patched.
- **A bot cannot message a chat that never sent it `/start`.** Therefore: if the
  bot is replaced with a *different* bot rather than renamed, **every stored
  `telegram_chat_id` becomes dead** while `hasContactRoute()` still reports those
  customers as reachable. Null the chat ids and re-invite. A rename keeps them.
- `TELEGRAM_BOT_USERNAME` is stored **without** the leading `@` and is used to
  build every invite deep link. A stale value produces links that fail silently.
- Never regenerate an existing `telegram_link_token`; a link already sent to a
  customer would stop working with no error.

### 7.5 Testing

- **It is PHPUnit 11, not Pest.** New tests are `test_*` methods on a class
  extending `Tests\TestCase`. There is no `tests/Pest.php`.
- **Dusk is not installed**, so nothing that only manifests in a browser is
  caught. The Enter-key bug in section 7.6 was invisible to every one of the 345
  tests passing at the time.
- Tests run on SQLite in memory. Anything relying on MySQL-specific SQL will
  pass locally and fail in production.
- The suite contains no count-based or ordered assertions
  (`assertSeeInOrder`, `assertSee($s, $count)`), which is what makes rendering
  the same record as both a card and a table row safe. **Adding one would couple
  the tests to that duplication.**

### 7.6 Frontend and build

- **Tailwind v3 scans Blade at build time.** A class that exists only in a file
  written after the last build does not exist in the CSS. A whole day was lost
  to a `public/build` compiled before the screens were written. After any change
  to markup, run `npm run dev` (or `npm run build`). If a screen looks unstyled,
  suspect the build before suspecting the markup.
- **`@tailwindcss/vite ^4.0.0` is in `package.json` but is never registered in
  `vite.config.js`.** It is inert. Tailwind v3 through PostCSS is what actually
  compiles. Do not "fix" this by wiring the v4 plugin in; that is a migration,
  not a config tidy-up.
- **Alpine plugins available in Livewire's bundle:** `collapse`, `intersect`,
  `anchor`, `mask`. **Not available:** `focus`, `persist`, `morph`. So
  `x-collapse` is safe but **`x-trap` does not exist** — no modal has a focus
  trap, and writing one silently does nothing. Verified by grepping
  `vendor/livewire/livewire/dist/livewire.js`.
- **Every `x-show` needs `style="display: none"` alongside it.** There is no
  x-cloak stylesheet, so without it the element flashes on load.
- Alpine 3 calls `destroy()` on the `x-data` object during teardown — that is
  how `form-modal` removes the body scroll lock.
- **A text input inside a `<form>` implicitly submits on Enter, and on a phone
  the keyboard's blue key *is* Enter.** The booking modal's customer search sat
  inside the form, so typing a name and reaching for "search" fired `save()` on
  an empty booking. Fixed with `x-on:keydown.enter.prevent="$event.target.blur()"`
  plus `enterkeyhint="search"`. Any new search field inside a form needs the same
  treatment.
- **A focused input that disappears leaves the keyboard up.** When a Livewire
  re-render or a modal close removes the focused element, iOS keeps the keyboard
  over the page with nothing to type into. Blur first — that is what
  `dropKeyboard()` is for, and why result buttons blur the *search box* rather
  than themselves.
- Inputs, selects and textareas carry `text-base sm:text-sm`. **Anything under
  16px makes iOS Safari zoom on focus** and the layout never comes back.
- Tailwind's `space-y-*` compiles to `> :not([hidden]) ~ :not([hidden])`, so an
  always-rendered wrapper introduces a permanent gap. `<x-toast>` works around
  it with an absolutely-positioned `sr-only` live region.

### 7.7 Tooling

- Grep for `<select` returns nothing because of entity escaping — use `select\b`.
- A repo-wide `grep -rn` times out at 120s on `vendor/`. Scope it to `app/`,
  `resources/`, `tests/`, `config/`, `routes/`.
- The Glob tool's default directory is **not** this repo; pass `path` explicitly.

---

## 8. Local development

**Standing instruction from the project owner, in his words:**
*"ami full app ta localiy run korte chai perfectly, localiy sob test korte chai,
then amra cpanel niye vabbo"* — the whole app running locally and perfectly,
everything tested locally, before deployment is discussed at all. **That
condition was met on 2026-09-10** — the reminder loop ran end to end locally and
the suite is green — so deployment work is now legitimately on the table. The
spirit of the instruction still stands: prove it locally first.

### First run (local XAMPP terminal)

```bash
cd C:\xampp\htdocs\bizapp
composer install
cp .env.example .env
php artisan key:generate
# create the MySQL database, set DB_* in .env
php artisan migrate:fresh --seed
npm install
npm run dev          # leave running while editing markup
php artisan serve
```

Seeded logins are printed on the welcome page in `local` only. All demo
passwords are `password`.

### Everyday commands

```bash
php artisan test                    # the 352-test baseline
php artisan test --filter=Foo       # one file
npm run dev                         # required after markup changes
php artisan config:clear            # required after any .env edit
```

### Messaging

```bash
php artisan telegram:poll           # local stand-in for the webhook
php artisan messages:plan           # fill the outbox
php artisan messages:queue          # inspect it, read-only
php artisan messages:dispatch       # send what is due
php artisan app:heartbeat           # scheduler liveness
```

### Manual reminder test — the exact sequence

1. Confirm `TELEGRAM_BOT_TOKEN` and `TELEGRAM_BOT_USERNAME` (no `@`) in `.env`,
   then `php artisan config:clear`.
2. Clear `MESSAGING_DRIVER` in `.env` so the real driver is used. Set it back to
   `log` afterwards.
3. Run **after 13:00 Dhaka**, otherwise London quiet hours defer everything.
4. Book an appointment **18–24 hours ahead** for a customer who has completed
   `/start` **with the current bot**.
5. `telegram:poll` in one terminal → send the invite link → customer sends
   `/start` → chat id is stored.
6. `messages:plan` → `messages:queue` (expect one pending row) →
   `messages:dispatch` → the message arrives on the phone.
7. `php artisan test` to confirm the baseline still holds.

If step 6 prints `already_planned` and nothing sends, read trap 7.4 first.

---

## 9. Open blockers and unknowns

### The one active blocker

**None in the core product.** The reminder loop was proven end to end on
2026-09-10 (section 3), which cleared the standing instruction that nothing
ship-related be discussed until the app ran perfectly locally.

The planner defect that was next in line (Phase 3.5) was fixed and verified on
2026-09-10 — 352 tests green. The next real piece of work is Phase 4,
deployment.

### Carried forward from the walkthrough

- The old `ScheduledMessage #1` is still `failed` from the revoked token. A
  `failed` row blocks re-planning for **its own appointment** by design (trap
  7.4), so if that booking is still in the future it will never be reminded.
  Cancel or delete the row for a clean outbox.
- `MESSAGING_DRIVER` is currently unset locally, so the real Telegram driver is
  live. Set it back to `log` between tests so a stray command cannot message a
  real person.

### Deferred by explicit decision

- **cPanel and deployment in their entirety.** Deferred until the app ran
  perfectly locally — a condition met on 2026-09-10, so this is now open.
- **Everything boss-facing.** The proposal document has not been sent, and three
  answers are outstanding from him: confirmation of the salon/barber niche, one
  free pilot business, and whether a registered UK company entity exists (Meta
  verification for WhatsApp depends on it).

### Genuine unknowns

- cPanel PHP version, extensions, and whether cron is available at all.
- Whether the host permits long-running processes (it is assumed not — hence the
  `--stop-when-empty --max-time=50` queue pattern sketched in
  `routes/console.php`).
- Commercial terms between Shovon and the boss are unsettled.
- Real pricing has never been tested against a real salon owner.

---

## 10. Roadmap

Phases are ordered by risk, not by size. Each has a gate: the observable fact
that says it is finished.

### ✅ Phase 0 — Foundation *(done)*
Laravel 12 + Livewire/Volt + Breeze. Multi-tenancy with the global scope and its
isolation tests. Schema for businesses, users, customers, services, staff,
appointments.
**Gate:** `TenantIsolationTest` and `TenantScreenAccessTest` green.

### ✅ Phase 1 — The owner's screens *(done)*
Dashboard, appointment diary with double-booking detection, customers with
search and filters, services, staff. Demo seeder with realistic history.
**Gate:** an owner can run a day's work in the app without touching the database.

### ✅ Phase 2 — The reminder engine *(done, proven live 2026-09-10)*
Outbox schema, `ReminderPlanner`, `MessageDispatcher`, driver contract, Telegram
driver with error classification, quiet hours, retry with backoff, per-customer
Telegram linking with `/stop`, webhook plus local polling, scheduler wiring.
**Gate:** the four messaging test files green (`MessageDispatcherTest` 20,
`MessageQueueCommandTest` 7, `ReminderPlannerTest` 17, `TelegramLinkingTest` 18
= 62 tests), **and** a reminder received on a real phone. Both met. ✅

### ✅ Phase 2.5 — Mobile pass *(done)*
44px targets, card layouts below `sm:`, bottom sheet modal, bottom tab bar,
16px input floor, keyboard handling, thin brand pass.
**Gate:** checked in a phone browser on 2026-09-09. ✅

### ✅ Phase 3 — Prove it works *(done 2026-09-10)*
The walkthrough in section 8, completed in one unbroken run: bot rotated to
`@auto_jis_bot`, customer re-linked via `/start`, booking made through the diary
for 2026-09-11 12:00 BST, `messages:plan` → 1 queued, `messages:dispatch` →
1 sent, reminder received on the phone.
**Gate:** a reminder received, and `php artisan test` still at 345. Both met. ✅

### ✅ Phase 3.5 — Fix what the walkthrough exposed *(done 2026-09-10)*
One real defect, found by reading the planner during the walkthrough rather than
by a failing test: **a `skipped` row permanently blocked re-planning for its
appointment.** `write()` records a row whether the customer is reachable or not,
and `alreadyPlanned()` excluded only `cancelled` — so the ordinary support case,
where the owner adds the missing number or the customer finally links Telegram,
left the reminder dead with no way to revive it short of a manual `DELETE`.

Fixed by reviving in place rather than by loosening the dedupe rule. The planner
runs every five minutes across a twelve hour horizon, so simply un-blocking
`skipped` would have written around a hundred and forty identical rows per
unreachable customer — turning the one screen that explains the problem into the
thing that buries it. `alreadyPlanned()` now excludes `cancelled` **and**
`skipped`; a new `revivableSkipped()` loads those rows in the same pass; `write()`
takes an optional existing row and rewrites it, resetting `attempts` and
`sent_at`. `failed` deliberately keeps blocking (trap 7.4).

**Gate:** a customer made reachable after a skipped reminder still gets reminded,
proven by test. Seven cases cover it — `ReminderPlannerTest` is now 24 tests — and
the full suite went 345 → **352 passed** on 2026-09-10, with `pint` clean on both
changed files. Met. ✅

### Phase 4 — Deploy
cPanel: PHP version and extensions, document root pointing at `public/`, `.env`
outside the webroot, MySQL, the single `schedule:run` cron entry, HTTPS,
`APP_DEBUG=false`, the Telegram webhook registered with its secret, and the
heartbeat confirming the scheduler is alive.
**Gate:** a reminder sent by cron on the server, with nobody watching.

### Phase 5 — Pilot
One free salon, ideally the boss's own contact. Real data, real bookings, real
reminders, and a weekly conversation about what is annoying. **Expect this phase
to rewrite the roadmap** — everything after it is a hypothesis until a real
owner has used it.
**Gate:** a pilot business using it unprompted for four consecutive weeks.

### Phase 6 — Sellable
The gap between "works" and "can be sold to a stranger":

- **Settings screen.** Quiet hours, default channel, business hours, reminder
  timing, invoice prefix. Currently only changeable through tinker, which means
  every client change is a developer task. This is the highest-value item in the
  phase.
- **GDPR tooling.** Data export, hard delete, retention purge
  (`gdpr:purge-old-data` is already stubbed in `routes/console.php`). **A UK
  client can ask for these on day one and there is currently no answer.**
- **Owner-visible message log.** "Was my customer reminded?" has no in-app
  answer today.
- Onboarding for a new business: without a seeder, the first screen an owner sees
  is five empty lists.
- Email as a free fallback channel.

**Gate:** a business can be onboarded, configured and supported without a
developer.

### Phase 7 — WhatsApp
The channel the market actually asks for, sequenced late because it is blocked
on someone else. Requires Meta business verification against a registered UK
company, a WhatsApp Business account and number, pre-approved message templates
(WhatsApp forbids free-form business-initiated messages outside the 24-hour
window), and a `WhatsAppCloudDriver` implementing `MessageDriver`.

The driver interface exists precisely so this is an addition, not a rewrite:
write the driver, register it in `MessagingManager::DRIVERS`, and the planner,
outbox, retries and quiet hours all work unchanged.
**Gate:** a WhatsApp reminder delivered through an approved template.

### Phase 8 — Invoicing and payments
Quotes and invoices from completed appointments, PDF generation, per-business
numbering (`invoice_prefix` is already there), overdue marking
(`invoices:mark-overdue` is stubbed), payment links, then Stripe. Deposits at
booking are the natural follow-on and the strongest no-show remedy after
reminders.
**Gate:** an owner sends an invoice and receives money without leaving the app.

### Phase 9 — Multi-client operations
The shift from one salon to a business:

- Subscription billing for the SaaS itself (`subscription_status` and
  `trial_ends_at` exist and are currently decorative). Trial expiry, dunning,
  cancellation.
- A real super-admin console — today a `business_id`-less user gets counts and
  little else.
- Per-tenant branded bots at scale (`channel_connections` was designed for this).
- Self-serve signup, if the sales model ever justifies it.
- Operational monitoring beyond the heartbeat: failed-message alerting, per-
  business delivery rates.

**Gate:** a new client can be onboarded and billed without Shovon touching the
server.

### Beyond
Online booking pages, marketing campaigns to lapsed customers
(`Customer::scopeLapsed()` already exists — this is the obvious second product),
loyalty, staff commission, multi-branch. All speculative; Phase 5 decides.

---

## 11. Deliberate gaps

Not oversights. Each was a decision, and each should be raised rather than
quietly fixed:

- **No settings screen.** Quiet hours and defaults change through tinker only.
- **No `ChannelConnectionFactory`.** Tests build those rows by hand.
- **No focus trap in any modal.** Alpine's `focus` plugin is not in Livewire's
  bundle, so `x-trap` would fail silently. An accessibility gap with no
  in-bundle fix.
- **No owner-facing message queue screen.**
- **The dashboard leads with "Good morning", not "who's next".** A candidate
  improvement, deferred until a pilot owner says which they want.
- **No one-tap `wa.me/…?text=` share** on the Telegram invite panel; the owner
  copies the link manually.
- **The diary is a list, not a time grid.** Offered and deferred on purpose.
- **`@tailwindcss/vite ^4.0.0` sits inert** in a Tailwind v3 project.
- **The earlier planning documents are not in this repo.** This document
  supersedes them.

---

## Appendix A — File map

```
app/
  Console/Commands/     CronHeartbeat, DispatchMessages, PlanMessages,
                        PollTelegram, ShowMessageQueue, TelegramWebhook
  Http/Controllers/     TelegramWebhookController, Auth/VerifyEmailController
  Livewire/             Actions/Logout, Concerns/TenantScreen, Forms/LoginForm
  Messaging/
    Contracts/          MessageDriver
    Drivers/            LogDriver, TelegramBotDriver
    Telegram/           TelegramApi, UpdateHandler
                        MessageDispatcher, MessagingManager, ReminderPlanner,
                        SendResult, TemplateRenderer
  Models/               Appointment, Business, ChannelConnection, Customer,
                        ScheduledMessage, Service, StaffMember, User
                        Concerns/BelongsToBusiness
  Observers/            AppointmentObserver
  Support/              BusinessSnapshot, Channel, Phone, QuietHours, Tenant

config/messaging.php    driver, batch_size, max_attempts, retry_after_minutes,
                        reminder{offset,min_notice,horizon,catch_up}, telegram{...}

database/migrations/    11 files — 8 project-specific, 2026_09_08_* and 2026_09_09_*
database/factories/     Appointment, Business, Customer, ScheduledMessage,
                        Service, StaffMember, User
database/seeders/       DatabaseSeeder, DemoBusinessSeeder

resources/views/
  components/           form-modal, toast, select-input, text-input,
                        primary-button, secondary-button, application-logo, …
  livewire/             dashboard, appointments/index, customers/index,
                        services/index, staff/index, layout/navigation,
                        pages/auth/*, profile/*

routes/web.php          welcome, dashboard, profile, tenant group, telegram
                        webhook, heartbeat
routes/console.php      the schedule — one cron entry drives all of it

tests/Feature/          24 files, 326 methods, 352 tests
docs/                   local-walkthrough.md, local-walkthrough-result.md,
                        HANDOVER.md (this file)
```

## Appendix B — Command reference

| Command | What it does | Where |
|---|---|---|
| `php artisan test` | Full suite; baseline 352 | local |
| `php artisan migrate:fresh --seed` | Rebuild with demo data | local only |
| `npm run dev` | Rebuild CSS — **required after markup changes** | local |
| `php artisan config:clear` | **Required after any `.env` edit** | both |
| `php artisan telegram:poll` | Read Telegram updates without a webhook | local |
| `php artisan messages:plan` | Fill the outbox | both |
| `php artisan messages:queue` | Inspect the outbox, read-only | both |
| `php artisan messages:dispatch` | Send what is due | both |
| `php artisan app:heartbeat` | Scheduler liveness | both |
| `php artisan schedule:run` | What cron calls every minute | server |

---

*Written 2026-09-09 against the repository as it stood. Every fact was checked
against the source rather than recalled. Where this document and the code
disagree, the code is right and this file needs correcting — please correct it.*
