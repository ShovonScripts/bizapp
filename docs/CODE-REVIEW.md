# BizFlow — কোড রিভিউ, 2026-09-12

> স্কোপ: uncommitted working tree — **34টা modified ফাইল (+4,597 / −496) + 37টা untracked ফাইল**।
> নতুন সাবসিস্টেম: Stripe deposit, WhatsApp Cloud API, public self-booking portal,
> email driver, settings স্ক্রিন, staff working hours, site settings/SEO।
>
> ⚠️ **আমি কোনো কোড চালাইনি** — আমার এখানে PHP/Composer/browser নেই।
> সব finding কোড পড়ে বের করা। তুমি XAMPP-এ verify করবে।

---

## এক নজরে

কাজের পরিমাণ বিশাল, আর engine-এর সাথে integration-গুলো ঠিক জায়গায় বসেছে।
কিন্তু **৭টা জিনিস আছে যেগুলো real client আসার আগে ঠিক করতেই হবে** — এর মধ্যে
দুইটা দিয়ে অপরিচিত লোক **টাকা না দিয়ে booking confirmed** করতে পারে, আর
**অন্য salon-এর customer data পড়তে** পারে।

| | কয়টা | কী ধরনের |
|---|---|---|
| 🔴 P0 | ৭ | payment forgery, cross-tenant data leak — client আসার আগে |
| 🟠 P1 | ৪ | encryption, log PII, WhatsApp production-এ কাজই করবে না |
| 🟡 P2 | ২ | GDPR consent, security test |
| ⚪ P3 | ২ | build, stale doc |

---

# 🔴 P0 — client আসার আগে অবশ্যই

## ১. Stripe webhook-এ signature verify হয় না

`app/Http/Controllers/StripePaymentController.php`

```php
    $sigHeader = $request->header('Stripe-Signature');   // ← নেওয়া হলো
    // ...এবং এর পরে কোথাও ব্যবহার হয়নি
```

header-টা পড়া হচ্ছে, তারপর **কিছুই করা হচ্ছে না**। route-টা CSRF-exempt
(`bootstrap/app.php`), তাই signature-ই ছিল একমাত্র authentication — সেটা নেই।

**ফল:** যে কেউ এই JSON টা POST করলেই:

```json
{"type":"checkout.session.completed","data":{"object":{"client_reference_id":"42"}}}
```

appointment #42 → `deposit_status = paid`, `status = CONFIRMED`। **এক পয়সাও না দিয়ে।**

`webhook_secret` ইতিমধ্যেই `stripeConfig()`-এ আছে — শুধু ব্যবহার করা হয়নি।

**ঠিক করার নিয়ম:** raw body-র উপর `"{timestamp}.{payload}"` এর HMAC-SHA256 বের করে
`hash_equals` দিয়ে মেলাও, না মিললে 400। সাথে timestamp tolerance (~৫ মিনিট) — নইলে
পুরোনো valid request replay করা যাবে।

---

## ২. `verifySession()` জাল payment approve করে

`app/Services/Payment/StripePaymentService.php` — এখানে **তিনটা আলাদা ফুটো**:

```php
    if (str_starts_with($sessionId, 'cs_test_') || str_starts_with($sessionId, 'cs_fallback_')) {
        return ['success' => true, ...];   // Stripe-কে জিজ্ঞেসই করা হয় না
    }
```

**(ক) `cs_test_` হলো Stripe-এর আসল test-mode prefix।** এটা তোমার mock-এর নিজস্ব
কিছু না। মানে test mode-এ প্রতিটা **সত্যিকারের** session-ও যাচাই ছাড়া pass করে যাবে।

**(খ) session id আসে query string থেকে** (`$request->query('session_id', ...)`)।
মানে কেউ browser-এ শুধু এটা লিখলেই booking paid হয়ে যাবে:

```
/booking/42/payment-success?session_id=cs_test_anything
```

