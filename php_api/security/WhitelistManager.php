<?php

class WhitelistManager
{
    private string $whitelistFile;
    private array $staticIps = [];
    private array $dynamicIps = [];

    public function __construct(array $config = [], ?string $whitelistFile = null)
    {
        $this->whitelistFile = $whitelistFile ?? __DIR__ . '/../log/whitelist.json';

        // 靜態白名單來自 .env
        if (!empty($config['WHITELIST_IPS'])) {
            $ips = explode(',', $config['WHITELIST_IPS']);
            foreach ($ips as $ip) {
                $ip = trim($ip);
                if ($ip !== '') {
                    $this->staticIps[$ip] = true;
                }
            }
        }

        // 動態白名單來自檔案
        $this->loadDynamic();
    }

    public function isWhitelisted(string $ip): bool
    {
        return isset($this->staticIps[$ip]) || isset($this->dynamicIps[$ip]);
    }

    public function add(string $ip): void
    {
        $this->dynamicIps[$ip] = true;
        $this->saveDynamic();
    }

    public function remove(string $ip): bool
    {
        if (!isset($this->dynamicIps[$ip])) {
            return false;
        }
        unset($this->dynamicIps[$ip]);
        $this->saveDynamic();
        return true;
    }

    public function list(): array
    {
        return [
            'static'  => array_keys($this->staticIps),
            'dynamic' => array_keys($this->dynamicIps),
        ];
    }

    private function loadDynamic(): void
    {
        if (!file_exists($this->whitelistFile)) {
            return;
        }
        $data = json_decode(file_get_contents($this->whitelistFile), true);
        if (is_array($data)) {
            foreach ($data as $ip) {
                $this->dynamicIps[$ip] = true;
            }
        }
    }

    private function saveDynamic(): void
    {
        $dir = dirname($this->whitelistFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->whitelistFile,
            json_encode(array_keys($this->dynamicIps), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}
