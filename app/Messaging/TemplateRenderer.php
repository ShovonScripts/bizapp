<?php

namespace App\Messaging;

use RuntimeException;

/**
 * Fills {{placeholders}} in a message template.
 *
 * Two kinds of "missing", treated very differently:
 *
 *   Key absent from the array   → exception. The template and the code that feeds
 *                                 it have drifted apart. That is a bug, and a bug
 *                                 that fails loudly in a cron run is far better
 *                                 than "Hi , your appointment at is tomorrow"
 *                                 arriving on a stranger's phone.
 *
 *   Key present but null/blank  → the whole LINE containing it is dropped. This is
 *                                 the deliberate case: not every business has
 *                                 filled in a phone number, and "Need to change
 *                                 it? Call ." is worse than not offering.
 *
 * Line-level rather than word-level because messages are written as sentences on
 * their own lines, and a half-erased sentence reads like a fault.
 */
class TemplateRenderer
{
    /**
     * @param  array<string, string|null>  $variables
     *
     * @throws RuntimeException when the template is unknown or a placeholder has
     *                          no corresponding key at all.
     */
    public static function render(string $templateKey, array $variables): string
    {
        $template = config('messaging.templates.'.$templateKey);

        if (! is_string($template) || $template === '') {
            throw new RuntimeException("No message template registered for [{$templateKey}].");
        }

        $lines = [];

        foreach (preg_split('/\R/', $template) as $line) {
            $rendered = static::renderLine($line, $variables, $templateKey);

            if ($rendered !== null) {
                $lines[] = $rendered;
            }
        }

        // Dropping a line can leave a stranded blank line where a paragraph break
        // used to be, so collapse runs of them rather than shipping the gap.
        $body = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));

        return trim($body);
    }

    /**
     * @return string|null Null means "drop this line".
     */
    protected static function renderLine(string $line, array $variables, string $templateKey): ?string
    {
        $drop = false;

        $rendered = preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            function (array $match) use ($variables, $templateKey, &$drop) {
                $key = $match[1];

                if (! array_key_exists($key, $variables)) {
                    throw new RuntimeException(
                        "Template [{$templateKey}] uses {{{$key}}}, but no such variable was supplied."
                    );
                }

                $value = $variables[$key];

                if (blank($value)) {
                    $drop = true;

                    return '';
                }

                return (string) $value;
            },
            $line
        );

        return $drop ? null : $rendered;
    }
}
