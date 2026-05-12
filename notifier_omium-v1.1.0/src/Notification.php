<?php

namespace GlpiPlugin\Notifier;

use CommonDBTM;
use CommonITILObject;
use CommonITILActor;
use Session;
use Ticket;
use Change;
use Problem;
use ProjectTask;
use ProjectTaskTeam;
use ITILFollowup;
use TicketTask;
use ChangeTask;
use ProblemTask;
use ITILSolution;
use Ticket_User;
use Change_User;
use Problem_User;
use Group_User;
use User;
use Toolbox;
use QueryExpression;

/**
 * Persistent store for in-app bell notifications. setup.php wires this
 * into GLPI's item_add / item_update hooks and the dispatcher fans the
 * event out to every affected user.
 */
class Notification extends CommonDBTM
{
    public static $rightname = '';
    public $dohistory        = false;

    // Slugs double as CSS modifier and i18n key — keep short.
    const EVENT_ASSIGNED       = 'assigned';
    const EVENT_CREATED        = 'created';
    const EVENT_COMMENTED      = 'commented';
    const EVENT_TASK_ADDED     = 'task_added';
    const EVENT_SOLUTION       = 'solution';
    const EVENT_STATUS_CHANGED = 'status_changed';
    const EVENT_UPDATED        = 'updated';

    public static function getTypeName($nb = 0): string
    {
        return _n('Notification', 'Notifications', $nb, 'notifier');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_notifier_notifications';
    }

    // ------------------------------------------------------------------ preferences
    //
    // Per-user opt-out flags in glpi_plugin_notifier_preferences, missing
    // row = all defaults. Preferences are a *view filter*, not a
    // subscription — every event is still stored, the filter applies at
    // read time so flipping a flag back on resurfaces history.

    public static function getDefaultPreferences(): array
    {
        return [
            'notify_ticket_direct'      => 1,
            'notify_ticket_group'       => 1,
            'notify_change_direct'      => 1,
            'notify_change_group'       => 1,
            'notify_problem_direct'     => 1,
            'notify_problem_group'      => 1,
            'notify_projecttask_direct' => 1,
            'notify_projecttask_group'  => 1,
            'sound_enabled'             => 1,
            'notify_others'             => 0,
            'update_message_style'      => 'priority',
        ];
    }

    /** @var array<int, array<string, int>> per-request memo for getPreferences() */
    private static array $prefsCache = [];

    private static bool $schemaEnsured = false;

