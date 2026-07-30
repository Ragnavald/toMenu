<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Business Cloud API.
 *
 * Fora da janela de 24h de atendimento, a Meta só permite mensagens baseadas em
 * template previamente aprovado — aprovação leva de 24 a 48h. Os templates
 * precisam existir antes do lançamento, não durante.
 */
class WhatsAppService
{
    private const API = 'https://graph.facebook.com/v21.0';

    public function notifyMerchant(Tenant $tenant, Order $order): void
    {
        if (! $tenant->whatsapp_phone_id || ! $tenant->whatsapp_token) {
            return; // Tenant não configurou o canal; não é erro.
        }

        $this->sendTemplate(
            tenant: $tenant,
            to: $tenant->settings?->delivery_config['merchant_phone'] ?? null,
            template: 'novo_pedido',
            params: [
                (string) $order->number,
                $this->formatMoney($order->total_cents),
                $order->customer?->name ?? 'Cliente',
            ],
        );
    }

    public function notifyCustomer(Tenant $tenant, Order $order): void
    {
        $this->sendTemplate(
            tenant: $tenant,
            to: $order->customer?->phone,
            template: 'pedido_confirmado',
            params: [
                $order->customer?->name ?? 'Cliente',
                (string) $order->number,
                $tenant->name,
            ],
        );
    }

    private function sendTemplate(Tenant $tenant, ?string $to, string $template, array $params): void
    {
        if (! $to || ! $tenant->whatsapp_phone_id) {
            return;
        }

        $response = Http::withToken($tenant->whatsapp_token)
            ->timeout(10)
            ->post(self::API."/{$tenant->whatsapp_phone_id}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $this->normalizePhone($to),
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => 'pt_BR'],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => array_map(
                            fn ($p) => ['type' => 'text', 'text' => $p],
                            $params,
                        ),
                    ]],
                ],
            ]);

        if ($response->failed()) {
            // Lança para acionar o retry com backoff do job. Uma notificação
            // perdida significa um pedido que a cozinha não viu.
            Log::warning('WhatsApp: falha no envio.', [
                'tenant_id' => $tenant->id,
                'template' => $template,
                'status' => $response->status(),
            ]);

            $response->throw();
        }
    }

    /** E.164 sem o "+", formato exigido pela Cloud API. */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return str_starts_with($digits, '55') ? $digits : "55{$digits}";
    }

    private function formatMoney(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
