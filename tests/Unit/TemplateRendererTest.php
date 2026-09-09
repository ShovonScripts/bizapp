<?php

namespace Tests\Unit;

use App\Messaging\TemplateRenderer;
use RuntimeException;
use Tests\TestCase;

/**
 * The renderer's job is to make the two failure modes look completely different.
 *
 * A template referring to a variable nobody supplies is a bug, and must stop the
 * run loudly. A variable that is legitimately empty — a business with no phone
 * number on file — is normal, and must simply leave that line out. Collapse the
 * two and you get "Need to change it? Call ." on a customer's phone, which is the
 * kind of thing that costs a client their confidence in the whole product.
 */
class TemplateRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('messaging.templates.test_message', implode("\n", [
            'Hi {{name}}, a reminder from {{business}}.',
            '',
            'Tomorrow at {{time}}.',
            'Service: {{service}}',
            'Call {{phone}}.',
        ]));
    }

    protected function render(array $overrides = []): string
    {
        return TemplateRenderer::render('test_message', array_merge([
            'name' => 'Sarah',
            'business' => 'Bright Hair Studio',
            'time' => '9:30am',
            'service' => 'Signature Cut',
            'phone' => '+441614960000',
        ], $overrides));
    }

    public function test_it_fills_in_the_variables(): void
    {
        $body = $this->render();

        $this->assertStringContainsString('Hi Sarah, a reminder from Bright Hair Studio.', $body);
        $this->assertStringContainsString('Tomorrow at 9:30am.', $body);
        $this->assertStringContainsString('Service: Signature Cut', $body);
        $this->assertStringContainsString('Call +441614960000.', $body);
    }

    public function test_a_null_variable_takes_its_whole_line_with_it(): void
    {
        $body = $this->render(['phone' => null]);

        $this->assertStringNotContainsString('Call', $body);

        // The rest must survive intact — dropping one optional line should not
        // disturb anything above it.
        $this->assertStringContainsString('Service: Signature Cut', $body);
        $this->assertStringContainsString('Hi Sarah', $body);
    }

    public function test_an_empty_string_counts_as_missing_too(): void
    {
        // Because that is what an untouched form field actually stores. Treating
        // '' as present would print "Call ." just as surely as null would.
        $this->assertStringNotContainsString('Call', $this->render(['phone' => '']));
    }

    public function test_two_dropped_lines_do_not_leave_a_hole(): void
    {
        $body = $this->render(['service' => null, 'phone' => null]);

        $this->assertStringNotContainsString('Service:', $body);
        $this->assertStringNotContainsString('Call', $body);

        // No trailing blank lines, and no run of three newlines where the removed
        // lines used to be. A message that ends in whitespace looks unfinished.
        $this->assertSame(trim($body), $body);
        $this->assertStringNotContainsString("\n\n\n", $body);
    }

    public function test_the_blank_line_between_paragraphs_survives(): void
    {
        $this->assertStringContainsString(
            "Bright Hair Studio.\n\nTomorrow at",
            $this->render(),
            'Collapsing legitimate paragraph breaks would run two sentences together.'
        );
    }

    /**
     * The loud failure. A template asking for a variable the code never supplies
     * means the two have drifted, and the only safe response is to stop — a cron
     * run that dies is noticed; a half-blank message sent to fifty customers is
     * noticed much later and by the wrong people.
     */
    public function test_a_variable_the_code_forgot_is_an_error_not_a_blank(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('{{phone}}');

        TemplateRenderer::render('test_message', [
            'name' => 'Sarah',
            'business' => 'Bright Hair Studio',
            'time' => '9:30am',
            'service' => 'Signature Cut',
            // phone omitted entirely — not the same as phone => null.
        ]);
    }

    public function test_an_unknown_template_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);

        TemplateRenderer::render('no_such_template', []);
    }

    /**
     * The real reminder template, rendered with everything present.
     *
     * Guards against a config edit that renames a placeholder without updating
     * ReminderPlanner::variables() — which would otherwise only surface when a
     * live cron run threw at 3am.
     */
    public function test_the_real_reminder_template_renders(): void
    {
        $body = TemplateRenderer::render('appointment_reminder_24h', [
            'customer_name' => 'Sarah',
            'business_name' => 'Bright Hair Studio',
            'appointment_date' => 'Thursday 16 July',
            'appointment_time' => '9:30am',
            'service_name' => 'Signature Cut',
            'business_phone' => '0161 496 0000',
        ]);

        $this->assertStringContainsString('Sarah', $body);
        $this->assertStringContainsString('Bright Hair Studio', $body);
        $this->assertStringContainsString('Thursday 16 July', $body);
        $this->assertStringContainsString('9:30am', $body);
        $this->assertStringContainsString('Signature Cut', $body);
        $this->assertStringContainsString('0161 496 0000', $body);

        // No stray indentation from the heredoc in config/messaging.php. Telegram
        // renders leading spaces literally, so this would be visible.
        foreach (explode("\n", $body) as $line) {
            $this->assertSame(ltrim($line), $line, 'A template line arrived indented.');
        }
    }
}
