<?php

if (!defined('GLPI_ROOT')) {
    include(dirname(__DIR__, 3) . '/inc/includes.php');
}

Session::checkLoginUser();

Html::header(
    __('Notificações', 'notifier'),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    'notifier',
    'notifications'
);

$users_id       = (int)Session::getLoginUserID();
$can_see_others = GlpiPlugin\Notifier\Notification::canSeeOthers($users_id);
$prefs          = GlpiPlugin\Notifier\Notification::getPreferences($users_id);
$show_resolved  = (bool)($prefs['show_resolved'] ?? 1);
$show_closed    = (bool)($prefs['show_closed'] ?? 1);

// URLs defined in JS via CFG_GLPI to avoid Plugin::getWebDir issues
?>

<div class="notifier-page container-fluid">

  <div class="notifier-page-header d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
      <a href="javascript:history.back()" class="btn btn-sm btn-outline-secondary me-1" title="Voltar">
        <i class="ti ti-arrow-left"></i>
      </a>
      <i class="ti ti-bell fs-4"></i>
      <h1 class="h4 mb-0"><?= __('Notificações', 'notifier') ?></h1>
      <span class="notifier-page-unread-badge badge bg-danger" style="display:none;"></span>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
      <label class="d-flex align-items-center gap-1 small text-muted" style="cursor:pointer;">
        <input type="checkbox" id="notifier-show-read" class="form-check-input m-0">
        <?= __('Mostrar lidas', 'notifier') ?>
      </label>
      <button class="btn btn-sm btn-outline-secondary" id="notifier-mark-all-read">
        <i class="ti ti-checks me-1"></i><?= __('Marcar tudo como lido', 'notifier') ?>
      </button>
      <select class="form-select form-select-sm w-auto" id="notifier-filter-type">
        <option value=""><?= __('Todos os status', 'notifier') ?></option>
        <option value="1"><?= __('Novo', 'notifier') ?></option>
        <option value="2"><?= __('Em andamento', 'notifier') ?></option>
        <option value="3"><?= __('Planejado', 'notifier') ?></option>
        <option value="4"><?= __('Pendente', 'notifier') ?></option>
        <option value="5"><?= __('Resolvido', 'notifier') ?></option>
        <option value="6"><?= __('Fechado', 'notifier') ?></option>
      </select>
      <select class="form-select form-select-sm w-auto" id="notifier-filter-period">
        <option value=""><?= __('Todos os períodos', 'notifier') ?></option>
        <option value="today"><?= __('Hoje', 'notifier') ?></option>
        <option value="week"><?= __('Últimos 7 dias', 'notifier') ?></option>
        <option value="month"><?= __('Últimos 30 dias', 'notifier') ?></option>
      </select>
    </div>
  </div>

  <div class="notifier-page-tabs d-flex gap-2 flex-wrap mb-3" id="notifier-tabs">
    <button class="btn btn-sm btn-outline-info active" data-tab="all"><?= __('Todos', 'notifier') ?> <span class="badge ms-1 notifier-tab-badge" data-tab="all">0</span></button>
    <button class="btn btn-sm btn-outline-secondary" data-tab="new"><?= __('Novos', 'notifier') ?> <span class="badge ms-1 notifier-tab-badge" data-tab="new">0</span></button>
    <button class="btn btn-sm btn-outline-secondary" data-tab="mine"><?= __('Meus', 'notifier') ?> <span class="badge ms-1 notifier-tab-badge" data-tab="mine">0</span></button>
    <button class="btn btn-sm btn-outline-secondary" data-tab="team"><?= __('Equipe', 'notifier') ?> <span class="badge ms-1 notifier-tab-badge" data-tab="team">0</span></button>
    <?php if ($can_see_others): ?>
    <button class="btn btn-sm btn-outline-secondary" data-tab="other"><?= __('Demais', 'notifier') ?> <span class="badge ms-1 notifier-tab-badge" data-tab="other">0</span></button>
    <?php endif; ?>
    <?php if ($show_resolved): ?>
    <button class="btn btn-sm btn-outline-secondary" data-tab="resolved"><?= __('Resolvidos', 'notifier') ?> <span class="badge ms-1 notifier-tab-badge" data-tab="resolved">0</span></button>
    <?php endif; ?>
    <?php if ($show_closed): ?>
    <button class="btn btn-sm btn-outline-secondary" data-tab="closed"><?= __('Fechados', 'notifier') ?> <span class="badge ms-1 notifier-tab-badge" data-tab="closed">0</span></button>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="notifier-table">
        <thead class="table-light">
          <tr>
            <th><?= __('Chamado', 'notifier') ?></th>
            <th style="width:70px;"><?= __('N°', 'notifier') ?></th>
            <th style="width:100px;"><?= __('Data / Hora', 'notifier') ?></th>
            <th style="width:90px;"><?= __('Status', 'notifier') ?></th>
            <th style="width:80px;"><?= __('Prioridade', 'notifier') ?></th>
            <th style="width:120px;"><?= __('Entidade', 'notifier') ?></th>
            <th style="width:110px;"><?= __('Requerente', 'notifier') ?></th>
            <th style="width:120px;"><?= __('Tipo', 'notifier') ?></th>
            <th style="width:100px;"><?= __('Usuário', 'notifier') ?></th>
            <th style="width:110px;"><?= __('Evento', 'notifier') ?></th>
            <th><?= __('Detalhe', 'notifier') ?></th>
            <th style="width:90px;"><?= __('Ações', 'notifier') ?></th>
          </tr>
        </thead>
        <tbody id="notifier-tbody">
          <tr><td colspan="12" class="text-center text-muted py-4">
            <div class="spinner-border spinner-border-sm me-2"></div>
            <?= __('Carregando...', 'notifier') ?>
          </td></tr>
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
      <span class="small text-muted" id="notifier-count-label"></span>
      <div class="d-flex align-items-center gap-2">
        <div id="notifier-pagination" class="d-flex gap-1"></div>
        <select class="form-select form-select-sm w-auto" id="notifier-per-page">
          <option value="10">10</option>
          <option value="25">25</option>
          <option value="50">50</option>
        </select>
      </div>
    </div>
  </div>

