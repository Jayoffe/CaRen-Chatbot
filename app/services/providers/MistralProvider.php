<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class MistralProvider implements AIProvider
{
    private $apiKey;
    private $model;
    private $client;

    public function __construct()
    {
        $this->apiKey = trim((string) getenv('MISTRAL_API_KEY'));
        $this->model = trim((string) (getenv('MISTRAL_MODEL') ?: 'mistral-small-latest'));
        $this->client = new HttpClient();
    }

    public function getName()
    {
        return 'mistral';
    }

    public function complete(array $messages, $timeout)
    {
        if ($this->apiKey === '') {
            throw new ProviderException('mistral', 'PROVIDER_NOT_CONFIGURED', 'The selected AI provider is not configured.', 503, false);
        }
        if ($this->model === '') {
            throw new ProviderException('mistral', 'PROVIDER_NOT_CONFIGURED', 'The selected AI provider is not configured.', 503, false);
        }

        $response = $this->client->postJson(
            'mistral',
            'https://api.mistral.ai/v1/chat/completions',
            ['Authorization: Bearer ' . $this->apiKey],
            ['model' => $this->model, 'messages' => $messages],
            $timeout
        );
        $message = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($message)) {
            throw new ProviderException('mistral', 'PROVIDER_INVALID_RESPONSE', 'The AI provider returned an invalid response.', 502, true);
        }
        return ['model' => $response['model'] ?? $this->model, 'message' => $message];
    }
}
