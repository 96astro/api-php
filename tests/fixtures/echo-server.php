<?php

// Router for `php -S` used by TransportTest: echoes the request so every transport can be checked
// against a real HTTP server. /slow sleeps, /status/{n} answers with that status.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}
if ($path === '/slow') {
    sleep(3);
}
if (preg_match('#^/status/(\d{3})$#', $path, $m)) {
    http_response_code((int) $m[1]);
    header('Retry-After: 7');
}
header('Content-Type: application/json');
header('X-Request-Id: req_echo');
header('RateLimit-Remaining: 41');
echo json_encode(['data' => [
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $path,
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
], 'meta' => ['request_id' => 'req_body']]);
