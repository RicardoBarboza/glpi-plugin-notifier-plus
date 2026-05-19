<?php

// GET — returns paginated grouped notifications for the full notifications page.
// Groups events by itemtype+items_id BEFORE paginating, so each "row" is one ticket.

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

header('Content-Type: application/json');

Session::checkLoginUser();

$users_id  = (int)Session::getLoginUserID();
$tab       = $_GET['tab']      ?? 'all';
$status    = $_GET['type']     ?? ''; // 'type' param now filters by ticket_status
$period    = $_GET['period']   ?? '';
$show_read = (int)($_GET['show_read'] ?? 0);
$page      = max(1, (int)($_GET['page']     ?? 1));
$per_page  = in_array((int)($_GET['per_page'] ?? 10), [10, 25, 50]) ? (int)$_GET['per_page'] : 10;

$all_items = $show_read
    ? GlpiPlugin\Notifier\Notification::getForUserIncludingRead($users_id, 500)
    : GlpiPlugin\Notifier\Notification::getForUser($users_id, 500);

$can_see_others = GlpiPlugin\Notifier\Notification::canSeeOthers($users_id);
$prefs          = GlpiPlugin\Notifier\Notification::getPreferences($users_id);
$update_style   = $prefs['update_message_style'] ?? 'priority';

// Badges: count unique tickets per tab (unread only, from ALL items)
$byTab   = ['all' => 0, 'new' => 0, 'mine' => 0, 'team' => 0, 'other' => 0, 'resolved' => 0, 'closed' => 0];
$seenAll = [];
$seenTab = [];
foreach ($all_items as $item) {
    if ($item['is_read']) continue;
    $key = $item['itemtype'] . ':' . $item['items_id'];
    if (!isset($seenAll[$key])) { $seenAll[$key] = true; $byTab['all']++; }
    $cat = $item['category'] ?? '';
    if (isset($byTab[$cat]) && !isset($seenTab[$cat][$key])) {
        $seenTab[$cat][$key] = true;
        $byTab[$cat]++;
    }
}

// Filter by tab, type, period
$valid_tabs = ['all', 'new', 'mine', 'team', 'other', 'resolved', 'closed'];
if (!in_array($tab, $valid_tabs, true)) $tab = 'all';

$cutoff = 0;
if ($period === 'today') $cutoff = strtotime('today');
elseif ($period === 'week')  $cutoff = time() - 7 * 86400;
elseif ($period === 'month') $cutoff = time() - 30 * 86400;

$filtered = array_values(array_filter($all_items, function($item) use ($tab, $show_read, $status, $period, $cutoff) {
    if (!$show_read && $item['is_read']) return false;
    if ($tab !== 'all' && ($item['category'] ?? '') !== $tab) return false;
    if ($status !== '' && (string)($item['ticket_status'] ?? '') !== $status) return false;
    if ($period !== '' && $cutoff > 0 && strtotime($item['date_creation']) < $cutoff) return false;
    return true;
}));

// Group by itemtype:items_id BEFORE paginating
$groups     = [];
$groupOrder = [];
foreach ($filtered as $item) {
    $key = $item['itemtype'] . ':' . $item['items_id'];
    if (!isset($groups[$key])) { $groups[$key] = []; $groupOrder[] = $key; }
    $groups[$key][] = $item;
}

$total_groups = count($groupOrder);
$total_pages  = max(1, (int)ceil($total_groups / $per_page));
$page         = min($page, $total_pages);
$paged_keys   = array_slice($groupOrder, ($page - 1) * $per_page, $per_page);

global $DB;

function getEventContent(array $item): string {
    global $DB;
    $type = $item['itemtype'] ?? ''; $id = (int)($item['items_id'] ?? 0); $event = $item['event'] ?? '';
    if ($id <= 0 || $type === '') return '';
    if ($event === 'commented') {
        $rs = $DB->request(['SELECT' => ['content'], 'FROM' => 'glpi_itilfollowups',
            'WHERE' => ['itemtype' => $type, 'items_id' => $id, 'is_private' => 0], 'ORDER' => ['date DESC'], 'LIMIT' => 1]);
        if ($row = $rs->current()) return mb_substr(strip_tags(html_entity_decode($row['content'] ?? '')), 0, 120);
    }
    if (in_array($event, ['solution', 'solution_refused'], true)) {
        $rs = $DB->request(['SELECT' => ['content'], 'FROM' => 'glpi_itilsolutions',
            'WHERE' => ['itemtype' => $type, 'items_id' => $id], 'ORDER' => ['id DESC'], 'LIMIT' => 1]);
        if ($row = $rs->current()) return mb_substr(strip_tags(html_entity_decode($row['content'] ?? '')), 0, 120);
    }
    if ($event === 'task_added') {
        $tm = ['Ticket' => 'glpi_tickettasks', 'Change' => 'glpi_changetasks', 'Problem' => 'glpi_problemtasks'];
        $fk = ['Ticket' => 'tickets_id', 'Change' => 'changes_id', 'Problem' => 'problems_id'];
        if (isset($tm[$type])) {
            $rs = $DB->request(['SELECT' => ['content'], 'FROM' => $tm[$type], 'WHERE' => [$fk[$type] => $id], 'ORDER' => ['id DESC'], 'LIMIT' => 1]);
            if ($row = $rs->current()) return mb_substr(strip_tags(html_entity_decode($row['content'] ?? '')), 0, 120);
        }
    }
    $tbl = ['Ticket' => 'glpi_tickets', 'Change' => 'glpi_changes', 'Problem' => 'glpi_problems'];
    if (isset($tbl[$type])) {
        $rs = $DB->request(['SELECT' => ['content'], 'FROM' => $tbl[$type], 'WHERE' => ['id' => $id], 'LIMIT' => 1]);
        if ($row = $rs->current()) return mb_substr(strip_tags(html_entity_decode($row['content'] ?? '')), 0, 120);
    }
    return '';
}

