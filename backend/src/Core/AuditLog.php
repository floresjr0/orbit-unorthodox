<?php

declare(strict_types=1);

namespace Orbit;

final class AuditLog
{
    public function __construct(private readonly App $app)
    {
    }

    public function write(
        string $scope,
        ?int $teamId,
        ?int $actorId,
        string $action,
        string $summary,
        ?string $entityType = null,
        ?string $entityId = null,
        array $meta = []
    ): void {
        $stmt = $this->app->pdo()->prepare(
            'INSERT INTO audit_logs
            (scope, team_id, actor_id, action, summary, entity_type, entity_id, ip, user_agent, meta_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $scope,
            $teamId,
            $actorId,
            substr($action, 0, 80),
            mb_substr($summary, 0, 255),
            $entityType,
            $entityId,
            client_ip($this->app->config),
            user_agent(),
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