**(গ) secret key ফাঁকা হলেও `success = true`** ফেরত যায়। যে salon এখনো Stripe
connect করেনি, তার প্রতিটা deposit "paid" দেখাবে।

**ঠিক করার নিয়ম:** mock path কখনো **string prefix** দেখে ঠিক হবে না — explicit
config flag দিয়ে হবে (`config('services.stripe.mock')`, শুধু local/test-এ true)।
আর key ফাঁকা = `success: false`, কারণ যাচাই করা যায়নি মানে টাকা আসেনি।

---

## ৩. WhatsApp webhook POST-এ কোনো verification নেই

`app/Http/Controllers/WhatsAppWebhookController.php`

GET verification (`hub.challenge`) **সঠিকভাবে** `hash_equals` দিয়ে করা — ওটা ঠিক আছে।
কিন্তু POST handler body-টা সরাসরি বিশ্বাস করে।

`processSingleMessage()` → `from` নম্বর দিয়ে customer খোঁজে → text টা
`AppointmentResponseHandler`-এ পাঠায় → `"cancel"` পেলে **সাথে সাথে appointment cancel**।

**ফল:** অপরিচিত কেউ victim-এর ফোন নম্বর আর `"cancel"` শব্দ দিয়ে forged payload POST
করলে **victim-এর appointment cancel হয়ে যাবে**। salon-ও জানবে না কেন।

**ঠিক করার নিয়ম:** `X-Hub-Signature-256` header — raw body-র HMAC-SHA256, key হলো
Meta app secret — `hash_equals` দিয়ে মেলাও, না মিললে 403। এটাও CSRF-exempt route,
তাই signature-ই একমাত্র দরজা।

---

## ৪. `.ics` route — cross-tenant data leak

`routes/web.php`

```php
    Route::get('appointments/{appointment}/calendar.ics', function (string $id) {
        $appointment = \App\Models\Appointment::withoutGlobalScope('business')
            ->findOrFail($id);            // ← public route, sequential integer id
```

Unauthenticated route + global scope বন্ধ + ক্রমিক id। কেউ `/1`, `/2`, `/3` করে
হাঁটলেই **প্ল্যাটফর্মের প্রতিটা business-এর** customer নাম, service, staff, সময়,
notes আর business phone ডাউনলোড হবে।

> এটা ঠিক সেই জিনিস যেটা আমাদের নিজেদের নিয়মে লেখা আছে:
> **tenant isolation fail = UK GDPR-এ reportable breach, শুধু bug না।**

**ঠিক করার নিয়ম:** `cancellation_token` (already `Str::random(64)`) দিয়ে route key
করো — ঠিক যেভাবে `booking.cancel` করে। raw id কখনোই না।

মজার ব্যাপার: **তোমার `booking.cancel` route-টা এই কাজটা একদম ঠিকভাবে করেছে।**
ওখানকার প্যাটার্নটাই এখানে copy করো।

---

## ৫. `?confirmed_id=` দিয়ে অন্যের token পড়া যায়

`resources/views/livewire/booking/public.blade.php` → `mount()`

```php
    $confirmedId = request()->query('confirmed_id');
    $appointment = Appointment::withoutGlobalScopes()
        ->where('business_id', $business->id)
        ->find($confirmedId);            // ← id টা query string থেকে, যাচাই নেই
```

`business_id` দিয়ে scope করা আছে — ভালো। কিন্তু **ঐ business-এর ভিতরে কোনো
যাচাই নেই**। salon-এর slug জানলেই `?confirmed_id=1,2,3...` করে হাঁটা যায়।

আর confirmation screen-টা শুধু নাম দেখায় না, **দুইটা secret** দেখায়:

| leak | কী করা যায় |
|---|---|
| `cancellation_token` | অন্যের appointment cancel |
| `telegram_link_token` | **নিজের Telegram ওই customer-এর record-এ link** করে ভবিষ্যতের সব reminder পড়া |

