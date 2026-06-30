# Yondra — API de Integração

Documentação da API REST do backend do **Yondra** (Laravel 12 + Sanctum) para
integração por aplicações externas.

O Yondra é um gerenciador de **projetos** e **quadros (boards) estilo Kanban**, com
cartões (cards), seções (colunas), checklists, comentários, tags, chat por board,
notificações e atualizações em tempo real.

---

## 1. Visão geral

- **Estilo:** REST, JSON em request e response.
- **Autenticação:** Tokens pessoais do **Laravel Sanctum** (Bearer token). Ideal para
  apps externos — não depende de cookies/sessão, então **não há problema de CORS**
  para clientes server-to-server, mobile ou outro domínio.
- **Prefixo:** Todas as rotas ficam sob `/api`.
- **Charset:** UTF-8.

---

## 2. Base URL e ambientes

| Ambiente | Base URL |
|----------|----------|
| Produção (túnel ngrok atual) | `https://yondra-backend.ngrok.pizza` |
| Local (Docker/Sail) | `http://localhost:8001` |

> Exemplo de endpoint completo: `POST https://yondra-backend.ngrok.pizza/api/login`

---

## 3. Headers obrigatórios

Inclua **sempre**:

```http
Accept: application/json
Content-Type: application/json
```

- `Accept: application/json` é **essencial** — sem ele, erros de validação podem vir
  como HTML/redirect em vez de JSON.
- Em rotas protegidas, adicione o token (ver seção 4):
  ```http
  Authorization: Bearer 1|AbCdEf123...
  ```
- **Se estiver usando o túnel ngrok**, adicione este header para pular a página de aviso
  do ngrok (caso contrário a resposta pode vir como HTML):
  ```http
  ngrok-skip-browser-warning: true
  ```

---

## 4. Autenticação

O fluxo é baseado em **token Bearer**. Você obtém o token no `register` ou `login` e o
envia no header `Authorization` de todas as chamadas protegidas.

### 4.1 Registrar usuário

`POST /api/register` — **público**

```json
{
  "name": "Maria Silva",
  "email": "maria@exemplo.com",
  "password": "senhaSegura123",
  "password_confirmation": "senhaSegura123"
}
```

Regras: `password` mínimo 8 caracteres e precisa de `password_confirmation` igual;
`email` deve ser único.

**201 Created**
```json
{
  "token": "1|AbCdEf0123456789...",
  "user": {
    "id": 5,
    "name": "Maria Silva",
    "email": "maria@exemplo.com",
    "created_at": "2026-06-23T12:00:00.000000Z",
    "updated_at": "2026-06-23T12:00:00.000000Z"
  }
}
```

### 4.2 Login

`POST /api/login` — **público**

```json
{ "email": "maria@exemplo.com", "password": "senhaSegura123" }
```

**200 OK** → mesmo formato do registro (`{ token, user }`).
Credenciais inválidas retornam **422** com `errors.email`.

### 4.3 Usar o token

```bash
curl https://yondra-backend.ngrok.pizza/api/user \
  -H "Accept: application/json" \
  -H "ngrok-skip-browser-warning: true" \
  -H "Authorization: Bearer 1|AbCdEf0123456789..."
```

### 4.4 Usuário atual

`GET /api/user` → retorna o objeto do usuário autenticado.

### 4.5 Logout

`POST /api/logout` → revoga **apenas o token atual**. Retorna `{ "message": "Logged out" }`.

> Cada chamada de login/registro gera um **novo** token; eles não expiram sozinhos.
> Faça logout para revogar.

---

## 5. Formato de erros

| Status | Significado | Corpo |
|--------|-------------|-------|
| `401 Unauthorized` | Token ausente/ inválido | `{ "message": "Unauthenticated." }` |
| `403 Forbidden` | Sem permissão no recurso | `{ "message": "..." }` |
| `404 Not Found` | Recurso inexistente | `{ "message": "..." }` |
| `422 Unprocessable Content` | Erro de validação | ver abaixo |

**Exemplo 422:**
```json
{
  "message": "The email field is required.",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password field confirmation does not match."]
  }
}
```

