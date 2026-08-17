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
        'commands' => env('APWATCH_COMMANDS', false),
        'schedule' => env('APWATCH_SCHEDULE', false),

        // Separate from 'requests': ip/user_agent/memory are captured
        // whenever requests are, but headers are opt-in on their own —
        // even redacted, they're a bigger sensitive-data surface than a
        // method/path/status/duration line.
        'request_headers' => env('APWATCH_REQUEST_HEADERS', false),

        // What the client actually sent: body + query string. Gated a
        // second time by response status (see 'request_input' below), so
        // leaving this on still captures nothing for healthy traffic.
        'request_input' => env('APWATCH_REQUEST_INPUT', true),

        // Who was authenticated when the event happened. Attached to every
        // event captured during a request, not just the request row, so a
        // query or exception can be traced back to a user too.
        'user' => env('APWATCH_USER', true),
    ],

    // Request body/query capture. Off for healthy traffic by default: this
    // is the most sensitive and most voluminous thing the client can send,
    // so it is worth paying for on errors and not on every 200.
    'request_input' => [
        // Comma-separated response statuses that save the input. Accepts
        // classes ('4xx') and exact codes ('422'), mixed freely. Empty
        // disables capture entirely, whatever 'capture.request_input' says.
        'statuses' => env('APWATCH_INPUT_STATUSES', '4xx,5xx'),

        // Guards against one pathological request (a base64 upload, a bulk
        // import) blowing up a ClickHouse row. Individual values are cut to
        // 'max_value_length'; if the whole encoded structure still exceeds
        // 'max_length', it is replaced by a marker rather than stored half.
        'max_length' => env('APWATCH_INPUT_MAX_LENGTH', 16384),
        'max_value_length' => env('APWATCH_INPUT_MAX_VALUE_LENGTH', 1024),

        // Matched case-insensitively against key names, at any nesting
        // depth. A key whose name merely contains one of these counts as a
        // match, so 'senha' covers 'senha_atual' and 'confirmar_senha'.
        'redact' => [
            'password', 'senha', 'secret', 'token', 'authorization', 'api_key',
            'access_token', 'refresh_token', 'client_secret', 'private_key',
            'credit_card', 'cartao', 'card_number', 'cvv', 'cvc',
            'cpf', 'cnpj', 'rg',
        ],
    ],

    // Which guard to read the authenticated user from. Null uses whatever
    // the app's default guard is.
    'user' => [
        'guard' => env('APWATCH_USER_GUARD'),

        // Model attributes to read. Renamed here when the host app's user
        // table does not use these column names.
        'id_attribute' => env('APWATCH_USER_ID_ATTRIBUTE', 'id'),
        'email_attribute' => env('APWATCH_USER_EMAIL_ATTRIBUTE', 'email'),
        'name_attribute' => env('APWATCH_USER_NAME_ATTRIBUTE', 'name'),
    ],

    // Kept short on purpose: the flush happens after the response has
    // already been sent to the browser (via defer()), so a slow or down
    // apwatch server must never hold up a worker/request for long.
    'http' => [
        'connect_timeout' => env('APWATCH_HTTP_CONNECT_TIMEOUT', 1),
        'timeout' => env('APWATCH_HTTP_TIMEOUT', 2),
    ],
];
