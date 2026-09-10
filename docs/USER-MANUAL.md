# BizApp User Manual

## What this app does

BizApp is a booking and reminder app for small service businesses — salons, barbershops, therapists, tradespeople. It handles three things:

1. A diary of appointments
2. A customer list
3. Automatic reminders sent before each appointment

Reminders go out by Telegram or WhatsApp. The owner sets up services and staff once, then books customers into the diary. The system sends reminders 24 hours before each appointment, and respects quiet hours so customers aren't woken up.

---

## Getting started

### Logging in

Open the app in your browser and log in with the email and password your business owner gave you.

If you can't log in, ask the owner to check your account is still active.

### The five main screens

The bottom tab bar gives you access to the five screens you'll use most:

| Tab | What it does |
|-----|-------------|
| **Today** | Dashboard — see today's bookings, money, and anything that needs attention |
| **Diary** | Appointments — the full calendar view |
| **Customers** | Customer list — search, filter, invite to Telegram |
| **Services** | What you offer — add, hide, price, duration |
| **Staff** | Who works for you — add, hide, calendar colours |

Services and Staff are also available from the **More** tab on the bottom bar.

---

## First-time setup

If this is a new business, do these steps once before taking bookings.

### 1. Add your services

Go to **Services** and tap **Add service**.

| Field | What to put |
|-------|-------------|
| **Name** | What the customer sees — e.g. "Gents Cut" |
| **Description** | Optional. Shown on the booking confirmation. |
| **Minutes** | How long the appointment lasts — e.g. 30 |
| **Price** | What you charge — e.g. 18.00 |
| **Order in the list** | Leave at 0 unless you want a specific order |
| **Available to book** | Keep this ticked while the service is active |

Add every service you offer. You can hide one later without deleting it — history stays intact.

### 2. Add your staff

Go to **Staff** and tap **Add staff member**.

| Field | What to put |
|-------|-------------|
| **Name** | Shown on the diary and in confirmations |
| **Phone** | Optional. Used for SMS reminders if needed. |
| **Order in the list** | Leave at 0 unless you want a specific order |
| **Calendar colour** | Pick a colour — this shows on the diary so you can tell staff apart |
| **Available to book** | Keep ticked while they're working |

### 3. Add your customers

Go to **Customers** and tap **Add customer**.

| Field | What to put |
|-------|-------------|
| **Name** | Required |
| **Phone** | Any format — saved as +44… automatically |
| **WhatsApp** | Only if different from their main phone |
| **Email** | Optional. Used for email reminders only. |
| **Preferred channel** | Telegram, WhatsApp, Email, SMS, or None |
| **Notes** | Private staff notes — not sent to the customer |

**Preferred channel explained:**

- **Telegram** — free reminders. Customer needs to have started your bot.
- **WhatsApp** — requires Meta business setup. Not available yet.
- **Email** — sent to the email address above.
- **SMS** — paid per message. Falls back to the main phone number.
- **None** — silences all reminders for this customer. This is different from unsubscribing — they'll still get appointment reminders if their channel is set back later.

**Marketing consent** is separate from appointment reminders. Tick it only if they actually agreed to receive offers and news.

### 4. Invite customers to Telegram

If you want to send Telegram reminders, each customer needs to have started your bot once.

In the Customers list, find the customer and tap **Invite**. This opens a panel with a personal link. Copy the message and send it to the customer (WhatsApp, SMS, email — whatever works).

When they tap the link and press **Start** in Telegram, the app records their chat ID automatically. You'll see a **Telegram** badge appear on their customer card.

One link per customer. If they lose it, just open the invite panel again — the same link reappears.

---

## Taking bookings

### Creating a booking

Go to **Diary** and tap **New booking** (or tap an empty slot on the calendar).

1. **Customer** — start typing a name or phone number. If the number matches an existing customer, they're selected automatically. If not, a **+ New customer** button appears — tap it to create them inline.
2. **Service** — pick what they're having. The duration and price fill in automatically.
3. **Staff** — pick who's doing it. Leave blank if it doesn't matter.
4. **Date and time** — the app uses your business timezone. Type or pick the slot.
5. **Notes** — optional, e.g. "Allergic to toner".
6. Tap **Book it**.

**Double bookings:** If the slot is already taken, the app warns you and doesn't save. You can overrule the warning if it's intentional — the app won't stop you.

**Time rules:** Appointments must be at least 18 hours away for reminders to be scheduled. Same-day bookings won't get a reminder.

### Editing a booking

Tap any booking in the diary. Change whatever you need and tap **Save changes**.

If you move a booking to a different time or date, the old reminder is cancelled and a new one is created automatically.

