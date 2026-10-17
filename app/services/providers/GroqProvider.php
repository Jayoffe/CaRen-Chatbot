<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'services/providers/AIProvider.php';
require_once APP_DIR . 'services/HttpClient.php';

class GroqProvider implements AIProvider
{
    private $apiKey;
    private $model;
    private $client;

    public function __construct()
    {
        $this->apiKey = trim((string) getenv('GROQ_API_KEY'));
        $this->model = trim((string) (getenv('GROQ_MODEL') ?: 'llama-3.3-70b-versatile'));
        $this->client = new HttpClient();
    }

    public function getName()
    {
        return 'groq';
    }

    public function complete(array $messages, $timeout)
    {
        if ($this->apiKey === '') {
            throw new ProviderException('groq', 'PROVIDER_NOT_CONFIGURED', 'The selected AI provider is not configured.', 503, false);
        }
        if ($this->model === '') {
            throw new ProviderException('groq', 'PROVIDER_NOT_CONFIGURED', 'The selected AI provider is not configured.', 503, false);
        }

        $response = $this->client->postJson(
            'groq',
            'https://api.groq.com/openai/v1/chat/completions',
            ['Authorization: Bearer ' . $this->apiKey],
            ['model' => $this->model, 'messages' => $messages],
            $timeout
        );
        $message = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($message)) {
            throw new ProviderException('groq', 'PROVIDER_INVALID_RESPONSE', 'The AI provider returned an invalid response.', 502, true);
        }
        return ['model' => $response['model'] ?? $this->model, 'message' => $message];
    }
}
