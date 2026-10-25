<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
class AIRouter
{
    private $providers;
    private $timeout;
    private $fallbackEnabled;
    private $priority;

    public function __construct()
    {
        $this->providers = [
            'google' => new GoogleAIStudioProvider(),
            'groq' => new GroqProvider(),
            'mistral' => new MistralProvider(),
            'ollama' => new OllamaProvider()
        ];
        $this->timeout = $this->positiveInteger(getenv('AI_REQUEST_TIMEOUT'), 60, 120);
        $this->fallbackEnabled = filter_var(getenv('AI_ENABLE_FALLBACK') ?: 'true', FILTER_VALIDATE_BOOLEAN);

        $configured = array_map('trim', explode(',', (string) (getenv('AI_PROVIDER_PRIORITY') ?: 'google,groq,mistral,ollama')));
        $this->priority = [];
        foreach ($configured as $name) {
            if (isset($this->providers[$name]) && !in_array($name, $this->priority, true)) {
                $this->priority[] = $name;
            }
        }
        foreach (array_keys($this->providers) as $name) {
            if (!in_array($name, $this->priority, true)) {
                $this->priority[] = $name;
            }
        }
    }

    public function complete(array $messages, $requestedProvider, $requestFallback)
    {
        $automatic = $requestedProvider === 'auto';
        $canFallback = $this->fallbackEnabled && ($automatic || $requestFallback);
        $candidates = $automatic ? $this->priority : [$requestedProvider];
        if (!$automatic && $canFallback) {
            foreach ($this->priority as $name) {
                if ($name !== $requestedProvider) {
                    $candidates[] = $name;
                }
            }
        }
        if ($automatic && !$canFallback) {
            $candidates = [reset($candidates)];
        }
        $failureCount = 0;
        $lastException = null;
        $retryAfter = null;

        foreach ($candidates as $name) {
            try {
                $result = $this->providers[$name]->complete($messages, $this->timeout);
                $result['provider'] = $name;
                return $result;
            } catch (ProviderException $exception) {
                $lastException = $exception;
                $failureCount++;
                if ($exception->getRetryAfter() !== null) {
                    $retryAfter = $exception->getRetryAfter();
                }

                if ($exception->getPublicCode() === 'PROVIDER_NOT_CONFIGURED') {
                    if ($canFallback) {
                        continue;
                    }
                    throw $exception;
                }

                if (!$canFallback || !$exception->isRetryable()) {
                    throw $exception;
                }
            }
        }

        if ($failureCount === 1 && $lastException instanceof ProviderException) {
            throw $lastException;
        }

        throw new ProviderException(
            'router',
            'NO_PROVIDER_AVAILABLE',
            'No AI provider is currently available.',
            503,
            false,
            $retryAfter
        );
    }

    private function positiveInteger($value, $default, $maximum)
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || $number < 1) {
            return $default;
        }
        return min((int) $number, $maximum);
    }
}