### Cancelling a booking

Open the booking, change **Status** to **Cancelled**, and save.

The slot opens up for someone else. The reminder is cancelled. The customer's history and your takings figures stay intact — cancellation is just a status change, not a deletion.

### Marking a booking done

When the appointment finishes, open it and set **Status** to **Completed**.

This updates the customer's **last visit** date and **total spent** figure. If you later undo it, the money is taken back off.

### No-shows

If a customer doesn't turn up, set **Status** to **No-show**. This doesn't count as a visit and doesn't add to their spend.

---

## Understanding reminders

### How reminders work

When you create or move a booking, the app calculates when to send the reminder:

- **Default:** 24 hours before the appointment
- The reminder is scheduled in the customer's preferred channel
- If the send time falls between 9 PM and 8 AM London time, it waits until 8 AM

### Quiet hours

Quiet hours are **9 PM – 8 AM London time** (2 AM – 1 PM Dhaka). Nothing is sent during this window. If a reminder lands in quiet hours, it's held until 8 AM London time.

### The reminder pipeline

Three commands run the reminder system:

| Command | What it does |
|---------|-------------|
| `messages:plan` | Scans bookings 18–36 hours ahead and creates reminder rows |
| `messages:queue` | Shows you what's queued, pending, skipped, or failed |
| `messages:dispatch` | Sends out all pending reminders |

In production these run automatically via cron. Locally you run them by hand.

### Skip reasons

Sometimes a reminder can't be sent:

| Reason | What it means | What to do |
|--------|---------------|------------|
| **Telegram not linked** | Customer hasn't started the bot | Send them the invite link again |
| **WhatsApp not available** | WhatsApp channel isn't set up | Use a different channel or enable WhatsApp |
| **Customer unsubscribed** | They asked for no messages | Respect their choice |
| **Reminders switched off** | Channel set to None | Change preferred channel if they want reminders |

**Skipped reminders are not dead.** When the obstacle is fixed (customer links Telegram, channel changes), the next `messages:plan` run automatically revives the reminder and sends it. You don't need to delete and recreate anything.

**Failed reminders** are different — something went wrong at send time (invalid chat ID, bot blocked, network error). These stay failed and won't auto-revive. If a customer says they're not getting reminders, check the failed queue and clear the row if the issue is fixed.

### Checking the queue

Run `messages:queue` to see all reminders:

| Status | Meaning |
|--------|---------|
| **pending** | Ready to send |
| **skipped** | Can't send yet — see reason |
| **failed** | Send failed — check the error |
| **sent** | Already delivered |
| **cancelled** | Cancelled because booking changed |
| **held** | Waiting for quiet hours to pass |

Add `--body=1` to see the full message text for any row.

---

## Managing customers

### Searching and filtering

At the top of the Customers screen:

- **Search box** — type a name or phone number. Matches any format.
- **Filter dropdown** — narrow the list:
  - All customers
  - Can receive marketing
  - Not seen in 90 days
  - Unsubscribed
  - Telegram not linked

If the list comes up empty and you didn't mean it to, tap **Clear filters**.

### Customer cards (phone view)

Each card shows:
- Name and email
- Phone number (tap to call)
- Last visit, total spent, preferred channel
- Status badges: Unsubscribed, Marketing OK, Telegram

Tap the **three dots** to expand actions: Edit, Invite to Telegram, Remove.

### Removing a customer

Tap **Remove** on a customer. Their appointment history is kept — only the customer record itself is soft-deleted. If they come back, restore them and their history reappears.

### Lapsed customers

The **Lapsed** filter shows customers who haven't visited in 90 days or more. Use this for win-back campaigns.

---

## Managing services

### Hiding a service

Tap **Hide** on a service. It disappears from new bookings but stays on past ones, so your history and figures don't change.

To bring it back, tap **Show**.

### Deleting a service

You can only delete a service that has never been booked. If it has any appointment history, the app hides it instead — this protects your records.

### Service order

Use **Order in the list** to control which services appear first. Lower numbers show first. Leave at 0 if you don't care.

---

## Managing staff

### Hiding a staff member

Tap **Hide** when someone leaves. Their past bookings stay on the record. Tap **Show** to bring them back.

### Deleting a staff member

Only staff with zero bookings can be deleted. If they have history, the app hides them instead.

### Calendar colours

Each staff member gets a colour dot on the diary. Pick colours that are easy to tell apart — the app provides a curated palette.

---

## The diary (appointments screen)

### Week strip

The top of the diary shows a row of day labels — Monday to Sunday. Tap a day to jump to it. The current day is highlighted. Bookings appear as blocks on their local day, not their UTC day — so a midnight booking shows on the day it happened.