---

## 6. Modelo de permissões

- **Project**: tem um `owner` e `members` com papel (`member` | `viewer`).
- **Board**: tem um `owner` (`user_id`) e pode ser compartilhado (`BoardShare`) com
  permissão `read` ou `write`.
  - `read` → pode visualizar.
  - `write` → pode criar/editar/mover cards, seções, tags etc.
  - Apenas o **dono** do board pode gerenciar compartilhamento e templates.

Rotas que escrevem em um board exigem permissão `write`; rotas de leitura exigem ao menos
acesso ao board. Caso contrário retornam **403**.

---

## 7. Referência de endpoints

> Todas as rotas abaixo (exceto `register`/`login`) exigem `Authorization: Bearer <token>`.
> `{boardId}`, `{cardId}` etc. são IDs inteiros.

### 7.1 Perfil

| Método | Rota | Corpo | Descrição |
|--------|------|-------|-----------|
| `GET`  | `/api/user` | — | Usuário autenticado |
| `PUT`  | `/api/user` | `{ name, email }` | Atualiza perfil |
| `PUT`  | `/api/user/password` | `{ current_password, password, password_confirmation }` | Troca senha |

### 7.2 Projetos

| Método | Rota | Corpo | Descrição |
|--------|------|-------|-----------|
| `GET`    | `/api/projects` | — | Lista projetos do usuário |
| `POST`   | `/api/projects` | `{ name, description?, color? }` | Cria projeto (`color` = `#RRGGBB`) |
| `GET`    | `/api/projects/{projectId}` | — | Detalhe (inclui `owner`, `members`, `boards`) |
| `PUT`    | `/api/projects/{projectId}` | `{ name?, description?, color? }` | Atualiza |
| `DELETE` | `/api/projects/{projectId}` | — | Remove (204) |

**Membros do projeto**

| Método | Rota | Corpo | Descrição |
|--------|------|-------|-----------|
| `POST`   | `/api/projects/{projectId}/members` | `{ email, role? }` | Adiciona membro (`role` = `member`\|`viewer`, padrão `member`) |
| `PUT`    | `/api/projects/{projectId}/members/{userId}` | `{ role }` | Altera papel |
| `DELETE` | `/api/projects/{projectId}/members/{userId}` | — | Remove membro |

### 7.3 Boards

| Método | Rota | Corpo | Descrição |
|--------|------|-------|-----------|
| `GET`    | `/api/boards` | — | Lista boards acessíveis |
| `POST`   | `/api/boards` | `{ name, description?, project_id? }` | Cria board |
| `GET`    | `/api/boards/{boardId}` | — | Detalhe completo (seções, cards, tags, membros) |
| `PUT`    | `/api/boards/{boardId}` | `{ name?, description?, project_id? }` | Atualiza |
| `DELETE` | `/api/boards/{boardId}` | — | Remove (204) |

O `GET /api/boards/{boardId}` retorna o board com:
`sections`, `cards` (não arquivados, com `assignedUser`, `createdBy`, `tags`,
`checklistItems`), `tags`, `owner` e `sharedWith` (cada um com `permission`).

**Compartilhamento de board** (somente dono)

| Método | Rota | Corpo | Descrição |
|--------|------|-------|-----------|
| `POST`   | `/api/boards/{boardId}/share` | `{ email, permission? }` | Compartilha (`permission` = `read`\|`write`, padrão `write`) |
| `PUT`    | `/api/boards/{boardId}/share/{userId}` | `{ permission }` | Altera permissão |
| `DELETE` | `/api/boards/{boardId}/share/{userId}` | — | Remove acesso |

### 7.4 Seções (colunas)

| Método | Rota | Corpo | Descrição |
|--------|------|-------|-----------|
| `POST`   | `/api/boards/{boardId}/sections` | `{ name }` | Cria seção |
| `PUT`    | `/api/boards/{boardId}/sections/{sectionId}` | `{ name }` | Renomeia |
| `POST`   | `/api/boards/{boardId}/sections/reorder` | `{ section_ids: [int] }` | Reordena (204) |
| `DELETE` | `/api/boards/{boardId}/sections/{sectionId}` | — | Remove (204) |

