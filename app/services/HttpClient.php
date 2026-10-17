<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'services/ProviderException.php';

class HttpClient
{
    public function postJson($provider, $url, array $headers, array $payload, $timeout)
    {
        if (!function_exists('curl_init')) {
            throw new ProviderException($provider, 'SERVER_CONFIGURATION_ERROR', 'The chat API is not configured.', 503, false);
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new ProviderException($provider, 'PROVIDER_UNAVAILABLE', 'The AI provider is unavailable.', 503, true);
        }

        $responseHeaders = [];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            curl_close($handle);
            throw new ProviderException($provider, 'INVALID_REQUEST', 'The request could not be encoded.', 400, false);
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, (int) $timeout),
            CURLOPT_TIMEOUT => (int) $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            }
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($handle);
        curl_close($handle);

        if ($raw === false || $curlError !== 0) {
            throw new ProviderException($provider, 'PROVIDER_UNAVAILABLE', 'The AI provider is unavailable.', 503, true);
        }

        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            if ($status === 401 || $status === 403) {
                throw new ProviderException($provider, 'PROVIDER_AUTH_ERROR', 'The selected AI provider is not configured correctly.', 503, false);
            }
            if ($status === 429) {
                throw new ProviderException(
                    $provider,
                    'PROVIDER_RATE_LIMITED',
                    'The selected AI provider is rate-limited.',
                    503,
                    true,
                    $this->retryAfter($responseHeaders['retry-after'] ?? null)
                );
            }
            if ($status === 400 || $status === 422) {
                throw new ProviderException($provider, 'PROVIDER_INVALID_REQUEST', 'The selected AI provider rejected the request.', 400, false);
            }
            if ($status === 404) {
                throw new ProviderException($provider, 'MODEL_NOT_AVAILABLE', 'The selected AI model is not available.', 503, false);
            }
            if ($status >= 500 || $status === 0) {
                throw new ProviderException($provider, 'PROVIDER_UNAVAILABLE', 'The AI provider is unavailable.', 503, true);
            }
            throw new ProviderException($provider, 'PROVIDER_ERROR', 'The AI provider could not process the request.', 502, false);
        }

        if (!is_array($decoded)) {
            throw new ProviderException($provider, 'PROVIDER_INVALID_RESPONSE', 'The AI provider returned an invalid response.', 502, true);
        }

        return $decoded;
    }

    private function retryAfter($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (ctype_digit((string) $value)) {
            return min(max((int) $value, 1), 300);
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        return min(max($timestamp - time(), 1), 300);
    }
}
