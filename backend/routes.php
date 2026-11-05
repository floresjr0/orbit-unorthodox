<?php

declare(strict_types=1);

use Orbit\AdminController;
use Orbit\AuthController;
use Orbit\PublicController;
use Orbit\Router;
use Orbit\TaskController;
use Orbit\TeamController;
use Orbit\UserController;
use Orbit\WorkspaceController;

return static function (Router $router): void {
    $auth = ['auth'];
    $admin = ['auth', 'admin'];

    $router->add('GET', '/v1/health', PublicController::class, 'health');
    $router->add('GET', '/v1/auth/providers', AuthController::class, 'providers');
    $router->add('POST', '/v1/auth/register', AuthController::class, 'register');
    $router->add('POST', '/v1/auth/login', AuthController::class, 'login');
    $router->add('POST', '/v1/auth/google', AuthController::class, 'google');
    $router->add('POST', '/v1/auth/refresh', AuthController::class, 'refresh');
    $router->add('POST', '/v1/auth/forgot-password', AuthController::class, 'forgot');
    $router->add('POST', '/v1/auth/reset-password', AuthController::class, 'reset');
    $router->add('POST', '/v1/contact', PublicController::class, 'contact');
    $router->add('GET', '/v1/releases/latest', PublicController::class, 'latestRelease');
    $router->add('GET', '/v1/releases/{id}/download', PublicController::class, 'downloadRelease');

    $router->add('POST', '/v1/auth/logout', AuthController::class, 'logout', $auth);
    $router->add('POST', '/v1/auth/logout-all', AuthController::class, 'logoutAll', $auth);
    $router->add('GET', '/v1/auth/me', AuthController::class, 'me', $auth);
    $router->add('GET', '/v1/auth/sessions', AuthController::class, 'sessions', $auth);
    $router->add('DELETE', '/v1/auth/sessions/{id}', AuthController::class, 'revokeSession', $auth);

    $router->add('PATCH', '/v1/users/me', UserController::class, 'update', $auth);
    $router->add('POST', '/v1/users/me/avatar', UserController::class, 'avatar', $auth);
    $router->add('POST', '/v1/users/me/password', UserController::class, 'password', $auth);
    $router->add('GET', '/v1/users/me/preferences', UserController::class, 'preferences', $auth);
    $router->add('PUT', '/v1/users/me/preferences', UserController::class, 'updatePreferences', $auth);
    $router->add('DELETE', '/v1/users/me', UserController::class, 'delete', $auth);
    $router->add('POST', '/v1/users/{id}/report', UserController::class, 'report', $auth);
    $router->add('POST', '/v1/devices', UserController::class, 'registerDevice', $auth);
    $router->add('DELETE', '/v1/devices', UserController::class, 'removeDevice', $auth);

    $router->add('GET', '/v1/dashboard', WorkspaceController::class, 'dashboard', $auth);
    $router->add('GET', '/v1/search', WorkspaceController::class, 'search', $auth);
    $router->add('GET', '/v1/calendar', TaskController::class, 'calendar', $auth);
    $router->add('GET', '/v1/notifications', WorkspaceController::class, 'notifications', $auth);
    $router->add('POST', '/v1/notifications/read', WorkspaceController::class, 'readAll', $auth);
    $router->add('POST', '/v1/notifications/{id}/read', WorkspaceController::class, 'readOne', $auth);
    $router->add('POST', '/v1/sync/push', WorkspaceController::class, 'push', $auth);
    $router->add('GET', '/v1/sync/pull', WorkspaceController::class, 'pull', $auth);
    $router->add('GET', '/v1/media/avatar/{id}', WorkspaceController::class, 'avatar', $auth);
    $router->add('GET', '/v1/media/team/{id}', WorkspaceController::class, 'teamAvatar', $auth);

    $router->add('GET', '/v1/teams', TeamController::class, 'index', $auth);
    $router->add('POST', '/v1/teams', TeamController::class, 'create', $auth);
    $router->add('GET', '/v1/teams/{id}', TeamController::class, 'show', $auth);
    $router->add('PATCH', '/v1/teams/{id}', TeamController::class, 'update', $auth);
    $router->add('POST', '/v1/teams/{id}/avatar', TeamController::class, 'avatar', $auth);
    $router->add('POST', '/v1/teams/{id}/archive', TeamController::class, 'archive', $auth);
    $router->add('DELETE', '/v1/teams/{id}', TeamController::class, 'delete', $auth);
    $router->add('POST', '/v1/teams/{id}/transfer', TeamController::class, 'transfer', $auth);
    $router->add('POST', '/v1/teams/{id}/leave', TeamController::class, 'leave', $auth);
    $router->add('GET', '/v1/teams/{id}/members', TeamController::class, 'members', $auth);
    $router->add('DELETE', '/v1/teams/{id}/members/{userId}', TeamController::class, 'removeMember', $auth);
    $router->add('POST', '/v1/teams/{id}/members/{userId}/approve', TeamController::class, 'approve', $auth);
    $router->add('POST', '/v1/teams/{id}/members/{userId}/reject', TeamController::class, 'reject', $auth);
    $router->add('GET', '/v1/teams/{id}/invitation', TeamController::class, 'invitation', $auth);
    $router->add('PUT', '/v1/teams/{id}/invitation', TeamController::class, 'updateInvitation', $auth);
    $router->add('POST', '/v1/teams/{id}/invitation/regenerate', TeamController::class, 'regenerate', $auth);
    $router->add('POST', '/v1/invitations/join', TeamController::class, 'join', $auth);
    $router->add('GET', '/v1/teams/{id}/announcements', TeamController::class, 'announcements', $auth);
    $router->add('POST', '/v1/teams/{id}/announcements', TeamController::class, 'createAnnouncement', $auth);
    $router->add('DELETE', '/v1/announcements/{id}', TeamController::class, 'deleteAnnouncement', $auth);
    $router->add('GET', '/v1/teams/{id}/report', WorkspaceController::class, 'report', $auth);
    $router->add('GET', '/v1/teams/{id}/report.csv', WorkspaceController::class, 'export', $auth);

    $router->add('GET', '/v1/tasks', TaskController::class, 'index', $auth);
    $router->add('POST', '/v1/teams/{id}/tasks', TaskController::class, 'create', $auth);
    $router->add('GET', '/v1/tasks/{id}', TaskController::class, 'show', $auth);
    $router->add('PATCH', '/v1/tasks/{id}', TaskController::class, 'update', $auth);
    $router->add('DELETE', '/v1/tasks/{id}', TaskController::class, 'delete', $auth);
    $router->add('POST', '/v1/tasks/{id}/status', TaskController::class, 'status', $auth);
    $router->add('POST', '/v1/tasks/{id}/review', TaskController::class, 'review', $auth);
    $router->add('GET', '/v1/tasks/{id}/activity', TaskController::class, 'activity', $auth);
    $router->add('GET', '/v1/tasks/{id}/comments', TaskController::class, 'comments', $auth);
    $router->add('POST', '/v1/tasks/{id}/comments', TaskController::class, 'addComment', $auth);
    $router->add('PATCH', '/v1/comments/{id}', TaskController::class, 'updateComment', $auth);
    $router->add('DELETE', '/v1/comments/{id}', TaskController::class, 'deleteComment', $auth);
    $router->add('POST', '/v1/tasks/{id}/checklist', TaskController::class, 'addChecklist', $auth);
    $router->add('PATCH', '/v1/checklist-items/{id}', TaskController::class, 'toggleChecklist', $auth);
    $router->add('DELETE', '/v1/checklist-items/{id}', TaskController::class, 'deleteChecklist', $auth);
    $router->add('POST', '/v1/tasks/{id}/attachments', TaskController::class, 'addAttachment', $auth);
    $router->add('GET', '/v1/attachments/{id}', TaskController::class, 'downloadAttachment', $auth);
    $router->add('DELETE', '/v1/attachments/{id}', TaskController::class, 'deleteAttachment', $auth);

    $router->add('GET', '/v1/admin/stats', AdminController::class, 'stats', $admin);
    $router->add('GET', '/v1/admin/users', AdminController::class, 'users', $admin);
    $router->add('POST', '/v1/admin/users/{id}/suspend', AdminController::class, 'suspend', $admin);
    $router->add('POST', '/v1/admin/users/{id}/reactivate', AdminController::class, 'reactivate', $admin);
    $router->add('GET', '/v1/admin/reports', AdminController::class, 'reports', $admin);
    $router->add('POST', '/v1/admin/reports/{id}/resolve', AdminController::class, 'resolve', $admin);
    $router->add('GET', '/v1/admin/audit', AdminController::class, 'audit', $admin);
    $router->add('GET', '/v1/admin/messages', AdminController::class, 'messages', $admin);
    $router->add('GET', '/v1/admin/releases', AdminController::class, 'releases', $admin);
    $router->add('POST', '/v1/admin/releases', AdminController::class, 'createRelease', $admin);
};