// Cache de metadados do ticket para não repetir queries
$ticketMeta = [];
function getTicketMeta(string $type, int $id): array {
    global $DB, $ticketMeta;
    $key = $type . ':' . $id;
    if (isset($ticketMeta[$key])) return $ticketMeta[$key];
    $meta = ['priority' => 0, 'entity' => '', 'requester' => ''];
    $tbl = ['Ticket' => 'glpi_tickets', 'Change' => 'glpi_changes', 'Problem' => 'glpi_problems'];
    if (!isset($tbl[$type])) return $ticketMeta[$key] = $meta;
    $rs = $DB->request(['SELECT' => ['priority', 'entities_id'], 'FROM' => $tbl[$type], 'WHERE' => ['id' => $id], 'LIMIT' => 1]);
    if ($row = $rs->current()) {
        $meta['priority'] = (int)($row['priority'] ?? 0);
        $entRs = $DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => (int)$row['entities_id']], 'LIMIT' => 1]);
        if ($entRow = $entRs->current()) $meta['entity'] = $entRow['name'] ?? '';
    }
    $lm = ['Ticket' => ['t' => 'glpi_tickets_users', 'fk' => 'tickets_id'],
           'Change' => ['t' => 'glpi_changes_users',  'fk' => 'changes_id'],
           'Problem'=> ['t' => 'glpi_problems_users', 'fk' => 'problems_id']];
    $l = $lm[$type];
    $rq = $DB->request(['SELECT' => ['u.firstname', 'u.realname', 'u.name'],
        'FROM' => $l['t'] . ' AS tu', 'INNER JOIN' => ['glpi_users AS u' => ['ON' => ['u' => 'id', 'tu' => 'users_id']]],
        'WHERE' => [$l['fk'] => $id, 'tu.type' => 1], 'LIMIT' => 1]);
    if ($rqRow = $rq->current()) {
        $full = trim(($rqRow['firstname'] ?? '') . ' ' . ($rqRow['realname'] ?? ''));
        $meta['requester'] = $full ?: ($rqRow['name'] ?? '');
    }
    return $ticketMeta[$key] = $meta;
}

function enrichItem(array $item): array {
    $meta = getTicketMeta($item['itemtype'] ?? '', (int)($item['items_id'] ?? 0));
    $item['content']   = getEventContent($item);
    $item['priority']  = $meta['priority'];
    $item['entity']    = $meta['entity'];
    $item['requester'] = $meta['requester'];
    return $item;
}

// Build response — enrich items on this page only
$response_items = [];
foreach ($paged_keys as $key) {
    $events = $groups[$key];
    $count  = count($events);
    $primary = enrichItem($events[0]);
    $primary['update_count'] = $count;

    // For combined mode, include sub_events
    if ($update_style === 'combined' && $count > 1) {
        $subs = [];
        for ($i = 1; $i < $count; $i++) {
            $subs[] = enrichItem($events[$i]);
        }
        $primary['sub_events'] = $subs;
    }

    // For multiple mode, add all events as separate items
    if ($update_style === 'multiple' && $count > 1) {
        foreach ($events as $ev) {
            $response_items[] = enrichItem($ev);
        }
        continue;
    }

    $response_items[] = $primary;
}

echo json_encode([
    'items'                => $response_items,
    'total'                => $update_style === 'multiple' ? count($filtered) : $total_groups,
    'page'                 => $page,
    'per_page'             => $per_page,
    'total_pages'          => $update_style === 'multiple' ? max(1, (int)ceil(count($filtered) / $per_page)) : $total_pages,
    'unread_by_tab'        => $byTab,
    'can_see_others'       => $can_see_others,
    'show_resolved'        => (bool)($prefs['show_resolved'] ?? 1),
    'show_closed'          => (bool)($prefs['show_closed'] ?? 1),
    'update_message_style' => $update_style,
]);
