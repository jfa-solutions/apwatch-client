<?php

return [
    // Master switch. When false, no listeners are registered and nothing
    // is ever captured or sent.
    'enabled' => env('APWATCH_ENABLED', true),

    'api_key' => env('APWATCH_API_KEY'),

    'endpoint' => env('APWATCH_ENDPOINT'),

    // Which event types this app reports. Toggle per-app via .env — the
    // server has no say over this, it just stores whatever it receives.
    // Off by default unless noted: request/exception/log are the common
    // case, the rest are opt-in since they're noisier and/or costlier to
    // capture (every query, every outgoing HTTP call, every job).
    'capture' => [
        'requests' => env('APWATCH_REQUESTS', true),
        'exceptions' => env('APWATCH_EXCEPTIONS', true),
        'logs' => env('APWATCH_LOGS', true),
        'queries' => env('APWATCH_QUERIES', false),
        'jobs' => env('APWATCH_JOBS', false),
        'mails' => env('APWATCH_MAILS', false),
        'http_clients' => env('APWATCH_HTTP_CLIENTS', false),
        'events' => env('APWATCH_EVENTS', false),

        // Separate from 'requests': ip/user_agent/memory are captured
        // whenever requests are, but headers are opt-in on their own —
        // even redacted, they're a bigger sensitive-data surface than a
        // method/path/status/duration line.
        'request_headers' => env('APWATCH_REQUEST_HEADERS', false),
    ],

    // Kept short on purpose: the flush happens after the response has
    // already been sent to the browser (via defer()), so a slow or down
    // apwatch server must never hold up a worker/request for long.
    'http' => [
        'connect_timeout' => env('APWATCH_HTTP_CONNECT_TIMEOUT', 1),
        'timeout' => env('APWATCH_HTTP_TIMEOUT', 2),
    ],
];
