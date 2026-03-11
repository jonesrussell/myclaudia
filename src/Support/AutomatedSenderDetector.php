<?php

declare(strict_types=1);

namespace Claudriel\Support;

final class AutomatedSenderDetector
{
    private const AUTOMATED_PREFIXES = [
        'noreply@', 'no-reply@', 'no_reply@', 'do-not-reply@',
        'donotreply@', 'notifications@', 'notification@', 'notify@',
        'mailer-daemon@', 'automated@', 'system@',
        'alerts@', 'alert@', 'bounce@', 'postmaster@',
        'news@', 'newsletter@', 'updates@',
        'support@', 'info@', 'billing@',
    ];

    private const AUTOMATED_DOMAINS = [
        'github.com', 'stripe.com', 'sendgrid.net', 'mailchimp.com',
        'amazonses.com', 'googlemail.com', 'googleusercontent.com',
        'google.com', 'linkedin.com', 'patreon.com',
        'indeed.com', 'glassdoor.com', 'twitch.tv', 'discord.com',
        'notify.bugsnag.com', 'email.ghostinspector.com',
        'northcloud.one',
    ];

    public function isAutomated(string $email, string $senderName = ''): bool
    {
        $lower = strtolower($email);

        foreach (self::AUTOMATED_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        $domain = substr($lower, strrpos($lower, '@') + 1);
        foreach (self::AUTOMATED_DOMAINS as $automatedDomain) {
            if ($domain === $automatedDomain) {
                return true;
            }
        }

        return false;
    }
}
