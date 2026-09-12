<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Global driver override
    |--------------------------------------------------------------------------
    |
    | Null means "use the real driver for each channel". Set MESSAGING_DRIVER=log
    | and every channel resolves to LogDriver instead, so nothing leaves the
    | building.
    |
    | This is the single most important safety switch in the app. Development
    | data contains real-looking customers, and it takes one stray `messages:
    | dispatch` against a seeded database to send fifty strangers a reminder for
    | an appointment that does not exist. phpunit.xml pins this to `log` so no
    | test can ever send, and .env.example ships with it set to `log` too.
    |
    | Turning it off is a deliberate act, and should be the last thing you change
    | before a real client goes live — not the first.
    |
    */

    'driver' => env('MESSAGING_DRIVER'),

    /*
    |--------------------------------------------------------------------------
    | Dispatch behaviour
    |--------------------------------------------------------------------------
    */

    // Messages per run of messages:dispatch. Shared hosting kills long
    // processes, and 50 sequential HTTP calls is already pushing it. The
    // scheduler runs every minute, so a backlog clears at 3,000/hour.
    'batch_size' => (int) env('MESSAGING_BATCH_SIZE', 50),

    // Tries before giving up. Only retryable failures count against this —
    // a blocked bot fails permanently on the first attempt.
    'max_attempts' => 3,

    // Backoff before a failed message becomes due again. Index = attempt number.
    // Deliberately short: a reminder is worthless once the appointment starts.
    'retry_after_minutes' => [1 => 5, 2 => 20],

    /*
    |--------------------------------------------------------------------------
    | The appointment reminder
    |--------------------------------------------------------------------------
    |
    | One hard-coded automation for now. The schema doc's `automation_rules`
    | table generalises this later; until a second automation exists, a table
    | with one row in it is indirection without a payoff.
    |
    */

    'reminder' => [

        // How far ahead of the appointment. 24 hours: long enough to rearrange
        // the day, short enough to still be remembered.
        'offset_minutes' => 1440,

        /*
         * Do not plan a reminder for an appointment closer than this.
         *
         * Someone booking two hours ahead does not need reminding — they are
         * already on their way. Without this rule, a walk-in booking would
         * trigger a "reminder" seconds after the owner saved it, which reads as
         * a bug to everyone who sees it.
         */
        'min_notice_minutes' => 120,

        /*
         * How far forward the planner looks for messages coming due.
         *
         * Rows are created shortly before they are needed rather than the moment
         * an appointment is booked. A reminder planned three weeks early is a
         * row that has to be cancelled and rebuilt every time the customer moves
         * their slot — more chances to leave a stale message queued.
         */
        'plan_horizon_hours' => 12,

        /*
         * How far back the planner will still pick up a message it missed.
         *
         * Shared-hosting cron does go down. If it was off for an hour, we still
         * want yesterday's un-planned reminders. The min_notice rule above stops
         * this from sending anything embarrassingly late.
         */
        'catch_up_hours' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Message text
    |--------------------------------------------------------------------------
    |
    | Placeholders in {{ braces }} are filled by TemplateRenderer, which treats a
    | missing KEY as a bug (exception) and a null VALUE as "leave this out" (the
    | whole line is dropped).
    |
    | That rule is why every optional fact below sits on its own line. Not every
    | business has filled in a phone number and not every booking names a service,
    | and "Need to change it? Call ." reaching a customer is worse than not
    | offering at all.
    |
    | Kept in config rather than a database table until an owner can actually edit
    | them. English only: these go to UK end-customers.
    |
    */

    'templates' => [

        'appointment_reminder_24h' => <<<'TXT'
            Hi {{customer_name}}, a quick reminder from {{business_name}}.

            Your appointment is tomorrow, {{appointment_date}} at {{appointment_time}}.
            Service: {{service_name}}
            Reply YES to confirm or CANCEL if you cannot make it.
            Need to change it? Call {{business_phone}}.
            TXT,
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    |
    | One platform-wide bot serves every business to start with. A business that
    | later wants its own branded bot gets a channel_connections row, and the
    | driver prefers that over these values.
    |
    */

    'telegram' => [

        // From @BotFather. Never commit this, and revoke it immediately if it
        // is ever pasted anywhere it should not be — /mybots → API Token →
        // Revoke current token.
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),

        // Without the trailing @, e.g. bright_hair_reminders_bot. Needed to
        // build the customer link https://t.me/<username>?start=<token>.
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),

        // The secret Telegram returns in X-Telegram-Bot-Api-Secret-Token on
        // every webhook call. Without this, anyone who knows the URL can post
        // fake updates and link their own chat to another salon's customers.
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

        // Base URL for API calls. Defaults to the real Telegram servers; change
        // this only when testing against a local mock bot server.
        'api_url' => 'https://api.telegram.org',

        // Seconds. The dispatcher sends up to 50 of these in one cron minute, so
        // a hung request must not take the whole run down with it.
        'timeout' => (int) env('TELEGRAM_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp (Meta Cloud API)
    |--------------------------------------------------------------------------
    |
    | Delivers automated appointment reminders via Meta's WhatsApp Cloud API.
    | A business can also supply its own dedicated credentials via the
    | channel_connections table.
    |
    */

    'whatsapp' => [
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'api_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v19.0'),
        'timeout' => (int) env('WHATSAPP_TIMEOUT', 10),
    ],

];
