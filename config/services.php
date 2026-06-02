<?php

return [
    'python_api_token' => env('PYTHON_API_TOKEN'),
    'coindcx_base_url' => env('COINDCX_BASE_URL', 'https://api.coindcx.com'),
    'signal_tracking_days' => (int) env('SIGNAL_TRACKING_DAYS', 7),
];
