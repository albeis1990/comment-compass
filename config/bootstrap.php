<?php
declare(strict_types=1);

load_environment_files();

function app_root(): string
{
    return dirname(__DIR__);
}

function load_environment_files(): void
{
    $paths = [
        app_root() . '/.env',
        dirname(app_root()) . '/.comment-compass.env',
        dirname(app_root(), 2) . '/.comment-compass.env',
    ];

    foreach ($paths as $path) {
        if (is_readable($path)) {
            load_environment_file($path);
        }
    }
}

function load_environment_file(string $path): void
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");

        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

function app_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : (string)$value;
}

function send_security_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        json_response(['error' => 'The request body is empty.'], 400);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json_response(['error' => 'The request body must be valid JSON.'], 400);
    }

    return $decoded;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function openai_responses_request(string $apiKey, array $request): array
{
    $body = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new RuntimeException('The OpenAI request could not be encoded.');
    }

    if (function_exists('curl_init')) {
        return openai_responses_request_with_curl($apiKey, $body);
    }

    return openai_responses_request_with_stream($apiKey, $body);
}

function openai_responses_request_with_curl(string $apiKey, string $body): array
{
    $curl = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
    ]);

    $raw = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($raw === false) {
        throw new RuntimeException('OpenAI request failed: ' . $error);
    }

    return decode_openai_response((string)$raw, $status);
}

function openai_responses_request_with_stream(string $apiKey, string $body): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 45,
            'header' => implode("\r\n", [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ]),
            'content' => $body,
            'ignore_errors' => true,
        ],
    ]);

    $raw = file_get_contents('https://api.openai.com/v1/responses', false, $context);
    $status = 0;

    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
        $status = (int)$matches[1];
    }

    if ($raw === false) {
        throw new RuntimeException('OpenAI request failed.');
    }

    return decode_openai_response((string)$raw, $status);
}

function decode_openai_response(string $raw, int $status): array
{
    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('OpenAI returned an unreadable response.');
    }

    if ($status < 200 || $status >= 300) {
        $message = $decoded['error']['message'] ?? 'OpenAI returned an error.';
        throw new RuntimeException((string)$message);
    }

    return $decoded;
}

function extract_output_text(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return $response['output_text'];
    }

    foreach (($response['output'] ?? []) as $item) {
        foreach (($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                return (string)$content['text'];
            }
        }
    }

    throw new RuntimeException('OpenAI returned no text output.');
}
