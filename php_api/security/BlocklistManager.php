<?php

class BlocklistManager
{
    private string $blocklistFile;
    private int $defaultDuration;
    private array $blocks = [];
    private bool $loaded = false;

    public function __construct(array $config = [], ?string $blocklistFile = null)
    {
        $this->blocklistFile   = $blocklistFile ?? __DIR__ . '/../log/blocklist.json';
        $this->defaultDuration = (int) ($config['BLOCK_DURATION'] ?? 3600);
    }

    public function isBlocked(string $ip): bool
    {
        $this->ensureLoaded();
        $this->cleanExpired();

        if (!isset($this->blocks[$ip])) {
            return false;
        }

        $entry = $this->blocks[$ip];
        return $entry['expires_at'] > time();
    }

    public function block(string $ip, string $reason, string $rule, ?int $duration = null): void
    {
        $this->ensureLoaded();

        $duration = $duration ?? $this->defaultDuration;

        $this->blocks[$ip] = [
            'ip'         => $ip,
            'reason'     => $reason,
            'rule'       => $rule,
            'blocked_at' => time(),
            'expires_at' => time() + $duration,
        ];

        $this->save();
    }

    public function unblock(string $ip): bool
    {
        $this->ensureLoaded();
        if (!isset($this->blocks[$ip])) {
            return false;
        }
        unset($this->blocks[$ip]);
        $this->save();
        return true;
    }

    public function list(): array
    {
        $this->ensureLoaded();
        $this->cleanExpired();
        return array_values($this->blocks);
    }

    public function cleanExpired(): void
    {
        $now = time();
        $changed = false;

        foreach ($this->blocks as $ip => $entry) {
            if ($entry['expires_at'] <= $now) {
                unset($this->blocks[$ip]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->save();
        }
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        if (!file_exists($this->blocklistFile)) {
            $this->blocks = [];
            return;
        }

        $data = json_decode(file_get_contents($this->blocklistFile), true);
        if (!is_array($data)) {
            $this->blocks = [];
            return;
        }

        foreach ($data as $entry) {
            if (isset($entry['ip'])) {
                $this->blocks[$entry['ip']] = $entry;
            }
        }
    }

    private function save(): void
    {
        $dir = dirname($this->blocklistFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->blocklistFile,
            json_encode(array_values($this->blocks), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
