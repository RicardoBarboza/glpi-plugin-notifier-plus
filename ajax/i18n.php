<?php

// Hydrates the JS-side T dictionary so the bell respects the session
// language. JS keeps English fallbacks if this fails.

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();

echo json_encode([
    'notifications'        => __('Notifications', 'notifier'),
    'markAllRead'          => __('Mark all as read', 'notifier'),
    'markAsRead'           => __('Mark as read', 'notifier'),
    'markAsUnread'         => __('Mark as unread', 'notifier'),
    'noNotifications'      => __('No notifications', 'notifier'),
    'noNotificationsHint'  => __("You're all caught up.", 'notifier'),
    'minimize'             => __('Minimize', 'notifier'),
    'expand'               => __('Expand notifications', 'notifier'),
    'tabAll'               => __('Todos', 'notifier'),
    'tabNew'               => __('Novos', 'notifier'),
    'tabMine'              => __('Meus', 'notifier'),
    'tabTeam'              => __('Equipe', 'notifier'),
    'tabOther'             => __('Demais', 'notifier'),
    'tabResolved'          => __('Resolvidos', 'notifier'),
    'tabClosed'            => __('Fechados', 'notifier'),
    'tabEnded'             => __('Encerrados', 'notifier'),
    'showResolved'         => __('Mostrar chamados resolvidos', 'notifier'),
    'soundPerTab'          => __('Som por fila', 'notifier'),
    'showClosed'           => __('Mostrar chamados fechados', 'notifier'),
    'resolvedClosedStyle'  => __('Exibição de encerrados', 'notifier'),
    'resolvedClosedSeparate' => __('Separados (Resolvidos | Fechados)', 'notifier'),
    'resolvedClosedEnded'  => __('Unificados (Encerrados)', 'notifier'),
    'settings'             => __('Settings', 'notifier'),
    'preferencesTitle'     => __('Notification preferences', 'notifier'),
    'preferencesIntro'     => __('Choose which updates you want to receive. Direct updates are about items assigned to you; group updates are about items assigned to one of your groups.', 'notifier'),
    'colDirect'            => __('Assigned to me', 'notifier'),
    'colGroup'             => __('Assigned to my group', 'notifier'),
    'typeTicket'           => __('Tickets', 'notifier'),
    'typeChange'           => __('Changes', 'notifier'),
    'typeProblem'          => __('Problems', 'notifier'),
    'typeProjectTask'      => __('Project tasks', 'notifier'),
    'save'                 => __('Save', 'notifier'),
    'cancel'               => __('Cancel', 'notifier'),
    'saved'                => __('Preferences saved', 'notifier'),
    'close'                => __('Close', 'notifier'),
    'soundEnabled'         => __('Som de notificação', 'notifier'),
    'notifyOthers'         => __('Notificar movimentações em chamados que tenho acesso', 'notifier'),
    'newFollowup'          => __('New followup', 'notifier'),
    'categoryChanged'      => __('Category changed', 'notifier'),
    'priorityChanged'      => __('Priority changed', 'notifier'),
    'actorsChanged'        => __('Actors changed', 'notifier'),
    'descriptionUpdated'   => __('Description updated', 'notifier'),
    'updateMessageStyle'   => __('Estilo de mensagem de atualização', 'notifier'),
    'updateStylePriority'  => __('Mensagem prioritária', 'notifier'),
    'updateStyleCombined'  => __('Mensagens combinadas', 'notifier'),
    'updateStyleMultiple'  => __('Múltiplas alterações', 'notifier'),
    'groupedUpdates'       => __('{n} updates', 'notifier'),
    'expandGroup'          => __('Show all updates', 'notifier'),
    'collapseGroup'        => __('Hide updates', 'notifier'),
]);
