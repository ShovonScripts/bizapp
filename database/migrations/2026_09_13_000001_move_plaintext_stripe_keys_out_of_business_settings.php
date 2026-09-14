<?php

use App\Models\ChannelConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Deleting the code that READ businesses.settings['stripe'] did not delete the
 * data. Any row written before that change still holds a live Stripe secret key
 * as plaintext JSON, and now nothing reads it — so nobody will ever notice it is
 * still there. A leaked DB dump or a stolen cPanel backup hands over the key
 * exactly as it did before, and that key can charge cards and read the whole
 * customer list.
 *
 * So: carry anything still usable into channel_connections (encrypted:json),
 * then destroy the plaintext copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('businesses')->select('id', 'settings')->get() as $row) {
            $settings = json_decode((string) $row->settings, true);

            if (! is_array($settings) || ! array_key_exists('stripe', $settings)) {
                continue;
            }

            $stripe = is_array($settings['stripe']) ? $settings['stripe'] : [];
            $secret = trim((string) ($stripe['secret_key'] ?? ''));
            $publishable = trim((string) ($stripe['publishable_key'] ?? ''));
            $webhook = trim((string) ($stripe['webhook_secret'] ?? ''));

            /*
             * Only carry the old values across if nothing has replaced them yet.
             * An existing connection is newer than this plaintext copy by
             * definition, so it must win.
             */
            $hasConnection = ChannelConnection::where('business_id', $row->id)
                ->where('channel', 'stripe')
                ->exists();

            if (! $hasConnection && $secret !== '') {
                $connection = new ChannelConnection;
                $connection->business_id = $row->id;
                $connection->channel = 'stripe';
                $connection->status = ChannelConnection::ACTIVE;
                $connection->credentials = [
                    'secret_key' => $secret,
                    'publishable_key' => $publishable ?: null,
                    'webhook_secret' => $webhook ?: null,
                ];
                $connection->meta = [
                    'test_mode' => (bool) ($stripe['test_mode'] ?? true),
                ];
                $connection->save();
            }

            unset($settings['stripe']);

            DB::table('businesses')
                ->where('id', $row->id)
                ->update(['settings' => json_encode($settings)]);
        }
    }

    public function down(): void
    {
        /*
         * Deliberately irreversible. Rolling back would mean writing live Stripe
         * secret keys back into plaintext, which is the exact problem this
         * migration exists to remove.
         */
    }
};
