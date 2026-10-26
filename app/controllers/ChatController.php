<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ChatController extends Controller
{
    public function chat()
    {
        load_class('security_headers', 'kernel')->apply_api();
        $this->response->add_header('Cache-Control', 'no-store');
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->response->add_header('Allow', 'POST');
            $this->response->send_json([
                'success' => false,
                'error' => [
                    'code' => 'METHOD_NOT_ALLOWED',
                    'message' => 'Only POST requests are allowed.'
                ]
            ], 405, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $token = trim((string) getenv('AI_ROUTER_TOKEN'));
        if ($token === '' || strlen($token) < 32) {
            $this->response->send_json([
                'success' => false,
                'error' => [
                    'code' => 'SERVER_CONFIGURATION_ERROR',
                    'message' => 'The chat API is not configured.'
                ]
            ], 503, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)
            || !hash_equals($token, trim($matches[1]))) {
            $this->response->send_json([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Authentication is required.'
                ]
            ], 401, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $maximumBytes = $this->positiveInteger(getenv('AI_MAX_REQUEST_BYTES'), 131072, 1048576);
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maximumBytes) {
            $this->response->send_json([
                'success' => false,
                'error' => [
                    'code' => 'REQUEST_TOO_LARGE',
                    'message' => 'The request body exceeds the allowed size.'
                ]
            ], 413, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (strpos($contentType, 'application/json') !== 0) {
            $this->response->send_json([
                'success' => false,
                'error' => [
                    'code' => 'UNSUPPORTED_MEDIA_TYPE',
                    'message' => 'Content-Type must be application/json.'
                ]
            ], 415, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $body = file_get_contents('php://input', false, null, 0, $maximumBytes + 1);
        if ($body === false || strlen($body) > $maximumBytes) {
            $this->response->send_json([
                'success' => false,
                'error' => [
                    'code' => 'REQUEST_TOO_LARGE',
                    'message' => 'The request body exceeds the allowed size.'
                ]
            ], 413, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $input = json_decode($body, true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
            $this->response->send_json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_JSON',
                    'message' => 'The request body must be a valid JSON object.'
                ]
            ], 400, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $message = $input['message'] ?? null;
        if (!is_string($message) || trim($message) === '' || strlen($message) > 8000) {
            $this->response->send_json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_MESSAGE',
                    'message' => 'Message must be a non-empty string of at most 8000 bytes.'
                ]
            ], 422, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $provider = $input['provider'] ?? (getenv('AI_DEFAULT_PROVIDER') ?: 'auto');
        if (!is_string($provider) || !in_array($provider, ['auto', 'google', 'groq', 'mistral', 'ollama'], true)) {
            $this->response->send_json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_PROVIDER',
                    'message' => 'Provider must be auto, google, groq, mistral, or ollama.'
                ]
            ], 400, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $conversationId = $input['conversation_id'] ?? null;
        if ($conversationId !== null && (!is_string($conversationId)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $conversationId))) {
            $this->response->send_json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CONVERSATION_ID',
                    'message' => 'conversation_id must be null or a valid UUID.'
                ]
            ], 400, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fallback = $input['fallback'] ?? false;
        if (!is_bool($fallback)) {
            $this->response->send_json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_FALLBACK_OPTION',
                    'message' => 'fallback must be a boolean.'
                ]
            ], 400, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $rentalContext = $input['rental_context'] ?? [];
        if (!is_array($rentalContext)) {
            $this->response->send_json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_RENTAL_CONTEXT',
                    'message' => 'The rental context must be an object.'
                ]
            ], 400, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $conversationScope = $input['conversation_scope'] ?? 'public';
        if (!is_string($conversationScope)
            || ($conversationScope !== 'public' && !preg_match('/^admin:[1-9][0-9]{0,9}$/', $conversationScope))) {
            $this->response->send_json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CONVERSATION_SCOPE',
                    'message' => 'The chat conversation scope is invalid.'
                ]
            ], 400, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $clientIp = $_SERVER['HTTP_X_CHAT_CLIENT_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            if (!filter_var($clientIp, FILTER_VALIDATE_IP)) {
                $clientIp = 'unknown';
            }
            $limiter = new RateLimiter(
                APP_DIR . '../runtime/ai_router/rate_limits',
                $this->positiveInteger(getenv('AI_RATE_LIMIT_REQUESTS'), 20, 1000),
                $this->positiveInteger(getenv('AI_RATE_LIMIT_SECONDS'), 60, 86400)
            );
            $retryAfter = $limiter->consume($clientIp);
            if ($retryAfter > 0) {
                $this->response->add_header('Retry-After', (string) $retryAfter);
                $this->response->send_json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many requests. Try again later.'
                    ]
                ], 429, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }

            $service = new ChatbotService();
            $result = $service->chat($message, $provider, $conversationId, $fallback, $rentalContext, $conversationScope);
            $this->response->send_json([
                'success' => true,
                'provider' => $result['provider'],
                'model' => $result['model'],
                'conversation_id' => $result['conversation_id'],
                'message' => $result['message']
            ], 200, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        } catch (ProviderException $exception) {
            $payload = [
                'success' => false,
                'error' => [
                    'code' => $exception->getPublicCode(),
                    'message' => $exception->getPublicMessage()
                ]
            ];
            $retryAfter = $exception->getRetryAfter();
            if ($retryAfter !== null) {
                $payload['error']['retry_after'] = $retryAfter;
                $this->response->add_header('Retry-After', (string) $retryAfter);
            }
            $this->response->send_json($payload, $exception->getHttpStatus(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        } catch (RuntimeException $exception) {
            error_log('Chat request failed: ' . $exception->getMessage());
            $this->response->send_json([
                'success' => false,
                'error' => [
                    'code' => 'CHAT_STORAGE_ERROR',
                    'message' => 'The chat request could not be completed.'
                ]
            ], 503, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    private function positiveInteger($value, $default, $maximum)
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < 1) {
            return $default;
        }
        return min((int) $number, $maximum);
    }

    private function respond(array $payload, $status, array $headers = [])
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            error_log('Chat API response could not be encoded: ' . json_last_error_msg());
            $status = 500;
            $body = '{"success":false,"error":{"code":"RESPONSE_ENCODING_ERROR","message":"The chat response could not be generated."}}';
        }

        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen($body));
        foreach ($headers as $header) {
            header($header);
        }
        echo $body;
        exit;
    }
}
