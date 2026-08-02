<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Tenant;
use App\Models\TenantSettings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Monta e cacheia o payload do cardápio.
 *
 * Este é o endpoint mais lido da plataforma — proporção aproximada de 100:1
 * entre leituras de cardápio e criações de pedido, concentradas em duas janelas
 * curtas (almoço e jantar). Três decisões sustentam isso:
 *
 * 1. Chave versionada (menu_version). Invalidação vira troca de chave, operação
 *    atômica e sem race condition — diferente de Cache::forget, onde uma escrita
 *    concorrente pode repovoar a chave com dado obsoleto.
 *
 * 2. Lock + stale fallback. Quando a chave expira às 12h05 com centenas de
 *    requests simultâneas, apenas uma reconstrói; as demais recebem a versão
 *    anterior em vez de irem todas ao banco (cache stampede). Sem cópia stale
 *    disponível, esperam pelo vencedor em vez de competir com ele.
 *
 * 3. Payload pronto para serializar. O cache guarda o array final, não models —
 *    evita hidratar Eloquent e re-executar transformações a cada hit.
 */
class MenuService
{
    public function __construct(
        private ThemeSanitizer $themes,
        private ModifierOptionResolver $options,
    ) {}

    public function forTenant(Tenant $tenant): array
    {
        $ttl = (int) config('tenancy.cache.menu_ttl');
        $key = $this->key($tenant);

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $lock = Cache::lock("{$key}:build", 10);

        if (! $lock->get()) {
            // Outro processo já está reconstruindo. Servir a versão anterior é
            // preferível a enfileirar todo mundo no banco.
            if ($stale = Cache::get($this->staleKey($tenant))) {
                return $stale;
            }

            /*
             * Sem cópia stale para servir — loja nova, primeiro acesso, ou
             * stale_ttl vencido. Antes daqui o código seguia direto para o
             * build, e nesse ponto TODOS os processos iam ao banco de uma vez:
             * exatamente o stampede que o lock existe para evitar, no pior
             * momento possível (a primeira carga, quando nada está aquecido).
             *
             * Esperar pelo vencedor e reler o cache é mais barato que competir
             * com ele. O timeout é curto de propósito: se o vencedor demorar
             * mais que isso, construir também é melhor que fazer o visitante
             * esperar mais — degradação de custo, não de disponibilidade.
             */
            try {
                $lock->block(3);
            } catch (LockTimeoutException) {
                return $this->buildAndCache($tenant, $key, $ttl);
            }

            // Chegamos aqui donos do lock, e o vencedor anterior já gravou.
            try {
                if ($fresh = Cache::get($key)) {
                    return $fresh;
                }

                return $this->buildAndCache($tenant, $key, $ttl);
            } finally {
                $lock->release();
            }
        }

        try {
            return $this->buildAndCache($tenant, $key, $ttl);
        } finally {
            $lock->release();
        }
    }

    /** Reconstrói o payload e repovoa as duas cópias (viva e stale). */
    private function buildAndCache(Tenant $tenant, string $key, int $ttl): array
    {
        $payload = $this->build($tenant);

        Cache::put($key, $payload, $ttl);
        // Cópia de longa duração usada apenas como rede de proteção.
        Cache::put($this->staleKey($tenant), $payload, (int) config('tenancy.cache.stale_ttl'));

        return $payload;
    }

