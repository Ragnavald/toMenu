# ToMenu

Plataforma SaaS multi-tenant de cardápio digital. Cada estabelecimento é um
tenant com seu próprio site de pedidos, tema visual e painel administrativo.

```
apps/
  api/         Laravel 13 — tenancy, catálogo, pedidos, Stripe Connect, WhatsApp
  storefront/  Next.js 16 — site público da loja (SSR/ISR, tema por tenant)
  admin/       React 19 + Vite — painel do estabelecimento (SPA)
docker/        Postgres com papel de aplicação restrito; imagem PHP
```

## Como rodar

Requisitos: Docker e Node 20+.

```bash
make up        # sobe Postgres, Redis e a API (migrations + seed)
make dev       # storefront em :3000 e admin em :5173
```

| Serviço | URL |
|---|---|
| Landing da plataforma | http://localhost:3000 |
| API | http://localhost:8000 |
| Admin | http://localhost:5173 |

Duas lojas de demonstração são criadas pelo seed, com identidades visuais
deliberadamente distintas:

| Loja | URL | Login | Tema |
|---|---|---|---|
| Forno di Napoli | http://forno-di-napoli.localhost:3000 | admin@fornodinapoli.test | vermelho, Playfair, cantos 14px |
| Aoi Sushi | http://aoi-sushi.localhost:3000 | admin@aoisushi.test | índigo, Sora, cantos 8px |

Senha de ambos: `password`.

As duas lojas rodam **exatamente o mesmo build** do storefront. Cores,
tipografia, arredondamento e layout vêm da API em runtime.

### Subdomínio por loja

Cada loja atende em `{slug}.{TENANCY_ROOT_DOMAIN}`. Em desenvolvimento o
domínio raiz é `localhost`, porque `*.localhost` já resolve nativamente na
maioria dos sistemas — nenhuma entrada em `/etc/hosts` é necessária para cada
loja nova.

```
http://forno-di-napoli.localhost:3000   loja
http://localhost:3000                   landing da plataforma
http://localhost:3000/forno-di-napoli   mesma loja, via path (fallback)
```

O `apps/storefront/proxy.ts` reescreve host → rota interna; o Laravel resolve o
tenant pelo `Host`.

**Cada loja é sempre um subdomínio — domínio próprio não é suportado.** Em
produção isso exige, no Cloudflare, um registro DNS `*` e o SSL/TLS em modo
"Full". O certificado é o universal do Cloudflare, que cobre `dominio` e
`*.dominio` e é renovado por eles: não há certbot nem ACME na stack.

O wildcard cobre **um** nível de subdomínio. `loja.dominio` é válido;
`loja.staging.dominio` não teria certificado, e por isso `IdentifyTenant` e o
`proxy.ts` recusam subdomínio com ponto em vez de tratá-lo como slug.

### Criar uma loja pelo cadastro público

A landing em `/` vende o plano único e cria a loja. O fluxo é:

1. Cadastro devolve token e slug; a loja nasce em `trial`, `onboarding_step = 1`.
2. O usuário é levado ao Admin já autenticado, direto no wizard de 5 passos
   (dados da loja → entrega → pagamento → horários → cardápio).
3. Ao concluir, `onboarding_completed_at` é gravado e a loja está publicada.

O token viaja no fragmento (`#`) da URL, não na query string: o fragmento não é
enviado ao servidor nem registrado em logs de acesso.

### Painel do lojista

Sidebar com três grupos:

| Grupo | Telas |
|---|---|
| Operação | Pedidos (com contador de ativos em tempo real) |
| Cardápio | Produtos (agrupados por seção), Categorias (com reordenação) |
| Configurações | Dados da loja, Entrega e pagamento, Horários, Aparência |