### 7.5 Cards

| Método | Rota | Corpo | Descrição |
|--------|------|-------|-----------|
| `POST`   | `/api/boards/{boardId}/cards` | ver abaixo | Cria card |
| `PUT`    | `/api/boards/{boardId}/cards/{cardId}` | campos parciais | Atualiza card |
| `PUT`    | `/api/boards/{boardId}/cards/reorder` | `{ ordered_ids: [int], section_id }` | Reordena/move cards |
| `DELETE` | `/api/boards/{boardId}/cards/{cardId}` | — | Arquiva (204) |
| `PUT`    | `/api/boards/{boardId}/cards/{cardId}/restore` | — | Restaura arquivado (204) |
| `GET`    | `/api/boards/{boardId}/cards/archived` | — | Lista cards arquivados |

**Corpo do card (create):**
```json
{
  "section_id": 12,
  "name": "Implementar login",
  "description": "texto opcional",
  "assigned_user_id": 5,
  "tag_ids": [1, 3],
  "due_date": "2026-07-01",
  "priority": "high"
}
```
Obrigatórios: `section_id`, `name`. `priority` ∈ `low|medium|high`.
No `PUT`, todos os campos são opcionais (envie só o que mudar); aceita também `position`.

**Subtarefas (subtasks)**

| Método | Rota | Corpo | Descrição |
|--------|------|-------|-----------|
| `GET`  | `/api/boards/{boardId}/cards/{cardId}/subtasks` | — | Lista subtarefas |
| `POST` | `/api/boards/{boardId}/cards/{cardId}/subtasks` | `{ name }` | Cria subtarefa |
| `PUT`  | `/api/boards/{boardId}/cards/{cardId}/subtasks/{subtaskId}` | `{ is_done }` | Marca concluída |

### 7.6 Checklist do card

| Método | Rota | Corpo | Descrição |
|--------|------|-------|-----------|
| `POST`   | `/api/boards/{boardId}/cards/{cardId}/checklist` | `{ text }` | Adiciona item |
| `PUT`    | `/api/boards/{boardId}/cards/{cardId}/checklist/{itemId}` | `{ text?, is_done? }` | Atualiza item |
| `DELETE` | `/api/boards/{boardId}/cards/{cardId}/checklist/{itemId}` | — | Remove item |

### 7.7 Comentários do card

| Método | Rota | Corpo | Descrição |
|--------|------|-------|-----------|
| `GET`    | `/api/boards/{boardId}/cards/{cardId}/comments` | — | Lista comentários |
| `POST`   | `/api/boards/{boardId}/cards/{cardId}/comments` | `{ body }` | Comenta (suporta `@menção`) |
| `DELETE` | `/api/boards/{boardId}/cards/{cardId}/comments/{commentId}` | — | Remove (só autor) |

### 7.8 Tags do board

| Método | Rota | Corpo | Descrição |
|--------|------|-------|-----------|
| `POST`   | `/api/boards/{boardId}/tags` | `{ name, color }` | Cria tag (`color` = `#RRGGBB`) |
| `DELETE` | `/api/boards/{boardId}/tags/{tagId}` | — | Remove tag |

### 7.9 Chat / mensagens do board

| Método | Rota | Corpo | Descrição |
|--------|------|-------|-----------|
| `GET`    | `/api/boards/{boardId}/messages` | — | Histórico do chat |
| `POST`   | `/api/boards/{boardId}/messages` | `{ body }` | Envia mensagem (máx 2000, suporta `@menção`) |
| `DELETE` | `/api/boards/{boardId}/messages/{messageId}` | — | Remove (só autor) |

