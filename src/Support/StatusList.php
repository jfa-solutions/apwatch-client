<?php

namespace Apwatch\Client\Support;

/**
 * Matches an HTTP status against a comma-separated list configured by the
 * host app. Classes ('4xx') and exact codes ('422') can be mixed freely, so
 * an app can capture everything that failed validation without paying for
 * every other error; the literal 'all' matches any status.
 */
class StatusList
{
    public static function matches(string $configured, int $status): bool
    {
        foreach (explode(',', $configured) as $token) {
            $token = strtolower(trim($token));

            if ($token === '') {
                continue;
            }

            if ($token === 'all') {
                return true;
            }

            $matches = str_ends_with($token, 'xx')
                ? (int) substr($token, 0, 1) === intdiv($status, 100)
                : (int) $token === $status;

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