Configurações de entrega cobrem taxa, pedido mínimo, tempo estimado, frete
grátis acima de um valor, raio, retirada no local e o WhatsApp que recebe os
pedidos. Horários são por dia da semana, com suporte a faixas que cruzam a
meia-noite (19:00–02:00) e um override manual de "fechar agora" que tem
precedência sobre a agenda.

## Isolamento entre tenants

O modelo é **single database com `tenant_id`** — escolhido para muitos tenants
pequenos, onde database-per-tenant multiplicaria custo de migrations e conexões
sem benefício proporcional. O isolamento é feito em quatro camadas independentes:

| Camada | Onde | O que impede |
|---|---|---|
| 1. Middleware | `IdentifyTenant` | Resolve a loja por domínio/subdomínio/slug; nunca confia no cliente |
| 2. Global scope | `BelongsToTenant` | Toda query Eloquent filtra por `tenant_id` automaticamente |
| 3. Row Level Security | migration `000400` | O Postgres recusa leitura e escrita cruzadas, mesmo via SQL cru |
| 4. Route binding | `resolveRouteBinding` | `/products/{id}` de outra loja resolve como 404 |

**A camada 3 exige um papel Postgres sem `SUPERUSER` e sem `BYPASSRLS`.** O
banco ignora políticas de RLS para esses papéis silenciosamente — as policies
continuam listadas em `pg_policies` enquanto nenhuma é aplicada. Por isso a
aplicação conecta como `tomenu_app` (conexão `pgsql`) e as migrations usam o
papel dono (`pgsql_admin`).

```bash
make test   # 84 testes; a suíte de isolamento roda contra Postgres real
```

`tests/Feature/TenantIsolationTest.php` é o arquivo mais importante do projeto:
tenta vazar dados de propósito, inclusive removendo o global scope e usando
query builder cru. Ao adicionar um model com `BelongsToTenant`, inclua-o no
dataset de `it('isola todos os models tenant-scoped')`.

## Decisões de arquitetura

**Storefront em Next.js, não SPA pura.** Cardápio é conteúdo público, indexável,
acessado majoritariamente por celular em rede móvel. SPA client-side entrega
HTML vazio ao crawler e tem LCP alto. O Admin, por ser área autenticada sem
SEO, é SPA Vite.

**Stripe Connect Express, não chaves de API por tenant.** O requisito original
previa cada tenant cadastrar suas chaves do Stripe. Armazenar secret keys de
terceiros cria passivo de segurança e PCI que a plataforma não precisa assumir.
Com Connect, o tenant autoriza via onboarding hospedado e a plataforma nunca vê
credencial alguma; a comissão é retida por `application_fee_amount`.

**Preços sempre em centavos (`integer`).** `float` para dinheiro produz erro de
arredondamento em produção.

**`order_items` guarda snapshot.** Nome e preço são copiados na compra. Se o
restaurante reajustar amanhã, o pedido de ontem não muda — requisito contábil.

**Numeração de pedido sequencial por tenant.** O restaurante espera "#42", não
"#918273". Gerado sob `lockForUpdate` na linha do tenant, para não colidir no
pico do almoço.

**Preço nunca vem do cliente.** O checkout envia apenas IDs e quantidades; todo
valor é relido do banco em `OrderService`.

## Cache e performance

Tráfego de cardápio é bursty (almoço e jantar) e assimétrico: leituras superam
escritas em cerca de 100:1.

```
Browser  →  CDN (s-maxage=60)  →  ISR do Next  →  Redis  →  Postgres
```

- **Chave versionada** (`menu:{id}:v{n}`): invalidar é trocar a chave, operação
  atômica. `Cache::forget` com escrita concorrente pode repovoar dado obsoleto.
- **Lock + stale fallback**: quando a chave expira às 12h05 com centenas de
  requests, apenas uma reconstrói; as demais recebem a versão anterior.
- **`s-maxage=60` + ETag**: uma loja com 500 acessos/min gera ~1 request/min até
  o Laravel. É o item de maior impacto de toda a stack.
