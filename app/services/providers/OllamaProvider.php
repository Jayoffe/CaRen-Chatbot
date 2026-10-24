<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class OllamaProvider implements AIProvider
{
    private $baseUrl;
    private $model;
    private $client;

    public function __construct()
    {
        $this->baseUrl = rtrim(trim((string) (getenv('OLLAMA_BASE_URL') ?: 'http://localhost:11434')), '/');
        $this->model = trim((string) (getenv('OLLAMA_MODEL') ?: 'llama3.2:3b'));
        $this->client = new HttpClient();
    }

    public function getName()
    {
        return 'ollama';
    }

    public function complete(array $messages, $timeout)
    {
        $parts = parse_url($this->baseUrl);
        if ($this->baseUrl === '' || !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || $this->model === '') {
            throw new ProviderException('ollama', 'PROVIDER_NOT_CONFIGURED', 'The selected AI provider is not configured.', 503, false);
        }

        $response = $this->client->postJson(
            'ollama',
            $this->baseUrl . '/api/chat',
            [],
            ['model' => $this->model, 'messages' => $messages, 'stream' => false],
            $timeout
        );
        $message = $response['message']['content'] ?? null;
        if (!is_string($message)) {
            throw new ProviderException('ollama', 'PROVIDER_INVALID_RESPONSE', 'The AI provider returned an invalid response.', 502, true);
        }
        return ['model' => $response['model'] ?? $this->model, 'message' => $message];
    }
}
