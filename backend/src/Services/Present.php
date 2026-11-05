<?php

declare(strict_types=1);

namespace Orbit;

final class Present
{
    public static function user(array $row, bool $self = false): array
    {
        $data = [
            'id' => (int) $row['id'],
            'full_name' => $row['full_name'],
            'has_avatar' => !empty($row['avatar_path']),
            'timezone' => $row['timezone'] ?? 'UTC',
        ];
        if ($self) {
            $data['email'] = $row['email'];
            $data['theme'] = $row['theme'] ?? 'system';
            $data['status'] = $row['status'];
            $data['has_password'] = !empty($row['password_hash']);
            $data['organization'] = $row['organization'] ?? null;
            $data['title'] = $row['title'] ?? null;
            $data['bio'] = $row['bio'] ?? null;
            $data['phone'] = $row['phone'] ?? null;
            $data['created_at'] = iso($row['created_at'] ?? null);
        }

        return $data;
    }

    public static function team(array $row, ?array $invitation = null): array
    {
        $data = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'category' => $row['category'],
            'has_avatar' => !empty($row['avatar_path']),
            'leader_id' => (int) $row['leader_id'],
            'require_join_approval' => (bool) $row['require_join_approval'],
            'require_task_verification' => (bool) $row['require_task_verification'],
            'status' => $row['status'],
            'role' => $row['role'] ?? null,
            'membership_status' => $row['membership_status'] ?? 'active',
            'member_count' => isset($row['member_count']) ? (int) $row['member_count'] : null,
            'created_at' => iso($row['created_at'] ?? null),
            'updated_at' => iso($row['updated_at'] ?? null),
        ];
        if ($invitation !== null) {
            $data['invitation'] = [
                'code' => self::formatCode($invitation['code']),
                'is_active' => (bool) $invitation['is_active'],
                'expires_at' => iso($invitation['expires_at'] ?? null),
                'max_uses' => $invitation['max_uses'] !== null ? (int) $invitation['max_uses'] : null,
                'use_count' => (int) $invitation['use_count'],
            ];
        }

        return $data;
    }

    public static function formatCode(string $code): string
    {
        return implode('-', str_split($code, 4));
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public static function task(
        array $row,
        array $assignees = [],
        array $checklist = [],
        int $commentCount = 0,
        int $attachmentCount = 0
    ): array {
        $done = 0;
        foreach ($checklist as $item) {
            if (!empty($item['is_done'])) {
                $done++;
            }
        }

        return [
            'id' => (int) $row['id'],
            'team_id' => (int) $row['team_id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'instructions' => $row['instructions'],
            'priority' => $row['priority'],
            'status' => $row['status'],
            'review_status' => $row['review_status'],
            'category' => $row['category'],
            'start_date' => iso($row['start_date'] ?? null),
            'deadline' => iso($row['deadline'] ?? null),
            'requires_verification' => (bool) $row['requires_verification'],
            'recurrence' => $row['recurrence'],
            'recurrence_interval' => (int) $row['recurrence_interval'],
            'recurrence_unit' => $row['recurrence_unit'],
            'recurrence_until' => iso_date($row['recurrence_until'] ?? null),
            'series_id' => $row['series_id'] !== null ? (int) $row['series_id'] : null,
            'occurrence_on' => iso_date($row['occurrence_on'] ?? null),
            'version' => (int) $row['version'],
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'submitted_at' => iso($row['submitted_at'] ?? null),
            'completed_at' => iso($row['completed_at'] ?? null),
            'created_at' => iso($row['created_at'] ?? null),
            'updated_at' => iso($row['updated_at'] ?? null),
            'deleted_at' => iso($row['deleted_at'] ?? null),
            'assignees' => $assignees,
            'checklist' => [
                'total' => count($checklist),
                'done' => $done,
                'items' => $checklist,
            ],
            'comment_count' => $commentCount,
            'attachment_count' => $attachmentCount,
        ];
    }

    public static function comment(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'task_id' => (int) $row['task_id'],
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'author_name' => $row['author_name'],
            'body' => $row['body'],
            'comment_type' => $row['comment_type'],
            'created_at' => iso($row['created_at'] ?? null),
            'updated_at' => iso($row['updated_at'] ?? null),
            'edited' => $row['edited_at'] !== null,
        ];
    }

    public static function announcement(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'team_id' => (int) $row['team_id'],
            'author_id' => $row['author_id'] !== null ? (int) $row['author_id'] : null,
            'author_name' => $row['author_name'],
            'title' => $row['title'],
            'body' => $row['body'],
            'is_important' => (bool) $row['is_important'],
            'created_at' => iso($row['created_at'] ?? null),
            'updated_at' => iso($row['updated_at'] ?? null),
        ];
    }

    public static function notification(array $row): array
    {
        $data = [];
        if (!empty($row['data_json'])) {
            $decoded = json_decode((string) $row['data_json'], true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        return [
            'id' => (int) $row['id'],
            'team_id' => $row['team_id'] !== null ? (int) $row['team_id'] : null,
            'type' => $row['type'],
            'title' => $row['title'],
            'body' => $row['body'],
            'data' => $data,
            'is_read' => (bool) $row['is_read'],
            'created_at' => iso($row['created_at'] ?? null),
            'read_at' => iso($row['read_at'] ?? null),
        ];
    }
}
