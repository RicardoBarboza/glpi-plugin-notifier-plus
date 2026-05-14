# Notifier+ for GLPI

**Plugin de notificações em tempo real para o GLPI com suporte a chamados por e-mail, categorização por fila e som.**

> Fork aprimorado de [dvbnl/glpi-plugin-notifier](https://github.com/dvbnl/glpi-plugin-notifier) e [dinoue/glpi-plugin-notifier](https://github.com/dinoue/glpi-plugin-notifier)  
> Desenvolvido por **Ricardo Barboza** — Omium Tecnologias & Negócios

---

## O que é o Notifier+?

O Notifier+ é um plugin para o GLPI que mantém os técnicos informados em tempo real sobre tudo que acontece com os chamados, sem precisar ficar atualizando a tela ou verificar e-mails.

Um **sino** aparece no canto inferior direito de todas as telas do GLPI. Quando algo importante acontece, o sino pulsa, o contador aumenta e um **som de alerta** toca automaticamente.

---

## Funcionalidades

### Notificações em tempo real
- **Chamados novos sem atribuição** — notifica todos os técnicos, inclusive quando o chamado chega por **e-mail**
- **Chamados atribuídos a mim** — quando alguém te coloca como responsável
- **Chamados do meu grupo** — quando um chamado é atribuído ao seu grupo
- **Atualizações** — acompanhamentos, mudanças de status, novas tarefas, soluções propostas
- **Movimentações em chamados que tenho acesso** — opcional, para supervisores e gestores

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

> As abas **Resolvidos** e **Fechados** podem ser unificadas como **Encerrados**.

### Som de notificação por fila
Configure o som individualmente para cada fila — útil em ambientes com alto volume de alertas automáticos.

### Mensagens específicas por tipo de alteração
Novo acompanhamento, Status alterado, Categoria alterada, Prioridade alterada, Atores alterados, Descrição atualizada, Solução proposta.

### Outras funcionalidades
- Status do chamado exibido com cor correspondente em cada notificação
- Marcar como lido automaticamente ao abrir o chamado
- Marcar tudo como lido por aba ativa
- Estilo de mensagem configurável: prioritária, combinadas ou múltiplas alterações
- Limpeza automática ao excluir ou mesclar chamados
- Somente para técnicos — usuários do portal não veem o sino

---

## Requisitos

| Componente | Versão mínima |
|------------|--------------|
| GLPI | 10.0.0 |
| PHP | 8.1 |

---

## Instalação

1. Baixe o arquivo zip da [última release](https://github.com/RicardoBarboza/glpi-plugin-notifier-plus/releases)
2. Extraia o conteúdo para `[GLPI]/plugins/notifier/`
3. Acesse **Configuração → Plugins** no GLPI
4. Clique em **Instalar** e depois em **Ativar**

---

## Atualização

1. Substitua os arquivos na pasta `[GLPI]/plugins/notifier/`
2. O plugin aplica automaticamente as migrações de banco necessárias

---

## Preferências por usuário

| Preferência | Descrição |
|-------------|-----------|
| Tipos de notificação | Chamados, Mudanças, Problemas, Tarefas de projeto |
| Canal | Atribuído a mim / Atribuído ao meu grupo |
| Som por fila | Novos, Meus, Equipe, Demais, Encerrados |
| Notificar chamados que tenho acesso | Aba Demais — apenas para perfis com permissão |
| Estilo de mensagem | Prioritária / Combinadas / Múltiplas alterações |
| Mostrar resolvidos/fechados | Ligar/desligar individualmente |
| Exibição de encerrados | Separados ou Unificados |

---

## Permissões

A aba **Demais** é visível apenas para usuários com direito **STEAL** em chamados (bit 16384):
- Super-Admin, Admin, Supervisor, Beholder

---

## Idiomas suportados

Português do Brasil, English, Français, Español, Nederlands

---

## Créditos

Fork de [dvbnl/glpi-plugin-notifier](https://github.com/dvbnl/glpi-plugin-notifier) e [dinoue/glpi-plugin-notifier](https://github.com/dinoue/glpi-plugin-notifier).

---

## Licença

GPLv3 — veja o arquivo [LICENSE](LICENSE) para detalhes.
