/**
 * Notifier plugin — bell widget. Central interface only (setup.php gates
 * the JS hook off in helpdesk mode, so no runtime guard here).
 */
(function() {
    'use strict';

    var POLL_INTERVAL_MS = 30000;
    var LS_COLLAPSED_KEY  = 'notifier:collapsed';
    var LS_TAB_KEY        = 'notifier:tab';
    var BASE_URL          = null;
    var pollTimer         = null;
    var pollInFlight      = false;
    var lastUnreadGroups  = null;
    var lastUnreadByTab   = null;
    var lastSoundPlayed   = 0;

    // ---------------------------------------------------------------- sound

    function playNotificationSound() {
        try {
            var now = Date.now();
            if ((now - lastSoundPlayed) < 5000) { return; }
            lastSoundPlayed = now;

            var audio = new Audio(BASE_URL + '/public/sound/notification.mp3');
            audio.volume = 0.5;
            var p = audio.play();
            if (p !== undefined) {
                p.catch(function() { /* autoplay blocked — silent fail */ });
            }
        } catch (e) { /* silent fail */ }
    }

    // typeLabelKey points at a T entry so the modal stays i18n-aware.
    var PREF_TYPES = [
        { slug: 'ticket',      typeLabelKey: 'typeTicket',      direct: 'notify_ticket_direct',      group: 'notify_ticket_group' },
        { slug: 'change',      typeLabelKey: 'typeChange',      direct: 'notify_change_direct',      group: 'notify_change_group' },
        { slug: 'problem',     typeLabelKey: 'typeProblem',     direct: 'notify_problem_direct',     group: 'notify_problem_group' },
        { slug: 'projecttask', typeLabelKey: 'typeProjectTask', direct: 'notify_projecttask_direct', group: 'notify_projecttask_group' }
    ];

    // English fallbacks; ajax/i18n.php hydrates this on boot.
    var T = {
        notifications:       'Notifications',
        markAllRead:         'Mark all as read',
        markAsRead:          'Mark as read',
        markAsUnread:        'Mark as unread',
        noNotifications:     'No notifications',
        noNotificationsHint: "You're all caught up.",
        minimize:            'Minimize',
        expand:              'Expand notifications',
        tabAll:              'Todos',
        tabNew:              'Novos',
        tabMine:             'Meus',
        tabTeam:             'Equipe',
        tabOther:            'Demais',
        tabResolved:         'Resolvidos',
        tabClosed:           'Fechados',
        tabEnded:            'Encerrados',
        settings:            'Settings',
        preferencesTitle:    'Notification preferences',
        preferencesIntro:    'Choose which updates you want to receive. Direct updates are about items assigned to you; group updates are about items assigned to one of your groups.',
        colDirect:           'Assigned to me',
        colGroup:            'Assigned to my group',
        typeTicket:          'Tickets',
        typeChange:          'Changes',
        typeProblem:         'Problems',
        typeProjectTask:     'Project tasks',
        save:                'Save',
        cancel:              'Cancel',
        saved:               'Preferences saved',
        close:               'Close',
        groupedUpdates:      '{n} updates',
        expandGroup:         'Show all updates',
        collapseGroup:       'Hide updates'
    };

    var state = {
        items: [],
        unread: 0,
        unreadGroups: 0,
        unreadByTab: { new: 0, mine: 0, team: 0, other: 0, resolved: 0, closed: 0, ended: 0 },
        tab: loadTab(),
        expanded: new Set(),
        soundEnabled: true,
        soundByTab: { new: true, mine: true, team: true, other: true, ended: false },
        canSeeOthers: false,
        showResolved: true,
        showClosed: true,
        resolvedClosedStyle: 'separate'
    };

    // ------------------------------------------------------------------ utils

    function resolveBaseUrl() {
        if (typeof window.CFG_GLPI === 'object' && window.CFG_GLPI && window.CFG_GLPI.root_doc) {
            return window.CFG_GLPI.root_doc + '/plugins/notifier';
        }
        var match = window.location.pathname.match(/^(.*?)\/(front|plugins|index\.php)/);
        var root  = match ? match[1] : '';
        return root + '/plugins/notifier';
    }

    function fetchJson(url, opts) {
        opts = opts || {};
        opts.credentials = 'same-origin';
        opts.headers = opts.headers || {};
        opts.headers['X-Requested-With'] = 'XMLHttpRequest';
        return fetch(url, opts).then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function isSameOrigin(url) {
        try {
            var resolved = new URL(url, window.location.href);
            return resolved.origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function timeAgo(dateStr) {
        if (!dateStr) return '';
        // GLPI TIMESTAMP "2026-04-14 13:37:00" — treat as local.
        var d = new Date(dateStr.replace(' ', 'T'));
        var diff = (Date.now() - d.getTime()) / 1000;
        if (diff < 60)    return Math.floor(diff) + 's';
        if (diff < 3600)  return Math.floor(diff / 60) + 'm';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h';
        return Math.floor(diff / 86400) + 'd';
    }

    var VALID_TABS = ['all', 'new', 'mine', 'team', 'other', 'resolved', 'closed', 'ended'];
    function loadTab() {
        try {
            var v = localStorage.getItem(LS_TAB_KEY);
            return VALID_TABS.indexOf(v) >= 0 ? v : 'all';
        } catch (e) { return 'all'; }
    }
    function saveTab(tab) {
        try { localStorage.setItem(LS_TAB_KEY, tab); } catch (e) { /* ignore */ }
    }

    // ---------------------------------------------------------------- mount

    function buildBell() {
        var wrap = document.createElement('div');
        wrap.className = 'notifier-bell-wrap';
        wrap.innerHTML = ''
            + '<button type="button" class="notifier-bell-btn" aria-label="' + escapeHtml(T.notifications) + '" aria-haspopup="true" aria-expanded="false">'
            +   '<i class="fas fa-bell"></i>'
            +   '<span class="notifier-bell-badge" hidden>0</span>'
            + '</button>'
            + '<button type="button" class="notifier-bell-restore" aria-label="' + escapeHtml(T.expand) + '" title="' + escapeHtml(T.expand) + '">'
            +   '<i class="fas fa-chevron-left"></i>'
            + '</button>'
            + '<div class="notifier-bell-panel" role="dialog" aria-label="' + escapeHtml(T.notifications) + '" hidden>'
            +   '<div class="notifier-bell-panel-header">'
            +     '<div class="notifier-bell-panel-titlebar">'
            +       '<span class="notifier-bell-panel-title">'
            +         escapeHtml(T.notifications)
            +         ' <span class="notifier-bell-panel-count">(0)</span>'
            +       '</span>'
            +       '<button type="button" class="notifier-bell-minimize" title="' + escapeHtml(T.minimize) + '" aria-label="' + escapeHtml(T.minimize) + '">'
            +         '<i class="fas fa-minus"></i>'
            +       '</button>'
            +     '</div>'
            +     '<div class="notifier-bell-tabs" role="tablist">'
            +       '<button type="button" class="notifier-bell-tab" data-tab="all"  role="tab"><span class="tab-label">' + escapeHtml(T.tabAll)   + '</span><span class="tab-badge" hidden>0</span></button>'
            +       '<button type="button" class="notifier-bell-tab" data-tab="new"  role="tab"><span class="tab-label">' + escapeHtml(T.tabNew)   + '</span><span class="tab-badge" hidden>0</span></button>'
            +       '<button type="button" class="notifier-bell-tab" data-tab="mine" role="tab"><span class="tab-label">' + escapeHtml(T.tabMine)  + '</span><span class="tab-badge" hidden>0</span></button>'
            +       '<button type="button" class="notifier-bell-tab" data-tab="team" role="tab"><span class="tab-label">' + escapeHtml(T.tabTeam)  + '</span><span class="tab-badge" hidden>0</span></button>'
            +       '<button type="button" class="notifier-bell-tab notifier-tab-others" data-tab="other" role="tab"><span class="tab-label">' + escapeHtml(T.tabOther) + '</span><span class="tab-badge" hidden>0</span></button>'
            +       '<button type="button" class="notifier-bell-tab notifier-tab-resolved" data-tab="resolved" role="tab"><span class="tab-label">' + escapeHtml(T.tabResolved) + '</span><span class="tab-badge" hidden>0</span></button>'
            +       '<button type="button" class="notifier-bell-tab notifier-tab-closed" data-tab="closed" role="tab"><span class="tab-label">' + escapeHtml(T.tabClosed) + '</span><span class="tab-badge" hidden>0</span></button>'
            +       '<button type="button" class="notifier-bell-tab notifier-tab-ended" data-tab="ended" role="tab"><span class="tab-label">' + escapeHtml(T.tabEnded || 'Encerrados') + '</span><span class="tab-badge" hidden>0</span></button>'
            +       '<button type="button" class="notifier-bell-markall">' + escapeHtml(T.markAllRead) + '</button>'
            +     '</div>'
            +   '</div>'
            +   '<div class="notifier-bell-panel-body">'
            +     '<ul class="notifier-bell-list" role="list"></ul>'
            +     '<div class="notifier-bell-empty" hidden>'
            +       '<div class="notifier-bell-empty-art" aria-hidden="true">'
            +         '<i class="fas fa-bell-slash"></i>'
            +       '</div>'
            +       '<div class="notifier-bell-empty-title">' + escapeHtml(T.noNotifications) + '</div>'
            +       '<div class="notifier-bell-empty-hint">' + escapeHtml(T.noNotificationsHint) + '</div>'
            +     '</div>'
            +   '</div>'
            +   '<div class="notifier-bell-panel-footer">'
            +     '<button type="button" class="notifier-bell-settings" title="' + escapeHtml(T.settings) + '">'
            +       '<i class="fas fa-cog"></i> ' + escapeHtml(T.settings)
            +     '</button>'
            +   '</div>'
            + '</div>';
        return wrap;
    }

    function setCollapsed(wrap, collapsed) {
        try { localStorage.setItem(LS_COLLAPSED_KEY, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
        if (!wrap) return;
        wrap.classList.toggle('is-collapsed', !!collapsed);
        var btn = wrap.querySelector('.notifier-bell-btn');
        if (btn) btn.setAttribute('aria-hidden', collapsed ? 'true' : 'false');
        if (collapsed) {
            var panel = wrap.querySelector('.notifier-bell-panel');
            if (panel) panel.hidden = true;
            if (btn) btn.setAttribute('aria-expanded', 'false');
        }
    }

    function isStoredCollapsed() {
        try { return localStorage.getItem(LS_COLLAPSED_KEY) === '1'; } catch (e) { return false; }
    }

    function installBell() {
        if (document.querySelector('.notifier-bell-wrap')) return true;
        // Floating mount: GLPI's header DOM varies by version/theme and
        // every selector-based mount we tried had edge cases. Fixed
        // bottom-right is theme-independent and always visible.
        var bell = buildBell();
        bell.classList.add('notifier-bell-floating');
        document.body.appendChild(bell);
        wireEvents(bell);
        return true;
    }

    // ---------------------------------------------------------------- render

    function visibleItems() {
        var items = state.items.filter(function(i) { return !i.is_read; });

        // Filter out resolved/closed if user disabled them
        items = items.filter(function(i) {
            if (i.category === 'resolved' && !state.showResolved) { return false; }
            if (i.category === 'closed' && !state.showClosed) { return false; }
            return true;
        });

        if (state.tab === 'all') { return items; }
        if (state.tab === 'ended') {
            return items.filter(function(i) { return i.category === 'resolved' || i.category === 'closed'; });
        }
        return items.filter(function(i) { return i.category === state.tab; });
    }

    function groupKey(item) {
        return item.itemtype + ':' + item.items_id;
    }

    function groupItems(items) {
        var byKey = Object.create(null);
        var order = [];
        items.forEach(function(item) {
            var key = groupKey(item);
            if (!byKey[key]) {
                byKey[key] = {
                    key:      key,
                    itemtype: item.itemtype,
                    items_id: item.items_id,
                    title:    item.title,
                    url:      item.url,
                    events:   []
                };
                order.push(key);
            }
            byKey[key].events.push(item);
        });
        return order.map(function(k) { return byKey[k]; });
    }

    // Group-level actions need every matching event, not just the
    // tab-filtered ones — so they cover read events on the Unread tab too.
    function eventsForKey(key) {
        var sep = key.indexOf(':');
        if (sep < 0) return [];
        var itemtype = key.substring(0, sep);
        var itemsId  = parseInt(key.substring(sep + 1), 10);
        return state.items.filter(function(ev) {
            return ev.itemtype === itemtype && ev.items_id === itemsId;
        });
    }

    function formatGroupedUpdates(n) {
        return (T.groupedUpdates || '{n} updates').replace('{n}', n);
    }

    function render() {
        var wrap = document.querySelector('.notifier-bell-wrap');
        if (!wrap) return;

        var displayCount = state.unreadGroups;

        var badge = wrap.querySelector('.notifier-bell-badge');
        if (displayCount > 0) {
            badge.textContent = displayCount > 99 ? '99+' : String(displayCount);
            badge.hidden = false;
            wrap.classList.add('has-unread');
        } else {
            badge.hidden = true;
            wrap.classList.remove('has-unread');
        }

        var countEl = wrap.querySelector('.notifier-bell-panel-count');
        if (countEl) countEl.textContent = '(' + displayCount + ')';

        // Show/hide tabs based on state
        var othersTab = wrap.querySelector('.notifier-tab-others');
        if (othersTab) { othersTab.hidden = !state.canSeeOthers; }

        var resolvedTab = wrap.querySelector('.notifier-tab-resolved');
        var closedTab = wrap.querySelector('.notifier-tab-closed');
        var endedTab = wrap.querySelector('.notifier-tab-ended');

        if (state.resolvedClosedStyle === 'ended') {
            if (resolvedTab) { resolvedTab.hidden = true; }
            if (closedTab) { closedTab.hidden = true; }
            if (endedTab) { endedTab.hidden = !state.showResolved && !state.showClosed; }
        } else {
            if (resolvedTab) { resolvedTab.hidden = !state.showResolved; }
            if (closedTab) { closedTab.hidden = !state.showClosed; }
            if (endedTab) { endedTab.hidden = true; }
        }

        var tabBtns = wrap.querySelectorAll('.notifier-bell-tab');
        tabBtns.forEach(function(btn) {
            var isActive = btn.dataset.tab === state.tab;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-selected', String(isActive));

            // Update per-tab badge
            var tabKey = btn.dataset.tab;
            var badgeEl = btn.querySelector('.tab-badge');
            if (badgeEl && tabKey !== 'all') {
                var cnt = state.unreadByTab[tabKey] || 0;
                if (cnt > 0) {
                    badgeEl.textContent = cnt > 99 ? '99+' : String(cnt);
                    badgeEl.hidden = false;
                } else {
                    badgeEl.hidden = true;
                }
            } else if (badgeEl && tabKey === 'all') {
                var total = displayCount;
                if (total > 0) {
                    badgeEl.textContent = total > 99 ? '99+' : String(total);
                    badgeEl.hidden = false;
                } else {
                    badgeEl.hidden = true;
                }
            }
        });

        var list  = wrap.querySelector('.notifier-bell-list');
        var empty = wrap.querySelector('.notifier-bell-empty');
        list.innerHTML = '';

        var items = visibleItems();
        if (!items.length) {
            empty.hidden = false;
            return;
        }
        empty.hidden = true;

        var groups = groupItems(items);
        groups.forEach(function(group) {
            list.appendChild(buildGroupNode(group));
        });
    }

    function buildGroupNode(group) {
        var unreadEvents = group.events.filter(function(e) { return !e.is_read; });
        var groupUnread  = unreadEvents.length > 0;
        var primary      = group.events[0];
        var batched      = group.events.length > 1;
        var isExpanded   = batched && state.expanded.has(group.key);

        var li = document.createElement('li');
        li.className = 'notifier-bell-group notifier-event-' + escapeHtml(primary.event)
            + (groupUnread ? ' is-unread' : ' is-read')
            + (batched     ? ' is-batched' : '')
            + (isExpanded  ? ' is-expanded' : '');
        li.dataset.key = group.key;
        li.dataset.url = group.url;

        var toggleAction = groupUnread ? 'group-read' : 'group-unread';
        var toggleIcon   = groupUnread ? 'fa-check'   : 'fa-rotate-left';
        var toggleLabel  = groupUnread ? T.markAsRead : T.markAsUnread;
        var toggleHtml = '<button type="button" class="notifier-bell-item-toggle"'
            + ' data-action="' + toggleAction + '"'
            + ' title="' + toggleLabel + '" aria-label="' + toggleLabel + '">'
            + '<i class="fas ' + toggleIcon + '"></i>'
            + '</button>';

        var expandHtml = '';
        var metaExtra  = '';
        if (batched) {
            var label = isExpanded ? T.collapseGroup : T.expandGroup;
            expandHtml = '<button type="button" class="notifier-bell-group-expand"'
                + ' data-action="expand"'
                + ' aria-expanded="' + (isExpanded ? 'true' : 'false') + '"'
                + ' title="' + escapeHtml(label) + '" aria-label="' + escapeHtml(label) + '">'
                + '<i class="fas fa-chevron-down"></i>'
                + '</button>';
            metaExtra = ' <span class="notifier-bell-group-meta-count">· '
                + escapeHtml(formatGroupedUpdates(group.events.length))
                + '</span>';
        }

        var header = document.createElement('div');
        header.className = 'notifier-bell-item notifier-bell-group-header'
            + (groupUnread ? ' is-unread' : ' is-read');
        header.innerHTML = ''
            + '<div class="notifier-bell-item-icon"><i class="fas ' + eventIcon(primary.event) + '"></i></div>'
            + '<div class="notifier-bell-item-body">'
            +   '<div class="notifier-bell-item-title">' + escapeHtml(group.title) + '</div>'
            +   '<div class="notifier-bell-item-msg">'
            +     (primary.actor_name ? '<strong>' + escapeHtml(primary.actor_name) + '</strong> ' : '')
            +     escapeHtml(primary.message)
            +   '</div>'
            +   '<div class="notifier-bell-item-meta">'
            +     statusBadgeHtml(primary.ticket_status)
            +     (primary.ticket_status ? ' &nbsp;' : '')
            +     escapeHtml(timeAgo(primary.date_creation))
            +     metaExtra
            +   '</div>'
            + '</div>'
            + expandHtml
            + toggleHtml;
        li.appendChild(header);

        if (isExpanded) {
            var ul = document.createElement('ul');
            ul.className = 'notifier-bell-subs';
            group.events.forEach(function(ev) {
                ul.appendChild(buildSubNode(ev));
            });
            li.appendChild(ul);
        }

        return li;
    }

    function buildSubNode(item) {
        var li = document.createElement('li');
        li.className = 'notifier-bell-sub-item notifier-event-' + escapeHtml(item.event)
            + (item.is_read ? ' is-read' : ' is-unread');
        li.dataset.id = item.id;
        li.dataset.url = item.url;

        var toggleAction = item.is_read ? 'unread' : 'read';
        var toggleIcon   = item.is_read ? 'fa-rotate-left' : 'fa-check';
        var toggleLabel  = item.is_read ? T.markAsUnread : T.markAsRead;
        var toggleHtml = '<button type="button" class="notifier-bell-item-toggle"'
            + ' data-action="' + toggleAction + '"'
            + ' title="' + toggleLabel + '" aria-label="' + toggleLabel + '">'
            + '<i class="fas ' + toggleIcon + '"></i>'
            + '</button>';

        li.innerHTML = ''
            + '<div class="notifier-bell-sub-icon"><i class="fas ' + eventIcon(item.event) + '"></i></div>'
            + '<div class="notifier-bell-sub-body">'
            +   '<div class="notifier-bell-sub-msg">'
            +     (item.actor_name ? '<strong>' + escapeHtml(item.actor_name) + '</strong> ' : '')
            +     escapeHtml(item.message)
            +   '</div>'
            +   '<div class="notifier-bell-item-meta">' + escapeHtml(timeAgo(item.date_creation)) + '</div>'
            + '</div>'
            + toggleHtml;
        return li;
    }

    function eventIcon(event) {
        switch (event) {
            case 'assigned':       return 'fa-user-check';
            case 'commented':      return 'fa-comment-dots';
            case 'status_changed': return 'fa-arrows-rotate';
            case 'solution':       return 'fa-lightbulb';
            case 'task_added':     return 'fa-list-check';
            case 'created':        return 'fa-plus';
            case 'updated':        return 'fa-pen';
            default:               return 'fa-bell';
        }
    }

    var TICKET_STATUS = {
        1: { label: 'Novo',        color: '#4caf50', icon: 'fa-circle' },
        2: { label: 'Em andamento', color: '#2196f3', icon: 'fa-circle' },
        3: { label: 'Em andamento', color: '#2196f3', icon: 'fa-circle' },
        4: { label: 'Pendente',    color: '#ff9800', icon: 'fa-circle' },
        5: { label: 'Resolvido',   color: '#9c27b0', icon: 'fa-circle' },
        6: { label: 'Fechado',     color: '#607d8b', icon: 'fa-circle' },
    };

    function statusBadgeHtml(status) {
        if (!status) { return ''; }
        var s = TICKET_STATUS[status];
        if (!s) { return ''; }
        return '<span class="notifier-status-badge" style="color:' + s.color + '">'
            + '<i class="fas fa-circle"></i> ' + escapeHtml(s.label)
            + '</span>';
    }

    // GET, not POST: GLPI 11's Symfony CheckCsrfListener only runs on POST
    // routes. Endpoint is still session + ownership protected.
    function fireMarkRead(id) {
        // Negative IDs are synthetic (reactive unassigned tickets).
        // Persist a read row in the DB so it survives the next poll.
        if (id < 0) {
            var ticketsId = -id;
            // Optimistically flip local state immediately
            state.items = state.items.map(function(item) {
                if (item.id === id) { return Object.assign({}, item, { is_read: true }); }
                return item;
            });
            return fetchJson(BASE_URL + '/ajax/markreadreactive.php?tickets_id=' + encodeURIComponent(ticketsId))
                .catch(function(err) {
                    if (window.console) console.warn('[notifier] markreadreactive failed:', err);
                });
        }
        return fetchJson(BASE_URL + '/ajax/markread.php?id=' + encodeURIComponent(id))
            .catch(function(err) {
                if (window.console) console.warn('[notifier] markread failed:', err);
            });
    }

    function fireMarkUnread(id) {
        if (id < 0) {
            state.items = state.items.map(function(item) {
                if (item.id === id) { return Object.assign({}, item, { is_read: false }); }
                return item;
            });
            return Promise.resolve();
        }
        return fetchJson(BASE_URL + '/ajax/markunread.php?id=' + encodeURIComponent(id))
            .catch(function(err) {
                if (window.console) console.warn('[notifier] markunread failed:', err);
            });
    }

    // ---------------------------------------------------------------- preferences modal

    function buildPreferencesModal() {
        var overlay = document.createElement('div');
        overlay.className = 'notifier-modal-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', T.preferencesTitle);
        overlay.hidden = true;

        var rows = PREF_TYPES.map(function(p) {
            return ''
                + '<tr>'
                +   '<th scope="row">' + escapeHtml(T[p.typeLabelKey] || p.slug) + '</th>'
                +   '<td>'
                +     '<label class="notifier-switch" title="' + escapeHtml(T.colDirect) + '">'
                +       '<input type="checkbox" data-pref="' + p.direct + '" checked>'
                +       '<span class="notifier-switch-slider"></span>'
                +     '</label>'
                +   '</td>'
                +   '<td>'
                +     '<label class="notifier-switch" title="' + escapeHtml(T.colGroup) + '">'
                +       '<input type="checkbox" data-pref="' + p.group + '" checked>'
                +       '<span class="notifier-switch-slider"></span>'
                +     '</label>'
                +   '</td>'
                + '</tr>';
        }).join('');

        overlay.innerHTML = ''
            + '<div class="notifier-modal">'
            +   '<div class="notifier-modal-header">'
            +     '<h3 class="notifier-modal-title"><i class="fas fa-cog"></i> ' + escapeHtml(T.preferencesTitle) + '</h3>'
            +     '<button type="button" class="notifier-modal-close" aria-label="' + escapeHtml(T.close) + '" title="' + escapeHtml(T.close) + '">'
            +       '<i class="fas fa-times"></i>'
            +     '</button>'
            +   '</div>'
            +   '<div class="notifier-modal-body">'
            +     '<p class="notifier-modal-intro">' + escapeHtml(T.preferencesIntro) + '</p>'
            +     '<table class="notifier-pref-table">'
            +       '<thead>'
            +         '<tr>'
            +           '<th></th>'
            +           '<th>' + escapeHtml(T.colDirect) + '</th>'
            +           '<th>' + escapeHtml(T.colGroup) + '</th>'
            +         '</tr>'
            +       '</thead>'
            +       '<tbody>' + rows + '</tbody>'
            +     '</table>'
            +     '<div class="notifier-pref-sound">'
            +       '<label class="notifier-pref-sound-label">'
            +         '<i class="fas fa-volume-up"></i> ' + escapeHtml(T.soundEnabled || 'Som de notificação') + '</label>'
            +       '<label class="notifier-switch">'
            +         '<input type="checkbox" data-pref="sound_enabled" checked>'
            +         '<span class="notifier-switch-slider"></span>'
            +       '</label>'
            +     '</div>'
            +     '<div class="notifier-pref-sound-subtoggle" id="notifier-sound-subtoggle">'
            +       '<div class="notifier-sound-sub-item">'
            +         '<label class="notifier-pref-sound-sublabel"><i class="fas fa-plus-circle"></i> ' + escapeHtml(T.tabNew || 'Novos') + '</label>'
            +         '<label class="notifier-switch notifier-switch-sm"><input type="checkbox" data-pref="sound_new" checked><span class="notifier-switch-slider"></span></label>'
            +       '</div>'
            +       '<div class="notifier-sound-sub-item">'
            +         '<label class="notifier-pref-sound-sublabel"><i class="fas fa-user"></i> ' + escapeHtml(T.tabMine || 'Meus') + '</label>'
            +         '<label class="notifier-switch notifier-switch-sm"><input type="checkbox" data-pref="sound_mine" checked><span class="notifier-switch-slider"></span></label>'
            +       '</div>'
            +       '<div class="notifier-sound-sub-item">'
            +         '<label class="notifier-pref-sound-sublabel"><i class="fas fa-users"></i> ' + escapeHtml(T.tabTeam || 'Equipe') + '</label>'
            +         '<label class="notifier-switch notifier-switch-sm"><input type="checkbox" data-pref="sound_team" checked><span class="notifier-switch-slider"></span></label>'
            +       '</div>'
            +       (state.canSeeOthers ? '<div class="notifier-sound-sub-item">'
            +         '<label class="notifier-pref-sound-sublabel"><i class="fas fa-bell"></i> ' + escapeHtml(T.tabOther || 'Demais') + '</label>'
            +         '<label class="notifier-switch notifier-switch-sm"><input type="checkbox" data-pref="sound_other" checked><span class="notifier-switch-slider"></span></label>'
            +       '</div>' : '')
            +       (state.showResolved || state.showClosed ? '<div class="notifier-sound-sub-item">'
            +         '<label class="notifier-pref-sound-sublabel notifier-sound-ended-label"><i class="fas fa-check-circle"></i> ' + escapeHtml(state.resolvedClosedStyle === 'ended' ? (T.tabEnded || 'Encerrados') : (T.tabResolved || 'Resolvidos') + ' / ' + (T.tabClosed || 'Fechados')) + '</label>'
            +         '<label class="notifier-switch notifier-switch-sm"><input type="checkbox" data-pref="sound_ended"><span class="notifier-switch-slider"></span></label>'
            +       '</div>' : '')
            +     '</div>'
            +     (state.canSeeOthers ? '<div class="notifier-pref-sound">'
            +       '<label class="notifier-pref-sound-label">'
            +         '<i class="fas fa-bell"></i> ' + escapeHtml(T.notifyOthers || 'Notificar movimentações em chamados que tenho acesso') + '</label>'
            +       '<label class="notifier-switch">'
            +         '<input type="checkbox" data-pref="notify_others">'
            +         '<span class="notifier-switch-slider"></span>'
            +       '</label>'
            +     '</div>' : '')
            +     '<div class="notifier-pref-sound">'
            +       '<label class="notifier-pref-sound-label">'
            +         '<i class="fas fa-edit"></i> ' + escapeHtml(T.updateMessageStyle || 'Estilo de mensagem de atualização') + '</label>'
            +       '<select class="notifier-pref-select" data-pref="update_message_style">'
            +         '<option value="priority">' + escapeHtml(T.updateStylePriority || 'Mensagem prioritária') + '</option>'
            +         '<option value="combined">' + escapeHtml(T.updateStyleCombined || 'Mensagens combinadas') + '</option>'
            +         '<option value="multiple">' + escapeHtml(T.updateStyleMultiple || 'Múltiplas alterações') + '</option>'
            +       '</select>'
            +     '</div>'
            +     '<div class="notifier-pref-sound">'
            +       '<label class="notifier-pref-sound-label">'
            +         '<i class="fas fa-check-circle"></i> ' + escapeHtml(T.showResolved || 'Mostrar chamados resolvidos') + '</label>'
            +       '<label class="notifier-switch">'
            +         '<input type="checkbox" data-pref="show_resolved" checked>'
            +         '<span class="notifier-switch-slider"></span>'
            +       '</label>'
            +     '</div>'
            +     '<div class="notifier-pref-sound">'
            +       '<label class="notifier-pref-sound-label">'
            +         '<i class="fas fa-lock"></i> ' + escapeHtml(T.showClosed || 'Mostrar chamados fechados') + '</label>'
            +       '<label class="notifier-switch">'
            +         '<input type="checkbox" data-pref="show_closed" checked>'
            +         '<span class="notifier-switch-slider"></span>'
            +       '</label>'
            +     '</div>'
            +     '<div class="notifier-pref-sound">'
            +       '<label class="notifier-pref-sound-label">'
            +         '<i class="fas fa-layer-group"></i> ' + escapeHtml(T.resolvedClosedStyle || 'Exibição de encerrados') + '</label>'
            +       '<select class="notifier-pref-select" data-pref="resolved_closed_style">'
            +         '<option value="separate">' + escapeHtml(T.resolvedClosedSeparate || 'Separados (Resolvidos | Fechados)') + '</option>'
            +         '<option value="ended">' + escapeHtml(T.resolvedClosedEnded || 'Unificados (Encerrados)') + '</option>'
            +       '</select>'
            +     '</div>'
            +     '<div class="notifier-modal-toast" hidden><i class="fas fa-check-circle"></i> ' + escapeHtml(T.saved) + '</div>'
            +   '</div>'
            +   '<div class="notifier-modal-footer">'
            +     '<button type="button" class="notifier-btn notifier-btn-secondary" data-action="cancel">' + escapeHtml(T.cancel) + '</button>'
            +     '<button type="button" class="notifier-btn notifier-btn-primary" data-action="save">' + escapeHtml(T.save) + '</button>'
            +   '</div>'
            + '</div>';

        return overlay;
    }

    function ensurePreferencesModal() {
        var existing = document.querySelector('.notifier-modal-overlay');
        if (existing) return existing;
        var overlay = buildPreferencesModal();
        document.body.appendChild(overlay);
        wirePreferencesModal(overlay);
        return overlay;
    }

    function wirePreferencesModal(overlay) {
        var modal = overlay.querySelector('.notifier-modal');

        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                closePreferences(overlay);
            }
        });
        modal.addEventListener('click', function(e) { e.stopPropagation(); });

        overlay.querySelector('.notifier-modal-close').addEventListener('click', function() {
            closePreferences(overlay);
        });
        overlay.querySelector('[data-action="cancel"]').addEventListener('click', function() {
            closePreferences(overlay);
        });
        overlay.querySelector('[data-action="save"]').addEventListener('click', function() {
            savePreferences(overlay);
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !overlay.hidden) {
                closePreferences(overlay);
            }
        });
    }

    function loadSoundPref() {
        fetchJson(BASE_URL + '/ajax/preferences.php')
            .then(function(resp) {
                var prefs = (resp && resp.preferences) || {};
                state.soundEnabled = prefs['sound_enabled'] === undefined ? true : !!+prefs['sound_enabled'];

                // Update sound_ended label dynamically based on resolved_closed_style
                var endedLabel = overlay.querySelector('.notifier-sound-ended-label');
                if (endedLabel) {
                    var rcs = prefs['resolved_closed_style'];
                    var isEnded = (rcs && rcs !== '0') ? rcs === 'ended' : state.resolvedClosedStyle === 'ended';
                    endedLabel.textContent = isEnded ? (T.tabEnded || 'Encerrados') : (T.tabResolved || 'Resolvidos') + ' / ' + (T.tabClosed || 'Fechados');
                }
            })
            .catch(function() {});
    }

    function openPreferences() {
        var overlay = ensurePreferencesModal();
        overlay.hidden = false;
        fetchJson(BASE_URL + '/ajax/preferences.php')
            .then(function(resp) {
                var prefs = (resp && resp.preferences) || {};
                // Fields that default to OFF (0) when not set
                var defaultOff = { 'notify_others': true, 'sound_ended': true }; // sound_ended defaults to OFF
                overlay.querySelectorAll('input[data-pref]').forEach(function(input) {
                    var key = input.dataset.pref;
                    if (prefs[key] === undefined) {
                        input.checked = !defaultOff[key];
                    } else {
                        input.checked = !!+prefs[key];
                    }
                });
                // Load string prefs - must use string value not numeric
                var selectDefaults = { 'update_message_style': 'priority', 'resolved_closed_style': 'separate' };
                overlay.querySelectorAll('select[data-pref]').forEach(function(select) {
                    var key = select.dataset.pref;
                    var raw = prefs[key];
                    // If server returned numeric 0, use default instead
                    var val = (raw !== undefined && raw !== '0' && raw !== 0) ? String(raw) : selectDefaults[key];
                    select.value = val || selectDefaults[key] || '';
                });
                state.soundEnabled = prefs['sound_enabled'] === undefined ? true : !!+prefs['sound_enabled'];
            })
            .catch(function() { /* keep defaults */ });
    }

    function closePreferences(overlay) {
        overlay.hidden = true;
        var toast = overlay.querySelector('.notifier-modal-toast');
        if (toast) toast.hidden = true;
    }

    function savePreferences(overlay) {
        var params = new URLSearchParams();
        params.append('save', '1');
        overlay.querySelectorAll('input[data-pref]').forEach(function(input) {
            params.append(input.dataset.pref, input.checked ? '1' : '0');
        });
        overlay.querySelectorAll('select[data-pref]').forEach(function(select) {
            params.append(select.dataset.pref, select.value);
        });

        // If user just enabled resolved/closed tabs, mark existing notifications as read
        // so they start fresh without being flooded by history
        var showResolved = overlay.querySelector('input[data-pref="show_resolved"]');
        var showClosed = overlay.querySelector('input[data-pref="show_closed"]');
        if (showResolved && showResolved.checked && !state.showResolved) {
            fetchJson(BASE_URL + '/ajax/markresolved.php').catch(function() {});
        }
        if (showClosed && showClosed.checked && !state.showClosed) {
            fetchJson(BASE_URL + '/ajax/markclosed.php').catch(function() {});
        }
        var saveBtn = overlay.querySelector('[data-action="save"]');
        saveBtn.disabled = true;

        // Toggle visibility of sound sub-options when main sound toggle changes
        var soundMainToggle = overlay.querySelector('input[data-pref="sound_enabled"]');
        var soundSubtoggle = overlay.querySelector('#notifier-sound-subtoggle');
        if (soundMainToggle && soundSubtoggle) {
            soundSubtoggle.style.display = soundMainToggle.checked ? '' : 'none';
            soundMainToggle.addEventListener('change', function() {
                soundSubtoggle.style.display = this.checked ? '' : 'none';
            });
        }

        // Mint a fresh token: preferences.php rejects without it.
        fetchJson(BASE_URL + '/ajax/csrftoken.php')
            .then(function(r) {
                params.append('_glpi_csrf_token', r && r.token ? r.token : '');
                return fetchJson(BASE_URL + '/ajax/preferences.php?' + params.toString());
            })
            .then(function() {
                var toast = overlay.querySelector('.notifier-modal-toast');
                if (toast) {
                    toast.hidden = false;
                    setTimeout(function() { toast.hidden = true; }, 1800);
                }
                setTimeout(function() { closePreferences(overlay); }, 900);
            })
            .catch(function(err) {
                if (window.console) console.error('[notifier] save preferences failed:', err);
            })
            .then(function() { saveBtn.disabled = false; });
    }

    // ---------------------------------------------------------------- events

    function wireEvents(wrap) {
        var btn      = wrap.querySelector('.notifier-bell-btn');
        var panel    = wrap.querySelector('.notifier-bell-panel');
        var list     = wrap.querySelector('.notifier-bell-list');
        var markAll  = wrap.querySelector('.notifier-bell-markall');
        var minimize = wrap.querySelector('.notifier-bell-minimize');
        var restore  = wrap.querySelector('.notifier-bell-restore');
        var settings = wrap.querySelector('.notifier-bell-settings');
        var tabs     = wrap.querySelectorAll('.notifier-bell-tab');

        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (wrap.classList.contains('is-collapsed')) {
                setCollapsed(wrap, false);
                panel.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                refresh();
                return;
            }
            var open = !panel.hidden;
            panel.hidden = open;
            btn.setAttribute('aria-expanded', String(!open));
            if (!open) refresh();
        });

        if (restore) {
            restore.addEventListener('click', function(e) {
                e.stopPropagation();
                setCollapsed(wrap, false);
                panel.hidden = false;
                btn.setAttribute('aria-expanded', 'true');
                refresh();
            });
        }

        if (minimize) {
            minimize.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                setCollapsed(wrap, true);
            });
        }

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var t = tab.dataset.tab;
                state.tab = VALID_TABS.indexOf(t) >= 0 ? t : 'all';
                saveTab(state.tab);
                render();
            });
        });

        if (settings) {
            settings.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                openPreferences();
            });
        }

        document.addEventListener('click', function(e) {
            if (panel.hidden) return;
            if (wrap.contains(e.target)) return;
            var overlay = document.querySelector('.notifier-modal-overlay');
            if (overlay && !overlay.hidden && overlay.contains(e.target)) return;
            panel.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
        });

        list.addEventListener('click', function(e) {
            var subLi = e.target.closest('.notifier-bell-sub-item');
            if (subLi) {
                e.stopPropagation();
                var subId = parseInt(subLi.dataset.id, 10);
                if (!subId) return;

                var subToggle = e.target.closest('.notifier-bell-item-toggle');
                if (subToggle) {
                    e.preventDefault();
                    var subOp = subToggle.dataset.action === 'unread' ? fireMarkUnread : fireMarkRead;
                    subOp(subId).then(refresh);
                    return;
                }

                var subUrl = subLi.dataset.url;
                if (subUrl && isSameOrigin(subUrl)) {
                    e.preventDefault();
                    fireMarkRead(subId).then(function() {
                        window.location.href = subUrl;
                    });
                } else {
                    fireMarkRead(subId).then(refresh);
                }
                return;
            }

            var groupLi = e.target.closest('.notifier-bell-group');
            if (!groupLi) return;
            var key = groupLi.dataset.key;
            if (!key) return;

            var expandBtn = e.target.closest('.notifier-bell-group-expand');
            if (expandBtn) {
                e.preventDefault();
                e.stopPropagation();
                if (state.expanded.has(key)) state.expanded.delete(key);
                else                         state.expanded.add(key);
                render();
                return;
            }

            var toggleBtn = e.target.closest('.notifier-bell-item-toggle');
            if (toggleBtn) {
                e.preventDefault();
                e.stopPropagation();
                var grpEvents = eventsForKey(key);
                if (!grpEvents.length) return;

                var ops;
                if (toggleBtn.dataset.action === 'group-read') {
                    ops = grpEvents
                        .filter(function(ev) { return !ev.is_read; })
                        .map(function(ev) { return fireMarkRead(ev.id); });
                } else {
                    ops = grpEvents
                        .filter(function(ev) { return ev.is_read; })
                        .map(function(ev) { return fireMarkUnread(ev.id); });
                }
                Promise.all(ops).then(refresh);
                return;
            }

            // Body click → navigate. Open-redirect guard: the `url` column
            // is a VARCHAR(500) with no DB constraint, refuse off-origin
            // even though getFormURLWithID() should never produce one.
            var url = groupLi.dataset.url;
            var unreadIds = eventsForKey(key)
                .filter(function(ev) { return !ev.is_read; })
                .map(function(ev) { return ev.id; });
            var markPromises = unreadIds.map(function(id) { return fireMarkRead(id); });

            if (url && isSameOrigin(url)) {
                e.preventDefault();
                Promise.all(markPromises).then(function() {
                    window.location.href = url;
                });
            } else {
                Promise.all(markPromises).then(refresh);
            }
        });

        markAll.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            // If on 'all' tab, mark everything. Otherwise mark only items
            // visible in the current tab (by category).
            if (state.tab === 'all') {
                fetchJson(BASE_URL + '/ajax/markallread.php')
                    .then(refresh)
                    .catch(function(err) {
                        if (window.console) console.error('[notifier] mark all as read failed:', err);
                    });
            } else {
                // Get IDs of unread items in the current tab
                var tabItems = state.items.filter(function(item) {
                    return !item.is_read && item.category === state.tab;
                });

                if (!tabItems.length) { return; }

                // Mark each one individually
                var promises = tabItems.map(function(item) {
                    if (item.id < 0) {
                        return fetchJson(BASE_URL + '/ajax/markreadreactive.php?tickets_id=' + (-item.id))
                            .catch(function() {});
                    }
                    return fetchJson(BASE_URL + '/ajax/markread.php?id=' + item.id)
                        .catch(function() {});
                });

                Promise.all(promises).then(refresh).catch(function(err) {
                    if (window.console) console.error('[notifier] mark tab as read failed:', err);
                });
            }
        });
    }

    // ---------------------------------------------------------------- polling

    function refresh() {
        if (pollInFlight) return;
        pollInFlight = true;
        fetchJson(BASE_URL + '/ajax/list.php').then(function(data) {
            state.items        = (data && data.items) || [];
            state.unread       = (data && data.unread) || 0;
            state.unreadGroups = (data && data.unread_groups) || 0;
            state.unreadByTab       = (data && data.unread_by_tab) || { new: 0, mine: 0, team: 0, other: 0, resolved: 0, closed: 0, ended: 0 };
            state.canSeeOthers      = !!(data && data.can_see_others);
            state.showResolved      = data && data.show_resolved !== undefined ? !!data.show_resolved : true;
            state.showClosed        = data && data.show_closed !== undefined ? !!data.show_closed : true;
            state.resolvedClosedStyle = (data && data.resolved_closed_style) || 'separate';
            state.soundByTab = {
                new:   data && data.sound_new   !== undefined ? !!data.sound_new   : true,
                mine:  data && data.sound_mine  !== undefined ? !!data.sound_mine  : true,
                team:  data && data.sound_team  !== undefined ? !!data.sound_team  : true,
                other: data && data.sound_other !== undefined ? !!data.sound_other : true,
                ended: data && data.sound_ended !== undefined ? !!data.sound_ended : false,
            };

            // Recalculate unread counts excluding hidden categories
            if (!state.showResolved) {
                state.unreadByTab.resolved = 0;
            }
            if (!state.showClosed) {
                state.unreadByTab.closed = 0;
            }
            if (!state.canSeeOthers) {
                state.unreadByTab.other = 0;
            }
            // ended tab badge = resolved + closed
            state.unreadByTab.ended = (state.unreadByTab.resolved || 0) + (state.unreadByTab.closed || 0);
            // Recalculate total
            state.unread = state.unreadByTab.new + state.unreadByTab.mine +
                           state.unreadByTab.team + state.unreadByTab.other +
                           state.unreadByTab.resolved + state.unreadByTab.closed;
            state.unreadGroups = state.unread;

            // Play sound when new notifications arrive — check per-tab sound preferences
            if (lastUnreadGroups !== null && state.soundEnabled !== false) {
                var prevByTab = lastUnreadByTab || {};
                var shouldPlay = false;
                ['new', 'mine', 'team'].forEach(function(tab) {
                    if ((state.unreadByTab[tab] || 0) > (prevByTab[tab] || 0) && state.soundByTab[tab]) {
                        shouldPlay = true;
                    }
                });
                if ((state.unreadByTab.other || 0) > (prevByTab.other || 0) && state.soundByTab.other && state.canSeeOthers) {
                    shouldPlay = true;
                }
                var endedNow = (state.unreadByTab.resolved || 0) + (state.unreadByTab.closed || 0);
                var endedPrev = (prevByTab.resolved || 0) + (prevByTab.closed || 0);
                if (endedNow > endedPrev && state.soundByTab.ended) {
                    shouldPlay = true;
                }
                if (shouldPlay) { playNotificationSound(); }
            }
            lastUnreadGroups = state.unreadGroups;
            lastUnreadByTab  = Object.assign({}, state.unreadByTab);

            render();
        }).catch(function() {
            /* leave previous state intact */
        }).then(function() {
            pollInFlight = false;
        });
    }

    function startPolling() {
        if (pollTimer) return;
        refresh();
        pollTimer = setInterval(refresh, POLL_INTERVAL_MS);
    }

    // ---------------------------------------------------------------- boot

    // If the current page is a ticket form, mark that ticket as read
    // in the bell — covers the case where the user opens a ticket directly
    // from the GLPI list instead of clicking through the bell dropdown.
    function markCurrentTicketRead() {
        var match = window.location.href.match(/ticket\.form\.php.*[?&]id=(\d+)/i);
        if (!match) { return; }
        var ticketId = parseInt(match[1], 10);
        if (!ticketId) { return; }

        // Wait for the first poll to populate state, then mark.
        // We retry a few times in case the poll hasn't fired yet.
        var attempts = 0;
        function tryMark() {
            attempts++;
            var found = false;

            // Check stored notifications for this ticket
            state.items.forEach(function(item) {
                if (item.itemtype === 'Ticket' && item.items_id === ticketId && !item.is_read) {
                    found = true;
                    if (item.id > 0) {
                        fireMarkRead(item.id);
                    } else {
                        fireMarkRead(-ticketId); // reactive
                    }
                }
            });

            // Also try reactive mark regardless (cheap, idempotent)
            if (!found) {
                fetchJson(BASE_URL + '/ajax/markreadreactive.php?tickets_id=' + ticketId)
                    .catch(function() {});
            }
        }

        // Try immediately and after first poll
        setTimeout(tryMark, 1500);
        setTimeout(tryMark, 5000);
    }

    function mountAndStart() {
        installBell();
        var wrap = document.querySelector('.notifier-bell-wrap');
        if (wrap && isStoredCollapsed()) {
            setCollapsed(wrap, true);
        }

        // Unlock audio API on first user interaction — browsers block
        // autoplay until the user clicks something on the page.
        document.addEventListener('click', function unlockAudio() {
            try {
                var a = new Audio();
                a.play().catch(function() {});
            } catch (e) {}
            document.removeEventListener('click', unlockAudio);
        }, { once: true });

        loadSoundPref();
        markCurrentTicketRead();
        startPolling();
    }

    function boot() {
        BASE_URL = resolveBaseUrl();
        // Hydrate T before mount so the first paint is in-language.
        fetchJson(BASE_URL + '/ajax/i18n.php')
            .then(function(dict) {
                Object.keys(dict || {}).forEach(function(k) { T[k] = dict[k]; });
            })
            .catch(function() { /* English fallbacks */ })
            .then(mountAndStart);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
