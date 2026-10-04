<?php

return [
    'upload_max_kb' => (int) env('INQUIRY_UPLOAD_MAX_KB', 10240),
    'upload_batch_limit' => (int) env('INQUIRY_UPLOAD_BATCH_LIMIT', 5),
    'document_limit' => (int) env('INQUIRY_DOCUMENT_LIMIT', 20),
    'clarification_template' => "Hello {name},\n\nThank you for your inquiry {reference}. To review the shipment, please confirm:\n\n{questions}\n\nWe will review your answers before proceeding.\n\nRegards,\n{company}",
];
