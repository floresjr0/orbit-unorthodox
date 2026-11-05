<?php

declare(strict_types=1);

/**
 * End-to-end API smoke test. The PHP server must already be running.
 * php backend/bin/smoke.php http://127.0.0.1:8080
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/') . '/api/v1';
$failures = 0;

function call_api(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json', 'X-Device-Name: ORBIT Smoke'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string) $raw, true);

    return ['status' => $status, 'json' => is_array($json) ? $json : ['raw' => $raw], 'raw' => $raw];
}

function check(bool $ok, string $label, array $response): void
{
    global $failures;
    if ($ok) {
        fwrite(STDOUT, "PASS  {$label}\n");
        return;
    }
    $failures++;
    $message = $response['json']['message'] ?? $response['raw'];
    fwrite(STDOUT, "FAIL  {$label} [{$response['status']}] {$message}\n");
}

$health = call_api('GET', $base . '/health');
check($health['status'] === 200 && ($health['json']['data']['database'] ?? '') === 'ok', 'health', $health);

$bad = call_api('POST', $base . '/auth/register', ['full_name' => 'A', 'email' => 'nope', 'password' => 'short', 'password_confirmation' => 'short']);
check($bad['status'] === 422, 'register validation', $bad);

$stamp = (string) time();
$leader = call_api('POST', $base . '/auth/register', [
    'full_name' => 'Amina Cole',
    'email' => "leader{$stamp}@orbit.test",
    'password' => 'OrbitPass1',
    'password_confirmation' => 'OrbitPass1',
]);
check($leader['status'] === 201 && !empty($leader['json']['data']['access_token']), 'register leader', $leader);
$tokenA = $leader['json']['data']['access_token'] ?? '';
$refreshA = $leader['json']['data']['refresh_token'] ?? '';

$member = call_api('POST', $base . '/auth/register', [
    'full_name' => 'Luis Ortega',
    'email' => "member{$stamp}@orbit.test",
    'password' => 'OrbitPass1',
    'password_confirmation' => 'OrbitPass1',
]);
check($member['status'] === 201, 'register member', $member);
$tokenB = $member['json']['data']['access_token'] ?? '';
$userB = (int) ($member['json']['data']['user']['id'] ?? 0);

$wrong = call_api('POST', $base . '/auth/login', ['email' => "leader{$stamp}@orbit.test", 'password' => 'wrongpass1']);
check($wrong['status'] === 401, 'login rejects bad password', $wrong);

$me = call_api('GET', $base . '/auth/me', null, $tokenA);
check($me['status'] === 200 && ($me['json']['data']['full_name'] ?? '') === 'Amina Cole', 'current user', $me);

$team = call_api('POST', $base . '/teams', [
    'name' => 'Studio Lab',
    'description' => 'Capstone team',
    'category' => 'Academic',
    'require_task_verification' => true,
], $tokenA);
check($team['status'] === 201 && !empty($team['json']['data']['invitation']['code']), 'create team', $team);
$teamId = (int) ($team['json']['data']['id'] ?? 0);
$code = $team['json']['data']['invitation']['code'] ?? '';

$denied = call_api('POST', $base . "/teams/{$teamId}/tasks", [
    'title' => 'Should fail',
    'assignee_ids' => [$userB],
], $tokenB);
check($denied['status'] === 404 || $denied['status'] === 403, 'outsider cannot create tasks', $denied);

$joined = call_api('POST', $base . '/invitations/join', ['code' => $code], $tokenB);
check($joined['status'] === 200 && ($joined['json']['data']['role'] ?? '') === 'member', 'join with invitation', $joined);

$task = call_api('POST', $base . "/teams/{$teamId}/tasks", [
    'title' => 'Draft the research brief',
    'description' => 'Collect sources and outline the argument.',
    'priority' => 'high',
    'assignee_ids' => [$userB],
    'deadline' => gmdate('c', time() + 86400),
    'checklist' => ['Collect sources', 'Write outline'],
    'requires_verification' => true,
], $tokenA);
check($task['status'] === 201 && ($task['json']['data']['checklist']['total'] ?? 0) === 2, 'create task', $task);
$taskId = (int) ($task['json']['data']['id'] ?? 0);
$version = (int) ($task['json']['data']['version'] ?? 0);

$hidden = call_api('POST', $base . '/teams', ['name' => 'Private Orbit'], $tokenA);
$hiddenId = (int) ($hidden['json']['data']['id'] ?? 0);
$secret = call_api('POST', $base . "/teams/{$hiddenId}/tasks", [
    'title' => 'Private task',
    'assignee_ids' => [(int) $leader['json']['data']['user']['id']],
], $tokenA);
$secretId = (int) ($secret['json']['data']['id'] ?? 0);
$peek = call_api('GET', $base . '/tasks/' . $secretId, null, $tokenB);
check($peek['status'] === 404, 'member cannot read another team task', $peek);

$status = call_api('POST', $base . "/tasks/{$taskId}/status", [
    'status' => 'in_progress',
    'base_version' => $version,
    'base_status' => 'todo',
], $tokenB);
check($status['status'] === 200 && ($status['json']['data']['status'] ?? '') === 'in_progress', 'member updates status', $status);
$version = (int) ($status['json']['data']['version'] ?? $version);

$notes = call_api('GET', $base . '/notifications', null, $tokenA);
check($notes['status'] === 200 && ($notes['json']['data']['unread_count'] ?? 0) >= 1, 'leader receives notification', $notes);

$comment = call_api('POST', $base . "/tasks/{$taskId}/comments", ['body' => 'Outline is underway.', 'comment_type' => 'progress'], $tokenB);
check($comment['status'] === 201, 'progress comment', $comment);

$submit = call_api('POST', $base . "/tasks/{$taskId}/status", [
    'status' => 'done',
    'base_version' => $version,
    'base_status' => 'in_progress',
    'note' => 'Ready for review',
], $tokenB);
check($submit['status'] === 200 && ($submit['json']['data']['review_status'] ?? '') === 'awaiting', 'submit for review', $submit);
check(($submit['json']['data']['status'] ?? '') !== 'done', 'awaiting review is not completed yet', $submit);

$reject = call_api('POST', $base . "/tasks/{$taskId}/review", [
    'decision' => 'rejected',
    'feedback' => 'Add the source list.',
    'return_status' => 'in_progress',
], $tokenA);
check($reject['status'] === 200 && ($reject['json']['data']['review_status'] ?? '') === 'rejected', 'leader rejects completion', $reject);
$version = (int) ($reject['json']['data']['version'] ?? 0);

$submit2 = call_api('POST', $base . "/tasks/{$taskId}/status", [
    'status' => 'done',
    'base_version' => $version,
    'base_status' => $reject['json']['data']['status'] ?? 'in_progress',
], $tokenB);
check(($submit2['json']['data']['review_status'] ?? '') === 'awaiting', 'resubmit completion', $submit2);

$approve = call_api('POST', $base . "/tasks/{$taskId}/review", ['decision' => 'approved', 'feedback' => 'Looks good.'], $tokenA);
check($approve['status'] === 200 && ($approve['json']['data']['status'] ?? '') === 'done', 'leader approves completion', $approve);

$conflict = call_api('POST', $base . "/tasks/{$taskId}/status", [
    'status' => 'pending',
    'base_version' => 1,
    'base_status' => 'todo',
], $tokenA);
check($conflict['status'] === 409, 'stale status conflicts', $conflict);

$op = 'smoke-' . $stamp;
$sync = call_api('POST', $base . '/sync/push', [
    'operations' => [[
        'op_id' => $op,
        'type' => 'task.comment',
        'entity_id' => $taskId,
        'base_version' => (int) $approve['json']['data']['version'],
        'payload' => ['body' => 'Synced from the queue.', 'comment_type' => 'comment', 'local_id' => 'local-1'],
    ]],
], $tokenB);
check(($sync['json']['data']['results'][0]['status'] ?? '') === 'applied', 'sync applies comment', $sync);
$again = call_api('POST', $base . '/sync/push', [
    'operations' => [[
        'op_id' => $op,
        'type' => 'task.comment',
        'entity_id' => $taskId,
        'payload' => ['body' => 'Synced from the queue.', 'comment_type' => 'comment'],
    ]],
], $tokenB);
check(($again['json']['data']['results'][0]['status'] ?? '') === 'applied' && ($again['json']['data']['results'][0]['data']['comment']['id'] ?? null) === ($sync['json']['data']['results'][0]['data']['comment']['id'] ?? null), 'sync operation is idempotent', $again);

$pull = call_api('GET', $base . '/sync/pull?since=1970-01-01T00:00:00Z', null, $tokenB);
check($pull['status'] === 200 && count($pull['json']['data']['tasks'] ?? []) >= 1, 'sync pull', $pull);

$dash = call_api('GET', $base . '/dashboard', null, $tokenA);
check($dash['status'] === 200 && (int) ($dash['json']['data']['leader']['completed_tasks'] ?? 0) >= 1, 'leader dashboard uses database counts', $dash);

$csv = call_api('GET', $base . "/teams/{$teamId}/report.csv", null, $tokenA);
check($csv['status'] === 200 && str_contains((string) $csv['raw'], 'Draft the research brief'), 'csv report', $csv);

$announce = call_api('POST', $base . "/teams/{$teamId}/announcements", [
    'title' => 'Studio check-in',
    'body' => 'We meet Thursday at 4.',
    'is_important' => true,
], $tokenA);
check($announce['status'] === 201, 'announcement', $announce);

$search = call_api('GET', $base . '/search?q=research', null, $tokenB);
check($search['status'] === 200 && count($search['json']['data']['tasks'] ?? []) >= 1, 'search tasks', $search);

$from = gmdate('Y-m-d\T00:00:00\Z', time() - 86400);
$to = gmdate('Y-m-d\T23:59:59\Z', time() + 3 * 86400);
$calendar = call_api('GET', $base . '/calendar?from=' . urlencode($from) . '&to=' . urlencode($to) . '&scope=mine', null, $tokenB);
check($calendar['status'] === 200, 'calendar', $calendar);

$device = call_api('POST', $base . '/devices', ['token' => 'smoke-device-token-' . $stamp, 'platform' => 'android'], $tokenB);
check($device['status'] === 200, 'register device token', $device);

$google = call_api('POST', $base . '/auth/google', ['id_token' => 'not-a-real-token']);
check($google['status'] === 503, 'google sign-in reports missing configuration', $google);

$forgot = call_api('POST', $base . '/auth/forgot-password', ['email' => "leader{$stamp}@orbit.test"]);
check($forgot['status'] === 200, 'forgot password', $forgot);
$log = file_get_contents(dirname(__DIR__) . '/storage/logs/mail.log') ?: '';
preg_match_all('/"token":"([a-f0-9]{64})"/', $log, $m);
$resetToken = $m[1] ? (string) end($m[1]) : '';
$reset = call_api('POST', $base . '/auth/reset-password', [
    'token' => $resetToken,
    'password' => 'OrbitPass2',
    'password_confirmation' => 'OrbitPass2',
]);
check($reset['status'] === 200 && $resetToken !== '', 'reset password from mailed token', $reset);
$relogin = call_api('POST', $base . '/auth/login', ['email' => "leader{$stamp}@orbit.test", 'password' => 'OrbitPass2']);
check($relogin['status'] === 200, 'login after reset', $relogin);
$tokenA = $relogin['json']['data']['access_token'] ?? $tokenA;

$refreshed = call_api('POST', $base . '/auth/refresh', ['refresh_token' => $refreshA]);
check($refreshed['status'] === 401, 'refresh tokens revoked after password reset', $refreshed);

$contact = call_api('POST', $base . '/contact', ['name' => 'Nora Vale', 'email' => 'nora@orbit.test', 'message' => 'How do reminders work for our lab?']);
check($contact['status'] === 201, 'contact message', $contact);

$latest = call_api('GET', $base . '/releases/latest');
check($latest['status'] === 200 && $latest['json']['data'] === null, 'no download until a release file exists', $latest);

$recurring = call_api('POST', $base . "/teams/{$teamId}/tasks", [
    'title' => 'Weekly lab note',
    'assignee_ids' => [$userB],
    'recurrence' => 'weekly',
    'start_date' => gmdate('c', time() - 8 * 86400),
    'requires_verification' => false,
], $tokenA);
check($recurring['status'] === 201 && !empty($recurring['json']['data']['series_id']), 'recurring task instance', $recurring);

$adminDenied = call_api('GET', $base . '/admin/stats', null, $tokenA);
check($adminDenied['status'] === 403, 'member cannot open platform admin', $adminDenied);

fwrite(STDOUT, $failures === 0 ? "\nAll smoke checks passed.\n" : "\n{$failures} smoke check(s) failed.\n");
exit($failures === 0 ? 0 : 1);
