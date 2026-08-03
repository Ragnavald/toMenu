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
 * Boas-vindas ao lojista que acabou de criar a loja.
 *
 * Enviado ao confirmar o e-mail, e não ao criar a loja: é a confirmação que
 * abre o painel, e "sua loja está no ar" chegando antes dela levaria o lojista
 * a uma porta trancada.
 *
 * ShouldQueue, ao contrário do PasswordResetLink e do VerifyEmailLink: quando
 * este e-mail sai o lojista já está dentro do painel, com a sessão emitida na
 * mesma resposta. Ninguém está parado esperando por ele, então não há motivo
 * para pendurar centenas de milissegundos de chamada ao Resend na request. Se
 * o worker estiver parado, o e-mail atrasa sem que o lojista perceba; se o
 * Resend cair, a fila tenta de novo em vez de derrubar a confirmação.
 */
class WelcomeStoreOwner extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Tenant $tenant,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Sua loja no ar: {$this->tenant->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.welcome',
            with: [
                'userName' => $this->firstName(),
                'storeName' => $this->tenant->name,
                'adminUrl' => $this->adminUrl(),
                'storefrontUrl' => $this->storefrontUrl(),
                'trialEndsAt' => $this->tenant->trial_ends_at,
            ],
        );
    }

    /**
     * Só o primeiro nome no "Olá".
     *
     * O cadastro pede o nome completo do dono, e "Olá, Ana Carolina Souza
     * Ribeiro" soa como mala direta justamente no primeiro contato da conta.
     */
    private function firstName(): string
    {
        return trim(explode(' ', trim($this->user->name))[0]) ?: $this->user->name;
    }

    /**
     * URL do painel, com o mesmo fallback do link de redefinição de senha:
     * sem TENANCY_ADMIN_URL no env o link chegaria sem host.
     */
    private function adminUrl(): string
    {
        $base = rtrim((string) config('tenancy.admin_url'), '/');

        if ($base === '') {
            $base = 'https://app.'.config('tenancy.root_domain');
        }

        return $base;
    }

    /**
     * Subdomínio público da loja.
     *
     * Mesma montagem do RegistrationController — em desenvolvimento o
     * storefront responde em :3000 e sem TLS; em produção, 443 implícito.
     */
    private function storefrontUrl(): string
    {
        $root = config('tenancy.root_domain');
        $scheme = config('tenancy.storefront_scheme', 'https');
        $port = config('tenancy.storefront_port');

        return $port
            ? "{$scheme}://{$this->tenant->slug}.{$root}:{$port}"
            : "{$scheme}://{$this->tenant->slug}.{$root}";
    }
}
