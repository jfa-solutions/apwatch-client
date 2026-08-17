<?php

namespace Apwatch\Client\Support;

use Illuminate\Http\UploadedFile;

/**
 * Strips sensitive values out of captured request input and keeps its size
 * bounded, so a single request can never write an unbounded (or embarrassing)
 * row on the apwatch server.
 *
 * Redaction replaces values rather than dropping keys: seeing that a request
 * carried a "password" field is useful, its value never is.
 */
class InputRedactor
{
    public const REDACTED = '[REDACTED]';

    /**
     * @param  array<int, string>  $redactKeys
     */
    public function __construct(
        private readonly array $redactKeys,
        private readonly int $maxLength,
        private readonly int $maxValueLength,
    ) {}

    /**
     * Builds a redactor over the app-wide key list. The list lives under
     * 'apwatch.redact' because it now guards responses and outgoing http
     * calls too, not just request input — the older 'request_input.redact'
     * location is still honoured so an app with a published config from
     * before that move keeps working.
     */
    public static function fromConfig(int $maxLength, int $maxValueLength): self
    {
        return new self(
            (array) (config('apwatch.redact') ?? config('apwatch.request_input.redact', [])),
            $maxLength,
            $maxValueLength,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function redact(array $input): array
    {
        $redacted = $this->walk($input);

        $encoded = json_encode($redacted, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        // Per-value truncation is usually enough; this catches the case where
        // the input is wide rather than deep (thousands of small fields).
        if ($encoded !== false && strlen($encoded) > $this->maxLength) {
            return [
                '_truncated' => true,
                '_reason' => "input exceeded {$this->maxLength} bytes and was dropped",
                '_bytes' => strlen($encoded),
            ];
        }

        return $redacted;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function walk(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = match (true) {
                is_array($value) => $this->walk($value),
                $value instanceof UploadedFile => $this->describeFile($value),
                is_string($value) => $this->truncate($value),
                is_scalar($value), is_null($value) => $value,
                // Objects with no meaningful array form (streams, resources,
                // closures in old form input) would break json_encode.
                default => '['.get_debug_type($value).']',
            };
        }

        return $result;
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->redactKeys as $needle) {
            if ($needle !== '' && str_contains($key, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= $this->maxValueLength) {
            return $value;
        }

        return substr($value, 0, $this->maxValueLength).'... [truncated '.strlen($value).' bytes]';
    }

    /**
     * @return array<string, mixed>
     */
    private function describeFile(UploadedFile $file): array
    {
        return [
            '_file' => $file->getClientOriginalName(),
            '_size' => $file->getSize(),
            '_mime' => $file->getClientMimeType(),
        ];
    }
}
