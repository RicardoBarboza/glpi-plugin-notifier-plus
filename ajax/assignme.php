<?php

// POST — assigns the current user as technician on a ticket.
// Params: items_id, itemtype (default: Ticket)

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();
Session::checkCentralAccess();

$items_id = (int)($_POST['items_id'] ?? 0);
$itemtype = $_POST['itemtype'] ?? 'Ticket';
$users_id = (int)Session::getLoginUserID();

if ($items_id <= 0 || !in_array($itemtype, ['Ticket', 'Change', 'Problem'], true)) {
    echo json_encode(['success' => false, 'message' => __('Parâmetros inválidos', 'notifier')]);
    exit;
}

// Only Ticket supports direct assignment via Ticket_User for now
if ($itemtype !== 'Ticket') {
    echo json_encode(['success' => false, 'message' => __('Tipo não suportado', 'notifier')]);
    exit;
}

$ticket = new Ticket();
if (!$ticket->getFromDB($items_id)) {
    echo json_encode(['success' => false, 'message' => __('Chamado não encontrado', 'notifier')]);
    exit;
}

// Check user already assigned
$ticket_user = new Ticket_User();
$existing = $ticket_user->find([
    'tickets_id' => $items_id,
    'users_id'   => $users_id,
    'type'       => CommonITILActor::ASSIGN,
]);

if (!empty($existing)) {
    echo json_encode(['success' => true, 'message' => __('Você já está atribuído a este chamado', 'notifier')]);
    exit;
}

// Assign user
$result = $ticket_user->add([
    'tickets_id'       => $items_id,
    'users_id'         => $users_id,
    'type'             => CommonITILActor::ASSIGN,
    'use_notification' => 1,
]);

if (!$result) {
    echo json_encode(['success' => false, 'message' => __('Erro ao atribuir chamado', 'notifier')]);
    exit;
}

// If ticket is still "New" (status=1), move to "Assigned" (status=2)
if ((int)$ticket->fields['status'] === Ticket::INCOMING) {
    $ticket->update([
        'id'     => $items_id,
        'status' => Ticket::ASSIGNED,
    ]);
}

echo json_encode(['success' => true, 'message' => __('Chamado assumido com sucesso', 'notifier')]);
