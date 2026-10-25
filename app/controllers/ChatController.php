<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ChatController extends Controller
{
    public function chat()
    {
        load_class('security_headers', 'kernel')->apply_api();

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->respond([
                'success' => false,
                'error' => [
                    'code' => 'METHOD_NOT_ALLOWED',
                    'message' => 'Only POST requests are allowed.'
                ]
            ], 405, ['Allow: POST']);
        }

        $token = trim((string) getenv('AI_ROUTER_TOKEN'));
        if ($token === '' || strlen($token) < 32) {
            $this->respond([
                'success' => false,
                'error' => [
                    'code' => 'SERVER_CONFIGURATION_ERROR',
                    'message' => 'The chat API is not configured.'
                ]
            ], 503);
        }

        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)
            || !hash_equals($token, trim($matches[1]))) {
            $this->respond([
                'success' => false,
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Authentication is required.'
                ]
            ], 401);
        }

        $maximumBytes = $this->positiveInteger(getenv('AI_MAX_REQUEST_BYTES'), 131072, 1048576);
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maximumBytes) {
            $this->respond([
                'success' => false,
                'error' => [
                    'code' => 'REQUEST_TOO_LARGE',
                    'message' => 'The request body exceeds the allowed size.'
                ]
            ], 413);
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (strpos($contentType, 'application/json') !== 0) {
            $this->respond([
                'success' => false,
                'error' => [
                    'code' => 'UNSUPPORTED_MEDIA_TYPE',
                    'message' => 'Content-Type must be application/json.'
                ]
            ], 415);
        }

        $body = file_get_contents('php://input', false, null, 0, $maximumBytes + 1);
        if ($body === false || strlen($body) > $maximumBytes) {
            $this->respond([
                'success' => false,
                'error' => [
                    'code' => 'REQUEST_TOO_LARGE',
                    'message' => 'The request body exceeds the allowed size.'
                ]
            ], 413);
        }

        $input = json_decode($body, true, 32);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
            $this->respond([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_JSON',
                    'message' => 'The request body must be a valid JSON object.'
                ]
            ], 400);
        }

        $message = $input['message'] ?? null;
        if (!is_string($message) || trim($message) === '' || strlen($message) > 8000) {
            $this->respond([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_MESSAGE',
                    'message' => 'Message must be a non-empty string of at most 8000 bytes.'
                ]
            ], 422);
        }

        $provider = $input['provider'] ?? (getenv('AI_DEFAULT_PROVIDER') ?: 'auto');
        if (!is_string($provider) || !in_array($provider, ['auto', 'google', 'groq', 'mistral', 'ollama'], true)) {
            $this->respond([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_PROVIDER',
                    'message' => 'Provider must be auto, google, groq, mistral, or ollama.'
                ]
            ], 400);
        }

        $conversationId = $input['conversation_id'] ?? null;
        if ($conversationId !== null && (!is_string($conversationId)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $conversationId))) {
            $this->respond([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CONVERSATION_ID',
                    'message' => 'conversation_id must be null or a valid UUID.'
                ]
            ], 400);
        }

        $fallback = $input['fallback'] ?? false;
        if (!is_bool($fallback)) {
            $this->respond([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_FALLBACK_OPTION',
                    'message' => 'fallback must be a boolean.'
                ]
            ], 400);
        }

        $rentalContext = $input['rental_context'] ?? [];
        if (!is_array($rentalContext)) {
            $this->respond([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_RENTAL_CONTEXT',
                    'message' => 'The rental context must be an object.'
                ]
            ], 400);
        }

        $conversationScope = $input['conversation_scope'] ?? 'public';
        if (!is_string($conversationScope)
            || ($conversationScope !== 'public' && !preg_match('/^admin:[1-9][0-9]{0,9}$/', $conversationScope))) {
            $this->respond([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CONVERSATION_SCOPE',
                    'message' => 'The chat conversation scope is invalid.'
                ]
            ], 400);
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
                $this->respond([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many requests. Try again later.'
                    ]
                ], 429, ['Retry-After: ' . $retryAfter]);
            }

            $service = new ChatbotService();
            $result = $service->chat($message, $provider, $conversationId, $fallback, $rentalContext, $conversationScope);
            $this->respond([
                'success' => true,
                'provider' => $result['provider'],
                'model' => $result['model'],
                'conversation_id' => $result['conversation_id'],
                'message' => $result['message']
            ], 200);
        } catch (ProviderException $exception) {
            $payload = [
                'success' => false,
                'error' => [
                    'code' => $exception->getPublicCode(),
                    'message' => $exception->getPublicMessage()
                ]
            ];
            if ($exception->getRetryAfter() !== null) {
                $payload['error']['retry_after'] = $exception->getRetryAfter();
                $retryHeaders = ['Retry-After: ' . $exception->getRetryAfter()];
            } else {
                $retryHeaders = [];
            }
            $this->respond($payload, $exception->getHttpStatus(), $retryHeaders);
        } catch (RuntimeException $exception) {
            error_log('Chat request failed: ' . $exception->getMessage());
            $this->respond([
                'success' => false,
                'error' => [
                    'code' => 'CHAT_STORAGE_ERROR',
                    'message' => 'The chat request could not be completed.'
                ]
            ], 503);
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
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($headers as $header) {
            header($header);
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
