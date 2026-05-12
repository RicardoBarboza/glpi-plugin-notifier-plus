<?php

// Persists a read row for a reactive unassigned ticket (negative synthetic ID).
// Called when the user clicks a reactive item in the bell dropdown.

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();

$users_id  = (int)Session::getLoginUserID();
$tickets_id = (int)($_GET['tickets_id'] ?? 0);

$ok = GlpiPlugin\Notifier\Notification::markReadReactive($tickets_id, $users_id);

echo json_encode([
    'success' => $ok,
    'unread'  => GlpiPlugin\Notifier\Notification::countUnread($users_id),
]);
