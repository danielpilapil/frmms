<?php
/**
 * Do not put your API key in this example file.
 * Edit includes/gemini_config.local.php instead.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}
return [
    'api_key' => '',
    'model' => 'gemini-3.8-flash',
];
