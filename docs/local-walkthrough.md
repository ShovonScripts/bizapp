# লোকাল ওয়াকথ্রু — reminder পুরোটা নিজের চোখে দেখা

উদ্দেশ্য: XAMPP-এ পুরো অ্যাপ চালিয়ে একটা আসল Telegram reminder নিজের ফোনে আনা।
সব কমান্ড **লোকাল টার্মিনালে** (`C:\xampp\htdocs\bizapp`) — cPanel-এ এখনো কিছু না।

---

## ০. সময়ের দুইটা নিয়ম — এটা আগে পড়ো

পুরো ওয়াকথ্রুতে যা ভুল হওয়ার সম্ভাবনা সবচেয়ে বেশি, সেটা কোড না, সময়।

**নিয়ম ১ — appointment টা এখন থেকে ১৮ থেকে ২৪ ঘণ্টা পরে হতে হবে।**

Planner প্রতিবার শুধু একটা জানালার ভেতরের appointment দেখে:
`এখন − ৬ ঘণ্টা + ২৪ ঘণ্টা` থেকে `এখন + ১২ ঘণ্টা + ২৪ ঘণ্টা`।
মানে **১৮ থেকে ৩৬ ঘণ্টা পরের** booking-ই সে দেখে। এর বাইরেরটা "দেরি হয়ে গেছে" না — "এখনো সময় হয়নি"।

আর reminder যায় appointment-এর ২৪ ঘণ্টা আগে। তুমি চাও এটা **এখনই** যাক, অপেক্ষা করতে চাও না — তাই booking টা এমন সময়ে দাও যেটা **২৪ ঘণ্টার চেয়ে কম** দূরে। দুইটা মিলিয়ে:

> **এখন থেকে ১৮–২৪ ঘণ্টা পরে booking দাও।** ২০ ঘণ্টা পরেরটা নিরাপদ পছন্দ।

তখন send_at হিসাব হয় "৪ ঘণ্টা আগে" → অতীত → `now`-এ টেনে আনা হয় → `messages:dispatch` সাথে সাথেই পাঠায়।

**নিয়ম ২ — quiet hours ঢাকার সময়ের সাথে খাপ খায় না।**

Business-এর timezone `Europe/London`, quiet hours ২১:০০–০৮:০০ লন্ডন। সেপ্টেম্বরে লন্ডন ঢাকার চেয়ে **৫ ঘণ্টা পিছিয়ে**, তাই:

> quiet hours = **রাত ২টা থেকে দুপুর ১টা, ঢাকার সময়ে**।

সকাল ১০টায় টেস্ট করলে dispatcher কিছুই পাঠাবে না — deferred দেখাবে, আর send_at ঠেলে দেবে দুপুর ১টায়। এটা বাগ না, ঠিক আচরণ।

দুইটা উপায়:

- **সহজ:** দুপুর ১টা থেকে রাত ২টার মধ্যে টেস্ট করো (= লন্ডনে ০৮:০০–২১:০০)।
- **নাহলে** নিচের tinker দিয়ে টেস্ট business-এর quiet hours বন্ধ করো। **plan চালানোর আগেই করবে** — পরে করলে সারি হয়ে যাওয়া row-এর পুরনো send_at বদলাবে না।

```
php artisan tinker
```
```php
$b = App\Models\Business::first();
$b->settings = array_merge($b->settings ?? [], ['quiet_hours' => ['from' => '00:00', 'to' => '00:00']]);
$b->save();
App\Support\QuietHours::isQuiet($b, now());   // false আসা উচিত
exit
```

`from` আর `to` এক হলে জানালার প্রস্থ শূন্য, কোড সেটাকে "quiet hours নেই" ধরে। পরে `'21:00'` / `'08:00'` বসিয়ে ফিরিয়ে দিও।

---

## ১. প্রস্তুতি

`.env` তে তিনটা জিনিস দেখে নাও:

| কী | কী হওয়া উচিত | কেন |
|---|---|---|
| `TELEGRAM_BOT_TOKEN` | **নতুন** token (chat-এ যেটা এসেছিল সেটা revoke করা) | পুরনোটা compromised |
| `TELEGRAM_BOT_USERNAME` | `jis26bot` | Invite লিংক এটা দিয়ে বানানো হয় |
| `MESSAGING_DRIVER` | **সেট করা থাকবে না** | সেট থাকলে (`log`) কিছুই আসলে পাঠানো হবে না |

