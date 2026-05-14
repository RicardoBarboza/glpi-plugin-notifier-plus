<?php
// Marks all closed ticket notifications as read for the current user.
// Called when the user enables the "Show closed" toggle for the first time.

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');
Session::checkLoginUser();

$users_id = (int)Session::getLoginUserID();

global $DB;
$DB->update(
    'glpi_plugin_notifier_notifications',
    ['is_read' => 1, 'date_mod' => date('Y-m-d H:i:s')],
    [
        'users_id' => $users_id,
        'is_read'  => 0,
        'items_id' => new QuerySubQuery([
            'SELECT' => 'id',
            'FROM'   => 'glpi_tickets',
            'WHERE'  => ['status' => 6],
        ]),
    ]
);

echo json_encode(['success' => true]);