- **Payload normalizado antes de cachear**: Collections aninhadas sobrevivem ao
  `json_encode` direto, mas voltam do cache como objeto tipado e quebram o
  cliente. `MenuService::toArray()` evita isso.

O que **não** é cacheado: disponibilidade de item e status de pedido. Cardápio
desatualizado irrita; produto esgotado vendido é prejuízo.

## Fluxo de pagamento e notificação

O momento do disparo importa mais que o canal:

| Método | Notifica a cozinha em |
|---|---|
| Dinheiro / cartão na entrega | criação do pedido |
| Cartão / Pix online | webhook `payment_intent.succeeded` |

Avisar a cozinha antes da confirmação faria o restaurante preparar pedidos que
podem nunca ser pagos. Os webhooks verificam assinatura sobre o corpo bruto,
gravam `webhook_events` para idempotência, respondem rápido e processam em fila.
O valor pago é revalidado contra o total do pedido antes de confirmar.

WhatsApp isoladamente não é canal confiável para cozinha em horário de pico. O
alerta primário é o WebSocket (`OrderReceived` → Laravel Reverb) com som no
painel; o WhatsApp é redundância.

## Pontos de atenção antes de produção

- **`TENANCY_TRUST_HEADER=false`.** O header `X-Tenant` existe apenas para
  desenvolvimento, onde não há wildcard DNS. Em produção ele permitiria a
  qualquer visitante escolher a loja.
- **DNS e TLS no Cloudflare.** Em Zero Trust > Networks > Tunnels, cadastre dois
  *public hostnames* no túnel, ambos para `http://nginx:80`: `dominio` e
  `*.dominio` (o apex não cobre subdomínio). Cada um cria o CNAME sozinho — não
  se cria registro DNS à mão. SSL/TLS em **Full**; "Full (strict)" exigiria um
  Origin Certificate no nginx. Sem certbot: o certificado universal cobre
  `dominio` e `*.dominio`. Defina `CLOUDFLARE_TUNNEL_TOKEN` no `.env` de
  produção.
- **Cache Rule para o cardápio no Cloudflare.** O `s-maxage=60` de
  `MenuController` só vale se o edge tratar a resposta como cacheável — e o
  padrão do Cloudflare ignora `application/json`, tratando `/api/*` como
  dinâmico. Sem uma Cache Rule (hostname `*.dominio` + URI `/api/`, "Eligible
  for cache", Edge TTL = "Use cache-control header"), todo acesso ao cardápio
  atravessa até a origem. Como o droplet fica nos EUA (a DO não tem região no
  Brasil), é esta regra que mantém a leitura no edge de São Paulo em vez de
  ~120 ms de ida e volta.
- **HTTPS atrás do túnel não depende do painel.** O nginx força
  `X-Forwarded-Proto: https` e o `trustProxies` em `bootstrap/app.php` confia na
  faixa interna do Docker. Sem esse par o Laravel gera URLs `http://` e o
  navegador barra o conteúdo misto — ajustar só o modo do Cloudflare não
  resolveria.
- **Octane**: se ativar, o reset de contexto por request é obrigatório —
  `TenancyServiceProvider::registerOctaneReset()` já trata, mas o worker
  persistente é a origem mais provável de vazamento entre tenants.
- **Templates do WhatsApp** levam de 24 a 48h para aprovação da Meta. Aprove
  antes do lançamento.
- **Pix via Stripe Connect no Brasil**: confirmar disponibilidade para
  destination charges antes de assumir no roadmap. A abstração de gateway
  existe justamente para permitir um PSP nacional sem reescrever o checkout.
- Índices de tabelas tenant-scoped devem começar por `tenant_id`.

## Comandos

```bash
make up        # sobe a stack e prepara o banco
make dev       # storefront + admin em modo desenvolvimento
make test      # suíte completa (Postgres real)
make fresh     # recria o banco e roda o seed
make down      # derruba os containers
```
