<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Log\AbstractLogger;

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<string> Messages at $level with their placeholders filled. */
    public function messages(string $level): array
    {
        $messages = [];
        foreach ($this->records as $record) {
            if ($record['level'] !== $level) {
                continue;
            }
            $replace = [];
            foreach ($record['context'] as $key => $value) {
                if (is_scalar($value)) {
                    $replace['{' . $key . '}'] = (string) $value;
                }
            }
            $messages[] = strtr($record['message'], $replace);
        }

        return $messages;
    }
}