</div>

<style>
.notifier-page { padding: 1.5rem 0; }
.notifier-page-event-badge { font-size: 11px; font-weight: 500; padding: 3px 8px; border-radius: 6px; white-space: nowrap; }
.notifier-page-event-created       { background: #E1F5EE; color: #0F6E56; }
.notifier-page-event-assigned      { background: #E6F1FB; color: #185FA5; }
.notifier-page-event-commented     { background: #E6F1FB; color: #185FA5; }
.notifier-page-event-task_added    { background: #E6F1FB; color: #185FA5; }
.notifier-page-event-status_changed{ background: #EEEDFE; color: #534AB7; }
.notifier-page-event-solution      { background: #EAF3DE; color: #3B6D11; }
.notifier-page-event-solution_refused { background: #FCEBEB; color: #A32D2D; }
.notifier-page-event-validation_asked { background: #FAEEDA; color: #854F0B; }
.notifier-page-event-validation_done  { background: #EAF3DE; color: #3B6D11; }
.notifier-page-event-updated       { background: #F1EFE8; color: #5F5E5A; }
.notifier-row-unread td:first-child { border-left: 3px solid #378ADD; }
.notifier-row-unread.event-solution_refused td:first-child { border-left-color: #E24B4A; }
.notifier-row-unread.event-created td:first-child         { border-left-color: #1D9E75; }
.notifier-row-unread.event-validation_asked td:first-child { border-left-color: #EF9F27; }
.notifier-row-unread.event-solution td:first-child         { border-left-color: #639922; }
.notifier-row-unread.event-status_changed td:first-child   { border-left-color: #7F77DD; }
.notifier-row-read { opacity: 0.55; }
.notifier-date-line1 { font-size: 12px; font-weight: 500; }
.notifier-date-line2 { font-size: 11px; color: #888; }
</style>

<script>
(function() {
    var notifierBase = (typeof window.CFG_GLPI === 'object' && window.CFG_GLPI && window.CFG_GLPI.root_doc)
        ? window.CFG_GLPI.root_doc + '/plugins/notifier'
        : '/plugins/notifier';
    var ajaxUrl  = notifierBase + '/ajax/notifications_page.php';
    var csrfUrl  = notifierBase + '/ajax/csrftoken.php';
    var state = { tab: 'all', type: '', period: '', show_read: 0, page: 1, per_page: 10 };

    var eventLabels = {
        'created':           <?= json_encode(__('Novo chamado', 'notifier')) ?>,
        'assigned':          <?= json_encode(__('Atribuído a mim', 'notifier')) ?>,
        'commented':         <?= json_encode(__('Novo acompanhamento', 'notifier')) ?>,
        'task_added':        <?= json_encode(__('Nova tarefa', 'notifier')) ?>,
        'status_changed':    <?= json_encode(__('Status alterado', 'notifier')) ?>,
        'solution':          <?= json_encode(__('Solução proposta', 'notifier')) ?>,
        'solution_refused':  <?= json_encode(__('Solução recusada', 'notifier')) ?>,
        'validation_asked':  <?= json_encode(__('Validação solicitada', 'notifier')) ?>,
        'validation_done':   <?= json_encode(__('Validação aprovada', 'notifier')) ?>,
        'updated':           <?= json_encode(__('Atualizado', 'notifier')) ?>,
    };

    var borderColors = {
        'created':'#1D9E75','assigned':'#378ADD','commented':'#378ADD',
        'task_added':'#378ADD','status_changed':'#7F77DD','solution':'#639922',
        'solution_refused':'#E24B4A','validation_asked':'#EF9F27',
        'validation_done':'#639922','updated':'#888780'
    };

    function load() {
        var params = new URLSearchParams({
            tab:       state.tab,
            type:      state.type,
            period:    state.period,
            show_read: state.show_read,
            page:      state.page,
            per_page:  state.per_page,
        });
        fetch(ajaxUrl + '?' + params, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(render)
            .catch(function(e) { console.error(e); });
    }

    var updateStyle = 'priority';

    function render(data) {
        window._lastRenderData = data;
        if (data.update_message_style) updateStyle = data.update_message_style;
        // Badges come from server already deduplicated per unique ticket
        renderBadges(data.unread_by_tab);

        if (updateStyle === 'combined') {
            // Items already have sub_events from server
            renderTableCombined(data.items, data.total);
        } else {
            // priority and multiple: flat list
            renderTable(data.items, data.total);
        }
        renderPagination(data.page, data.total_pages, data.total, data.per_page);
    }

    function renderBadges(byTab) {
        document.querySelectorAll('.notifier-tab-badge').forEach(function(el) {
            var t = el.dataset.tab;
            el.textContent = byTab[t] || 0;
            el.style.display = (byTab[t] || 0) > 0 ? '' : 'none';
        });
        var total = byTab['all'] || 0;
        var badge = document.querySelector('.notifier-page-unread-badge');
        if (badge) {
            badge.textContent = total + ' ' + <?= json_encode(__('não lidas', 'notifier')) ?>;
            badge.style.display = total > 0 ? '' : 'none';
        }
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr.replace(' ', 'T'));
        var day   = String(d.getDate()).padStart(2,'0');
        var month = String(d.getMonth()+1).padStart(2,'0');
        var year  = d.getFullYear();
        var h     = String(d.getHours()).padStart(2,'0');
        var m     = String(d.getMinutes()).padStart(2,'0');
        return { date: day+'/'+month+'/'+year, time: h+':'+m };
    }

    function esc(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    var expandedGroups = {};

    function renderTableCombined(items, total) {
        var tbody = document.getElementById('notifier-tbody');
        if (!items || items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted py-4">'
                + <?= json_encode(__('Sem notificações', 'notifier')) ?> + '</td></tr>';
            return;
        }
        var html = '';
        items.forEach(function(item) {
            var key       = item.itemtype + ':' + item.items_id;
            var count     = item.update_count || 1;
            var isExpanded = expandedGroups[key] === true;

            html += buildRow(item, count);

            // Sub-rows come from server as sub_events
            if (isExpanded && item.sub_events && item.sub_events.length > 0) {
                item.sub_events.forEach(function(ev) {
                    html += buildRow(ev, 0, true);
                });
            }
        });
        tbody.innerHTML = html;
        attachRowHandlers(tbody, items);
    }

    function buildRow(item, count, isSub) {
        var dt           = formatDate(item.date_creation);
        var label        = eventLabels[item.event] || item.event;
        var cls          = 'notifier-page-event-' + (item.event || 'updated');
        var actorDisplay = item.actor_name || '';
        var borderColor  = borderColors[item.event] || '#378ADD';
        var rowStyle     = item.is_read ? '' : 'border-left: 3px solid ' + borderColor + ';';
        var rowCls       = (item.is_read ? 'notifier-row-read' : 'notifier-row-unread')
                         + (isSub ? ' notifier-sub-row' : '');
        var eyeIcon  = item.is_read ? 'ti-eye-off' : 'ti-eye';
        var eyeTitle = item.is_read
            ? <?= json_encode(__('Marcar como não lido', 'notifier')) ?>
            : <?= json_encode(__('Marcar como lido', 'notifier')) ?>;

        var statusBadge = buildStatusBadge(item.ticket_status);
        var prioBadge   = buildPrioBadge(item.priority);

        var expandBtn = '';
        if (count > 1) {
            var expanded = expandedGroups[item.itemtype + ':' + item.items_id];
            expandBtn = '<button class="btn btn-sm btn-outline-secondary notifier-expand-group ms-1" '
                + 'data-key="' + item.itemtype + ':' + item.items_id + '" '
                + 'title="' + (expanded ? 'Recolher' : 'Expandir') + '" style="padding:2px 6px;">'
                + '<i class="ti ' + (expanded ? 'ti-chevron-up' : 'ti-chevron-down') + '" style="font-size:12px;"></i>'
                + '</button>';
        }

        var assignBtn = '';
        if (!isSub && item.category === 'new' && item.itemtype === 'Ticket') {
            assignBtn = '<button class="btn btn-sm btn-outline-success notifier-assign-me" '
                + 'data-items-id="' + item.items_id + '" data-itemtype="' + item.itemtype + '" '
                + 'title="Assumir chamado"><i class="ti ti-user-plus"></i></button>';
        }

        var eventCell = esc(label);
        if (count > 1) {
            eventCell += ' <span style="font-size:10px;background:rgba(255,255,255,.15);color:#b0bec5;padding:1px 5px;border-radius:3px;margin-left:4px;">' + count + 'x</span>';
        }

        var row = '<tr class="' + rowCls + '" style="' + rowStyle + (isSub ? 'padding-left:20px;opacity:0.85;' : '') + '" data-id="' + item.id + '" data-read="' + (item.is_read ? 1 : 0) + '">';
        row += '<td class="fw-500">' + (isSub ? '&nbsp;&nbsp;&nbsp;↳ ' : '') + esc(item.title) + '</td>';
        row += '<td class="text-muted">' + (item.items_id ? '#'+item.items_id : '') + '</td>';
        row += '<td><div class="notifier-date-line1">' + dt.date + '</div><div class="notifier-date-line2">' + dt.time + '</div></td>';
        row += '<td>' + statusBadge + '</td>';
        row += '<td>' + prioBadge + '</td>';
        row += '<td class="text-muted small" style="font-size:11px;">' + esc(item.entity || '') + '</td>';
        row += '<td class="text-muted small" style="font-size:11px;">' + esc(item.requester || '') + '</td>';
        row += '<td><span class="notifier-page-event-badge ' + cls + '">' + esc(label) + '</span></td>';
        row += '<td class="text-muted small">' + esc(actorDisplay) + '</td>';
        row += '<td class="text-muted small">' + eventCell + '</td>';
        row += '<td class="text-muted small">' + esc(item.content || '') + '</td>';
        row += '<td><div class="d-flex gap-1">'
            + '<button class="btn btn-sm btn-outline-secondary notifier-toggle-read" title="' + eyeTitle + '"><i class="ti ' + eyeIcon + '"></i></button>'
            + '<a href="' + esc(item.url) + '" class="btn btn-sm btn-outline-secondary" title="Abrir chamado"><i class="ti ti-external-link"></i></a>'
            + assignBtn + expandBtn
            + '</div></td>';
        row += '</tr>';
        return row;
    }

    function buildStatusBadge(status) {
        var statusLabels = {1:'Novo',2:'Em andamento',3:'Planejado',4:'Pendente',5:'Resolvido',6:'Fechado'};
        var statusColors = {1:'#1D9E75',2:'#378ADD',3:'#7F77DD',4:'#EF9F27',5:'#534AB7',6:'#888780'};
        var label = statusLabels[status] || '';
        var color = statusColors[status] || '#888780';
        return label ? '<span style="background:' + color + '20;color:' + color + ';font-size:11px;font-weight:500;padding:2px 7px;border-radius:4px;white-space:nowrap;">' + label + '</span>' : '';
    }

    function buildPrioBadge(priority) {
        var prioLabels = {1:'Muito alta',2:'Alta',3:'Média',4:'Baixa',5:'Muito baixa',6:'Nenhuma'};
        var prioColors = {1:'#E24B4A',2:'#EF9F27',3:'#378ADD',4:'#1D9E75',5:'#888780',6:'#888780'};
        var label = prioLabels[priority] || '';
        var color = prioColors[priority] || '#888780';
        return label ? '<span style="background:' + color + '20;color:' + color + ';font-size:11px;font-weight:500;padding:2px 7px;border-radius:4px;white-space:nowrap;">' + label + '</span>' : '';
    }

    function attachRowHandlers(tbody, groups) {
        tbody.querySelectorAll('.notifier-toggle-read').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var row = btn.closest('tr');
                toggleRead(row.dataset.id, parseInt(row.dataset.read), row, btn);
            });
        });
        tbody.querySelectorAll('.notifier-assign-me').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var itemsId = btn.dataset.itemsId;
                var itemtype = btn.dataset.itemtype;
                btn.disabled = true;
                btn.innerHTML = '<i class="ti ti-loader"></i>';
                getCsrf(function(token) {
                    fetch(notifierBase + '/ajax/assignme.php', {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'items_id=' + itemsId + '&itemtype=' + itemtype + '&_glpi_csrf_token=' + token
                    }).then(function(r) { return r.json(); }).then(function(data) {
                        if (data.success) {
                            btn.innerHTML = '<i class="ti ti-check"></i>';
                            btn.classList.remove('btn-outline-success');
                            btn.classList.add('btn-success');
                            setTimeout(function() { load(); }, 800);
                        } else {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="ti ti-user-plus"></i>';
                            alert(data.message || 'Erro');
                        }
                    });
                });
            });
        });
        tbody.querySelectorAll('.notifier-expand-group').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var key = btn.dataset.key;
                expandedGroups[key] = !expandedGroups[key];
                // Re-render without reloading from server
                var currentData = window._lastRenderData;
                if (currentData) render(currentData);
                else load();
            });
        });
    }

    function renderTable(items, total) {
        var tbody = document.getElementById('notifier-tbody');
        if (!items || items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted py-4">'
                + <?= json_encode(__('Sem notificações', 'notifier')) ?> + '</td></tr>';
            return;
        }
        var html = '';
        items.forEach(function(item) {
            html += buildRow(item, item.update_count || 1);
        });
        tbody.innerHTML = html;
        attachRowHandlers(tbody, null);
    }


    function renderPagination(page, totalPages, total, perPage) {
        var label = document.getElementById('notifier-count-label');
        var from  = (page-1)*perPage + 1;
        var to    = Math.min(page*perPage, total);
        label.textContent = total > 0
            ? <?= json_encode(__('Exibindo', 'notifier')) ?> + ' ' + from + '-' + to + ' ' + <?= json_encode(__('de', 'notifier')) ?> + ' ' + total
            : <?= json_encode(__('Sem notificações', 'notifier')) ?>;

        var pag = document.getElementById('notifier-pagination');
        pag.innerHTML = '';
        if (totalPages <= 1) return;

        function mkBtn(label, p, disabled, active) {
            var b = document.createElement('button');
            b.className = 'btn btn-sm ' + (active ? 'btn-info' : 'btn-outline-secondary');
            b.textContent = label;
            b.disabled = disabled;
            if (!disabled) b.addEventListener('click', function() { state.page = p; load(); });
            return b;
        }

        pag.appendChild(mkBtn('‹', page-1, page===1, false));
        var start = Math.max(1, page-2), end = Math.min(totalPages, page+2);
        for (var i = start; i <= end; i++) {
            pag.appendChild(mkBtn(i, i, false, i===page));
        }
        pag.appendChild(mkBtn('›', page+1, page===totalPages, false));
    }

    function getCsrf(cb) {
        fetch(csrfUrl).then(function(r){ return r.json(); }).then(function(d){ cb(d.token); });
    }

    function toggleRead(id, isRead, row, btn) {
        getCsrf(function(token) {
            var endpoint = isRead
                ? notifierBase + '/ajax/markunread.php'
                : notifierBase + '/ajax/markread.php';
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + id + '&_glpi_csrf_token=' + token
            }).then(function() { load(); });
        });
    }

    document.getElementById('notifier-tabs').addEventListener('click', function(e) {
        var btn = e.target.closest('[data-tab]');
        if (!btn) return;
        document.querySelectorAll('#notifier-tabs [data-tab]').forEach(function(b) {
            b.classList.remove('active','btn-info');
            b.classList.add('btn-outline-secondary');
        });
        btn.classList.add('active','btn-info');
        btn.classList.remove('btn-outline-secondary');
        state.tab = btn.dataset.tab;
        state.page = 1;
        load();
    });

    document.getElementById('notifier-filter-type').addEventListener('change', function() {
        state.type = this.value; state.page = 1; load();
    });
    document.getElementById('notifier-filter-period').addEventListener('change', function() {
        state.period = this.value; state.page = 1; load();
    });
    document.getElementById('notifier-show-read').addEventListener('change', function() {
        state.show_read = this.checked ? 1 : 0; state.page = 1; load();
    });
    document.getElementById('notifier-per-page').addEventListener('change', function() {
        state.per_page = parseInt(this.value); state.page = 1; load();
    });

    document.getElementById('notifier-mark-all-read').addEventListener('click', function() {
        getCsrf(function(token) {
            fetch(notifierBase + '/ajax/markallread.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: '_glpi_csrf_token=' + token + '&tab=' + encodeURIComponent(state.tab)
            }).then(function() { load(); });
        });
    });

    load();

    // Auto-refresh every 30 seconds (same interval as the bell)
    setInterval(function() {
        // Only refresh if page is visible
        if (!document.hidden) {
            load();
        }
    }, 30000);
})();
</script>

<?php
Html::footer();
