# Changelog

## [1.3.0] - 2026-05-15

### Adicionado
- Filtro por entidade em chamados sem atribuição — técnicos só veem chamados das entidades que têm acesso
- Filtro por entidade no notify_others — supervisores só recebem notificações de chamados das entidades que têm acesso
- Suporte a subgrupos aninhados — grupos com recursive_membership=1 notificam membros de subgrupos automaticamente
- Suporte a perfis recursivos — perfis com is_recursive=1 cobrem entidades filhas automaticamente
- Limpeza automática ao transferir chamado entre entidades — remove notificações de usuários sem acesso à nova entidade
- Status de Change e Problem exibido nas notificações (além de Ticket)
- Limpeza automática de notificações lidas há mais de 30 dias
- Limpeza automática de notificações de usuários deletados/inativos
- Limite de tickets reativos (aba Novos) aumentado de 25 para 100
- Proteção de performance: máximo de 200 destinatários por notificação

### Corrigido
- Erro SELECT DISTINCT no DBmysqlIterator ao buscar entidades do usuário
- Tickets na entidade raiz (id=0) não apareciam na aba Novos
- Técnico com perfil Self-Service em uma entidade não recebe notificações de chamados dessa entidade

## [1.2.1] - 2026-05-15

### Corrigido
- Tickets mesclados/deletados via merge do GLPI agora são limpos automaticamente do sino
- Removida dependência do array $item->updates para detectar is_deleted

## [1.2.0] - 2026-05-14

### Adicionado
- Aba **Demais** visível apenas para usuários com direito STEAL em tickets (bit 16384 = Admin, Super-Admin, Supervisor, Beholder)
- Toggle "Notificar movimentações em chamados que tenho acesso" restrito aos mesmos perfis com permissão
- Toggle para mostrar/ocultar chamados resolvidos e fechados individualmente
- Opção de exibir resolvidos e fechados em abas separadas ou unificados como "Encerrados"
- Ao ativar as abas de encerrados, notificações antigas são automaticamente marcadas como lidas
- Configuração de som por fila (Novos, Meus, Equipe, Demais, Encerrados)
- Sub-toggles de som ficam ocultos quando som principal está desligado
- Som de Encerrados desligado por padrão

### Corrigido
- Notificações de followup, tarefa e solução agora também notificam usuários com notify_others
- Notificações de tickets deletados/mesclados são limpas automaticamente
- Preferência update_message_style e resolved_closed_style carregando corretamente como string
- Contador do sino consistente com badges das abas
- Filtro de itens visíveis respeita configuração individual de resolvidos/fechados

## [1.1.0] - 2026-05-11

### Adicionado
- Notificações para chamados sem atribuição chegados por **e-mail** (coletor)
- 5 abas de categorização: Todos, Novos, Meus, Equipe, Demais
- Badges individuais por aba com contagem de tickets únicos
- Abas Resolvidos e Fechados (7 abas no total)
- Som de notificação com toggle por usuário
- Status do chamado exibido em cada notificação com cor correspondente
- Marcar como lido automaticamente ao abrir o chamado pelo GLPI
- Marcar tudo como lido por aba ativa
- Estilo de mensagem de atualização configurável
- Mensagens específicas por tipo de alteração (categoria, prioridade, atores, descrição)
- "Novo acompanhamento" em vez de "Novo comentário"
- Tradução completa para Português do Brasil, English, Français, Español, Nederlands

## [1.0.2] - 2026-05-06

### Changed
- **Unread tab is now the default**: opening the bell panel lands on the Unread tab so the first thing the user sees is "what still needs my attention". The All tab is right next to it; the user's choice still persists per-browser via `localStorage`, so anyone who explicitly switches to All will keep landing on All
- **Stronger unread visual treatment**: unread rows now carry a soft primary-color background tint plus a bolder title alongside the existing left border, so they read as "needs attention" at a glance rather than as a thin colored stripe
- **Notifications are batched per source object**: every Ticket / Change / Problem / ProjectTask now renders as a single row showing its most recent event, with a chevron and a "{n} updates" count when there are more. Expanding the chevron reveals the full sub-event list (status changes, comments, tasks, ...) for that object. Clicking the row body navigates to the item and marks every unread sub-event as read in one go; a per-group toggle marks the whole batch read or flips it back to unread
- **Bell badge counts source items, not raw events**: the unread badge on the bell and the `(N)` counter in the panel header now show the number of unique source items (tickets/changes/problems/projecttasks) with at least one unread event, matching what the user sees in the batched list. Backed by a new `Notification::countUnreadGroups()` helper that does a `COUNT(DISTINCT itemtype, items_id)` over the unread rows

### Fixed
- **GLPI 11 asset path drift**: `setup.php` serves `public/notifier.{js,css}` on GLPI 11 layouts but those files had drifted from the `js/`/`css/` source since v1.0.0. The build now ships matching copies in both locations, so all the bell UX changes actually reach the browser on GLPI 11 installs

## [1.0.1] - 2026-04-29

### Fixed
- **GLPI 11 compatibility**: every `ajax/*.php` endpoint now guards its bootstrap include with `defined('GLPI_ROOT')`. GLPI 11 routes legacy plugin endpoints through `LegacyFileLoadController`, which has already booted the kernel and defined `GLPI_ROOT`; re-running `/inc/includes.php` emitted a "constant already defined" warning that ended up in the response body and broke the bell's JSON parsing. GLPI 10 still hits these files directly and is unaffected — the include runs as before

## [1.0.0] - 2026-04-14

Initial release.

### Added
- **Central header bell**: a new bell button is injected next to the user avatar in GLPI's top header, with an unread badge, a gentle pulse animation on first load, and a dropdown panel listing the latest notifications. Only loaded for the central (technician) interface; self-service users are not affected
- **Complete ITIL event coverage**: Notifier listens to `item_add` / `item_update` on every ITIL object and turns them into bell rows (Ticket, Change, Problem, ProjectTask, followups, tasks, solutions, assignments)
- **Smart target resolution**: resolves every user that should hear about an event and always filters out the acting user so nobody gets a bell for their own action
- **Notification preferences modal**: per-type, per-channel preferences (direct / group) for each ITIL type, defaults to all on
- **Rich panel layout**: animated pop-in, event-specific icons, colored left border for unread rows, illustrated empty state
- **Per-row mark read / mark unread**, **mark all as read**, **one-click redirect + auto mark-read**
- **Floating bell with minimize**: fixed bottom-right, persists state via localStorage
- **Automatic cleanup**: item_purge hook removes notification rows when source items are deleted
- **30-second polling** while a page is open
- **Multi-language support**: Português do Brasil, English, Français, Español, Nederlands
- **CSRF-safe AJAX** via csrftoken.php endpoint
