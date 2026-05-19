<?php

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();

$users_id = (int)Session::getLoginUserID();
$tab      = $_POST['tab'] ?? 'all';

$valid_tabs = ['all', 'new', 'mine', 'team', 'other', 'resolved', 'closed'];
if (!in_array($tab, $valid_tabs, true)) $tab = 'all';

$ok = GlpiPlugin\Notifier\Notification::markAllReadByTab($users_id, $tab);

echo json_encode([
    'success' => $ok,
    'unread'  => GlpiPlugin\Notifier\Notification::countUnread($users_id),
]);
