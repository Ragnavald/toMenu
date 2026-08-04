<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Confirmação da assinatura da mensalidade.
 *
 * Enviado a partir do webhook, e não do retorno do `success_url`: aquela URL só
 * informa que o navegador voltou. O lojista pode fechar a aba antes do redirect
 * — e o pagamento continua valendo —, ou voltar antes de o Stripe confirmar a
 * cobrança. Quem sabe que a assinatura existe é o evento.
 *
 * ShouldQueue como o WelcomeStoreOwner, e pelo mesmo motivo: ninguém está
 * parado esperando este e-mail. Aqui pesa ainda mais, porque o remetente é o
 * processamento do webhook, e o Stripe considera falho o endpoint que demora —
 * pendurar uma chamada ao Resend ali provocaria reentrega do evento.
 */
class SubscriptionConfirmed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Tenant $tenant,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Assinatura confirmada: {$this->tenant->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.subscription-confirmed',
            with: [
                'userName' => $this->firstName(),
                'storeName' => $this->tenant->name,
                'planName' => $this->tenant->plan?->name,
                'priceCents' => $this->tenant->plan?->price_cents,
                'currentPeriodEndsAt' => $this->tenant->current_period_ends_at,
                'adminUrl' => $this->adminUrl(),
            ],
        );
    }

    /** Só o primeiro nome no "Olá", como no WelcomeStoreOwner. */
    private function firstName(): string
    {
        return trim(explode(' ', trim($this->user->name))[0]) ?: $this->user->name;
    }

    /** Mesmo fallback dos demais e-mails: sem TENANCY_ADMIN_URL o link iria sem host. */
    private function adminUrl(): string
    {
        $base = rtrim((string) config('tenancy.admin_url'), '/');

        if ($base === '') {
            $base = 'https://app.'.config('tenancy.root_domain');
        }

        return $base;
    }
}
