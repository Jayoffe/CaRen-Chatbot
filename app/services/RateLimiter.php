<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class RateLimiter
{
    private $directory;
    private $limit;
    private $window;

    public function __construct($directory, $limit, $window)
    {
        $this->directory = $directory;
        $this->limit = max(1, (int) $limit);
        $this->window = max(1, (int) $window);
    }

    public function consume($identity)
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to initialize rate-limit storage.');
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . hash('sha256', (string) $identity) . '.json';
        $file = fopen($path, 'c+');
        if ($file === false || !flock($file, LOCK_EX)) {
            if (is_resource($file)) {
                fclose($file);
            }
            throw new RuntimeException('Unable to lock rate-limit storage.');
        }

        try {
            rewind($file);
            $contents = stream_get_contents($file);
            $timestamps = $contents === false || $contents === '' ? [] : json_decode($contents, true);
            if (!is_array($timestamps)) {
                throw new RuntimeException('Rate-limit storage contains invalid data.');
            }
            $now = time();
            $timestamps = array_values(array_filter($timestamps, function ($timestamp) use ($now) {
                return is_int($timestamp) && $timestamp > $now - $this->window;
            }));

            if (count($timestamps) >= $this->limit) {
                return max(1, min($this->window, $timestamps[0] + $this->window - $now));
            }

            $timestamps[] = $now;
            $encoded = json_encode($timestamps);
            if ($encoded === false) {
                throw new RuntimeException('Unable to encode rate-limit storage.');
            }
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $encoded) === false || !fflush($file)) {
                throw new RuntimeException('Unable to update rate-limit storage.');
            }
            return 0;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
