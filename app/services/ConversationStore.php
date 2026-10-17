<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
require_once APP_DIR . 'services/ProviderException.php';

class ConversationStore
{
    private $directory;
    private $maximumTurns;

    public function __construct($directory, $maximumTurns)
    {
        $this->directory = $directory;
        $this->maximumTurns = max(1, (int) $maximumTurns);
    }

    public function withConversation($conversationId, $userMessage, callable $respond)
    {
        $conversationId = $conversationId ?: $this->uuid();
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to initialize conversation storage.');
        }

        $lockPath = $this->directory . DIRECTORY_SEPARATOR . $conversationId . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to lock conversation storage.');
        }

        try {
            $messages = $this->read($conversationId);
            $result = call_user_func($respond, $messages);
            $messages[] = ['role' => 'user', 'content' => $userMessage];
            $messages[] = ['role' => 'assistant', 'content' => $result['message']];
            $messages = array_slice($messages, -($this->maximumTurns * 2));
            $this->write($conversationId, $messages);
            $result['conversation_id'] = $conversationId;
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function read($conversationId)
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $conversationId . '.json';
        if (!is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read conversation storage.');
        }
        $messages = json_decode($contents, true);
        if (!is_array($messages)) {
            throw new RuntimeException('Conversation storage contains invalid data.');
        }
        return array_slice($messages, -($this->maximumTurns * 2));
    }

    private function write($conversationId, array $messages)
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $conversationId . '.json';
        $temporaryPath = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $contents = json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($contents === false || file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write conversation storage.');
        }
        if (!rename($temporaryPath, $path)) {
            unlink($temporaryPath);
            throw new RuntimeException('Unable to save conversation storage.');
        }
        @chmod($path, 0600);
    }

    private function uuid()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
