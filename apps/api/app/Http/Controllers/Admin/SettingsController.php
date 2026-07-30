<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantSettings;
use App\Services\ThemeSanitizer;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private const PAYMENT_METHODS = [
        'cash', 'card_on_delivery', 'pix_on_delivery', 'stripe_card', 'stripe_pix',
    ];

    public function show(TenantContext $context, ThemeSanitizer $sanitizer): JsonResponse
    {
        $tenant = $context->getOrFail();
        $settings = $tenant->settings;

        return response()->json([
            'store' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'storefrontUrl' => $tenant->storefrontUrl(),
                'status' => $tenant->status,
                'onboardingStep' => $tenant->onboarding_step,
                'onboardingCompleted' => $tenant->onboarding_completed_at !== null,
                'acceptsOnlinePayment' => $tenant->acceptsOnlinePayment(),
                'trialEndsAt' => $tenant->trial_ends_at?->toIso8601String(),
            ],
            'profile' => [
                'phone' => $settings?->phone,
                'whatsapp' => $settings?->whatsapp,
                'address' => $settings?->address,
                'description' => $settings?->description,
                'logoUrl' => $settings?->logo_url,
                'coverUrl' => $settings?->cover_url,
            ],
            'theme' => $sanitizer->sanitize($settings?->theme ?? []),
            'delivery' => $this->deliveryDefaults($settings?->delivery_config ?? []),
            'businessHours' => $this->hoursDefaults($settings?->business_hours ?? []),
            'paymentMethods' => $settings?->payment_methods ?? ['cash'],
            'isOpenOverride' => $settings?->is_open_override,
            'options' => [
                'fonts' => ThemeSanitizer::FONTS,
                'layouts' => ThemeSanitizer::LAYOUTS,
                'paymentMethods' => self::PAYMENT_METHODS,
            ],
        ]);
    }

    /** Dados de identidade da loja: nome, contato, endereço. */
    public function updateProfile(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->getOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'whatsapp' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:500'],
            'logoUrl' => ['nullable', 'url', 'max:500'],
            'coverUrl' => ['nullable', 'url', 'max:500'],
        ]);

        $tenant->update(['name' => $data['name']]);

        $settings = TenantSettings::firstOrNew(['tenant_id' => $tenant->id]);
        $settings->fill([
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'address' => $data['address'] ?? null,
            'description' => $data['description'] ?? null,
            'logo_url' => $data['logoUrl'] ?? null,
            'cover_url' => $data['coverUrl'] ?? null,
        ]);
        $settings->save();

        return response()->json(['message' => 'Perfil atualizado.']);
    }

    /**
     * Entrega: taxa, pedido mínimo, tempo estimado, frete grátis e raio.
     *
     * Todos os valores monetários chegam e são gravados em centavos — o
     * frontend converte na borda para não propagar float pelo domínio.
     */
    public function updateDelivery(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->getOrFail();

        $data = $request->validate([
            'feeCents' => ['required', 'integer', 'min:0', 'max:100000'],
            'minOrderCents' => ['required', 'integer', 'min:0', 'max:1000000'],
            'etaMinutes' => ['required', 'integer', 'min:5', 'max:240'],
            'freeAboveCents' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'radiusKm' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'acceptsPickup' => ['boolean'],
            'acceptsDelivery' => ['boolean'],
            'merchantPhone' => ['nullable', 'string', 'max:20'],
        ]);

        $settings = TenantSettings::firstOrNew(['tenant_id' => $tenant->id]);
        $settings->delivery_config = [
            'fee_cents' => $data['feeCents'],
            'min_order_cents' => $data['minOrderCents'],
            'eta_minutes' => $data['etaMinutes'],
            'free_above_cents' => $data['freeAboveCents'] ?? null,
            'radius_km' => $data['radiusKm'] ?? null,
            'accepts_pickup' => $data['acceptsPickup'] ?? true,
            'accepts_delivery' => $data['acceptsDelivery'] ?? true,
            'merchant_phone' => $data['merchantPhone'] ?? null,
        ];
        $settings->save();

        return response()->json(['delivery' => $settings->delivery_config]);
    }

    public function updateHours(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->getOrFail();

        $rules = ['isOpenOverride' => ['nullable', 'boolean']];

        foreach (self::DAYS as $day) {
            $rules["hours.{$day}.enabled"] = ['required', 'boolean'];
            $rules["hours.{$day}.open"] = ['required', 'date_format:H:i'];
            $rules["hours.{$day}.close"] = ['required', 'date_format:H:i'];
        }

        $data = $request->validate($rules);

        $settings = TenantSettings::firstOrNew(['tenant_id' => $tenant->id]);
        $settings->business_hours = $data['hours'];
        // null = segue o horário; true/false = força aberto/fechado agora.
        $settings->is_open_override = $data['isOpenOverride'] ?? null;
        $settings->save();

        return response()->json(['businessHours' => $settings->business_hours]);
    }

    public function updatePayments(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->getOrFail();

        $data = $request->validate([
            'methods' => ['required', 'array', 'min:1'],
            'methods.*' => [Rule::in(self::PAYMENT_METHODS)],
        ]);

        // Pagamento online só é ofertável depois do onboarding do Stripe
        // Connect; aceitar aqui deixaria o cliente final num checkout que falha.
        $methods = collect($data['methods'])
            ->reject(fn (string $method) => str_starts_with($method, 'stripe_')
                && ! $tenant->acceptsOnlinePayment())
            ->values()
            ->all();

        if ($methods === []) {
            $methods = ['cash'];
        }

        $settings = TenantSettings::firstOrNew(['tenant_id' => $tenant->id]);
        $settings->payment_methods = $methods;
        $settings->save();

        return response()->json(['paymentMethods' => $methods]);
    }

    public function updateTheme(
        Request $request,
        TenantContext $context,
        ThemeSanitizer $sanitizer,
    ): JsonResponse {
        $tenant = $context->getOrFail();

        $settings = TenantSettings::firstOrNew(['tenant_id' => $tenant->id]);
        $settings->theme = $sanitizer->sanitize($request->input('theme', []));
        $settings->save();

        return response()->json(['theme' => $settings->theme]);
    }

    /** Avança o wizard. A loja só é publicada quando todos os passos passam. */
    public function updateOnboarding(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->getOrFail();

        $data = $request->validate([
            'step' => ['required', 'integer', 'min:1', 'max:5'],
            'complete' => ['boolean'],
        ]);

        $tenant->onboarding_step = max($tenant->onboarding_step, $data['step']);

        if ($data['complete'] ?? false) {
            $tenant->onboarding_completed_at = now();
            $tenant->status = $tenant->status === 'trial' ? 'trial' : 'active';
        }

        $tenant->save();

        return response()->json([
            'onboardingStep' => $tenant->onboarding_step,
            'onboardingCompleted' => $tenant->onboarding_completed_at !== null,
        ]);
    }

    /** @return array<string,mixed> */
    private function deliveryDefaults(array $config): array
    {
        return [
            'feeCents' => (int) ($config['fee_cents'] ?? 0),
            'minOrderCents' => (int) ($config['min_order_cents'] ?? 0),
            'etaMinutes' => (int) ($config['eta_minutes'] ?? 40),
            'freeAboveCents' => $config['free_above_cents'] ?? null,
            'radiusKm' => $config['radius_km'] ?? null,
            'acceptsPickup' => (bool) ($config['accepts_pickup'] ?? true),
            'acceptsDelivery' => (bool) ($config['accepts_delivery'] ?? true),
            'merchantPhone' => $config['merchant_phone'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function hoursDefaults(array $hours): array
    {
        return collect(self::DAYS)
            ->mapWithKeys(fn (string $day) => [
                $day => [
                    'enabled' => (bool) ($hours[$day]['enabled'] ?? true),
                    'open' => $hours[$day]['open'] ?? '18:00',
                    'close' => $hours[$day]['close'] ?? '23:00',
                ],
            ])
            ->all();
    }
}