তারপর:

```
php artisan config:clear
php artisan telegram:webhook
```

দ্বিতীয় কমান্ডটা কিছু বদলায় না, শুধু রিপোর্ট করে — বট সাড়া দিচ্ছে কিনা, username মিলছে কিনা, webhook বসানো আছে কিনা। `Bot: @jis26bot` দেখলে token ঠিক আছে।

---

## ২. Telegram শোনা শুরু করো

Webhook-এর জন্য HTTPS লাগে, localhost-এ নেই। তাই লোকালে polling:

```
php artisan telegram:webhook --delete     (একবার, আগে কখনো webhook বসিয়ে থাকলে)
php artisan telegram:poll
```

**এই টার্মিনালটা খোলা রেখে দাও।** নতুন টার্মিনালে বাকি কমান্ড চালাবে।

Webhook বসানো থাকলে Telegram একই সাথে polling দিতে দেয় না — 409 Conflict দেয়। তাই `--delete` লাইনটা।

---

## ৩. ব্রাউজারে: business থেকে booking পর্যন্ত

`http://localhost/bizapp/public` (বা তোমার vhost)

1. **Register** — নতুন business খোলো। এটা owner অ্যাকাউন্ট বানায়, timezone `Europe/London`।
2. **Services** — একটা service যোগ করো, যেমন `Signature Cut`, ৩০ মিনিট, £৩৫।
3. **Staff** — একজন যোগ করো।
4. **Customers** — নতুন customer, channel **Telegram**। নাম নিজের দাও, ফোন `07700 900123` (Ofcom-এর নাটকের জন্য সংরক্ষিত রেঞ্জ, কারো কাছে যাবে না)।

---

## ৪. Invite — এই ধাপটাই আসল

Customers স্ক্রিনে ওই row-তে **Invite** বাটন। চাপো।

একটা প্যানেল খুলবে, ভেতরে দুইটা জিনিস: WhatsApp-এ পাঠানোর মতো তৈরি বার্তা, আর শুধু লিংকটা। **এখান থেকে কিছুই পাঠানো হয় না** — owner নিজে কপি করে পাঠাবে, এটাই তোমার বেছে নেওয়া ফ্লো।

লিংকটা কপি করে **নিজের ফোনে** খোলো (নিজেকে Telegram-এ পাঠাও, বা QR)। ফর্ম্যাট হবে:

```
https://t.me/jis26bot?start=<লম্বা টোকেন>
```

ফোনে Telegram খুলবে, **START** চাপো। বট উত্তর দেবে — বার্তায় `all set`, business-এর নাম আর `/stop` থাকবে।

`telegram:poll` টার্মিনালে update টা প্রসেস হতে দেখবে।

**যাচাই:** Customers পেজ রিফ্রেশ করো — **Invite বাটন আর নেই**। মানে `telegram_chat_id` বসে গেছে।

> লিংক না খুলে ফরওয়ার্ড করে অন্য কাউকে দিয়ে খোলালে বট বলবে "already been used" আর কারো নাম ফাঁস করবে না। চাইলে দ্বিতীয় Telegram অ্যাকাউন্ট দিয়ে দেখতে পারো।

---

## ৫. Booking

**Appointments** → **New booking** → ওই customer, ওই service, ওই staff।

তারিখ-সময়: **এখন থেকে ২০ ঘণ্টা পরে** (নিয়ম ১)। ফর্মের সময় business-এর লোকাল সময়, মানে লন্ডন — ঢাকার সময় থেকে ৫ ঘণ্টা বাদ দিয়ে ভাববে।

উদাহরণ: এখন ঢাকায় বিকাল ৪টা (লন্ডনে সকাল ১১টা)। ২০ ঘণ্টা পরে = **পরদিন লন্ডন সময় সকাল ৭টা**। ফর্মে ওটাই লিখবে।

---

## ৬. টার্মিনালে: plan → দেখা → পাঠানো

```
php artisan messages:plan
```

আশা করা যায়: `Planned: 1 queued, 0 skipped, 0 already planned, 0 too close to bother.`

`0 queued` পেলে কমান্ডটা এখন নিজেই বলে দেবে কোন জানালাটা সে দেখেছে — appointment টা তার বাইরে পড়েছে, সময় ঠিক করে আবার booking দাও।

