<?php

declare(strict_types=1);

namespace Claudriel\Service\Mail;

final class LoggedMailTransport implements MailTransportInterface
{
    public function __construct(
        private readonly string $logFile,
    ) {}

    public function send(array $message): array
    {
        $entry = $message + [
            'transport' => 'logged',
            'logged_at' => gmdate(\DateTimeInterface::ATOM),
        ];

        $dir = dirname($this->logFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $this->logFile,
            json_encode($entry, JSON_THROW_ON_ERROR).PHP_EOL,
            FILE_APPEND,
        );

        return [
            'transport' => 'logged',
            'status' => 'queued',
        ];
    }
}
