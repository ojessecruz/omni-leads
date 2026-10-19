<?php

namespace App\Domains\Webhooks;

use InvalidArgumentException;

/**
 * Custom headers a webhook sends with every delivery (e.g. the receiver's Authorization),
 * typed one per line as "Name: value". The headers that make a delivery verifiable stay ours.
 */
class WebhookHeaders
{
    public const int MAX = 10;

    public const int MAX_VALUE_LENGTH = 2000;

    /** Lowercase names, or prefixes ending in "-", that a custom header may not set. */
    private const array RESERVED = ['content-type', 'content-length', 'host', 'user-agent', 'transfer-encoding', 'connection', 'x-omnileads-'];

    /**
     * @return array<string, string> name => value
     *
     * @throws InvalidArgumentException code 1 = bad line, 2 = reserved name, 3 = too many; message = the offending line or name
     */
    public static function parse(string $text): array
    {
        $headers = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (! preg_match('/^\s*([A-Za-z0-9!#$%&\'*+.^_`|~-]+)\s*:\s*(.*?)\s*$/', $line, $m) || $m[2] === '' || strlen($m[2]) > self::MAX_VALUE_LENGTH) {
                throw new InvalidArgumentException($line, 1);
            }

            if (self::isReserved($m[1])) {
                throw new InvalidArgumentException($m[1], 2);
            }

            $headers[$m[1]] = $m[2];
        }

        if (count($headers) > self::MAX) {
            throw new InvalidArgumentException((string) self::MAX, 3);
        }

        return $headers;
    }

    public static function isReserved(string $name): bool
    {
        $name = strtolower($name);

        foreach (self::RESERVED as $reserved) {
            if ($name === $reserved || (str_ends_with($reserved, '-') && str_starts_with($name, $reserved))) {
                return true;
            }
        }

        return false;
    }
}
