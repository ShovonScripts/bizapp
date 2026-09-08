<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plain PHPUnit\TestCase, not Tests\TestCase: Phone touches no framework state,
 * so there is no reason to boot Laravel for it. These run in milliseconds.
 */
class PhoneTest extends TestCase
{
    /**
     * Every one of these is the same UK mobile. If any stops normalising to the
     * same string, the unique index on customers(business_id, phone) stops
     * preventing duplicates and one person becomes three customer records —
     * each getting their own reminder.
     */
    #[DataProvider('ukMobileFormats')]
    public function test_uk_mobile_formats_all_normalise_to_one_value(string $input): void
    {
        $this->assertSame('+447700900123', Phone::normalise($input));
    }

    public static function ukMobileFormats(): array
    {
        return [
            'national with space' => ['07700 900123'],
            'national no space' => ['07700900123'],
            'international with plus' => ['+447700900123'],
            'international spaced' => ['+44 7700 900123'],
            'double zero prefix' => ['00447700900123'],
            'country code no plus' => ['447700900123'],
            'bare national' => ['7700900123'],
            'website paste with (0)' => ['+44 (0)7700 900123'],
            'trunk zero after country code' => ['+4407700900123'],
            'trunk zero after 00 prefix' => ['004407700900123'],
            'trunk zero, no plus' => ['4407700900123'],
            'dashes' => ['07700-900-123'],
            'trailing whitespace' => ['  07700900123  '],
        ];
    }

    public function test_uk_landline_normalises(): void
    {
        $this->assertSame('+441611234567', Phone::normalise('0161 123 4567'));
    }

    #[DataProvider('unusableInputs')]
    public function test_unusable_input_returns_null(?string $input): void
    {
        // null rather than an empty string, so the column stays NULL and the
        // (business_id, phone) index does not collide on '' for every customer.
        $this->assertNull(Phone::normalise($input));
    }

    public static function unusableInputs(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            'letters only' => ['no phone'],
            'punctuation only' => ['--()--'],
        ];
    }

    public function test_a_non_uk_number_with_a_plus_is_left_alone(): void
    {
        // Important for us specifically: Bangladeshi test numbers must survive.
        $this->assertSame('+8801712345678', Phone::normalise('+880 1712-345678'));
    }

    /**
     * The trunk-zero strip must only fire for the country code we were given,
     * never for a leading 0 further along the number.
     */
    public function test_trunk_zero_strip_does_not_touch_other_countries(): void
    {
        // +1 440 ... is a real US area code; the "440" must survive untouched
        // even though the default calling code is 44.
        $this->assertSame('+14407700123', Phone::normalise('+1 440 770 0123'));

        // A Bangladeshi number normalised with a UK default keeps its 0.
        $this->assertSame('+8801712345678', Phone::normalise('+8801712345678'));
    }

    public function test_the_default_calling_code_can_be_changed(): void
    {
        $this->assertSame('+8801712345678', Phone::normalise('01712345678', '880'));
    }

    /**
     * KNOWN LIMITATION, asserted so it is a decision and not a surprise.
     *
     * A number typed in another country's national format is assumed to be UK,
     * because there is nothing in "087..." that says Ireland. This is the price of
     * not pulling in libphonenumber. It only bites if we sell outside the UK — at
     * which point swap Phone::normalise for giggsey/libphonenumber-for-php, which
     * takes the same arguments.
     */
    public function test_foreign_national_format_is_assumed_uk(): void
    {
        $this->assertSame('+44871234567', Phone::normalise('087 123 4567'));
    }

    #[DataProvider('validityCases')]
    public function test_looks_valid(?string $input, bool $expected): void
    {
        $this->assertSame($expected, Phone::looksValid($input));
    }

    public static function validityCases(): array
    {
        return [
            'uk mobile' => ['+447700900123', true],
            'uk landline' => ['+441611234567', true],
            'bangladesh' => ['+8801712345678', true],
            'shortest allowed' => ['+12345678', true],
            'no plus' => ['07700900123', false],
            'zero after plus' => ['+0447700900123', false],
            'too short' => ['+4471', false],
            'too long' => ['+4477009001234567', false],
            'letters' => ['+44abc7700900', false],
            'null' => [null, false],
            'empty' => ['', false],
        ];
    }

    public function test_uk_mobiles_are_displayed_in_national_format(): void
    {
        $this->assertSame('07700 900123', Phone::forHumans('+447700900123'));
    }

    public function test_non_uk_numbers_are_displayed_as_stored(): void
    {
        // Better a correct ugly number than a confidently wrong pretty one.
        $this->assertSame('+8801712345678', Phone::forHumans('+8801712345678'));
        $this->assertSame('+441611234567', Phone::forHumans('+441611234567'));
    }

    public function test_for_humans_handles_null(): void
    {
        $this->assertNull(Phone::forHumans(null));
    }

    /** Normalising an already-normalised number must not change it. */
    public function test_normalise_is_idempotent(): void
    {
        $once = Phone::normalise('07700 900123');

        $this->assertSame($once, Phone::normalise($once));
    }
}
