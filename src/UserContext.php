<?php

namespace Apwatch\Client;

/**
 * Holds the authenticated user for the current request so every event in the
 * batch can be attributed to them — not just the request row, but the queries,
 * logs and exceptions it produced along the way.
 *
 * Resolved during the request (by CaptureRequest, once the auth middleware has
 * run) rather than at flush time, because the flush happens inside a defer()
 * callback where the auth guard's state is no longer guaranteed.
 *
 * Bound as a container singleton, so it lives exactly as long as the request
 * that created it.
 */
class UserContext
{
    /** @var array<string, mixed>|null */
    private ?array $user = null;

    /**
     * @param  array<string, mixed>|null  $user
     */
    public function set(?array $user): void
    {
        $this->user = $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(): ?array
    {
        return $this->user;
    }

    public function clear(): void
    {
        $this->user = null;
    }
}
