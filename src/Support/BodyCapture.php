<?php

namespace Apwatch\Client\Support;

/**
 * Turns a raw HTTP body (a response the app returned, or the request/response
 * of an outgoing http client call) into something safe to store: JSON is
 * decoded and run through the redactor so secrets in it are masked the same
 * way they are in captured request input, anything else is kept as a string,
 * and both are size-capped.
 *
 * A body that is refused (binary, oversized) is reported as a marker rather
 * than silently omitted — "not stored, and here is why" is far more useful
 * when debugging than an empty panel.
 */
class BodyCapture
{
    // Content types whose bodies are worth reading. Anything else is only
    // stored if it does not look binary, which covers the many APIs that
    // answer with a vague or missing Content-Type.
    private const TEXTUAL_HINTS = [
        'json', 'text/', 'xml', 'javascript', 'html', 'csv', 'x-www-form-urlencoded',
    ];

    public function __construct(
        private readonly InputRedactor $redactor,
        private readonly int $maxLength,
    ) {}

    /**
     * @param  string|false|null  $body  false/null for a streamed or binary
     *                                   response, whose content was never
     *                                   materialised in memory
     * @return array<string, mixed>
     */
    public function capture(string|false|null $body, string $contentType = ''): array
    {
        $meta = array_filter(['content_type' => $contentType]);

        if (! is_string($body)) {
            return $meta + ['_skipped' => 'body not available (streamed or file response)'];
        }

        $bytes = strlen($body);
        $meta['bytes'] = $bytes;

        if ($bytes === 0) {
            return $meta + ['body' => ''];
        }

        // Size is checked first because it is O(1) — the binary sniff below
        // scans the whole string, and there is no point paying for that on a
        // body that is going to be refused either way.
        if ($bytes > $this->maxLength) {
            return $meta + ['_skipped' => "body of {$bytes} bytes exceeded the {$this->maxLength} byte cap"];
        }

        if ($this->looksBinary($body, $contentType)) {
            return $meta + ['_skipped' => "binary body of {$bytes} bytes not stored"];
        }

        $decoded = json_decode($body, true);

        // Only arrays/objects go through the redactor — a scalar JSON body
        // ("ok", 42) has no keys to match against, so it is kept verbatim.
        return $meta + ['body' => is_array($decoded) ? $this->redactor->redact($decoded) : $body];
    }

    private function looksBinary(string $body, string $contentType): bool
    {
        $contentType = strtolower($contentType);

        foreach (self::TEXTUAL_HINTS as $hint) {
            if (str_contains($contentType, $hint)) {
                return false;
            }
        }

        // NUL bytes never occur in the text formats above, so they are a
        // cheap and reliable tell for images, PDFs, archives and the like.
        return str_contains($body, "\0");
    }
}