দ্বিতীয়টা বেশি খারাপ। এটা ঠিক সেই forwarded-link attack যেটা `UpdateHandler`-এ
তুমি **already defend করেছো** ("used token stays with chat") — কিন্তু সেই
defence-টা এখানে বাইপাস হয়ে যাচ্ছে, কারণ attacker টোকেনটা সরাসরি পেয়ে যাচ্ছে।

**একই সমস্যার আরেকটা মুখ:** `submitBooking()` phone দিয়ে existing customer খুঁজে
নেয়। কারো ফোন নম্বর জানলে তার নামে booking করে confirmation screen-এ **তার
token-গুলো** দেখে ফেলা যায়। ফোন verify (OTP) নেই।

**ঠিক করার নিয়ম:** confirmation view `cancellation_token` দিয়ে gate করো, অথবা
just-booked id টা **session-এ** রাখো, URL-এ না।

---

## ৬. WhatsApp inbound — ভুল business-এর appointment cancel হতে পারে

```php
    $customer = Customer::withoutGlobalScope('business')
        ->where(...phone match...)
        ->first();                       // ← পুরো platform, প্রথমটা নেয়
```

একই ফোন নম্বর দুইটা salon-এ customer হলে — যা UK-তে খুব স্বাভাবিক — `"cancel"`
reply করলে **ভুল salon-এর appointment cancel** হবে। চুপচাপ।

**ঠিক করার নিয়ম:** Meta payload-এ `value.metadata.phone_number_id` থাকে — ওটা
দিয়ে business resolve করে তারপর customer খোঁজো।

> **এখানে একটা সূক্ষ্ম পার্থক্য মনে রাখো।** Telegram-এর `/stop` ইচ্ছাকৃতভাবে ওই
> chat id-র **সব** record-এ প্রয়োগ হয় — সেটা ঠিক আছে, কারণ বেশি opt-out করা নিরাপদ।
> কিন্তু **cancel** বেশি করা নিরাপদ না। একই যুক্তি এখানে উল্টো দিকে যায়।

---

## ৭. Stripe mock fallback live path-এ আছে

```php
    // Graceful fallback to mock session on connection/API error
    $mockSessionId = 'cs_fallback_' . uniqid() . '_' . $appointment->id;
    return ['url' => $successUrl . '...&mock=1'];
```

Stripe API একবার timeout করলে customer সোজা success URL-এ চলে যায় → `verifySession()`
`cs_fallback_` দেখে auto-approve করে → **booking paid, টাকা আসেনি**।

cPanel আর api.stripe.com-এর মাঝে একটা network hiccup = একটা ফ্রি booking।

**নিয়ম:** payment path সবসময় **fail closed** হবে। সৎভাবে বলো "payment শুরু করা
গেল না, আপনার slot ১৫ মিনিট ধরে রাখা আছে" — আর `deposit_status` unpaid-ই থাকবে।

---

# 🟠 P1 — launch-এর আগে

## ৮. Stripe secret key plaintext-এ জমা হচ্ছে

Settings স্ক্রিন `stripe.secret_key` লেখে `businesses.settings`-এ। কিন্তু:

```php
    protected $casts = [
        'settings' => 'array',        // ← encrypted না
    ];
```

Database-এ **plaintext JSON**-এ live Stripe secret key। ঐ key দিয়ে charge করা যায়,
পুরো customer list পড়া যায়।

`channel_connections.credentials`-এ তুমি এই নিয়মটা **already ঠিকভাবে** মেনেছো
(`encrypted:json` + `$hidden`) — Stripe-এ মানা হয়নি।

**সাবধান:** `settings`-কে পুরোটা `encrypted:array` বানিয়ে দিও **না**। ওখানে
`quiet_hours`, `opening_hours` সব আছে আর সেগুলো constantly পড়া হয় — data migration
ছাড়া পুরোনো row গুলো decrypt fail করবে। আলাদা encrypted column, অথবা Stripe keys
`channel_connections`-এ সরাও।

---

