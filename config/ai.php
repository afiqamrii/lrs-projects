<?php

return [
    'key' => env('OPENAI_API_KEY'),
    'timeout' => 75,
    'input_chars' => max(1000, min(60000, (int) env('AI_INPUT_CHAR_LIMIT', 60000))),
    'input_tokens' => max(5000, min(80000, (int) env('AI_INPUT_TOKEN_LIMIT', 80000))),
    'output_tokens' => max(128, min(16000, (int) env('AI_OUTPUT_TOKEN_LIMIT', 4000))),
    'paid_attempts_per_inquiry' => max(1, min(10, (int) env('AI_PAID_ATTEMPT_LIMIT', 3))),
    'prompt_version' => 'shipment-evidence-1',
    'schema_version' => 'shipment-proposals-1',
];
