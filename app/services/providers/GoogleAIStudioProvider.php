<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class GoogleAIStudioProvider implements AIProvider
{
    private $apiKey;
    private $model;
    private $client;

    public function __construct()
    {
        $this->apiKey = trim((string) getenv('GOOGLE_AI_STUDIO_API_KEY'));
        $this->model = trim((string) (getenv('GOOGLE_AI_STUDIO_MODEL') ?: 'gemini-2.5-flash'));
        $this->client = new HttpClient();
    }

    public function getName()
    {
        return 'google';
    }

    public function complete(array $messages, $timeout)
    {
        if ($this->apiKey === '' || $this->model === '') {
            throw new ProviderException('google', 'PROVIDER_NOT_CONFIGURED', 'The selected AI provider is not configured.', 503, false);
        }

        $systemParts = [];
        $contents = [];
        foreach ($messages as $message) {
            if (!isset($message['role'], $message['content']) || !is_string($message['content'])) {
                continue;
            }
            if ($message['role'] === 'system') {
                $systemParts[] = ['text' => $message['content']];
                continue;
            }
            if (in_array($message['role'], ['user', 'assistant'], true)) {
                $contents[] = [
                    'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $message['content']]],
                ];
            }
        }
        if (!$contents) {
            throw new ProviderException('google', 'INVALID_REQUEST', 'The AI request did not contain a user message.', 400, false);
        }

        $payload = ['contents' => $contents];
        if ($systemParts) {
            $payload['systemInstruction'] = ['parts' => $systemParts];
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($this->model) . ':generateContent';
        $response = $this->client->postJson(
            'google',
            $url,
            ['x-goog-api-key: ' . $this->apiKey],
            $payload,
            $timeout
        );

        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        $textParts = [];
        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null) && empty($part['thought'])) {
                $textParts[] = $part['text'];
            }
        }
        $message = trim(implode('', $textParts));
        if ($message === '') {
            throw new ProviderException('google', 'PROVIDER_INVALID_RESPONSE', 'The AI provider returned an invalid response.', 502, true);
        }
        return ['model' => $response['modelVersion'] ?? $this->model, 'message' => $message];
    }
}
