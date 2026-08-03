<?php

declare(strict_types=1);

namespace BYD\Queue\Jobs;

use BYD\Queue\Contracts\JobInterface;

/**
 * NotificationJob - Send email/SMS notifications async
 *
 * Payload:
 * { "type": "email", "to": "customer@example.com", "subject": "...", "body": "..." }
 */
final class NotificationJob implements JobInterface
{
    public function handle(array $payload): void
    {
        $type = $payload['type'] ?? 'email';
        echo "  → Sending {$type} notification to {$payload['to']}\n";

        // TODO: Integrate with SMTP/SMS provider
        // mail($payload['to'], $payload['subject'], $payload['body']);

        echo "  ✓ NotificationJob completed.\n";
    }
}