## ৯. Log-এ PII যাচ্ছে

আমাদের নিয়ম: **log-এ কখনো token / phone / chat id / raw provider body না।**
দুই জায়গায় ভাঙছে:

```php
    Log::info('[StripeWebhook] Received webhook', ['payload' => substr($payload, 0, 100)]);
    Log::info('[whatsapp] ... unlinked number', ['from' => $digits]);
```

প্রথমটায় body-র শুরুতেই session/customer data থাকে। দ্বিতীয়টা সরাসরি ফোন নম্বর।
shared cPanel-এ log ফাইল database-এর চেয়ে অনেক বেশি লোকে পড়তে পারে।

**ঠিক করো:** body-র বদলে event type + appointment id; ফোন নম্বর বাদ দাও বা hash করো।

---

## ১০. WhatsApp reminder production-এ **কখনোই** যাবে না

এটা security না, কিন্তু সমান গুরুত্বপূর্ণ — কারণ code review-তে একদম সুস্থ দেখায়।

`WhatsAppCloudApiDriver::send()` পাঠায় `type: text` (free-form)।

Meta free-form text শুধু **24-hour customer service window**-এর ভিতরে দেয় —
অর্থাৎ customer আগে message করলে। কিন্তু **24 ঘণ্টা আগের reminder সংজ্ঞা অনুযায়ীই
ওই window-এর বাইরে**, কারণ customer কিছু লেখেনি।

Meta reject করবে **error 131047** — আর তোমার `classify()` ওটাকে (সঠিকভাবেই)
permanent failure ধরে। মানে WhatsApp reminder **১০০% fail করবে**, চুপচাপ।

**দরকার:** pre-approved **template message** (`type: template`)।

> ⚠️ এখানে একটা architectural সংঘর্ষ আছে যেটা আগে ভাবতে হবে।
> `TemplateRenderer`-এর নিয়ম হলো **value null → পুরো লাইন বাদ**। কিন্তু approved
> template-এ parameter সংখ্যা **fixed** — একটা null চুপচাপ লাইন সরাতে পারে না,
> Meta reject করবে। Settings UI বানানোর **আগে** template-এর shape ঠিক করো।

---

# 🟡 P2 — GDPR ও টেস্ট

## ১১. Public booking-এ consent log হচ্ছে না

`submitBooking()` খোলা ইন্টারনেট থেকে নাম/ফোন/email নেয় আর
`preferred_channel = defaultChannel()` বসিয়ে দেয় — অর্থাৎ messaging-এ enrol করে,
কিন্তু **কীভাবে consent পাওয়া গেল তার কোনো record নেই**।

`consent_source`, `consent_at` — column দুইটা schema-তে **আছে**, লেখা হচ্ছে না।

Transactional reminder contract basis-এ defensible, কিন্তু UK GDPR-এ data
collect করার **মুহূর্তে** বলতে হয় কে রাখছে আর কেন।

**করো:** submit button-এর পাশে `/privacy` link (page টা already আছে), আর
`consent_source = 'booking_form'` + `consent_at` লেখো।
**`marketing_consent` এই ফর্ম থেকে কখনো true করবে না।**

---

## ১২. Security test একটাও নেই

১২টা নতুন test file, ~৭০টা নতুন test — happy path ভালোই cover করেছে।
কিন্তু `tests/`-এ `signature`, `X-Hub`, `calendar.ics` — **একটাও নেই**।

উপরের প্রতিটা fix-এর জন্য এমন test লাগবে যেটা **আজকের কোডে fail করে**:

- forged Stripe payload → reject
- `?session_id=cs_test_forged` → paid হবে না
- unsigned WhatsApp POST → reject
- business A → business B-র `.ics` পড়তে পারবে না
- অন্যের `?confirmed_id=` → token render হবে না

failing-first test ছাড়া security fix পরেরবার ফাইল ছুঁলেই আবার ভেঙে যায়।

---

# ⚪ P3 — housekeeping

