<?php

declare(strict_types=1);

namespace Claudriel\Service\Mail;

interface MailTransportInterface
{
    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function send(array $message): array;
}
