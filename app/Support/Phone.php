<?php

namespace App\Support;

/**
 * Normalises phone numbers to E.164 (+447700900123).
 *
 * WHY THIS MATTERS MORE THAN IT LOOKS:
 * 1. WhatsApp Cloud API and every SMS gateway require E.164. A number stored as
 *    "07700 900123" simply fails to send, and you find out from an angry client.
 * 2. The unique index on customers(business_id, phone) is worthless if the same
 *    person can be saved as "07700900123", "+447700900123" and "07700 900123".
 *    Normalising at the model boundary is what makes that index real.
 *
 * SCOPE: this handles the UK properly and does something sensible elsewhere.
 * It is NOT a full libphonenumber. If we ever sell outside the UK, swap this for
 * giggsey/libphonenumber-for-php — same method signature, so nothing else changes.
 */
class Phone
{
    /**
     * @param  string|null  $number  whatever the user typed
     * @param  string  $defaultCallingCode  digits only, no plus. '44' = UK.
     * @return string|null E.164, or null if there was nothing usable
     */
    public static function normalise(?string $number, string $defaultCallingCode = '44'): ?string
    {
        if (blank($number)) {
            return null;
        }

        $number = trim($number);

        // Keep digits and a leading plus only. Strips spaces, dashes and brackets.
        $hasPlus = str_starts_with($number, '+');
        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if ($digits === '') {
            return null;
        }

        // Already international
        if ($hasPlus) {
            return '+'.static::stripTrunkZero($digits, $defaultCallingCode);
        }

        // 00447700900123 — international prefix used across most of Europe
        if (str_starts_with($digits, '00')) {
            return '+'.static::stripTrunkZero(substr($digits, 2), $defaultCallingCode);
        }

        // 07700900123 — national format. Drop the trunk 0, add the country code.
        if (str_starts_with($digits, '0')) {
            return '+'.$defaultCallingCode.substr($digits, 1);
        }

        // 447700900123 — country code typed without the plus
        if (str_starts_with($digits, $defaultCallingCode)) {
            return '+'.static::stripTrunkZero($digits, $defaultCallingCode);
        }

        // 7700900123 — bare national number, no trunk 0
        return '+'.$defaultCallingCode.$digits;
    }

    /**
     * Remove a trunk 0 sitting directly after the country code.
     *
     * "+44 (0)7700 900123" is how UK businesses print their number on websites and
     * letterheads, and people paste it verbatim. Once the brackets are stripped
     * that leaves +4407700900123, which is not a real number: WhatsApp rejects it,
     * and because it does not match the +447700900123 already in the table, the
     * same person is saved twice and gets two of every reminder.
     *
     * An E.164 national number never begins with 0 — that zero exists only for
     * domestic dialling. We still only strip it when it directly follows a country
     * code we recognise, so numbers from countries we have not thought about are
     * left exactly as typed.
     */
    protected static function stripTrunkZero(string $digits, string $callingCode): string
    {
        if ($callingCode !== '' && str_starts_with($digits, $callingCode.'0')) {
            return $callingCode.substr($digits, strlen($callingCode) + 1);
        }

        return $digits;
    }

    /**
     * Loose sanity check. Deliberately permissive: E.164 allows 8–15 digits after
     * the plus, and we would rather store an odd-looking number than reject a
     * valid one from a country we did not think about.
     */
    public static function looksValid(?string $e164): bool
    {
        return is_string($e164) && preg_match('/^\+[1-9]\d{7,14}$/', $e164) === 1;
    }

    /**
     * Human-friendly display. UK mobiles as "07700 900123", everything else
     * left in E.164 — better a correct ugly number than a wrong pretty one.
     */
    public static function forHumans(?string $e164): ?string
    {
        if (blank($e164)) {
            return null;
        }

        // UK mobile: +447xxxxxxxxx
        if (preg_match('/^\+447(\d{9})$/', $e164, $m) === 1) {
            return '07'.substr($m[1], 0, 3).' '.substr($m[1], 3);
        }

        return $e164;
    }
}
