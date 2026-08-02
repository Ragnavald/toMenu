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
 * Link de redefinição de senha do painel.
 *
 * Não é ShouldQueue de propósito. O lojista está parado na tela esperando o
 * e-mail chegar, e um worker parado adiaria indefinidamente o único e-mail que
 * ele pediu explicitamente — exatamente o tipo de falha silenciosa que o resto
 * desta stack evita. O envio pelo Resend é uma chamada HTTP de centenas de
 * milissegundos, aceitável no ciclo da request.
 */
class PasswordResetLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Tenant $tenant,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Redefinir a senha do painel — {$this->tenant->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.password-reset',
            with: [
                'url' => $this->resetUrl(),
                'storeName' => $this->tenant->name,
                'userName' => $this->user->name,
                'minutes' => 60,
            ],
        );
    }

    /**
     * URL da tela de redefinição, no painel.
     *
     * O e-mail e o slug viajam na query porque a tela precisa dos três dados
     * para chamar a API — o token sozinho não identifica a conta, já que o
     * mesmo endereço pode existir em lojas diferentes.
     */
    private function resetUrl(): string
    {
        $base = rtrim((string) config('tenancy.admin_url'), '/');

        // Fallback para o host padrão do painel: sem TENANCY_ADMIN_URL no env,
        // um link vazio ("/redefinir-senha?...") chegaria ao lojista sem host.
        if ($base === '') {
            $base = 'https://app.'.config('tenancy.root_domain');
        }

        return $base.'/redefinir-senha?'.http_build_query([
            'token' => $this->token,
            'email' => $this->user->email,
            'tenant' => $this->tenant->slug,
        ]);
    }
}
