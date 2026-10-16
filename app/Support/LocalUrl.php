<?php

namespace App\Support;

/** Third-party tools can never call back a URL on the developer's machine. */
final class LocalUrl
{
    public static function is(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test');
    }
}
