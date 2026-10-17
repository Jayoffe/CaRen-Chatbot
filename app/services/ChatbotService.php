<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'services/AIRouter.php';
require_once APP_DIR . 'services/ConversationStore.php';

class ChatbotService
{
    private $router;
    private $store;

    public function __construct()
    {
        $this->router = new AIRouter();
        $maximumTurns = filter_var(getenv('AI_MAX_HISTORY_TURNS'), FILTER_VALIDATE_INT);
        if ($maximumTurns === false || $maximumTurns < 1) {
            $maximumTurns = 10;
        }
        $this->store = new ConversationStore(
            APP_DIR . '../runtime/ai_router/conversations',
            min((int) $maximumTurns, 100)
        );
    }

    public function chat($message, $provider, $conversationId, $fallback)
    {
        return $this->store->withConversation($conversationId, trim($message), function (array $history) use ($message, $provider, $fallback) {
            $messages = [];
            $systemPrompt = trim((string) getenv('AI_SYSTEM_PROMPT'));
            if ($systemPrompt !== '') {
                $messages[] = ['role' => 'system', 'content' => $systemPrompt];
            }
            foreach ($history as $entry) {
                if (isset($entry['role'], $entry['content'])
                    && in_array($entry['role'], ['user', 'assistant'], true)
                    && is_string($entry['content'])) {
                    $messages[] = ['role' => $entry['role'], 'content' => $entry['content']];
                }
            }
            $messages[] = ['role' => 'user', 'content' => trim($message)];
            return $this->router->complete($messages, $provider, $fallback);
        });
    }
}
