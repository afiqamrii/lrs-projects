<?php

return [
    'google_client_id' => env('GOOGLE_CLIENT_ID'),
    'google_client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'google_redirect_uri' => env('GOOGLE_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/settings/mailbox/google/callback'),
    'tenant_id' => env('OUTLOOK_TENANT_ID'),
    'client_id' => env('OUTLOOK_CLIENT_ID'),
    'client_secret' => env('OUTLOOK_CLIENT_SECRET'),
    'redirect_uri' => env('OUTLOOK_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/settings/mailbox/callback'),
    'demo_enabled' => (bool) env('MAILBOX_DEMO_ENABLED', false),
    'history_days' => 90,
    'message_max_bytes' => 1048576,
    'message_attachment_total' => 31457280,
    'sync_page_limit' => 100,
    'extended_property' => 'String {91ea4066-d244-448f-9c29-7632fc496e78} Name LrsDispatchId',
];
