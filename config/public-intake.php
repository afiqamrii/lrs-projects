<?php

return [
    'url' => env('PUBLIC_INTAKE_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/request-quote'),
    'form_expiry_hours' => 24,
    'verification_expiry_hours' => (int) env('PUBLIC_VERIFICATION_EXPIRY_HOURS', 24),
    'upload_total_kb' => (int) env('PUBLIC_UPLOAD_TOTAL_KB', 30720),
    'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx', 'csv'],
    'receipt_mailer' => env('PUBLIC_RECEIPT_MAILER'),
    'allow_local_capture' => (bool) env('PUBLIC_RECEIPT_LOCAL_CAPTURE', false),
];