```
php artisan messages:queue
```

সারিতে row টা দেখাবে: কখন যাবে (business-এর লোকাল সময়ে), কোন channel, status, আর বার্তার প্রথম লাইন। send_at যদি অতীত/এখন হয়, তাহলে পরের ধাপে যাবে।

```
php artisan messages:queue --body=1
```

customer আসলে ঠিক যে লেখাটা পাবে, হুবহু সেটা। রেন্ডার হয় plan-এর সময়, তাই এটাই যাবে — dispatcher নতুন করে বানায় না।

```
php artisan messages:dispatch
```

`sent: 1` আশা করা যায় — আর **ফোন বাজবে**।

```
php artisan messages:queue
```

status এখন `sent`।

---

## ৭. যেগুলো ভুল হওয়ার কথা, সেগুলোও দেখে নাও

এই চারটা আসলে বেশি গুরুত্বপূর্ণ, কারণ ঠিকটা কাজ করা সহজ।

**ক) দুইবার চললে দুইবার যায় না**

```
php artisan messages:plan
```
→ `1 already planned, 0 queued`. একই reminder দুইবার যায় না।

**খ) `/stop` আসলেই থামায়**

ফোনে বটকে `/stop` লেখো। বট বলবে আর message যাবে না। তারপর নতুন একটা booking (আবার ২০ ঘণ্টা পরে) দিয়ে:

```
php artisan messages:plan
php artisan messages:queue
```

`skipped` status আর কারণ হিসেবে **"Asked us to stop."** দেখাবে। চুপচাপ কিছু না করার বদলে কারণসহ row রাখাটাই নকশা — "Sarah কেন reminder পেল না" প্রশ্নের উত্তর তিন সপ্তাহ পরেও থাকে।

আবার চালু করতে ফোনে `/start` না — ওটা unsubscribe ফেরায় না (ইচ্ছাকৃত, PECR)। tinker-এ:

```php
$c = App\Models\Customer::withoutGlobalScope('business')->first();
$c->forceFill(['unsubscribed_at' => null])->save();
```

**গ) booking বাতিল করলে reminder-ও বাতিল**

একটা pending reminder আছে এমন booking কে Appointments স্ক্রিন থেকে Cancel করো, তারপর `messages:queue` — ওই row `cancelled`।

**ঘ) booking সরালে পুরনোটা বাতিল, নতুনটা তৈরি**

সময় বদলাও → পুরনো row `cancelled`, `messages:plan` চালালে নতুন সময় নিয়ে নতুন row।

---

## ৮. শেষে

```
php artisan test
```

আগের ৩৩৮-এর সাথে `messages:queue`-এর ৭টা নতুন যোগ হয়েছে, তাই **৩৪৫** আশা করছি। কম বা fail হলে হুবহু output পাঠিও।

quiet hours বন্ধ করে থাকলে ফিরিয়ে দাও:

```php
$b = App\Models\Business::first();
$b->settings = array_merge($b->settings ?? [], ['quiet_hours' => ['from' => '21:00', 'to' => '08:00']]);
$b->save();
```

---

## আটকে গেলে

| যা দেখছো | সাধারণত কারণ |
|---|---|
| Invite চাপলে "Telegram is not set up yet" | `.env`-এ `TELEGRAM_BOT_USERNAME` নেই, বা `config:clear` করোনি |
| লিংকে START চাপলে বট চুপ | `telegram:poll` চলছে না, বা webhook বসানো আছে (`--delete` করো) |
| "isn't valid any more" | টোকেন ভুল কপি হয়েছে, বা অন্য কেউ আগে ব্যবহার করেছে |
| `messages:plan` → 0 queued | appointment ১৮–৩৬ ঘণ্টার জানালার বাইরে (কমান্ডই জানালাটা ছাপবে) |
| `messages:dispatch` → deferred | এখন quiet hours (ঢাকায় রাত ২টা–দুপুর ১টা) |
| `messages:dispatch` → skipped | কারণ `messages:queue`-তে লেখা আছে, পড়ে নাও |
| queue-তে কিছুই নেই | `messages:plan` চালাওনি |
| status `failed`, "token is wrong or has been revoked" | নতুন token `.env`-এ বসাওনি, বা `config:clear` বাকি |
