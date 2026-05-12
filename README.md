# Notifier+ for GLPI

**Plugin de notificações em tempo real para o GLPI com suporte a chamados por e-mail, categorização por fila e som.**

> Fork aprimorado de [dvbnl/glpi-plugin-notifier](https://github.com/dvbnl/glpi-plugin-notifier) e [dinoue/glpi-plugin-notifier](https://github.com/dinoue/glpi-plugin-notifier)  
> Desenvolvido por **Ricardo Barboza** — [Omium Tecnologias & Negócios](https://omium.com.br)

---

## O que é o Notifier+?

O Notifier+ é um plugin para o GLPI que mantém os técnicos informados em tempo real sobre tudo que acontece com os chamados, sem precisar ficar atualizando a tela ou verificar e-mails.

Um **sino** aparece no canto inferior direito de todas as telas do GLPI. Quando algo importante acontece, o sino pulsa, o contador aumenta e um **som de alerta** toca automaticamente.

---

## Funcionalidades

### Notificações em tempo real
- **Chamados novos sem atribuição** — notifica todos os técnicos, inclusive quando o chamado chega por **e-mail** (sem depender de sessão ativa)
- **Chamados atribuídos a mim** — quando alguém te coloca como responsável
- **Chamados do meu grupo** — quando um chamado é atribuído ao seu grupo
- **Atualizações** — comentários, mudanças de status, novas tarefas, soluções propostas
- **Movimentações em chamados que tenho acesso** — opcional, para supervisores e gestores

### 7 filas de notificação
| Aba | O que mostra |
|-----|-------------|
| **Todos** | Visão geral de tudo não lido |
| **Novos** | Chamados sem nenhum técnico atribuído |
| **Meus** | Chamados atribuídos diretamente a mim |
| **Equipe** | Chamados do meu grupo (não atribuídos a mim diretamente) |
| **Demais** | Movimentações em outros chamados que tenho acesso |
| **Resolvidos** | Chamados resolvidos com notificações pendentes |
| **Fechados** | Chamados fechados com notificações pendentes |

### Mensagens específicas por tipo de alteração
- Status alterado
- Categoria alterada
- Prioridade alterada
- Atores alterados
- Descrição atualizada
- Novo acompanhamento
- Solução proposta

### Outras funcionalidades
- **Som de notificação** configurável por usuário
- **Status do chamado** exibido em cada notificação com cor correspondente
- **Marcar como lido automaticamente** ao abrir o chamado pelo GLPI
- **Marcar tudo como lido por aba** — marca apenas os itens da aba ativa
- **Estilo de mensagem de atualização** configurável: mensagem prioritária, mensagens combinadas ou "múltiplas alterações"
- Limpeza automática ao excluir ou mesclar chamados
- Somente para técnicos (interface central) — usuários do portal não veem o sino

---

## Requisitos

| Componente | Versão mínima |
|------------|--------------|
| GLPI | 10.0.0 |
| PHP | 8.1 |

---

## Instalação

1. Baixe o arquivo zip da [última release](https://github.com/RicardoBarboza/glpi-plugin-notifier-plus/releases)
2. Extraia o conteúdo da pasta `notifier/` para `[GLPI]/plugins/notifier/`
3. Acesse **Configuração → Plugins** no GLPI
4. Clique em **Instalar** e depois em **Ativar**

---

## Atualização

1. Substitua os arquivos na pasta `[GLPI]/plugins/notifier/`
2. O plugin detecta e aplica automaticamente as migrações de banco necessárias

---

## Preferências por usuário

Cada técnico pode configurar individualmente clicando no ícone **Configurações** no rodapé do sino:

- Tipos de notificação desejados (Chamados, Mudanças, Problemas, Tarefas de projeto)
- Canal de notificação (atribuído a mim / atribuído ao meu grupo)
- Som de notificação (ligar/desligar)
- Notificar movimentações em chamados que tenho acesso (ligar/desligar)
- Estilo de mensagem de atualização (mensagem prioritária / combinadas / múltiplas alterações)

---

## Idiomas suportados

- 🇧🇷 Português do Brasil
- 🇬🇧 English
- 🇫🇷 Français
- 🇪🇸 Español
- 🇳🇱 Nederlands

---

## Créditos

Este plugin é um fork aprimorado de:
- [dvbnl/glpi-plugin-notifier](https://github.com/dvbnl/glpi-plugin-notifier) — projeto original
- [dinoue/glpi-plugin-notifier](https://github.com/dinoue/glpi-plugin-notifier) — fork base

**Melhorias implementadas neste fork:**
- Notificações para chamados sem atribuição chegados por e-mail
- 7 abas de categorização por fila com badges individuais
- Som de notificação com toggle por usuário
- Status do chamado exibido em cada notificação
- Marcar como lido ao abrir o chamado diretamente
- Marcar tudo como lido por aba
- Estilo de mensagem de atualização configurável
- Mensagens específicas por tipo de alteração
- Notificações para gestores via "Demais"
- Tradução completa para Português do Brasil
- Correções de compatibilidade com GLPI 11 e Percona

---

## Licença

GPLv3 — veja o arquivo [LICENSE](LICENSE) para detalhes.
