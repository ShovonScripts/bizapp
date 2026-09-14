<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SiteSettings
{
    protected static array $cache = [];

    public static function defaults(): array
    {
        return [
            // General Site Settings
            'site_name' => config('app.name', 'BizFlow'),
            'site_tagline' => 'Simple booking, diary and reminder software for UK local businesses.',
            'support_email' => 'support@example.com',
            'support_phone' => '+44 20 7946 0991',
            'business_address' => '71-75 Shelton Street, Covent Garden, London, WC2H 9JQ',
            'currency_symbol' => '£',
            'default_timezone' => 'Europe/London',
            'allow_registrations' => true,
            'trial_days' => 14,
            'social_x' => 'https://x.com',
            'social_github' => 'https://github.com',
            'social_linkedin' => 'https://linkedin.com',
            'social_discord' => 'https://discord.com',

            // Global SEO Manager
            'seo_meta_title_template' => '%title% — %site_name%',
            'seo_default_description' => 'Simple booking, diary and reminder software for UK salons, barbers and local service businesses.',
            'seo_default_keywords' => 'salon booking software, barbershop diary, appointment reminders, UK booking system, whatsapp booking confirmation',
            'seo_canonical_base' => config('app.url', 'http://127.0.0.1:8000'),
            'seo_og_image' => '',
            'seo_twitter_handle' => '@bizflowapp',
            'seo_google_site_verification' => '',
            'seo_enable_json_ld' => true,

            // Pricing Configuration
            'plan_starter_price' => '19',
            'plan_growth_price' => '39',
            'plan_business_price' => '69',
            'plan_billing_period' => 'month',
            'plan_annual_discount' => '20',

            // Platform Stripe Gateway Credentials
            'stripe_publishable_key' => config('services.stripe.key', ''),
            'stripe_test_mode' => true,
            'stripe_currency' => 'gbp',

            // Page-specific SEO
            'seo_home_title' => 'Bookings that run themselves',
            'seo_home_description' => 'A shared diary, one-tap booking and automatic reminders so your customers show up on time and your staff never double-book.',
            'seo_home_keywords' => 'salon appointment system, barber calendar, automated reminders, uk local business software',
            'seo_home_robots' => 'index, follow',

            'seo_pricing_title' => 'Simple, Transparent Pricing',
            'seo_pricing_description' => 'Predictable monthly plans for solo traders, busy salons, and expanding teams. No setup fees, cancel anytime.',
            'seo_pricing_keywords' => 'booking software pricing, salon diary plans, appointment software cost',
            'seo_pricing_robots' => 'index, follow',

            'seo_privacy_title' => 'Privacy Policy & GDPR Compliance',
            'seo_privacy_description' => 'Our strict UK GDPR compliance commitment. Your customer data is never sold or shared.',
            'seo_privacy_keywords' => 'privacy policy, gdpr compliance, customer data security',
            'seo_privacy_robots' => 'index, follow',

            'seo_terms_title' => 'Terms of Service',
            'seo_terms_description' => 'Terms and conditions governing use of our booking and reminder platform.',
            'seo_terms_keywords' => 'terms of service, software terms, user agreement',
            'seo_terms_robots' => 'index, follow',
        ];
    }

    public static function clearCache(): void
    {
        static::$cache = [];
    }

    public static function get(string $keyOrGroup, mixed $keyOrDefault = null, mixed $default = null): mixed
    {
        // Support both get('site_name', 'default') and get('general', 'site_name', 'default')
        if (is_string($keyOrDefault) && (array_key_exists($keyOrDefault, static::defaults()) || in_array($keyOrGroup, ['general', 'billing', 'social', 'seo']))) {
            $key = $keyOrDefault;
            $fallbackDefault = $default;
        } else {
            $key = $keyOrGroup;
            $fallbackDefault = $keyOrDefault;
        }

        if (array_key_exists($key, static::$cache)) {
            return static::$cache[$key];
        }

        $defaults = static::defaults();
        $fallback = $fallbackDefault ?? ($defaults[$key] ?? null);

        if (! Schema::hasTable('site_settings')) {
            return $fallback;
        }

        $row = DB::table('site_settings')->where('key', $key)->first();

        if (! $row) {
            return $fallback;
        }

        $decoded = json_decode($row->value, true);
        $value = $decoded !== null ? $decoded : $row->value;

        return static::$cache[$key] = $value;
    }

    public static function set(string $key, mixed $value): void
    {
        static::$cache[$key] = $value;

        if (! Schema::hasTable('site_settings')) {
            return;
        }

        DB::table('site_settings')->updateOrInsert(
            ['key' => $key],
            [
                'value' => json_encode($value),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public static function all(): array
    {
        $defaults = static::defaults();

        if (! Schema::hasTable('site_settings')) {
            return $defaults;
        }

        $records = DB::table('site_settings')->pluck('value', 'key')->all();

        foreach ($records as $key => $raw) {
            $decoded = json_decode($raw, true);
            $defaults[$key] = $decoded !== null ? $decoded : $raw;
        }

        return $defaults;
    }

    public static function seoForPage(string $page): array
    {
        $prefix = "seo_{$page}_";
        $siteName = static::get('site_name', config('app.name', 'BizFlow'));
        $title = static::get("{$prefix}title", $siteName);
        $description = static::get("{$prefix}description", static::get('seo_default_description'));
        $keywords = static::get("{$prefix}keywords", static::get('seo_default_keywords'));
        $robots = static::get("{$prefix}robots", 'index, follow');

        return [
            'title' => "{$title} — {$siteName}",
            'raw_title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'robots' => $robots,
            'og_image' => static::get('seo_og_image'),
            'twitter_handle' => static::get('seo_twitter_handle'),
            'canonical' => rtrim(static::get('seo_canonical_base', config('app.url')), '/'),
        ];
    }

    public static function stripeConfig(): array
    {
        return [
            'publishable_key' => static::get('stripe_publishable_key', config('services.stripe.key', '')),
            'test_mode' => (bool) static::get('stripe_test_mode', true),
            'currency' => static::get('stripe_currency', 'gbp'),
        ];
    }

    public static function pricingPlans(): array
    {
        $currency = static::get('currency_symbol', '£');
        $period = static::get('plan_billing_period', 'month');

        return [
            [
                'name' => 'Starter',
                'price' => $currency.static::get('plan_starter_price', '19'),
                'raw_price' => (float) static::get('plan_starter_price', 19),
                'period' => $period,
                'description' => 'For solo traders getting started.',
                'popular' => false,
                'features' => [
                    '1 staff member',
                    'Up to 100 customers',
                    'Telegram reminders',
                    'Email support',
                    'Basic diary',
                ],
            ],
            [
                'name' => 'Growth',
                'price' => $currency.static::get('plan_growth_price', '39'),
                'raw_price' => (float) static::get('plan_growth_price', 39),
                'period' => $period,
                'description' => 'For busy salons and expanding teams.',
                'popular' => true,
                'features' => [
                    'Up to 10 staff',
                    'Unlimited customers',
                    'Telegram + WhatsApp',
                    'Stripe deposit support',
                    'Analytics & win-back',
                    'Priority support',
                ],
            ],
            [
                'name' => 'Business',
                'price' => $currency.static::get('plan_business_price', '69'),
                'raw_price' => (float) static::get('plan_business_price', 69),
                'period' => $period,
                'description' => 'For multi-chair and high-volume shops.',
                'popular' => false,
                'features' => [
                    'Unlimited staff',
                    'Multi-location ready',
                    'Custom branding',
                    'API access',
                    'Dedicated onboarding',
                    'SLA support',
                ],
            ],
        ];
    }
}
