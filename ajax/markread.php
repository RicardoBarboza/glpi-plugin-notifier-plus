<?php

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();

$users_id  = (int)Session::getLoginUserID();
$id        = (int)($_GET['id']        ?? $_POST['id']        ?? 0);
$ticket_id = (int)($_GET['ticket_id'] ?? $_POST['ticket_id'] ?? 0);

if ($ticket_id > 0) {
    // Mark all unread notifications for this ticket (covers 'other' category)
    global $DB;
    $DB->update(
        'glpi_plugin_notifier_notifications',
        ['is_read' => 1, 'date_mod' => date('Y-m-d H:i:s')],
        ['users_id' => $users_id, 'itemtype' => 'Ticket', 'items_id' => $ticket_id, 'is_read' => 0]
    );
    $ok = true;
} else {
    $ok = GlpiPlugin\Notifier\Notification::markRead($id, $users_id);
}

echo json_encode([
    'success' => $ok,
    'unread'  => GlpiPlugin\Notifier\Notification::countUnread($users_id),
]);
