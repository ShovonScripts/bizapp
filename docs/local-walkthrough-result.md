Environment (local):

.env has TELEGRAM_BOT_TOKEN set and TELEGRAM_BOT_USERNAME=jis26bot — correct
MESSAGING_DRIVER is not set — correct (must remain unset for real sends)
php artisan config:clear succeeded
php artisan telegram:webhook reports Bot: @jis26bot, Webhook: not set — ready for telegram:poll
Tests:

Baseline run: 344 passed, 1 failed
Fixed the failing test in tests/Feature/MessageQueueCommandTest.php (test_it_lists_a_queued_message). Root cause: Laravel's mocked PendingCommand can only match one expectsOutputToContain per doWrite call, and the table row containing both "Sarah Khan" and "Bright Hair Studio" was being consumed by the first expectation, leaving the second unfulfilled.
After fix: 345 passed, 0 failed

Note: The actual walkthrough steps requiring browser/Telegram interaction (register business, invite via bot link, create booking, run messages:plan/messages:queue/messages:dispatch) still need to be done manually on your end.