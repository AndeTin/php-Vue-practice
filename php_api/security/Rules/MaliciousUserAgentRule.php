<?php

require_once __DIR__ . '/RuleInterface.php';
require_once __DIR__ . '/../LogManager.php';

class MaliciousUserAgentRule implements RuleInterface
{
    private array $patterns;

    private const DEFAULT_PATTERNS = [
        'sqlmap',
        'nikto',
        'nmap',
        'gobuster',
        'dirbuster',
        'wpscan',
        'join/user-agent',
        'ffuf',
        'hydra',
        'metasploit',
        'openvas',
        'nessus',
        'acunetix',
        'burpsuite',
        'zap',
        'netsparker',
        'w3af',
        'arachni',
        'crawler',
        'scanner',
        'masscan',
        'zmeu',
        'mellivora',
        'netcraft',
        'swarm',
        'nstealth',
        'webinspect',
        'blackwidow',
        'ghost',
        'stalker',
    ];

    public function __construct(?array $extraPatterns = null)
    {
        $this->patterns = array_merge(self::DEFAULT_PATTERNS, $extraPatterns ?? []);
        $this->patterns = array_unique($this->patterns);
    }

    public function getName(): string
    {
        return 'malicious_ua';
    }

    public function analyze(LogManager $logManager, ?string $targetIp = null): array
    {
        $window = 3600;
        $entries = $logManager->getRecentEntries($window);
        $violations = [];
        $seenIps = [];

        foreach ($entries as $entry) {
            if ($targetIp !== null && $entry['ip'] !== $targetIp) {
                continue;
            }
            if (isset($seenIps[$entry['ip']])) {
                continue;
            }

            $ua = mb_strtolower($entry['ua'] ?? '');

            foreach ($this->patterns as $pattern) {
                if (str_contains($ua, $pattern)) {
                    $violations[] = [
                        'ip'     => $entry['ip'],
                        'reason' => "使用惡意掃描工具 UA: {$entry['ua']}",
                        'rule'   => $this->getName(),
                    ];
                    $seenIps[$entry['ip']] = true;
                    break;
                }
            }
        }

        return $violations;
    }
}