    private function build(Tenant $tenant): array
    {
        // Eager load explícito: sem isto seriam N+1 queries por produto para
        // buscar grupos de modificadores, o gargalo clássico deste endpoint.
        //
        // optionProducts entra na mesma leva: um grupo composto que resolvesse
        // os sabores sob demanda faria uma query por tamanho de pizza exibido.
        $categories = Category::query()
            ->where('is_active', true)
            ->with(['products' => function ($query) {
                $query->where('is_available', true)
                    ->with(['modifierGroups.modifiers', 'modifierGroups.optionProducts'])
                    ->orderBy('position');
            }])
            ->orderBy('position')
            ->get()
            // Seção sem itens disponíveis não vai ao ar: um título solto no
            // cardápio parece erro para o cliente final, e a situação é comum
            // — a loja cria as seções antes de cadastrar os pratos.
            ->filter(fn (Category $category) => $category->products->isNotEmpty())
            // Categoria que só serve de insumo para grupo composto também não
            // vai ao ar. "Sabores de Pizza" existe para abastecer a escolha
            // dentro da pizza; listada solta no cardápio ela venderia meia
            // pizza avulsa e apareceria duplicada logo abaixo dos tamanhos.
            ->reject(fn (Category $category) => $category->is_option_only)
            ->values();

        $settings = $tenant->settings;
        $theme = $this->themes->sanitize($settings?->theme ?? []);

        /*
         * O payload é convertido para array puro antes de sair daqui.
         *
         * As chamadas encadeadas de ->map() devolvem Collection em cada nível.
         * Uma Collection sobrevive ao json_encode do controller, mas ao passar
         * pelo cache ela é serializada como objeto PHP e volta como estrutura
         * tipada — o JSON então entrega {"categories": {...}} em vez de um
         * array, e o cliente quebra ao iterar. Normalizar aqui garante que o
         * payload cacheado seja idêntico ao payload recém-construído.
         */
        return $this->toArray([
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'logoUrl' => $settings?->logo_url,
                'coverUrl' => $settings?->cover_url,
                'description' => $settings?->description,
                'phone' => $settings?->phone,
                'whatsapp' => $settings?->whatsapp,
                'address' => $settings?->address,
                'acceptsOnlinePayment' => $tenant->acceptsOnlinePayment(),
                // Plano somente-cardápio: a loja é uma vitrine. O storefront
                // não monta carrinho nem checkout, e a API recusa o POST.
                'acceptsOrders' => $tenant->allowsOrders(),
                'paymentMethods' => $settings?->payment_methods ?? ['cash'],
                'deliveryConfig' => $settings?->delivery_config ?? [],
                // Modalidades ofertadas no cardápio. Normalizado aqui porque a
                // loja pode nunca ter salvo a configuração — o storefront não
                // deve ter que reproduzir os defaults do backend.
                'fulfillments' => $this->fulfillments($tenant, $settings),
                'businessHours' => $settings?->business_hours ?? [],
                // Calculado no servidor: o relógio do cliente não é confiável
                // para decidir se a loja aceita pedidos.
                'isOpen' => $this->isOpen($settings),
            ],
            'theme' => $theme,
            'categories' => $categories->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'products' => $category->products->map(fn ($product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description,
                    'priceCents' => $product->price_cents,
                    'promoPriceCents' => $product->promo_price_cents,
                    'imageUrl' => $product->image_url,
                    'modifierGroups' => $product->modifierGroups->map(fn ($group) => [
                        'id' => $group->id,
                        'name' => $group->name,
                        'minSelect' => $group->min_select,
                        'maxSelect' => $group->max_select,
                        'isRequired' => $group->is_required,
                        // O storefront muda a apresentação conforme estes dois:
                        // grupo composto vira lista de sabores com foto, e a
                        // regra decide se o rodapé mostra "+R$ x" ou o total.
                        'source' => $group->source,
                        'pricingRule' => $group->pricing_rule,
                        'modifiers' => $this->options->options($group)->map(fn (array $option) => [
                            'id' => $option['id'],
                            'name' => $option['name'],
                            // Mantém o nome do campo por compatibilidade: para
                            // grupo de lista continua sendo um delta. No grupo
                            // composto é o preço cheio do sabor, e quem
                            // interpreta é a pricingRule.
                            'priceDeltaCents' => $option['priceCents'],
                            'imageUrl' => $option['imageUrl'],
                            'description' => $option['description'],
                        ]),
                    ]),
                ])->values(),
            ])->values(),
            'version' => (string) $tenant->menu_version,
        ]);
    }

    /**
     * Modalidades de recebimento que a loja oferta, na ordem de exibição.
     *
     * Espelha o `assertFulfillmentAllowed` do OrderService: o que não aparece
     * aqui é recusado lá. Consumo no local é opt-in; entrega e retirada seguem
     * ligadas por padrão para não mudar o comportamento de quem já vende.
     *
     * @return array<int,string>
     */
    private function fulfillments(Tenant $tenant, ?TenantSettings $settings): array
    {
        $config = $settings?->delivery_config ?? [];

        return array_values(array_filter([
            $tenant->allowsDelivery() && ($config['accepts_delivery'] ?? true) ? 'delivery' : null,
            ($config['accepts_pickup'] ?? true) ? 'pickup' : null,
            ($config['accepts_dine_in'] ?? false) ? 'dine_in' : null,
        ]));
    }

    /**
     * A loja está aceitando pedidos agora?
     *
     * O override manual ("fechar agora") tem precedência sobre o horário —
     * é o botão de pânico do restaurante quando a cozinha lota.
     *
     * Fica fora da chave de cache versionada porque muda com o relógio, não
     * com edições do cardápio: o TTL curto do HTTP (s-maxage=60) é o que
     * limita a defasagem aqui.
     */
    private function isOpen(?TenantSettings $settings): bool
    {
        if ($settings?->is_open_override !== null) {
            return (bool) $settings->is_open_override;
        }

        $hours = $settings?->business_hours ?? [];
        $now = now();
        $today = strtolower($now->format('D')); // mon, tue, ...
        $slot = $hours[substr($today, 0, 3)] ?? null;

        if (! $slot || ! ($slot['enabled'] ?? false)) {
            return false;
        }

        $current = $now->format('H:i');
        $open = $slot['open'] ?? '00:00';
        $close = $slot['close'] ?? '23:59';

        // Faixa que cruza a meia-noite (ex.: 19:00–02:00) precisa da comparação
        // invertida, senão a loja aparece fechada justamente no pico da noite.
        return $open <= $close
            ? $current >= $open && $current <= $close
            : $current >= $open || $current <= $close;
    }

    /**
     * Converte Collections aninhadas em arrays puros, recursivamente.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function toArray(array $payload): array
    {
        return json_decode(json_encode($payload), associative: true);
    }

    private function key(Tenant $tenant): string
    {
        return "menu:{$tenant->id}:v{$tenant->menu_version}";
    }

    private function staleKey(Tenant $tenant): string
    {
        return "menu:{$tenant->id}:stale";
    }
}
