<?php

// GET — returns { unread, unread_groups, unread_by_tab, items } for the session user.

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();

$users_id = (int)Session::getLoginUserID();

$items = GlpiPlugin\Notifier\Notification::getForUser($users_id);
$can_see_others = GlpiPlugin\Notifier\Notification::canSeeOthers($users_id);
$prefs = GlpiPlugin\Notifier\Notification::getPreferences($users_id);

// Count UNIQUE tickets per category (not individual notifications)
// This ensures badge numbers match what the user sees in the list
$byTab = ['new' => 0, 'mine' => 0, 'team' => 0, 'other' => 0, 'resolved' => 0, 'closed' => 0];
$seenPerTab = ['new' => [], 'mine' => [], 'team' => [], 'other' => [], 'resolved' => [], 'closed' => []];
foreach ($items as $item) {
    if (!$item['is_read'] && isset($byTab[$item['category']])) {
        $key = $item['itemtype'] . ':' . $item['items_id'];
        if (!isset($seenPerTab[$item['category']][$key])) {
            $seenPerTab[$item['category']][$key] = true;
            $byTab[$item['category']]++;
        }
    }
}

// Total = unique tickets across all tabs
$unread = array_sum($byTab);

echo json_encode([
    'unread'               => $unread,
    'unread_groups'        => $unread,
    'unread_by_tab'        => $byTab,
    'items'                => $items,
    'can_see_others'       => $can_see_others,
    'show_resolved'        => (bool)($prefs['show_resolved'] ?? 1),
    'show_closed'          => (bool)($prefs['show_closed'] ?? 1),
    'resolved_closed_style'=> $prefs['resolved_closed_style'] ?? 'separate',
    'sound_new'            => (bool)($prefs['sound_new'] ?? 1),
    'sound_mine'           => (bool)($prefs['sound_mine'] ?? 1),
    'sound_team'           => (bool)($prefs['sound_team'] ?? 1),
    'sound_other'          => (bool)($prefs['sound_other'] ?? 1),
    'sound_ended'          => (bool)($prefs['sound_ended'] ?? 0),
]);
