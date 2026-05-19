# Notifier+ for GLPI

**Plugin de notificações em tempo real para o GLPI com suporte a chamados por e-mail, categorização por fila, página completa de notificações, som e filtro por entidade.**

> Fork aprimorado de [dvbnl/glpi-plugin-notifier](https://github.com/dvbnl/glpi-plugin-notifier) e [dinoue/glpi-plugin-notifier](https://github.com/dinoue/glpi-plugin-notifier)  
> Inspirado também em [ticketanswers](https://github.com/pluginsGLPI/ticketanswers) e [glpi-reservationalert](https://github.com/SantiPT007/glpi-reservationalert)  
> Desenvolvido por **Ricardo Barboza** — Omium Tecnologias & Negócios

---

## O que é o Notifier+?

O Notifier+ mantém os técnicos informados em tempo real sobre tudo que acontece com os chamados, sem precisar atualizar a tela ou verificar e-mails.

Um **sino** aparece no canto inferior direito de todas as telas do GLPI. Quando algo importante acontece, o sino pulsa, o contador aumenta e um **som de alerta** toca automaticamente.

A partir da v1.4.0, um botão **"Ver todas"** no rodapé do sino abre uma **página completa de notificações** com lista detalhada, filtros e ações rápidas.

---

## Funcionalidades

### Sino em tempo real
- Badge com contador de notificações não lidas
- Painel com 7 filas: **Todos, Novos, Meus, Equipe, Demais, Resolvidos, Fechados**
- Polling a cada 30 segundos
- Som configurável por fila

### Cobertura completa de eventos
- Chamados novos sem atribuição (inclusive por **e-mail/coletor**)
- Atribuição direta ou por grupo (incluindo subgrupos aninhados)
- Acompanhamentos, tarefas, soluções, mudanças de status
- **Solução recusada** — técnico notificado quando solicitante recusa
- **Validações** — notifica aprovador na solicitação e atores na resposta
- **Transferência entre entidades** — notifica técnicos da entidade destino
- Followup privado notifica apenas técnicos, nunca o solicitante

### Filtro por entidade
Técnicos só recebem notificações de chamados das entidades que têm acesso. Perfis recursivos expandem automaticamente para entidades filhas.

### Página completa de notificações
Acessível pelo botão "Ver todas" no sino:
- **12 colunas**: Chamado, Nº, Data/Hora, Status, Prioridade, Entidade, Requerente, Tipo, Usuário, Evento, Detalhe, Ações
- Filtro por **status atual** do chamado e por **período**
- Agrupamento por ticket respeitando o estilo configurado (Prioritária / Combinadas / Múltiplas)
- Botão **Assumir** para tickets na aba Novos — atribui o técnico sem abrir o chamado
- "Marcar tudo como lido" por aba ativa
- Toggle "Mostrar lidas" para consultar histórico
- Refresh automático a cada 30 segundos

### Limpeza automática inteligente
- Chamados mesclados/deletados removidos automaticamente do sino
- Ator removido do ticket para de receber notificações
- Grupo desatribuído: membros param de receber notificações
- Notificações de usuários inativos/deletados removidas periodicamente
- Notificações de entidades sem acesso filtradas automaticamente

### Preferências por usuário
| Preferência | Descrição |
|-------------|-----------|
| Tipos de notificação | Chamados, Mudanças, Problemas, Tarefas de projeto |
| Canal | Atribuído a mim / Atribuído ao meu grupo |
| Som por fila | Novos, Meus, Equipe, Demais, Encerrados |
| Notificar chamados que tenho acesso | Aba Demais — apenas perfis com permissão STEAL |
| Estilo de mensagem | Prioritária / Combinadas / Múltiplas alterações |
| Mostrar resolvidos/fechados | Ligar/desligar individualmente |
| Exibição de encerrados | Separados ou Unificados |

### 7 filas de notificação

| Aba | O que mostra | Quem vê |
|-----|-------------|---------|
| **Todos** | Visão geral de tudo não lido | Todos |
| **Novos** | Chamados sem nenhum técnico atribuído | Todos |
| **Meus** | Chamados atribuídos diretamente a mim | Todos |
| **Equipe** | Chamados do meu grupo | Todos |
| **Demais** | Movimentações em chamados que tenho acesso | Admin, Super-Admin, Supervisor, Beholder |
| **Resolvidos** | Chamados resolvidos pendentes | Todos (configurável) |
| **Fechados** | Chamados fechados pendentes | Todos (configurável) |

---

## Requisitos

| Componente | Versão mínima |
|------------|--------------|
| GLPI | 11.0.0 |
| PHP | 8.1 |

---

## Instalação

1. Baixe o arquivo zip da [última release](https://github.com/RicardoBarboza/glpi-plugin-notifier-plus/releases)
2. Extraia o conteúdo em uma pasta temporária
3. Copie a pasta `notifier/` para `[GLPI]/plugins/`
4. Acesse **Configuração → Plugins** no GLPI
5. Clique em **Instalar** e depois em **Ativar**

> ⚠️ Não extraia o zip diretamente dentro da pasta `plugins/notifier/` existente — extraia em pasta temporária e copie o conteúdo.

---

## Atualização

1. Extraia o zip em pasta temporária
2. Substitua os arquivos em `[GLPI]/plugins/notifier/`
3. Acesse **Configuração → Plugins** e clique em **Atualizar** se disponível
4. Limpe o cache: `php bin/console cache:clear`

---

## Permissões

A aba **Demais** e o toggle "Notificar movimentações" são visíveis apenas para usuários com direito **STEAL** em chamados (bit 16384): Super-Admin, Admin, Supervisor, Beholder.

---

## Idiomas suportados

Português do Brasil, English, Français, Español, Nederlands

---

## Créditos

Fork de [dvbnl/glpi-plugin-notifier](https://github.com/dvbnl/glpi-plugin-notifier) e [dinoue/glpi-plugin-notifier](https://github.com/dinoue/glpi-plugin-notifier).

A página completa de notificações foi inspirada na interface do [glpi-plugin-ticketanswers](https://github.com/pluginsGLPI/ticketanswers) — estrutura de lista detalhada com tipos coloridos, conteúdo resumido e ações por linha.

O suporte a notificações de reserva (em desenvolvimento) foi inspirado no [glpi-reservationalert](https://github.com/SantiPT007/glpi-reservationalert) de Santiago Almendra.

---

## Licença

GPLv3 — veja o arquivo [LICENSE](LICENSE) para detalhes.