### Booking blocks

Each booking shows:
- Time
- Customer name
- Service name
- Status badge
- Price
- Staff colour dot

Tap a booking to open it for editing.

### Cancellations

Cancelled bookings are hidden by default. Tap **Show cancellations** to see them — they appear faded so you can still tell they're cancelled.

### Staff filter

Use the staff filter at the top to show only one person's bookings. This is useful when you're scheduling around availability.

---

## Profile and settings

### Your account

Go to **Profile** from the bottom-right menu.

- **Update profile information** — change your name or email
- **Update password** — change your login password
- **Delete account** — removes your user account. Ask the platform admin if you need this.

### Business settings

Business settings (name, timezone, quiet hours) are managed by the business owner. Ask them if you need something changed.

---

## Telegram setup (owner only)

### How it works

BizApp uses Telegram bots to send free reminders. One bot per business. The owner generates an invite link and sends it to each customer. When the customer taps the link and presses **Start** in Telegram, the app records their chat ID and can send them reminders.

### Generating an invite link

1. Go to **Customers**
2. Find the customer and tap **Invite**
3. Copy the ready-made message
4. Send it to the customer via WhatsApp, SMS, or email

The link is personal — one link per customer. Opening it again shows the same link.

### What the customer sees

When the customer taps the link:
1. Telegram opens to your bot
2. They tap **Start**
3. The bot replies with a confirmation
4. Their record in BizApp now shows a **Telegram** badge

### If the customer can't find the link

Just open the Invite panel again — the same link is still there. If they've already started the bot, the panel closes automatically.

### Stopping reminders

A customer can send `/stop` to the bot at any time. This unsubscribes them from all reminders. If they want to start again, send them the invite link once more.

---

## Understanding the dashboard

The Today screen shows four things:

### Today's bookings

How many appointments are scheduled for today, and how much money is expected.

### Next 7 days

Bookings coming up in the next week, split into:
- **Confirmed** — booked and confirmed
- **Unconfirmed** — pending, needs attention

### Lapsed customers

People who haven't visited in 90 days. Split into:
- **Reachable** — you can message them
- **Unreachable** — missing phone, unsubscribed, or Telegram not linked

### No-shows this month

How many no-shows this month and the value of lost time.

### Needs action

This panel only appears when there's something you should do — unreachable customers, unconfirmed bookings, lapsed win-back candidates. Each item has a direct link to the right screen.

---

## Privacy and data

- Customer phone numbers are stored in E.164 format (e.g. +447700900123)
- Appointment reminders are transactional, not marketing — they go out even if the customer hasn't ticked the marketing consent box
- Marketing consent is logged with the date, time, and IP address when it was given
- Customers can unsubscribe from all messages by sending `/stop` to the Telegram bot
- Removed customers are soft-deleted — their appointment history stays on record
- No data is shared with third parties

---

## Troubleshooting

### Customer isn't getting reminders

1. Check their **preferred channel** — is it set to a working channel?
2. If Telegram: check they have a **Telegram** badge. If not, send the invite link again.
3. If WhatsApp: check WhatsApp is set up for your business.
4. Check **messages:queue** for skip reasons.
5. Check **messages:queue --status=failed** for delivery errors.

### Reminder says "skipped"

Run `messages:queue` to see the reason. Common fixes:
- **Telegram not linked** — send invite link again
- **WhatsApp not available** — switch channel or enable WhatsApp
- **Customer unsubscribed** — they need to opt back in

### Double booking happened

The app warns before saving, but you can overrule it. If a double booking is confirmed:
1. Open both bookings
2. Move or cancel one of them
3. The diary updates immediately

### Can't see a customer

Check the filter dropdown at the top of the Customers screen. It might be set to **Unsubscribed** or **Telegram not linked**. Tap **Clear filters** to reset.

### Booking shows wrong time

The diary always shows times in your business timezone. If a booking looks wrong:
1. Check the business timezone in Profile → Business settings
2. Check your device timezone — the app uses the business timezone, not your phone's

---

## Quick reference

| Task | Where |
|------|-------|
| Add a service | Services → Add service |
| Add a staff member | Staff → Add staff member |
| Add a customer | Customers → Add customer |
| Book an appointment | Diary → New booking |
| Edit a booking | Diary → tap the booking |
| Cancel a booking | Diary → tap booking → Status → Cancelled |
| Invite to Telegram | Customers → tap Invite |
| Check reminders | Run `messages:queue` |
| Send reminders | Run `messages:dispatch` |
| See lapsed customers | Customers → filter: Not seen in 90 days |
| Hide a service | Services → tap Hide |
| Hide a staff member | Staff → tap Hide |