### 7.10 Atividade do board

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET` | `/api/boards/{boardId}/activity` | Últimos 50 eventos do board |

### 7.11 Notificações

| Método | Rota | Descrição |
|--------|------|-----------|
| `GET` | `/api/notifications` | Últimas 30 notificações do usuário |
| `PUT` | `/api/notifications/{id}/read` | Marca uma como lida (204) |
| `PUT` | `/api/notifications/read-all` | Marca todas como lidas (204) |

### 7.12 Templates de card (somente dono do board)

| Método | Rota | Corpo | Descrição |
|--------|------|-------|-----------|
| `GET`    | `/api/boards/{boardId}/templates` | — | Lista templates |
| `POST`   | `/api/boards/{boardId}/templates` | `{ name, template_data }` | Cria template (`template_data` = objeto JSON) |
| `DELETE` | `/api/boards/{boardId}/templates/{templateId}` | — | Remove |

---

## 8. Formatos de dados (referência)

**User**
```json
{ "id": 1, "name": "...", "email": "...", "created_at": "...", "updated_at": "..." }
```
(`password` nunca é retornado.)

**Card**
```json
{
  "id": 1, "board_id": 1, "section_id": 12, "assigned_user_id": 5,
  "created_by_user_id": 1, "name": "...", "description": "...",
  "due_date": "2026-07-01", "priority": "high", "position": 0,
  "is_done": false, "archived_at": null, "parent_card_id": null,
  "created_at": "...", "updated_at": "..."
}
```

**Project** (no detalhe) inclui `owner`, `members[]` (cada um com `role`) e `boards[]`.
**Board** (no detalhe) inclui `sections[]`, `cards[]`, `tags[]`, `owner`, `sharedWith[]`
(cada um com `permission`).

---

## 9. Tempo real (opcional)

O backend emite eventos de board (criação/edição/movimentação de cards, seções,
mensagens) via **Laravel Reverb** (WebSocket compatível com Pusher). Para assinar:

- Endpoint de autorização: `POST /api/broadcasting/auth` (precisa do Bearer token).
- Canal privado por board: `private-board.{boardId}`.
- Config do servidor Reverb (ambiente atual):
  - Host: `yondra-reverb.ngrok.pizza`
  - Porta: `443`, scheme `https`
  - App key: ver `NEXT_PUBLIC_REVERB_APP_KEY` no front.

Use a lib `laravel-echo` + `pusher-js` apontando para esse host. Isto é opcional — a API
REST funciona de forma independente.

---

## 10. Exemplo completo (curl)

```bash
BASE="https://yondra-backend.ngrok.pizza"
H='-H Accept:application/json -H Content-Type:application/json -H ngrok-skip-browser-warning:true'

# 1) Login e captura do token
TOKEN=$(curl -s $H -X POST "$BASE/api/login" \
  -d '{"email":"maria@exemplo.com","password":"senhaSegura123"}' \
  | python3 -c "import sys,json;print(json.load(sys.stdin)['token'])")

# 2) Listar projetos
curl -s $H -H "Authorization: Bearer $TOKEN" "$BASE/api/projects"

# 3) Criar um board
curl -s $H -H "Authorization: Bearer $TOKEN" -X POST "$BASE/api/boards" \
  -d '{"name":"Sprint 1","description":"Quadro inicial"}'

# 4) Criar um card
curl -s $H -H "Authorization: Bearer $TOKEN" -X POST "$BASE/api/boards/1/cards" \
  -d '{"section_id":1,"name":"Primeira tarefa","priority":"medium"}'
```

---

## 11. Checklist para o app do seu amigo

1. Apontar a base URL para o backend correto (produção ou local).
2. Sempre enviar `Accept: application/json` (+ `ngrok-skip-browser-warning: true` no túnel).
3. Fazer `POST /api/login` (ou `register`), guardar o `token`.
4. Mandar `Authorization: Bearer <token>` em todas as chamadas protegidas.
5. Tratar `401` (token inválido → refazer login), `403` (sem permissão) e `422`
   (validação: ler `errors`).

> **Nota sobre CORS:** clientes que usam **token Bearer** (mobile, server-to-server,
> ou outro front) **não precisam** de configuração de CORS, pois não usam cookies. CORS só
> importa para apps de navegador em outro domínio — nesse caso, o domínio precisa ser
> adicionado em `config/cors.php` (`allowed_origins`) no backend.
