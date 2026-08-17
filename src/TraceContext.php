<?php

namespace Apwatch\Client;

use Illuminate\Support\Str;

/**
 * Ties every event produced by one unit of work together under a single id,
 * so the dashboard can show a request next to the queries, logs, mails and
 * exceptions it caused instead of as an isolated line.
 *
 * A "unit of work" is one HTTP request, one queued job, or one artisan
 * command. Units nest: a job dispatched during a request records the
 * request's trace as its parent (the id travels on the queue payload), which
 * is what lets the dashboard walk from a request down into the jobs it
 * queued.
 *
 * Bound as a container singleton — in a web request that means one trace for
 * the whole request; in a long-running worker, restart() gives each job its
 * own.
 */
class TraceContext
{
    private ?string $id = null;

    private ?string $parentId = null;

    /**
     * Generated on first read rather than on construction: a process that
     * captures nothing never pays for a uuid, and the id is only needed once
     * something is actually going to be reported.
     */
    public function id(): string
    {
        return $this->id ??= (string) Str::uuid();
    }

    public function parentId(): ?string
    {
        return $this->parentId;
    }

    /**
     * Begins a new unit of work under an optional parent. Called when a
     * worker picks up a job (or a command starts), since those share the
     * process — and therefore the singleton — with everything that ran
     * before them.
     */
    public function restart(?string $parentId = null): void
    {
        $this->id = (string) Str::uuid();
        $this->parentId = ($parentId === null || $parentId === '') ? null : $parentId;
    }

    public function clear(): void
    {
        $this->id = null;
        $this->parentId = null;
    }
}
