<?php

declare(strict_types=1);

namespace App\Core\Rbac;

use App\Core\Database\Database;

final class AuditRecorder
{
    public function __construct(private readonly Database $database) {}

    public function record(int $actorId, string $action, string $targetType, int|string|null $targetId, array $metadata = []): void
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (is_string($key) && preg_match('/^[a-z_]{1,50}$/', $key) && (is_string($value) || is_int($value) || is_bool($value) || $value === null)) {
                $safe[$key] = $value;
            }
        }
        $this->database->execute('INSERT INTO audit_logs (actor_user_id, action, target_type, target_id, metadata, occurred_at) VALUES (:actor, :action, :type, :target, :metadata, :occurred)', [
            'actor' => $actorId, 'action' => $action, 'type' => $targetType, 'target' => $targetId === null ? null : (string) $targetId,
            'metadata' => json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'occurred' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
