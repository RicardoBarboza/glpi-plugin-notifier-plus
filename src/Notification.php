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
    const EVENT_ASSIGNED          = 'assigned';
    const EVENT_CREATED           = 'created';
    const EVENT_COMMENTED         = 'commented';
    const EVENT_TASK_ADDED        = 'task_added';
    const EVENT_SOLUTION          = 'solution';
    const EVENT_STATUS_CHANGED    = 'status_changed';
    const EVENT_UPDATED           = 'updated';
    const EVENT_VALIDATION_ASKED  = 'validation_asked';
    const EVENT_VALIDATION_DONE   = 'validation_done';

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
            'sound_new'                 => 1,
            'sound_mine'                => 1,
            'sound_team'                => 1,
            'sound_other'               => 1,
            'sound_ended'               => 0,
            'notify_others'             => 0,
            'show_resolved'             => 1,
            'show_closed'               => 1,
            'resolved_closed_style'     => 'separate',
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
        $stringCols = ['update_message_style', 'resolved_closed_style'];
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
        $stringCols = ['update_message_style', 'resolved_closed_style'];
        $validStyles = ['priority', 'combined', 'multiple'];
        $validResolvedClosedStyles = ['separate', 'ended'];
        $row = ['users_id' => $users_id];
        foreach ($allowed as $col) {
            if (in_array($col, $stringCols, true)) {
                if ($col === 'resolved_closed_style') {
                    $val = $input[$col] ?? 'separate';
                    $row[$col] = in_array($val, $validResolvedClosedStyles, true) ? $val : 'separate';
                } else {
                    $val = $input[$col] ?? 'priority';
                    $row[$col] = in_array($val, $validStyles, true) ? $val : 'priority';
                }
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

        // If the item is in trash (is_deleted = 1), clean up notifications.
        if (
            in_array($item::getType(), ['Ticket', 'Change', 'Problem'], true)
            && !empty($item->fields['is_deleted'])
        ) {
            global $DB;
            $DB->delete('glpi_plugin_notifier_notifications', [
                'itemtype' => $item::getType(),
                'items_id' => (int)$item->fields['id'],
            ]);
            return;
        }

        // If the item was transferred to another entity, clean up notifications
        // for users who no longer have access to the new entity, then notify
        // technicians of the destination entity as if it were a new assignment.
        if (
            in_array($item::getType(), ['Ticket', 'Change', 'Problem'], true)
            && in_array('entities_id', $item->updates ?? [], true)
        ) {
            global $DB;
            $newEntityId = (int)($item->fields['entities_id'] ?? 0);
            self::cleanNotificationsForEntityTransfer($item::getType(), (int)$item->fields['id'], $newEntityId);
            self::handleEntityTransferNotification($item, $newEntityId);
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

        if (in_array($type, ['TicketValidation', 'ChangeValidation'], true)) {
            self::handleValidation($item);
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

        // Performance guard: cap notifications at 200 recipients
        if (count($targets) > 200) {
            $targets = array_slice($targets, 0, 200, true);
        }

        $title   = self::formatItemTitle($item);
        $baseUrl = $item::getFormURLWithID($id, false);

        if ($isCreate) {
            // If no assignee (type=ASSIGN) is linked yet, broadcast to all
            // active technicians so the new ticket doesn't go unnoticed.
            // We check for assignees specifically, not just any actor
            // (requesters/observers are always present but not assignees).
            if (!self::hasAssignee($item)) {
                $targets = self::collectAllTechnicians((int)($item->fields['entities_id'] ?? 0));
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
        $others = self::collectOtherTechnicians($targets, $type, (int)($item->fields['entities_id'] ?? 0));
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

        $parentEntityId = (int)($parent->fields['entities_id'] ?? 0);
        $others = self::collectOtherTechnicians($targets, $parentType, $parentEntityId);
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

        $parentEntityId2 = (int)($parent->fields['entities_id'] ?? 0);
        $others = self::collectOtherTechnicians($targets, $parentType, $parentEntityId2);
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
        $parentEntityId3 = (int)($parent->fields['entities_id'] ?? 0);
        $others = self::collectOtherTechnicians($targets, $parentType, $parentEntityId3);
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
            'SELECT'     => ['gu.users_id'],
            'FROM'       => 'glpi_groups_users AS gu',
            'INNER JOIN' => [
                'glpi_users AS u' => ['ON' => ['u' => 'id', 'gu' => 'users_id']],
            ],
            'WHERE' => [
                'gu.groups_id' => $groupId,
                'u.is_active'  => 1,
                'u.is_deleted' => 0,
            ],
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
            $allGroups = self::expandGroupsWithSubgroups([$memberId]);
            $rs = $DB->request([
                'SELECT'     => ['gu.users_id'],
                'FROM'       => 'glpi_groups_users AS gu',
                'INNER JOIN' => [
                    'glpi_users AS u' => ['ON' => ['u' => 'id', 'gu' => 'users_id']],
                ],
                'WHERE' => [
                    'gu.groups_id' => $allGroups,
                    'u.is_active'  => 1,
                    'u.is_deleted' => 0,
                ],
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
            // Expand groups to include subgroups (recursive_membership support)
            $allGroups = self::expandGroupsWithSubgroups(array_values($groups));
            $rs = $DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => 'glpi_groups_users',
                'WHERE'  => ['groups_id' => $allGroups],
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

    /**
     * Returns the list of entity IDs the given user has access to via a central
     * (technician) profile. Always includes entity 0 (root).
     * If the user has any recursive profile, all entities are included.
     *
     * @return array<int,true>  keys are entity IDs
     */
    private static function getAllowedEntitiesForUser(int $users_id): array
    {
        global $DB;

        $userEntitiesRs = $DB->doQuery("
            SELECT DISTINCT gu.entities_id, gu.is_recursive
            FROM glpi_profiles_users AS gu
            INNER JOIN glpi_profiles AS p ON p.id = gu.profiles_id
            WHERE gu.users_id = {$users_id}
            AND p.interface = 'central'
        ");

        $allowedEntities = [0 => true]; // always include root entity

        while ($row = $userEntitiesRs->fetch_assoc()) {
            $eid = (int)$row['entities_id'];
            $allowedEntities[$eid] = true;

            if ($row['is_recursive']) {
                // Expand only the subtree rooted at this entity,
                // not the entire system. getSonsOf returns the entity
                // itself plus all its descendants.
                $sons = getSonsOf('glpi_entities', $eid);
                foreach ($sons as $sid) {
                    $allowedEntities[(int)$sid] = true;
                }
            }
        }

        return $allowedEntities;
    }

    private static function collectAllTechnicians(int $entities_id = 0): array
    {
        global $DB;

        $users = [];

        // Build entity filter: include the entity and all its ancestors
        // so that recursive profiles (is_recursive=1) on parent entities are also matched.
        $entityIds = [0]; // 0 = root, always included
        if ($entities_id > 0) {
            $entityIds[] = $entities_id;
            // Add all ancestor entities
            $ancestors = getAncestorsOf('glpi_entities', $entities_id);
            foreach ($ancestors as $eid) {
                $entityIds[] = (int)$eid;
            }
        }
        $entityIn = implode(',', array_unique($entityIds));

        // Fetch all active users who have at least one profile that grants
        // access to the central (technician) interface in the item's entity.
        $result = $DB->doQuery("
            SELECT DISTINCT gu.users_id
            FROM glpi_profiles_users AS gu
            INNER JOIN glpi_profiles AS p ON p.id = gu.profiles_id
            INNER JOIN glpi_users AS u ON u.id = gu.users_id
            WHERE p.interface = 'central'
            AND u.is_active = 1
            AND u.is_deleted = 0
            AND u.id > 0
            AND (
                (gu.entities_id = {$entities_id} )
                OR (gu.is_recursive = 1 AND gu.entities_id IN ({$entityIn}))
            )
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
        if ($items_id <= 0) {
            return 0;
        }
        $tableMap = [
            'Ticket'  => 'glpi_tickets',
            'Change'  => 'glpi_changes',
            'Problem' => 'glpi_problems',
        ];
        $table = $tableMap[$itemtype] ?? null;
        if ($table === null) {
            return 0;
        }
        $rs = $DB->request([
            'SELECT' => ['status'],
            'FROM'   => $table,
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
    private static function collectOtherTechnicians(array $existingTargets, string $itemtype, int $entities_id = 0): array
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

        // Build entity filter
        $entityIds = [0];
        if ($entities_id > 0) {
            $entityIds[] = $entities_id;
            $ancestors = getAncestorsOf('glpi_entities', $entities_id);
            foreach ($ancestors as $eid) {
                $entityIds[] = (int)$eid;
            }
        }
        $entityIn = implode(',', array_unique($entityIds));

        // Get all active technicians with notify_others = 1 who have access to the item's entity
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
            AND (pr.rights & 16384) = 16384
            AND pref.notify_others = 1
            AND (
                (pu.entities_id = {$entities_id})
                OR (pu.is_recursive = 1 AND pu.entities_id IN ({$entityIn}))
            )
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
     * Returns true if the given user has the READALL right on tickets (bit 65536).
     * This is used to control visibility of the "Demais" tab and notify_others toggle.
     */
    public static function canSeeOthers(int $users_id): bool
    {
        global $DB;
        $rs = $DB->request([
            'COUNT'      => 'cpt',
            'FROM'       => 'glpi_profilerights AS pr',
            'INNER JOIN' => [
                'glpi_profiles_users AS pu' => [
                    'ON' => ['pu' => 'profiles_id', 'pr' => 'profiles_id'],
                ],
            ],
            'WHERE' => [
                'pu.users_id' => $users_id,
                'pr.name'     => 'ticket',
                new QueryExpression('(`pr`.`rights` & 16384) = 16384'),
            ],
        ]);
        return (int)($rs->current()['cpt'] ?? 0) > 0;
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
     * Expands a list of group IDs to include subgroups that have recursive_membership = 1.
     * Uses sons_cache stored by GLPI to avoid recursive queries.
     *
     * @param int[] $groupIds
     * @return int[]
     */
    private static function expandGroupsWithSubgroups(array $groupIds): array
    {
        global $DB;
        if (empty($groupIds)) {
            return $groupIds;
        }

        $allGroups = $groupIds;

        $rs = $DB->request([
            'SELECT' => ['id', 'sons_cache', 'recursive_membership'],
            'FROM'   => 'glpi_groups',
            'WHERE'  => ['id' => $groupIds],
        ]);

        foreach ($rs as $row) {
            if (!$row['recursive_membership']) {
                continue;
            }
            // sons_cache is a JSON-encoded array of child group IDs
            $sonsCache = $row['sons_cache'] ?? '';
            if ($sonsCache && $sonsCache !== 'null') {
                $sons = json_decode($sonsCache, true);
                if (is_array($sons)) {
                    foreach ($sons as $sid) {
                        $sid = (int)$sid;
                        if ($sid > 0 && !in_array($sid, $allGroups, true)) {
                            $allGroups[] = $sid;
                        }
                    }
                }
            }
        }

        return array_unique($allGroups);
    }

    /**
     * When a ticket/change/problem is transferred to another entity,
     * remove notifications from users who no longer have access to the new entity.
     */
    /**
     * When a ticket/change/problem is transferred to a new entity, notify
     * technicians of the destination entity:
     * - If the item has no assignee: broadcast to all technicians of the new entity
     *   (same as a new unassigned ticket arriving there).
     * - If the item has assignees: notify only the current actors who still have
     *   access to the new entity, plus other-technicians with notify_others=1.
     */
    /**
     * Handle TicketValidation / ChangeValidation events.
     *
     * ITEM_ADD  (status=waiting) → notify the validator (users_id_validate)
     * ITEM_UPDATE (status changed to accepted/refused) → notify actors of the parent item
     *
     * Validation status constants (CommonITILValidation):
     *   1 = WAITING, 2 = ACCEPTED, 4 = REFUSED
     */
    private static function handleValidation(CommonDBTM $item): void
    {
        $type     = $item::getType();
        $isCreate = empty($item->updates ?? []);
        $status   = (int)($item->fields['status'] ?? 1);

        $parentMap = [
            'TicketValidation' => ['parent' => 'Ticket', 'fk' => 'tickets_id'],
            'ChangeValidation' => ['parent' => 'Change',  'fk' => 'changes_id'],
        ];
        if (!isset($parentMap[$type])) {
            return;
        }

        $map      = $parentMap[$type];
        $parentId = (int)($item->fields[$map['fk']] ?? 0);
        if ($parentId <= 0) {
            return;
        }

        $parent = new $map['parent']();
        if (!$parent->getFromDB($parentId)) {
            return;
        }

        $title   = self::formatItemTitle($parent);
        $baseUrl = $map['parent']::getFormURLWithID($parentId, false);
        $actor   = self::actionAuthor($item);

        if ($isCreate || $status === 1) {
            // New validation requested — notify the validator
            $validatorId = (int)($item->fields['users_id_validate'] ?? 0);
            if ($validatorId > 0 && $validatorId !== $actor) {
                self::insert([
                    'users_id' => $validatorId,
                    'itemtype' => $map['parent'],
                    'items_id' => $parentId,
                    'event'    => self::EVENT_VALIDATION_ASKED,
                    'channel'  => 'direct',
                    'title'    => $title,
                    'message'  => __('Validation requested', 'notifier'),
                    'url'      => $baseUrl,
                ]);
            }
            return;
        }

        // Validation answered (accepted or refused) — notify actors of the parent
        if (in_array($status, [2, 4], true)) {
            $targets = self::collectActorsForItil($parent);
            unset($targets[$actor]);
            $message = $status === 2
                ? __('Validation accepted', 'notifier')
                : __('Validation refused', 'notifier');

            foreach ($targets as $uid => $channel) {
                self::insert([
                    'users_id' => $uid,
                    'itemtype' => $map['parent'],
                    'items_id' => $parentId,
                    'event'    => self::EVENT_VALIDATION_DONE,
                    'channel'  => $channel,
                    'title'    => $title,
                    'message'  => $message,
                    'url'      => $baseUrl,
                ]);
            }
        }
    }

    private static function handleEntityTransferNotification(CommonDBTM $item, int $new_entities_id): void
    {
        $type    = $item::getType();
        $id      = (int)$item->fields['id'];
        $title   = self::formatItemTitle($item);
        $baseUrl = $item::getFormURLWithID($id, false);
        $actor   = self::actionAuthor($item);
        $message = __('Ticket transferred to your entity', 'notifier');

        if (!self::hasAssignee($item)) {
            // No assignee — broadcast to all technicians of the destination entity
            $targets = self::collectAllTechnicians($new_entities_id);
            unset($targets[$actor]);
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
        } else {
            // Has assignees — notify current actors who have access to the new entity
            $targets = self::collectActorsForItil($item);
            unset($targets[$actor]);
            foreach ($targets as $uid => $channel) {
                self::insert([
                    'users_id' => $uid,
                    'itemtype' => $type,
                    'items_id' => $id,
                    'event'    => self::EVENT_UPDATED,
                    'channel'  => $channel,
                    'title'    => $title,
                    'message'  => $message,
                    'url'      => $baseUrl,
                ]);
            }
            // Also notify other-technicians of the new entity
            $others = self::collectOtherTechnicians($targets, $type, $new_entities_id);
            unset($others[$actor]);
            foreach ($others as $uid => $channel) {
                self::insert([
                    'users_id' => $uid,
                    'itemtype' => $type,
                    'items_id' => $id,
                    'event'    => self::EVENT_UPDATED,
                    'channel'  => $channel,
                    'title'    => $title,
                    'message'  => $message,
                    'url'      => $baseUrl,
                ]);
            }
        }
    }

    private static function cleanNotificationsForEntityTransfer(string $itemtype, int $items_id, int $new_entities_id): void
    {
        global $DB;

        // Get all users with notifications for this item
        $notifRs = $DB->doQuery("
            SELECT DISTINCT users_id
            FROM glpi_plugin_notifier_notifications
            WHERE itemtype = '{$itemtype}'
            AND items_id = {$items_id}
        ");

        if (!$notifRs) {
            return;
        }

        // Pre-compute entity ancestors once (not inside the loop)
        // A user covers the new entity if they have a direct profile there,
        // or a recursive profile on any ancestor (including root=0).
        $entityAncestors = array_merge(
            [0, $new_entities_id],
            array_map('intval', array_keys(getAncestorsOf('glpi_entities', $new_entities_id)))
        );
        $entityIn = implode(',', array_unique($entityAncestors));

        // Fetch in one query all users who DO have access to the new entity
        $accessRs = $DB->doQuery("
            SELECT DISTINCT pu.users_id
            FROM glpi_profiles_users AS pu
            INNER JOIN glpi_profiles AS p ON p.id = pu.profiles_id
            WHERE p.interface = 'central'
            AND (
                pu.entities_id = {$new_entities_id}
                OR (pu.is_recursive = 1 AND pu.entities_id IN ({$entityIn}))
            )
        ");
        $usersWithAccess = [];
        if ($accessRs) {
            while ($arow = $accessRs->fetch_assoc()) {
                $usersWithAccess[(int)$arow['users_id']] = true;
            }
        }

        $toDelete = [];
        while ($row = $notifRs->fetch_assoc()) {
            $uid = (int)$row['users_id'];
            if ($uid <= 0) {
                continue;
            }
            if (!isset($usersWithAccess[$uid])) {
                $toDelete[] = $uid;
            }
        }

        if (!empty($toDelete)) {
            $DB->delete('glpi_plugin_notifier_notifications', [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'users_id' => $toDelete,
            ]);
        }
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

        // Periodic cleanup: purge stale notifications roughly every 100 calls
        // Uses a simple session counter to avoid running on every poll
        if (!isset($_SESSION['notifier_purge_counter'])) {
            $_SESSION['notifier_purge_counter'] = 0;
        }
        $_SESSION['notifier_purge_counter']++;
        if ($_SESSION['notifier_purge_counter'] >= 100) {
            $_SESSION['notifier_purge_counter'] = 0;
            self::purgeStaleNotifications();
        }

        self::ensureNotificationsSchema();

        $where = ['users_id' => $users_id, 'is_read' => 0];
        $filter = self::prefFilterExpression($users_id);
        if ($filter !== null) {
            $where[] = $filter;
        }

        // Get allowed entities for this user so we can filter stored notifications
        // by the entity of the source ticket/change/problem.
        $allowedEntities = self::getAllowedEntitiesForUser($users_id);
        $entityIn = implode(',', array_keys($allowedEntities));

        // Join with the source item table to enforce entity access.
        // Non-ticket items (Change, Problem, ProjectTask) are also filtered.
        // We use a raw query to support the dynamic JOIN across item types.
        $notifTable = 'glpi_plugin_notifier_notifications';
        $whereStr = "n.users_id = {$users_id} AND n.is_read = 0";
        if ($filter !== null) {
            // Re-express the pref filter as raw SQL (same logic as QueryExpression)
            $whereStr .= ' AND ' . $filter->getValue();
        }

        $rawSql = "
            SELECT n.*
            FROM {$notifTable} AS n
            LEFT JOIN glpi_tickets   AS t  ON (n.itemtype = 'Ticket'      AND n.items_id = t.id)
            LEFT JOIN glpi_changes   AS c  ON (n.itemtype = 'Change'      AND n.items_id = c.id)
            LEFT JOIN glpi_problems  AS pr ON (n.itemtype = 'Problem'     AND n.items_id = pr.id)
            LEFT JOIN glpi_projecttasks AS pt ON (n.itemtype = 'ProjectTask' AND n.items_id = pt.id)
            WHERE {$whereStr}
            AND (
                (n.itemtype = 'Ticket'      AND t.id  IS NOT NULL AND t.entities_id  IN ({$entityIn}))
                OR (n.itemtype = 'Change'   AND c.id  IS NOT NULL AND c.entities_id  IN ({$entityIn}))
                OR (n.itemtype = 'Problem'  AND pr.id IS NOT NULL AND pr.entities_id IN ({$entityIn}))
                OR (n.itemtype = 'ProjectTask' AND pt.id IS NOT NULL AND pt.entities_id IN ({$entityIn}))
            )
            ORDER BY n.date_creation DESC
        ";

        $rs = $DB->doQuery($rawSql);

        // Collect all Ticket items_ids from the result to batch-check is_deleted.
        // This avoids N+1 queries and handles tickets deleted/merged after the
        // notification was stored (e.g. merge sets is_deleted=1 but the stored
        // notification row was not cleaned up yet).
        $rawRows = [];
        if ($rs) {
            while ($row = $rs->fetch_assoc()) {
                $rawRows[] = $row;
            }
        }
        $ticketIdsToCheck = [];
        foreach ($rawRows as $row) {
            if ($row['itemtype'] === 'Ticket') {
                $ticketIdsToCheck[(int)$row['items_id']] = true;
            }
        }
        $deletedTicketIds = [];
        if (!empty($ticketIdsToCheck)) {
            $deletedRs = $DB->doQuery(
                'SELECT id FROM glpi_tickets WHERE id IN ('
                . implode(',', array_keys($ticketIdsToCheck))
                . ') AND is_deleted = 1'
            );
            while ($dr = $deletedRs->fetch_assoc()) {
                $deletedTicketIds[(int)$dr['id']] = true;
            }
            // Also clean up those stale rows so they don't accumulate
            if (!empty($deletedTicketIds)) {
                $DB->delete('glpi_plugin_notifier_notifications', [
                    'itemtype' => 'Ticket',
                    new QueryExpression(
                        '`items_id` IN (' . implode(',', array_keys($deletedTicketIds)) . ')'
                    ),
                ]);
            }
        }

        $rows = [];
        $storedTicketIds = [];
        foreach ($rawRows as $row) {
            // Skip notifications for tickets that were deleted or merged
            if ($row['itemtype'] === 'Ticket' && isset($deletedTicketIds[(int)$row['items_id']])) {
                continue;
            }
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

        // Check user has a central interface profile
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

        $allowedEntities = self::getAllowedEntitiesForUser($users_id);

        $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

        $where = [
            't.status'        => ['<>', 6],
            't.is_deleted'    => 0,
            't.date_creation' => ['>', $sevenDaysAgo],
            'tech.id'         => null,
            'grp.id'          => null,
        ];

        // Filter tickets by entities the user has access to
        if (!empty($allowedEntities)) {
            $where[] = new QueryExpression(
                '`t`.`entities_id` IN (' . implode(',', array_keys($allowedEntities)) . ')'
            );
        }

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
            'LIMIT' => 100,
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

        $filter    = self::prefFilterExpression($users_id);
        $whereStr  = "n.users_id = {$users_id} AND n.is_read = 0";
        if ($filter !== null) {
            $whereStr .= ' AND ' . $filter->getValue();
        }
        $allowedEntities = self::getAllowedEntitiesForUser($users_id);
        $entityIn = implode(',', array_keys($allowedEntities));

        $rs = $DB->doQuery("
            SELECT COUNT(*) AS cpt
            FROM glpi_plugin_notifier_notifications AS n
            LEFT JOIN glpi_tickets      AS t  ON (n.itemtype = 'Ticket'      AND n.items_id = t.id)
            LEFT JOIN glpi_changes      AS c  ON (n.itemtype = 'Change'      AND n.items_id = c.id)
            LEFT JOIN glpi_problems     AS pr ON (n.itemtype = 'Problem'     AND n.items_id = pr.id)
            LEFT JOIN glpi_projecttasks AS pt ON (n.itemtype = 'ProjectTask' AND n.items_id = pt.id)
            WHERE {$whereStr}
            AND (
                (n.itemtype = 'Ticket'      AND t.id  IS NOT NULL AND t.entities_id  IN ({$entityIn}))
                OR (n.itemtype = 'Change'   AND c.id  IS NOT NULL AND c.entities_id  IN ({$entityIn}))
                OR (n.itemtype = 'Problem'  AND pr.id IS NOT NULL AND pr.entities_id IN ({$entityIn}))
                OR (n.itemtype = 'ProjectTask' AND pt.id IS NOT NULL AND pt.entities_id IN ({$entityIn}))
            )
        ");
        $row    = $rs ? $rs->fetch_assoc() : [];
        $stored = (int)($row['cpt'] ?? 0);

        $unassigned       = self::getUnassignedTicketsForUser($users_id);
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

        $filter   = self::prefFilterExpression($users_id);
        $whereStr = "n.users_id = {$users_id} AND n.is_read = 0";
        if ($filter !== null) {
            $whereStr .= ' AND ' . $filter->getValue();
        }
        $allowedEntities = self::getAllowedEntitiesForUser($users_id);
        $entityIn = implode(',', array_keys($allowedEntities));

        $rs = $DB->doQuery("
            SELECT COUNT(DISTINCT n.itemtype, n.items_id) AS cpt
            FROM glpi_plugin_notifier_notifications AS n
            LEFT JOIN glpi_tickets      AS t  ON (n.itemtype = 'Ticket'      AND n.items_id = t.id)
            LEFT JOIN glpi_changes      AS c  ON (n.itemtype = 'Change'      AND n.items_id = c.id)
            LEFT JOIN glpi_problems     AS pr ON (n.itemtype = 'Problem'     AND n.items_id = pr.id)
            LEFT JOIN glpi_projecttasks AS pt ON (n.itemtype = 'ProjectTask' AND n.items_id = pt.id)
            WHERE {$whereStr}
            AND (
                (n.itemtype = 'Ticket'      AND t.id  IS NOT NULL AND t.entities_id  IN ({$entityIn}))
                OR (n.itemtype = 'Change'   AND c.id  IS NOT NULL AND c.entities_id  IN ({$entityIn}))
                OR (n.itemtype = 'Problem'  AND pr.id IS NOT NULL AND pr.entities_id IN ({$entityIn}))
                OR (n.itemtype = 'ProjectTask' AND pt.id IS NOT NULL AND pt.entities_id IN ({$entityIn}))
            )
        ");
        $row    = $rs ? $rs->fetch_assoc() : [];
        $stored = (int)($row['cpt'] ?? 0);

        $unassigned       = self::getUnassignedTicketsForUser($users_id);
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

    /**
     * Purges stale notifications:
     * - Read notifications older than 30 days
     * - Notifications for deleted or inactive users
     * Called on every 100th poll to avoid overhead.
     */
    public static function purgeStaleNotifications(): void
    {
        global $DB;

        // Remove read notifications older than 30 days
        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
        $DB->delete('glpi_plugin_notifier_notifications', [
            'is_read'       => 1,
            ['date_mod' => ['<', $cutoff]],
        ]);

        // Remove notifications for deleted or inactive users
        $DB->doQuery("
            DELETE n FROM glpi_plugin_notifier_notifications AS n
            LEFT JOIN glpi_users AS u ON u.id = n.users_id
            WHERE u.id IS NULL OR u.is_deleted = 1 OR u.is_active = 0
        ");
    }

    // PLUGIN_HOOKS item_purge — keeps the bell from dangling.
    public static function cleanForItem($item): void
    {
        global $DB;
        if (!is_object($item) || !isset($item->fields['id'])) {
            return;
        }

        $type = $item::getType();

        // --- Ator direto removido do ticket/change/problem ---
        // Quando Ticket_User é purgado, o usuário não é mais ator
        // e não deve continuar recebendo notificações desse item.
        $actorUserMap = [
            'Ticket_User'  => ['parent' => 'Ticket',  'fk' => 'tickets_id'],
            'Change_User'  => ['parent' => 'Change',  'fk' => 'changes_id'],
            'Problem_User' => ['parent' => 'Problem', 'fk' => 'problems_id'],
        ];
        if (isset($actorUserMap[$type])) {
            $map      = $actorUserMap[$type];
            $uid      = (int)($item->fields['users_id'] ?? 0);
            $parentId = (int)($item->fields[$map['fk']] ?? 0);
            if ($uid > 0 && $parentId > 0) {
                // Only remove if user has no other actor role on this item
                $stillActor = $DB->request([
                    'COUNT' => 'cpt',
                    'FROM'  => 'glpi_' . strtolower($map['parent']) . 's_users',
                    'WHERE' => [
                        $map['fk']   => $parentId,
                        'users_id'   => $uid,
                    ],
                ])->current();
                if ((int)($stillActor['cpt'] ?? 0) === 0) {
                    $DB->delete('glpi_plugin_notifier_notifications', [
                        'itemtype' => $map['parent'],
                        'items_id' => $parentId,
                        'users_id' => $uid,
                    ]);
                }
            }
            return;
        }

        // --- Grupo removido do ticket/change/problem ---
        // Notifica membros do grupo que não são mais atores por outro caminho
        $actorGroupMap = [
            'Group_Ticket'  => ['parent' => 'Ticket',  'fk' => 'tickets_id',  'gtable' => 'glpi_groups_tickets'],
            'Change_Group'  => ['parent' => 'Change',  'fk' => 'changes_id',  'gtable' => 'glpi_changes_groups'],
            'Group_Problem' => ['parent' => 'Problem', 'fk' => 'problems_id', 'gtable' => 'glpi_groups_problems'],
        ];
        if (isset($actorGroupMap[$type])) {
            $map      = $actorGroupMap[$type];
            $groupId  = (int)($item->fields['groups_id'] ?? 0);
            $parentId = (int)($item->fields[$map['fk']] ?? 0);
            if ($groupId > 0 && $parentId > 0) {
                // Get members of the removed group
                $members = $DB->request([
                    'SELECT' => ['users_id'],
                    'FROM'   => 'glpi_groups_users',
                    'WHERE'  => ['groups_id' => $groupId],
                ]);
                foreach ($members as $mrow) {
                    $uid = (int)$mrow['users_id'];
                    if ($uid <= 0) continue;
                    // Check if user is still actor via another group or direct link
                    $stillDirect = $DB->request([
                        'COUNT' => 'cpt',
                        'FROM'  => 'glpi_' . strtolower($map['parent']) . 's_users',
                        'WHERE' => [$map['fk'] => $parentId, 'users_id' => $uid],
                    ])->current();
                    $stillGroup = $DB->doQuery("
                        SELECT COUNT(*) as cpt
                        FROM {$map['gtable']} gt
                        INNER JOIN glpi_groups_users gu ON gu.groups_id = gt.groups_id
                        WHERE gt.{$map['fk']} = {$parentId}
                        AND gu.users_id = {$uid}
                        AND gt.groups_id != {$groupId}
                    ")->fetch_assoc();
                    if ((int)($stillDirect['cpt'] ?? 0) === 0 && (int)($stillGroup['cpt'] ?? 0) === 0) {
                        $DB->delete('glpi_plugin_notifier_notifications', [
                            'itemtype' => $map['parent'],
                            'items_id' => $parentId,
                            'users_id' => $uid,
                        ]);
                    }
                }
            }
            return;
        }

        // --- Followup/Tarefa deletado ---
        // Notificações de followup e tarefa são armazenadas com itemtype=Ticket/Change/Problem
        // e items_id = id do pai — NÃO existem linhas com itemtype=ITILFollowup/TicketTask etc.
        // Portanto não há orphans para limpar: a notificação do evento já foi entregue
        // e fica no histórico do ticket pai, o que é o comportamento correto.
        $childTypes = ['ITILFollowup', 'TicketTask', 'ChangeTask', 'ProblemTask'];
        if (in_array($type, $childTypes, true)) {
            return;
        }

        // --- Validação deletada ---
        if (in_array($type, ['TicketValidation', 'ChangeValidation'], true)) {
            // Validation notifications are stored with the validation itemtype/id
            $DB->delete('glpi_plugin_notifier_notifications', [
                'itemtype' => $type,
                'items_id' => (int)$item->fields['id'],
            ]);
            return;
        }

        // --- Default: limpar todas as notificações do item ---
        $DB->delete('glpi_plugin_notifier_notifications', [
            'itemtype' => $type,
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
