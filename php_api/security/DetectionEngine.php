<?php

require_once __DIR__ . '/Rules/RuleInterface.php';
require_once __DIR__ . '/LogManager.php';

class DetectionEngine
{
    private array $rules = [];

    public function registerRule(RuleInterface $rule): void
    {
        $this->rules[$rule->getName()] = $rule;
    }

    public function runAll(LogManager $logManager, ?string $targetIp = null): array
    {
        $violations = [];

        foreach ($this->rules as $rule) {
            $result = $rule->analyze($logManager, $targetIp);
            $violations = array_merge($violations, $result);
        }

        return $violations;
    }

    public function getRule(string $name): ?RuleInterface
    {
        return $this->rules[$name] ?? null;
    }

    public function getRules(): array
    {
        return $this->rules;
    }
}