**১৩.** `npm run build` চালাও (local/XAMPP) — `app.css` শেষ build-এর পরে বদলেছে,
তাই reduced-motion block আর নতুন Tailwind class এখনো compiled CSS-এ পৌঁছায়নি।

**১৪.** `docs/Analysis-results-v1.md` (untracked, AI-generated, আমার লেখা না) বলছে
অ্যাপ "uses SQLite" — **ভুল**। locally MySQL, SQLite শুধু test-এ (`phpunit.xml`)।
ঠিক করো বা `docs/` থেকে সরাও, নইলে ভবিষ্যতে কেউ ওটাই বিশ্বাস করবে।

---

# ✅ যেগুলো ঠিকভাবে হয়েছে

এগুলো বলা দরকার, কারণ এগুলোই প্রমাণ যে architecture টা কাজ করছে:

- **Public booking-এ tenant scoping সঠিক।** `firstOrFail()` slug দিয়ে →
  `Tenant::set()` → আর `boot()` প্রতিটা পরের Livewire request-এ আবার set করে।
  এই `boot()`-টা অনেকে ভুলে যায় (`mount()` আর চলে না), তুমি ভোলোনি।
- **`booking.cancel` একদম ঠিক** — unguessable token + `business_id` scope।
  `.ics` route-এ এই প্যাটার্নটাই লাগবে।
- **`cancellation_token` = `Str::random(64)`**, observer-এ auto-generate।
- **WhatsApp driver-এর `classify()` চমৎকার** — Meta-র error code গুলো
  retryable vs permanent-এ ঠিকভাবে ভাগ করা। এটা সাধারণত production-এ ব্যথা
  পাওয়ার পরে শেখা হয়।
- **Engine-এ হাত দিতে হয়নি** — planner / dispatcher / quiet hours / retry
  channel-agnostic ছিল, তাই WhatsApp আর email driver শুধু plug করা গেছে।
  এটাই মূল architecture-এর সবচেয়ে বড় প্রমাণ।
- **GET webhook verification `hash_equals` দিয়ে** — timing-safe, ঠিক আছে।

---

# কোন ক্রমে করবে

```
১. সব commit + push          ← আগে। এখন সব কাজ শুধু তোমার হার্ডডিস্কে।
২. P0 #1,2,7 (Stripe)        ← একসাথে, একই ফাইল-গুচ্ছ
৩. P0 #3,6 (WhatsApp)        ← একসাথে, একই controller
৪. P0 #4,5 (token/IDOR)      ← একসাথে, একই প্যাটার্ন
৫. প্রতিটার জন্য test         ← fix-এর সাথে সাথে, পরে না
৬. P1 → P2 → P3
```

**Stripe আর WhatsApp আলাদা করে ভেবো না** — দুইটাই "বাইরের কেউ POST করছে,
আমি কীভাবে জানব এটা সত্যি ওরাই?" একই প্রশ্ন। উত্তরও একই: signature verify করো।

---

## দুইটা প্রশ্ন যেগুলোর উত্তর আমার দরকার

**১. Design layer।** তোমার তিনটা UI সিদ্ধান্তের একটা ছিল **"brand = পাতলা পরত,
শুধু রং"**। কিন্তু নতুন glass-card / gradient / animation layer সেটার চেয়ে অনেক
বেশি। এটা কি ইচ্ছাকৃত নতুন সিদ্ধান্ত? হ্যাঁ হলে আমি memory আপডেট করব।

**২. UK company entity।** WhatsApp-এর কোড লেখা হয়ে গেছে, কিন্তু Meta-র Business
Verification-এ registered UK company লাগে। boss-এর কাছ থেকে এটার উত্তর না পেলে
WhatsApp-এর বাকি কাজ (#10 template) করার কোনো মানে নেই।

---

*রিভিউ: 2026-09-12 · কোড পড়ে, চালিয়ে নয় · টাস্ক লিস্ট অ্যাপে আছে (#4–#17)*