    // Idempotent runtime safety net for installs that predate the table;
    // re-running plugin install via the UI is the canonical upgrade path.
    private static function ensurePreferencesTable(): bool
    {
        global $DB;

        if ($DB->tableExists('glpi_plugin_notifier_preferences')) {
            return true;
        }

        $charset   = \DBConnection::getDefaultCharset();
        $collation = \DBConnection::getDefaultCollation();

        $query = "CREATE TABLE IF NOT EXISTS `glpi_plugin_notifier_preferences` (
            `users_id`                    INT UNSIGNED NOT NULL,
            `notify_ticket_direct`        TINYINT NOT NULL DEFAULT 1,
            `notify_ticket_group`         TINYINT NOT NULL DEFAULT 1,
            `notify_change_direct`        TINYINT NOT NULL DEFAULT 1,
            `notify_change_group`         TINYINT NOT NULL DEFAULT 1,
            `notify_problem_direct`       TINYINT NOT NULL DEFAULT 1,
            `notify_problem_group`        TINYINT NOT NULL DEFAULT 1,
            `notify_projecttask_direct`   TINYINT NOT NULL DEFAULT 1,
            `notify_projecttask_group`    TINYINT NOT NULL DEFAULT 1,
            `sound_enabled`               TINYINT NOT NULL DEFAULT 1,
            `notify_others`               TINYINT NOT NULL DEFAULT 0,
            `date_mod`                    TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";

        // Add sound_enabled column if table already exists (migration)
        if ($DB->tableExists('glpi_plugin_notifier_preferences')) {
            $cols = $DB->doQuery("SHOW COLUMNS FROM `glpi_plugin_notifier_preferences` LIKE 'sound_enabled'");
            if ($cols && $cols->num_rows === 0) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_notifier_preferences` ADD COLUMN `sound_enabled` TINYINT NOT NULL DEFAULT 1 AFTER `notify_projecttask_group`");
            }
            $cols2 = $DB->doQuery("SHOW COLUMNS FROM `glpi_plugin_notifier_preferences` LIKE 'notify_others'");
            if ($cols2 && $cols2->num_rows === 0) {
                $DB->doQuery("ALTER TABLE `glpi_plugin_notifier_preferences` ADD COLUMN `notify_others` TINYINT NOT NULL DEFAULT 0 AFTER `sound_enabled`");
            }
            return true;
        }

        return (bool)$DB->doQuery($query);
    }

    public static function getPreferences(int $users_id): array
    {
        global $DB;

        if (isset(self::$prefsCache[$users_id])) {
            return self::$prefsCache[$users_id];
        }

        $prefs = self::getDefaultPreferences();
        if ($users_id <= 0 || !$DB->tableExists('glpi_plugin_notifier_preferences')) {
            return self::$prefsCache[$users_id] = $prefs;
        }

        $rs = $DB->request([
            'FROM'  => 'glpi_plugin_notifier_preferences',
            'WHERE' => ['users_id' => $users_id],
            'LIMIT' => 1,
        ]);
        $row = $rs->current();
        if (!$row) {
            return self::$prefsCache[$users_id] = $prefs;
        }
        $stringCols = ['update_message_style'];
        foreach ($prefs as $k => $_default) {
            if (array_key_exists($k, $row)) {
                if (in_array($k, $stringCols, true)) {
                    $prefs[$k] = (string)$row[$k];
                } else {
                    $prefs[$k] = (int)$row[$k] ? 1 : 0;
                }
            }
        }
        return self::$prefsCache[$users_id] = $prefs;
    }

    public static function savePreferences(int $users_id, array $input): bool
    {
        global $DB;

        if ($users_id <= 0) {
            return false;
        }

        if (!self::ensurePreferencesTable()) {
            return false;
        }

        $allowed = array_keys(self::getDefaultPreferences());
        $stringCols = ['update_message_style'];
        $validStyles = ['priority', 'combined', 'multiple'];
        $row = ['users_id' => $users_id];
        foreach ($allowed as $col) {
            if (in_array($col, $stringCols, true)) {
                $val = $input[$col] ?? 'priority';
                $row[$col] = in_array($val, $validStyles, true) ? $val : 'priority';
            } else {
                $row[$col] = isset($input[$col]) && (int)$input[$col] ? 1 : 0;
            }
        }
        $row['date_mod'] = date('Y-m-d H:i:s');

        // Upsert via delete+insert — single-row PK keeps it cheap.
        $DB->delete('glpi_plugin_notifier_preferences', ['users_id' => $users_id]);
        $DB->insert('glpi_plugin_notifier_preferences', $row);

        unset(self::$prefsCache[$users_id]);
        return true;
    }

    /**
     * WHERE fragment that excludes (itemtype, channel) combos the user
     * has opted out of. Returns null when no filter applies.
     */
    private static function prefFilterExpression(int $users_id): ?QueryExpression
    {
        $prefs = self::getPreferences($users_id);

        $typeMap = [
            'ticket'      => 'Ticket',
            'change'      => 'Change',
            'problem'     => 'Problem',
            'projecttask' => 'ProjectTask',
        ];

        // Pre-channel rows carry channel='' and can't be backfilled.
        // Full opt-out hides them too; partial opt-out leaves them
        // visible since we can't tell which channel they belonged to.
        $disabled = [];
        foreach ($typeMap as $slug => $itemtype) {
            $directOff = empty($prefs['notify_' . $slug . '_direct']);
            $groupOff  = empty($prefs['notify_' . $slug . '_group']);

            if ($directOff && $groupOff) {
                $disabled[] = "(`itemtype` = '{$itemtype}')";
            } elseif ($directOff) {
                $disabled[] = "(`itemtype` = '{$itemtype}' AND `channel` = 'direct')";
            } elseif ($groupOff) {
                $disabled[] = "(`itemtype` = '{$itemtype}' AND `channel` = 'group')";
            }
        }

        if (empty($disabled)) {
            return null;
        }

        return new QueryExpression('NOT (' . implode(' OR ', $disabled) . ')');
    }

    // ------------------------------------------------------------------ event dispatch

    public static function handleItemEvent($item): void
    {
        if (!is_object($item) || !isset($item->fields['id'])) {
            return;
        }

        // If the item was just moved to trash (is_deleted = 1), clean up
        // any existing notifications and stop processing. This covers the
        // merge scenario where the secondary ticket goes to the recycle bin.
        if (
            in_array($item::getType(), ['Ticket', 'Change', 'Problem'], true)
            && !empty($item->fields['is_deleted'])
            && in_array('is_deleted', $item->updates ?? [], true)
        ) {
            global $DB;
            $DB->delete('glpi_plugin_notifier_notifications', [
                'itemtype' => $item::getType(),
                'items_id' => (int)$item->fields['id'],
            ]);
            return;
        }

        $type = $item::getType();

        if (in_array($type, ['Ticket', 'Change', 'Problem'], true)) {
            self::handleItilParent($item);
            return;
        }

        if ($type === 'ProjectTask') {
            self::handleProjectTask($item);
            return;
        }

        if ($type === 'ITILFollowup') {
            self::handleFollowup($item);
            return;
        }

        if (in_array($type, ['TicketTask', 'ChangeTask', 'ProblemTask'], true)) {
            self::handleItilTask($item);
            return;
        }

        if ($type === 'ITILSolution') {
            self::handleSolution($item);
            return;
        }

        $itilUserMap = [
            'Ticket_User'  => ['parent' => 'Ticket',  'fk' => 'tickets_id'],
            'Change_User'  => ['parent' => 'Change',  'fk' => 'changes_id'],
            'Problem_User' => ['parent' => 'Problem', 'fk' => 'problems_id'],
        ];
        if (isset($itilUserMap[$type])) {
            self::handleItilUserLink($item, $itilUserMap[$type]['parent'], $itilUserMap[$type]['fk']);
            return;
        }

        $itilGroupMap = [
            'Group_Ticket'  => ['parent' => 'Ticket',  'fk' => 'tickets_id'],
            'Change_Group'  => ['parent' => 'Change',  'fk' => 'changes_id'],
            'Group_Problem' => ['parent' => 'Problem', 'fk' => 'problems_id'],
        ];
        if (isset($itilGroupMap[$type])) {
            self::handleItilGroupLink($item, $itilGroupMap[$type]['parent'], $itilGroupMap[$type]['fk']);
            return;
        }

        if ($type === 'ProjectTaskTeam') {
            self::handleProjectTaskTeamLink($item);
            return;
        }
    }

    /**
     * Returns the ID of the user who performed the current action.
     * Prefers users_id_lastupdater (works even without a session, e.g. mailgate)
     * and falls back to the active session user.
     */
    private static function actionAuthor(CommonDBTM $item): int
    {
        $lastUpdater = (int)($item->fields['users_id_lastupdater'] ?? 0);
        if ($lastUpdater > 0) {
            return $lastUpdater;
        }
        return (int)Session::getLoginUserID();
    }

    private static function handleItilParent(CommonDBTM $item): void
    {
        $type = $item::getType();
        $id   = (int)$item->fields['id'];
        // CommonDBTM leaves $item->updates empty on item_add.
        $isCreate = empty($item->updates ?? []);

        $updates = $item->updates ?? [];
        $watchedFields = ['status', 'content', 'name', 'priority', 'urgency', 'itilcategories_id', 'users_id_lastupdater', '_itil_assign', '_actors'];
        $relevant = array_intersect($updates, $watchedFields);

        $targets = self::collectActorsForItil($item);
        unset($targets[self::actionAuthor($item)]);

        $title   = self::formatItemTitle($item);
        $baseUrl = $item::getFormURLWithID($id, false);

        if ($isCreate) {
            // If no assignee (type=ASSIGN) is linked yet, broadcast to all
            // active technicians so the new ticket doesn't go unnoticed.
            // We check for assignees specifically, not just any actor
            // (requesters/observers are always present but not assignees).
            if (!self::hasAssignee($item)) {
                $targets = self::collectAllTechnicians();
                unset($targets[self::actionAuthor($item)]);
                $message = __('New unassigned ticket', 'notifier');
            } else {
                $message = __('New item concerning you', 'notifier');
            }

            foreach ($targets as $uid => $channel) {
                self::insert([
                    'users_id' => $uid,
                    'itemtype' => $type,
                    'items_id' => $id,
                    'event'    => self::EVENT_CREATED,
                    'channel'  => $channel,
                    'title'    => $title,
                    'message'  => $message,
                    'url'      => $baseUrl,
                ]);
            }
            return;
        }

        if (empty($targets)) {
            return;
        }

        if (in_array('status', $relevant, true)) {
            $event   = self::EVENT_STATUS_CHANGED;
            $message = __('Status changed', 'notifier');
        } elseif (!empty($relevant)) {
            $event   = self::EVENT_UPDATED;
            $message = self::buildUpdateMessage($relevant, $targets);
        } else {
            return;
        }

        foreach ($targets as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => $type,
                'items_id' => $id,
                'event'    => $event,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => $message,
                'url'      => $baseUrl,
            ]);
        }

        // Notify other technicians who have notify_others = 1 and are not actors
        $others = self::collectOtherTechnicians($targets, $type);
        unset($others[self::actionAuthor($item)]);
        foreach ($others as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => $type,
                'items_id' => $id,
                'event'    => $event,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => $message,
                'url'      => $baseUrl,
            ]);
        }
    }

    private static function handleProjectTask(CommonDBTM $item): void
    {
        $id       = (int)$item->fields['id'];
        $isCreate = empty($item->updates);

        // ProjectTask uses its own team junction, not the ITIL actor pattern.
        $targets = self::collectProjectTaskMembers($id);
        unset($targets[self::actionAuthor($item)]);

        if (empty($targets)) {
            return;
        }

        $title   = self::formatItemTitle($item);
        $baseUrl = ProjectTask::getFormURLWithID($id, false);

        if ($isCreate) {
            $event   = self::EVENT_CREATED;
            $message = __('New project task concerning you', 'notifier');
        } else {
            $updates = $item->updates ?? [];
            if (empty($updates)) {
                return;
            }
            if (in_array('projectstates_id', $updates, true) || in_array('percent_done', $updates, true)) {
                $event   = self::EVENT_STATUS_CHANGED;
                $message = __('Status changed', 'notifier');
            } else {
                $event   = self::EVENT_UPDATED;
                $message = __('Project task updated', 'notifier');
            }
        }

        foreach ($targets as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => 'ProjectTask',
                'items_id' => $id,
                'event'    => $event,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => $message,
                'url'      => $baseUrl,
            ]);
        }
    }

    private static function handleFollowup(CommonDBTM $item): void
    {
        $parentType = $item->fields['itemtype'] ?? '';
        $parentId   = (int)($item->fields['items_id'] ?? 0);
        if ($parentType === '' || $parentId === 0) {
            return;
        }
        if (!class_exists($parentType)) {
            return;
        }
        $parent = new $parentType();
        if (!$parent->getFromDB($parentId)) {
            return;
        }

        $targets = self::collectActorsForItil($parent);
        unset($targets[self::actionAuthor($item)]);
        if (empty($targets)) {
            return;
        }

        $title   = self::formatItemTitle($parent);
        $baseUrl = $parentType::getFormURLWithID($parentId, false);

        foreach ($targets as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => $parentType,
                'items_id' => $parentId,
                'event'    => self::EVENT_COMMENTED,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => __('New followup', 'notifier'),
                'url'      => $baseUrl,
            ]);
        }

        $others = self::collectOtherTechnicians($targets, $parentType);
        unset($others[self::actionAuthor($item)]);
        foreach ($others as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => $parentType,
                'items_id' => $parentId,
                'event'    => self::EVENT_COMMENTED,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => __('New followup', 'notifier'),
                'url'      => $baseUrl,
            ]);
        }
    }

    private static function handleItilTask(CommonDBTM $item): void
    {
        $type = $item::getType();
        $map  = [
            'TicketTask'  => ['parent' => 'Ticket',  'fk' => 'tickets_id'],
            'ChangeTask'  => ['parent' => 'Change',  'fk' => 'changes_id'],
            'ProblemTask' => ['parent' => 'Problem', 'fk' => 'problems_id'],
        ];
        if (!isset($map[$type])) {
            return;
        }

        $parentType = $map[$type]['parent'];
        $parentId   = (int)($item->fields[$map[$type]['fk']] ?? 0);
        if ($parentId === 0) {
            return;
        }

        $parent = new $parentType();
        if (!$parent->getFromDB($parentId)) {
            return;
        }

        $targets = self::collectActorsForItil($parent);

        // A named tech is always notified, marked 'direct' so a group-only
        // opt-out can't silence them.
        if (!empty($item->fields['users_id_tech'])) {
            $targets[(int)$item->fields['users_id_tech']] = 'direct';
        }

        unset($targets[self::actionAuthor($item)]);
        if (empty($targets)) {
            return;
        }

        $title   = self::formatItemTitle($parent);
        $baseUrl = $parentType::getFormURLWithID($parentId, false);

        foreach ($targets as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => $parentType,
                'items_id' => $parentId,
                'event'    => self::EVENT_TASK_ADDED,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => __('New task', 'notifier'),
                'url'      => $baseUrl,
            ]);
        }

        $others = self::collectOtherTechnicians($targets, $parentType);
        unset($others[self::actionAuthor($item)]);
        foreach ($others as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => $parentType,
                'items_id' => $parentId,
                'event'    => self::EVENT_TASK_ADDED,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => __('New task', 'notifier'),
                'url'      => $baseUrl,
            ]);
        }
    }

    private static function handleSolution(CommonDBTM $item): void
    {
        $parentType = $item->fields['itemtype'] ?? '';
        $parentId   = (int)($item->fields['items_id'] ?? 0);
        if ($parentType === '' || $parentId === 0 || !class_exists($parentType)) {
            return;
        }

        $parent = new $parentType();
        if (!$parent->getFromDB($parentId)) {
            return;
        }

        $targets = self::collectActorsForItil($parent);
        unset($targets[self::actionAuthor($item)]);
        if (empty($targets)) {
            return;
        }

        $title   = self::formatItemTitle($parent);
        $baseUrl = $parentType::getFormURLWithID($parentId, false);

        foreach ($targets as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => $parentType,
                'items_id' => $parentId,
                'event'    => self::EVENT_SOLUTION,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => __('Solution proposed', 'notifier'),
                'url'      => $baseUrl,
            ]);
        }

        // Notify other technicians who have notify_others = 1
        $others = self::collectOtherTechnicians($targets, $parentType);
        unset($others[self::actionAuthor($item)]);
        foreach ($others as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => $parentType,
                'items_id' => $parentId,
                'event'    => self::EVENT_SOLUTION,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => __('Solution proposed', 'notifier'),
                'url'      => $baseUrl,
            ]);
        }
    }

    // Only ASSIGN (CommonITILActor::ASSIGN = 2) gets a bell — requesters
    // and observers are noise during ticket creation. Hard-coded to avoid
    // a use-statement dependency in hook context.
    private static function handleItilUserLink(CommonDBTM $item, string $parentType, string $fk): void
    {
        $linkType = (int)($item->fields['type'] ?? 0);
        if ($linkType !== 2) {
            return;
        }

        $targetUser = (int)($item->fields['users_id'] ?? 0);
        $parentId   = (int)($item->fields[$fk] ?? 0);
        if ($targetUser <= 0 || $parentId <= 0) {
            return;
        }

        if ($targetUser === self::actionAuthor($item)) {
            return;
        }

        $parent = new $parentType();
        if (!$parent->getFromDB($parentId)) {
            return;
        }

        self::insert([
            'users_id' => $targetUser,
            'itemtype' => $parentType,
            'items_id' => $parentId,
            'event'    => self::EVENT_ASSIGNED,
            'channel'  => 'direct',
            'title'    => self::formatItemTitle($parent),
            'message'  => __('You have been assigned', 'notifier'),
            'url'      => $parentType::getFormURLWithID($parentId, false),
        ]);
    }

    private static function handleItilGroupLink(CommonDBTM $item, string $parentType, string $fk): void
    {
        global $DB;

        $linkType = (int)($item->fields['type'] ?? 0);
        if ($linkType !== 2) {
            return;
        }

        $groupId  = (int)($item->fields['groups_id'] ?? 0);
        $parentId = (int)($item->fields[$fk] ?? 0);
        if ($groupId <= 0 || $parentId <= 0) {
            return;
        }

        $parent = new $parentType();
        if (!$parent->getFromDB($parentId)) {
            return;
        }

        $rs = $DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => 'glpi_groups_users',
            'WHERE'  => ['groups_id' => $groupId],
        ]);

        $title   = self::formatItemTitle($parent);
        $baseUrl = $parentType::getFormURLWithID($parentId, false);
        $actor   = self::actionAuthor($item);

        foreach ($rs as $row) {
            $uid = (int)$row['users_id'];
            if ($uid <= 0 || $uid === $actor) {
                continue;
            }
            self::insert([
                'users_id' => $uid,
                'itemtype' => $parentType,
                'items_id' => $parentId,
                'event'    => self::EVENT_ASSIGNED,
                'channel'  => 'group',
                'title'    => $title,
                'message'  => __('Your group has been assigned', 'notifier'),
                'url'      => $baseUrl,
            ]);
        }
    }

    private static function handleProjectTaskTeamLink(CommonDBTM $item): void
    {
        global $DB;

        $taskId = (int)($item->fields['projecttasks_id'] ?? 0);
        $memberType = (string)($item->fields['itemtype'] ?? '');
        $memberId   = (int)($item->fields['items_id'] ?? 0);
        if ($taskId <= 0 || $memberId <= 0) {
            return;
        }

        $task = new ProjectTask();
        if (!$task->getFromDB($taskId)) {
            return;
        }

        $actor = self::actionAuthor($item);
        $targets = [];

        if ($memberType === 'User') {
            if ($memberId !== $actor) {
                $targets[$memberId] = 'direct';
            }
        } elseif ($memberType === 'Group') {
            $rs = $DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => 'glpi_groups_users',
                'WHERE'  => ['groups_id' => $memberId],
            ]);
            foreach ($rs as $row) {
                $uid = (int)$row['users_id'];
                if ($uid > 0 && $uid !== $actor) {
                    $targets[$uid] = 'group';
                }
            }
        }

        if (empty($targets)) {
            return;
        }

        $title   = self::formatItemTitle($task);
        $baseUrl = ProjectTask::getFormURLWithID($taskId, false);

        foreach ($targets as $uid => $channel) {
            self::insert([
                'users_id' => $uid,
                'itemtype' => 'ProjectTask',
                'items_id' => $taskId,
                'event'    => self::EVENT_ASSIGNED,
                'channel'  => $channel,
                'title'    => $title,
                'message'  => __('You have been added to a project task', 'notifier'),
                'url'      => $baseUrl,
            ]);
        }
    }

    // ------------------------------------------------------------------ actor collection

    /**
     * Returns [user_id => channel] where channel is 'direct' (personal
     * actor) or 'group' (via group link). Direct beats group when both
     * apply, so a group-only opt-out can't silence a personal actor.
     */
    private static function collectActorsForItil(CommonDBTM $item): array
    {
        global $DB;

        $type = $item::getType();
        $id   = (int)$item->fields['id'];

        $linkMap = [
            'Ticket'  => ['users' => 'glpi_tickets_users',  'groups' => 'glpi_groups_tickets',  'fk' => 'tickets_id'],
            'Change'  => ['users' => 'glpi_changes_users',  'groups' => 'glpi_changes_groups',  'fk' => 'changes_id'],
            'Problem' => ['users' => 'glpi_problems_users', 'groups' => 'glpi_groups_problems', 'fk' => 'problems_id'],
        ];
        if (!isset($linkMap[$type])) {
            return [];
        }

        $users  = [];
        $groups = [];

        $rs = $DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => $linkMap[$type]['users'],
            'WHERE'  => [$linkMap[$type]['fk'] => $id],
        ]);
        foreach ($rs as $row) {
            $uid = (int)$row['users_id'];
            if ($uid > 0) {
                $users[$uid] = 'direct';
            }
        }

        $rs = $DB->request([
            'SELECT' => ['groups_id'],
            'FROM'   => $linkMap[$type]['groups'],
            'WHERE'  => [$linkMap[$type]['fk'] => $id],
        ]);
        foreach ($rs as $row) {
            $gid = (int)$row['groups_id'];
            if ($gid > 0) {
                $groups[$gid] = $gid;
            }
        }

        if (!empty($groups)) {
            $rs = $DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => 'glpi_groups_users',
                'WHERE'  => ['groups_id' => array_values($groups)],
            ]);
            foreach ($rs as $row) {
                $uid = (int)$row['users_id'];
                if ($uid > 0 && !isset($users[$uid])) {
                    $users[$uid] = 'group';
                }
            }
        }

        return $users;
    }

    private static function collectProjectTaskMembers(int $taskId): array
    {
        global $DB;

        $users  = [];
        $groups = [];

        $rs = $DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM'   => 'glpi_projecttaskteams',
            'WHERE'  => ['projecttasks_id' => $taskId],
        ]);
        foreach ($rs as $row) {
            if ($row['itemtype'] === 'User') {
                $uid = (int)$row['items_id'];
                if ($uid > 0) {
                    $users[$uid] = 'direct';
                }
            } elseif ($row['itemtype'] === 'Group') {
                $gid = (int)$row['items_id'];
                if ($gid > 0) {
                    $groups[$gid] = $gid;
                }
            }
        }

        if (!empty($groups)) {
            $rs = $DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => 'glpi_groups_users',
                'WHERE'  => ['groups_id' => array_values($groups)],
            ]);
            foreach ($rs as $row) {
                $uid = (int)$row['users_id'];
                if ($uid > 0 && !isset($users[$uid])) {
                    $users[$uid] = 'group';
                }
            }
        }

        return $users;
    }

    /**
     * Returns true if the ITIL item already has at least one assignee
     * (user or group with type = ASSIGN = 2).
     */
    private static function hasAssignee(CommonDBTM $item): bool
    {
        global $DB;

        $type = $item::getType();
        $id   = (int)$item->fields['id'];

        $linkMap = [
            'Ticket'  => ['users' => 'glpi_tickets_users',  'groups' => 'glpi_groups_tickets',  'fk' => 'tickets_id'],
            'Change'  => ['users' => 'glpi_changes_users',  'groups' => 'glpi_changes_groups',  'fk' => 'changes_id'],
            'Problem' => ['users' => 'glpi_problems_users', 'groups' => 'glpi_groups_problems', 'fk' => 'problems_id'],
        ];

        if (!isset($linkMap[$type])) {
            return false;
        }

        // type = 2 means ASSIGN in GLPI
        $rs = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => $linkMap[$type]['users'],
            'WHERE' => [$linkMap[$type]['fk'] => $id, 'type' => 2],
        ]);
        $row = $rs->current();
        if ((int)($row['cpt'] ?? 0) > 0) {
            return true;
        }

        $rs = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => $linkMap[$type]['groups'],
            'WHERE' => [$linkMap[$type]['fk'] => $id, 'type' => 2],
        ]);
        $row = $rs->current();
        return (int)($row['cpt'] ?? 0) > 0;
    }

    /**
     * Returns [user_id => 'broadcast'] for every active GLPI user that
     * has at least one profile with the technician interface (is_default_profile
     * or any profile linked via glpi_profiles_users). Used to fan out
     * notifications for new unassigned tickets.
     */
    private static function collectAllTechnicians(): array
    {
        global $DB;

        $users = [];

        // Fetch all active users who have at least one profile that grants
        // access to the central (technician) interface.
        // glpi_profiles.interface = 'central' identifies technician profiles.
        // Use raw SQL to avoid GLPI DBmysqlIterator issues with SELECT DISTINCT
        $result = $DB->doQuery("
            SELECT DISTINCT gu.users_id
            FROM glpi_profiles_users AS gu
            INNER JOIN glpi_profiles AS p ON p.id = gu.profiles_id
            INNER JOIN glpi_users AS u ON u.id = gu.users_id
            WHERE p.interface = 'central'
            AND u.is_active = 1
            AND u.is_deleted = 0
            AND u.id > 0
        ");

        while ($row = $result->fetch_assoc()) {
            $uid = (int)$row['users_id'];
            if ($uid > 0) {
                $users[$uid] = 'broadcast';
            }
        }

        return $users;
    }

    private static function formatItemTitle(CommonDBTM $item): string
    {
        $type = $item::getType();
        $id   = (int)$item->fields['id'];
        $name = (string)($item->fields['name'] ?? '');
        if ($name === '') {
            $name = '#' . $id;
        }
        $name = Toolbox::substr($name, 0, 180);
        return sprintf('[%s #%d] %s', $type, $id, $name);
    }

    // ------------------------------------------------------------------ insert / read / cleanup

    // Lazy migration: adds the `channel` column on installs that predate
    // read-time filtering. Once-per-request, no-op after the first call.
    private static function ensureNotificationsSchema(): void
    {
        global $DB;

        if (self::$schemaEnsured) {
            return;
        }
        self::$schemaEnsured = true;

        if (!$DB->tableExists('glpi_plugin_notifier_notifications')) {
            return;
        }
        if ($DB->fieldExists('glpi_plugin_notifier_notifications', 'channel')) {
            return;
        }
        $DB->doQuery(
            "ALTER TABLE `glpi_plugin_notifier_notifications`
             ADD COLUMN `channel` VARCHAR(10) NOT NULL DEFAULT '' AFTER `event`"
        );
    }

    /**
     * Insert a notification row, deduplicated against the most recent
     * unread row for the same user/item/event in the last 60 seconds —
     * a single form save can fire several hooks and we don't want spam.
     */
    public static function insert(array $data): void
    {
        global $DB;

        $users_id = (int)($data['users_id'] ?? 0);
        $itemtype = (string)($data['itemtype'] ?? '');
        $items_id = (int)($data['items_id'] ?? 0);
        $event    = (string)($data['event'] ?? '');
        $channel  = (string)($data['channel'] ?? '');

        if ($users_id <= 0 || $itemtype === '' || $items_id === 0 || $event === '') {
            return;
        }

        self::ensureNotificationsSchema();

        $recent = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_notifier_notifications',
            'WHERE'  => [
                'users_id'      => $users_id,
                'itemtype'      => $itemtype,
                'items_id'      => $items_id,
                'event'         => $event,
                'is_read'       => 0,
                'date_creation' => ['>', date('Y-m-d H:i:s', time() - 60)],
            ],
            'LIMIT'  => 1,
        ]);
        if (count($recent) > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $DB->insert('glpi_plugin_notifier_notifications', [
            'users_id'       => $users_id,
            'actor_users_id' => (int)Session::getLoginUserID(),
            'itemtype'       => $itemtype,
            'items_id'       => $items_id,
            'event'          => $event,
            'channel'        => $channel,
            'title'          => (string)($data['title'] ?? ''),
            'message'        => (string)($data['message'] ?? ''),
            'url'            => (string)($data['url'] ?? ''),
            'is_read'        => 0,
            'date_creation'  => $now,
            'date_mod'       => $now,
        ]);
    }

    /**
     * Returns the GLPI ticket status integer for the given item, or 0 if not applicable.
     */
    private static function getTicketStatus(string $itemtype, int $items_id): int
    {
        global $DB;
        if ($itemtype !== 'Ticket' || $items_id <= 0) {
            return 0;
        }
        $rs = $DB->request([
            'SELECT' => ['status'],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => ['id' => $items_id],
            'LIMIT'  => 1,
        ]);
        $row = $rs->current();
        return (int)($row['status'] ?? 0);
    }

    /**
     * Returns [user_id => 'other'] for every active technician who:
     * - has notify_others = 1 in their preferences
     * - is NOT already in $existingTargets (not an actor of the item)
     * - has access to the given itemtype (based on GLPI profile rights)
     */
    private static function collectOtherTechnicians(array $existingTargets, string $itemtype): array
    {
        global $DB;

        $rightMap = [
            'Ticket'  => 'ticket',
            'Change'  => 'change',
            'Problem' => 'problem',
        ];
        $right = $rightMap[$itemtype] ?? null;
        if ($right === null) {
            return [];
        }

        // Get all active technicians with notify_others = 1
        $result = $DB->doQuery("
            SELECT DISTINCT u.id
            FROM glpi_users AS u
            INNER JOIN glpi_profiles_users AS pu ON pu.users_id = u.id
            INNER JOIN glpi_profiles AS p ON p.id = pu.profiles_id
            INNER JOIN glpi_profilerights AS pr ON pr.profiles_id = p.id
            LEFT JOIN glpi_plugin_notifier_preferences AS pref ON pref.users_id = u.id
            WHERE p.interface = 'central'
            AND u.is_active = 1
            AND u.is_deleted = 0
            AND u.id > 0
            AND pr.name = '{$right}'
            AND pr.rights > 0
            AND pref.notify_others = 1
        ");

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $uid = (int)$row['id'];
            if ($uid > 0 && !isset($existingTargets[$uid])) {
                $users[$uid] = 'other';
            }
        }

        return $users;
    }

    /**
     * Builds the update message based on what fields changed and the user's
     * update_message_style preference.
     *
     * @param array $relevant  Intersected list of changed fields
     * @param array $targets   [users_id => channel] map of recipients
     */
    private static function buildUpdateMessage(array $relevant, array $targets): string
    {
        $fieldMessages = [];
        if (in_array('itilcategories_id', $relevant, true)) {
            $fieldMessages[] = __('Category changed', 'notifier');
        }
        if (in_array('priority', $relevant, true) || in_array('urgency', $relevant, true)) {
            $fieldMessages[] = __('Priority changed', 'notifier');
        }
        if (in_array('_itil_assign', $relevant, true) || in_array('_actors', $relevant, true)) {
            $fieldMessages[] = __('Actors changed', 'notifier');
        }
        if (in_array('content', $relevant, true)) {
            $fieldMessages[] = __('Description updated', 'notifier');
        }

        if (empty($fieldMessages)) {
            return __('Item updated', 'notifier');
        }

        // We don't have a specific user here, so we use the first target's
        // preference as reference (all targets see the same message).
        // Default to 'priority' if no target found.
        $style = 'priority';
        if (!empty($targets)) {
            $firstUid = array_key_first($targets);
            $prefs = self::getPreferences((int)$firstUid);
            $style = $prefs['update_message_style'] ?? 'priority';
        }

        if ($style === 'multiple' && count($fieldMessages) > 1) {
            return __('Multiple changes', 'notifier');
        }

        if ($style === 'combined' && count($fieldMessages) > 1) {
            return implode(' + ', $fieldMessages);
        }

        // 'priority' or single change: return first (most important)
        return $fieldMessages[0];
    }

    /**
     * Returns the category of a notification for the given user:
     * - 'new'   : ticket has no assignee (user or group)
     * - 'mine'  : user is directly assigned
     * - 'team'  : user's group is assigned, but not the user directly
     * - 'other' : user has a notification but no direct/group assignment
     */
    public static function classifyNotification(array $item, int $users_id): string
    {
        global $DB;

        if ($item['itemtype'] !== 'Ticket') {
            // For non-tickets, classify based on channel
            if ($item['channel'] === 'direct') { return 'mine'; }
            if ($item['channel'] === 'group')  { return 'team'; }
            return 'other';
        }

        $ticketId = (int)$item['items_id'];

        // Resolved (5) and closed (6) go to their own tabs
        $status = (int)($item['ticket_status'] ?? 0);
        if ($status === 6) { return 'closed'; }
        if ($status === 5) { return 'resolved'; }

        // Check if ticket has any assignee at all
        $hasAssigneeRs = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_tickets_users',
            'WHERE' => ['tickets_id' => $ticketId, 'type' => 2],
        ]);
        $row = $hasAssigneeRs->current();
        $hasUserAssignee = (int)($row['cpt'] ?? 0) > 0;

        $hasGroupAssigneeRs = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_groups_tickets',
            'WHERE' => ['tickets_id' => $ticketId, 'type' => 2],
        ]);
        $row = $hasGroupAssigneeRs->current();
        $hasGroupAssignee = (int)($row['cpt'] ?? 0) > 0;

        if (!$hasUserAssignee && !$hasGroupAssignee) {
            return 'new';
        }

        // Check if user is directly assigned
        $directRs = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_tickets_users',
            'WHERE' => ['tickets_id' => $ticketId, 'type' => 2, 'users_id' => $users_id],
        ]);
        $row = $directRs->current();
        if ((int)($row['cpt'] ?? 0) > 0) {
            return 'mine';
        }

        // Check if any of user's groups is assigned
        $userGroupsRs = $DB->request([
            'SELECT' => ['groups_id'],
            'FROM'   => 'glpi_groups_users',
            'WHERE'  => ['users_id' => $users_id],
        ]);
        $userGroupIds = [];
        foreach ($userGroupsRs as $r) {
            $userGroupIds[] = (int)$r['groups_id'];
        }

        if (!empty($userGroupIds)) {
            $groupRs = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => 'glpi_groups_tickets',
                'WHERE' => [
                    'tickets_id' => $ticketId,
                    'type'       => 2,
                    'groups_id'  => $userGroupIds,
                ],
            ]);
            $row = $groupRs->current();
            if ((int)($row['cpt'] ?? 0) > 0) {
                return 'team';
            }
        }

        return 'other';
    }

    public static function getForUser(int $users_id, int $limit = 100): array
    {
        global $DB;

        self::ensureNotificationsSchema();

        $where = ['users_id' => $users_id, 'is_read' => 0];
        $filter = self::prefFilterExpression($users_id);
        if ($filter !== null) {
            $where[] = $filter;
        }

        $rs = $DB->request([
            'FROM'  => 'glpi_plugin_notifier_notifications',
            'WHERE' => $where,
            'ORDER' => ['date_creation DESC'],
        ]);

        $rows = [];
        $storedTicketIds = [];
        foreach ($rs as $row) {
            if ($row['itemtype'] === 'Ticket') {
                $storedTicketIds[(int)$row['items_id']] = true;
            }
            $item = [
                'id'            => (int)$row['id'],
                'itemtype'      => $row['itemtype'],
                'items_id'      => (int)$row['items_id'],
                'event'         => $row['event'],
                'channel'       => $row['channel'],
                'title'         => $row['title'],
                'message'       => $row['message'],
                'url'           => $row['url'],
                'is_read'       => (bool)$row['is_read'],
                'actor_name'    => self::actorName((int)$row['actor_users_id']),
                'date_creation' => $row['date_creation'],
                'ticket_status' => self::getTicketStatus($row['itemtype'], (int)$row['items_id']),
            ];
            $item['category'] = self::classifyNotification($item, $users_id);
            $rows[] = $item;
        }

        // Merge reactive unassigned tickets
        $unassigned = self::getUnassignedTicketsForUser($users_id, $storedTicketIds);
        foreach ($unassigned as $u) {
            $u['category'] = 'new';
            $rows[] = $u;
        }

        // Sort by date descending (all unread)
        usort($rows, function (array $a, array $b): int {
            return strcmp($b['date_creation'], $a['date_creation']);
        });

        return array_slice($rows, 0, $limit);
    }

    private static function getUnassignedTicketsForUser(int $users_id, array $excludeTicketIds = []): array
    {
        global $DB;

        $profileCheck = $DB->request([
            'COUNT'      => 'cpt',
            'FROM'       => 'glpi_profiles_users AS gu',
            'INNER JOIN' => [
                'glpi_profiles AS p' => ['ON' => ['p' => 'id', 'gu' => 'profiles_id']],
            ],
            'WHERE' => [
                'gu.users_id' => $users_id,
                'p.interface' => 'central',
            ],
        ]);
        $profileRow = $profileCheck->current();
        if ((int)($profileRow['cpt'] ?? 0) === 0) {
            return [];
        }

        $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

        $where = [
            't.status'        => ['<>', 6],
            't.is_deleted'    => 0,
            't.date_creation' => ['>', $sevenDaysAgo],
            'tech.id'         => null,
            'grp.id'          => null,
        ];

        if (!empty($excludeTicketIds)) {
            $where[] = new QueryExpression(
                '`t`.`id` NOT IN (' . implode(',', array_map('intval', array_keys($excludeTicketIds))) . ')'
            );
        }

        $rs = $DB->request([
            'SELECT'    => ['t.id', 't.name', 't.date_creation', 't.status'],
            'FROM'      => 'glpi_tickets AS t',
            'LEFT JOIN' => [
                'glpi_tickets_users AS tech' => [
                    'ON' => [
                        'tech' => 'tickets_id',
                        't'    => 'id',
                        ['AND' => ['tech.type' => 2]],
                    ],
                ],
                'glpi_groups_tickets AS grp' => [
                    'ON' => [
                        'grp' => 'tickets_id',
                        't'   => 'id',
                        ['AND' => ['grp.type' => 2]],
                    ],
                ],
            ],
            'WHERE' => $where,
            'ORDER' => ['t.date_creation DESC'],
            'LIMIT' => 25,
        ]);

        // Fetch ALL ticket IDs that already have a stored row (read or unread).
        // Those are handled by the normal DB query in getForUser/countUnread,
        // so we must exclude them from the reactive list to avoid double-counting.
        $storedInDb = [];
        if (count($rs) > 0) {
            $storedRs = $DB->request([
                'SELECT' => ['items_id', 'is_read'],
                'FROM'   => 'glpi_plugin_notifier_notifications',
                'WHERE'  => [
                    'users_id' => $users_id,
                    'itemtype' => 'Ticket',
                ],
            ]);
            foreach ($storedRs as $r) {
                $storedInDb[(int)$r['items_id']] = (bool)$r['is_read'];
            }
        }

        $rows = [];
        foreach ($rs as $row) {
            $ticketId = (int)$row['id'];
            // Skip tickets already in DB — they appear via the normal stored query
            if (isset($storedInDb[$ticketId])) {
                continue;
            }
            $isRead = false;
            $name     = Toolbox::substr((string)($row['name'] ?? ''), 0, 180);
            if ($name === '') {
                $name = '#' . $ticketId;
            }
            $rows[] = [
                'id'            => -$ticketId,
                'itemtype'      => 'Ticket',
                'items_id'      => $ticketId,
                'event'         => self::EVENT_CREATED,
                'title'         => sprintf('[Ticket #%d] %s', $ticketId, $name),
                'message'       => __('New unassigned ticket', 'notifier'),
                'url'           => Ticket::getFormURLWithID($ticketId, false),
                'is_read'       => $isRead,
                'actor_name'    => '',
                'date_creation' => $row['date_creation'],
                'ticket_status' => (int)$row['status'],
            ];
        }

        return $rows;
    }

    public static function countUnread(int $users_id): int
    {
        global $DB;

        self::ensureNotificationsSchema();

        $where = [
            'users_id' => $users_id,
            'is_read'  => 0,
        ];
        $filter = self::prefFilterExpression($users_id);
        if ($filter !== null) {
            $where[] = $filter;
        }

        $rs = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_plugin_notifier_notifications',
            'WHERE' => $where,
        ]);
        $row = $rs->current();
        $stored = (int)($row['cpt'] ?? 0);

        $unassigned = self::getUnassignedTicketsForUser($users_id);
        $unreadUnassigned = count(array_filter($unassigned, fn($u) => !$u['is_read']));

        return $stored + $unreadUnassigned;
    }

    /**
     * Number of unique source items with at least one unread row — what
     * the bell badge shows. Mirrors the JS-side groupKey().
     */
    public static function countUnreadGroups(int $users_id): int
    {
        global $DB;

        self::ensureNotificationsSchema();

        $where = [
            'users_id' => $users_id,
            'is_read'  => 0,
        ];
        $filter = self::prefFilterExpression($users_id);
        if ($filter !== null) {
            $where[] = $filter;
        }

        $rs = $DB->request([
            'SELECT' => [new QueryExpression('COUNT(DISTINCT `itemtype`, `items_id`) AS cpt')],
            'FROM'   => 'glpi_plugin_notifier_notifications',
            'WHERE'  => $where,
        ]);
        $row = $rs->current();
        $stored = (int)($row['cpt'] ?? 0);

        $unassigned = self::getUnassignedTicketsForUser($users_id);
        $unreadUnassigned = count(array_filter($unassigned, fn($u) => !$u['is_read']));

        return $stored + $unreadUnassigned;
    }

    /**
     * Persist a read row for a reactive (unassigned) ticket that has no
     * stored notification yet. Called when the user clicks the item in
     * the bell dropdown — id is negative (synthetic).
     */
    public static function markReadReactive(int $tickets_id, int $users_id): bool
    {
        global $DB;
        if ($tickets_id <= 0 || $users_id <= 0) {
            return false;
        }

        self::ensureNotificationsSchema();

        // Check if a row already exists
        $exists = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_plugin_notifier_notifications',
            'WHERE' => [
                'users_id' => $users_id,
                'itemtype' => 'Ticket',
                'items_id' => $tickets_id,
                'event'    => self::EVENT_CREATED,
            ],
        ]);
        $row = $exists->current();
        if ((int)($row['cpt'] ?? 0) > 0) {
            // Already exists — just mark it read
            return (bool)$DB->update(
                'glpi_plugin_notifier_notifications',
                ['is_read' => 1, 'date_mod' => date('Y-m-d H:i:s')],
                [
                    'users_id' => $users_id,
                    'itemtype' => 'Ticket',
                    'items_id' => $tickets_id,
                    'event'    => self::EVENT_CREATED,
                ]
            );
        }

        // Insert a new read row
        $ticket = new \Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return false;
        }
        $name = \Toolbox::substr((string)($ticket->fields['name'] ?? ''), 0, 180);
        if ($name === '') {
            $name = '#' . $tickets_id;
        }
        $now = date('Y-m-d H:i:s');
        $DB->insert('glpi_plugin_notifier_notifications', [
            'users_id'       => $users_id,
            'actor_users_id' => 0,
            'itemtype'       => 'Ticket',
            'items_id'       => $tickets_id,
            'event'          => self::EVENT_CREATED,
            'channel'        => 'broadcast',
            'title'          => sprintf('[Ticket #%d] %s', $tickets_id, $name),
            'message'        => __('New unassigned ticket', 'notifier'),
            'url'            => \Ticket::getFormURLWithID($tickets_id, false),
            'is_read'        => 1,
            'date_creation'  => $now,
            'date_mod'       => $now,
        ]);
        return true;
    }

    public static function markRead(int $id, int $users_id): bool
    {
        global $DB;
        if ($id <= 0 || $users_id <= 0) {
            return false;
        }
        return (bool)$DB->update(
            'glpi_plugin_notifier_notifications',
            ['is_read' => 1, 'date_mod' => date('Y-m-d H:i:s')],
            ['id' => $id, 'users_id' => $users_id]
        );
    }

    public static function markUnread(int $id, int $users_id): bool
    {
        global $DB;
        if ($id <= 0 || $users_id <= 0) {
            return false;
        }
        return (bool)$DB->update(
            'glpi_plugin_notifier_notifications',
            ['is_read' => 0, 'date_mod' => date('Y-m-d H:i:s')],
            ['id' => $id, 'users_id' => $users_id]
        );
    }

    public static function markAllRead(int $users_id): bool
    {
        global $DB;
        if ($users_id <= 0) {
            return false;
        }

        // Mark stored notifications as read.
        $DB->update(
            'glpi_plugin_notifier_notifications',
            ['is_read' => 1, 'date_mod' => date('Y-m-d H:i:s')],
            ['users_id' => $users_id, 'is_read' => 0]
        );

        // Also insert read rows for reactive unassigned tickets that have
        // no stored row yet, so they disappear from the Unread tab.
        $unassigned = self::getUnassignedTicketsForUser($users_id);
        $now = date('Y-m-d H:i:s');
        foreach ($unassigned as $u) {
            if ($u['is_read']) {
                continue;
            }
            $ticketId = (int)$u['items_id'];
            // Check if a row already exists to avoid duplicates.
            $exists = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => 'glpi_plugin_notifier_notifications',
                'WHERE' => [
                    'users_id' => $users_id,
                    'itemtype' => 'Ticket',
                    'items_id' => $ticketId,
                    'event'    => self::EVENT_CREATED,
                ],
            ]);
            $row = $exists->current();
            if ((int)($row['cpt'] ?? 0) > 0) {
                continue;
            }
            $DB->insert('glpi_plugin_notifier_notifications', [
                'users_id'       => $users_id,
                'actor_users_id' => 0,
                'itemtype'       => 'Ticket',
                'items_id'       => $ticketId,
                'event'          => self::EVENT_CREATED,
                'channel'        => 'broadcast',
                'title'          => $u['title'],
                'message'        => $u['message'],
                'url'            => $u['url'],
                'is_read'        => 1,
                'date_creation'  => $now,
                'date_mod'       => $now,
            ]);
        }

        return true;
    }

    // PLUGIN_HOOKS item_purge — keeps the bell from dangling.
    public static function cleanForItem($item): void
    {
        global $DB;
        if (!is_object($item) || !isset($item->fields['id'])) {
            return;
        }
        $DB->delete('glpi_plugin_notifier_notifications', [
            'itemtype' => $item::getType(),
            'items_id' => (int)$item->fields['id'],
        ]);
    }

    private static function actorName(int $users_id): string
    {
        if ($users_id <= 0) {
            return '';
        }
        $user = new User();
        if (!$user->getFromDB($users_id)) {
            return '';
        }
        $full = trim(($user->fields['firstname'] ?? '') . ' ' . ($user->fields['realname'] ?? ''));
        return $full !== '' ? $full : (string)($user->fields['name'] ?? '');
    }
}
