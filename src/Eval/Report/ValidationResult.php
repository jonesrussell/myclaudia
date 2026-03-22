<?php

declare(strict_types=1);

namespace Claudriel\Eval\Report;

final readonly class ValidationResult
{
    private function __construct(
        public string $file,
        public string $severity,
        public string $rule,
        public string $message,
        public ?string $test = null,
    ) {}

    public static function error(string $file, string $rule, string $message, ?string $test = null): self
    {
        return new self($file, 'error', $rule, $message, $test);
    }

    public static function warning(string $file, string $rule, string $message, ?string $test = null): self
    {
        return new self($file, 'warning', $rule, $message, $test);
    }

    public function isError(): bool
    {
        return $this->severity === 'error';
    }

    /** @return array{file: string, severity: string, rule: string, test: ?string, message: string} */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'severity' => $this->severity,
            'rule' => $this->rule,
            'test' => $this->test,
            'message' => $this->message,
        ];
    }
}
