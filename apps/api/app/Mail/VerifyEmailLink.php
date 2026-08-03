<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Link de confirmação do e-mail do lojista.
 *
 * Não é ShouldQueue, pelo mesmo motivo do PasswordResetLink e ao contrário do
 * WelcomeStoreOwner: o lojista está parado na tela "confirme seu e-mail" e não
 * consegue entrar no painel até clicar neste link. Um worker parado deixaria a
 * conta inacessível por tempo indeterminado, logo depois do cadastro — a pior
 * hora possível para uma falha silenciosa.
 */
class VerifyEmailLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Tenant $tenant,
        public string $token,
        public int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Confirme seu e-mail — {$this->tenant->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.verify-email',
            with: [
                'url' => $this->verifyUrl(),
                'storeName' => $this->tenant->name,
                'userName' => $this->firstName(),
                'minutes' => $this->minutes,
            ],
        );
    }

    /** Só o primeiro nome no "Olá", como nas boas-vindas. */
    private function firstName(): string
    {
        return trim(explode(' ', trim($this->user->name))[0]) ?: $this->user->name;
    }

    /**
     * URL da tela de confirmação, no painel.
     *
     * O e-mail e o slug viajam junto do token porque a conta é o par
     * (loja, e-mail) — o mesmo endereço pode existir em lojas diferentes, e o
     * backend recusa um token usado fora da loja para a qual foi emitido.
     *
     * Mesmo fallback de host do PasswordResetLink: sem TENANCY_ADMIN_URL no
     * env, um link sem host chegaria ao lojista.
     */
    private function verifyUrl(): string
    {
        $base = rtrim((string) config('tenancy.admin_url'), '/');

        if ($base === '') {
            $base = 'https://app.'.config('tenancy.root_domain');
        }

        return $base.'/confirmar-email?'.http_build_query([
            'token' => $this->token,
            'email' => $this->user->email,
            'tenant' => $this->tenant->slug,
        ]);
    }
}
