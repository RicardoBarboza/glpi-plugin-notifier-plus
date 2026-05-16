# Changelog

## [1.3.1] - 2026-05-16

### Corrigido
- Filtro de entidade não aplicado em `countUnread()` e `countUnreadGroups()` — badge do sino exibia contagem de notificações de todas as entidades, ignorando o acesso do usuário
- `handleItilGroupLink()` notificava usuários inativos/deletados ao atribuir um grupo a um ticket
- `handleProjectTaskTeamLink()` notificava usuários inativos/deletados em tarefas de projeto
- `cleanNotificationsForEntityTransfer()` não considerava entidades ancestrais ao verificar acesso após transferência de chamado
- Ticket mergeado permanecia no sino — notificações de tickets deletados/mergeados são filtradas e limpas automaticamente no próximo poll
- Queries redundantes em `handleFollowup`, `handleItilTask` e `handleSolution` (objeto parent carregado duas vezes)

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
- Tickets mesclados/deletados via merge do GLPI agora são limpos automaticamente do sino
- Erro SELECT DISTINCT no DBmysqlIterator ao buscar entidades do usuário
- Tickets na entidade raiz (id=0) não apareciam na aba Novos
- Técnico com perfil Self-Service em uma entidade não recebe notificações de chamados dessa entidade

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

### Alterado
- Aba não lida como padrão ao abrir o painel
- Tratamento visual mais forte para notificações não lidas
- Notificações agrupadas por item de origem com contador de atualizações
- Badge do sino conta itens únicos, não eventos individuais

### Corrigido
- Drift de arquivos entre js/ e public/ no GLPI 11

## [1.0.1] - 2026-04-29

### Corrigido
- Compatibilidade com GLPI 11: endpoints ajax protegidos contra redefinição de GLPI_ROOT

## [1.0.0] - 2026-04-14

Versão inicial.
