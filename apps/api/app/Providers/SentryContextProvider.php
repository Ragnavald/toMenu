<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\State\Scope;

use function Sentry\configureScope;

/**
 * Enriquece os eventos do Sentry com o contexto desta aplicação.
 *
 * Um alerta de erro numa plataforma multi-tenant sem dizer QUAL loja quebrou é
 * quase inútil: obriga a cruzar horário do evento com log de acesso para
 * descobrir quem foi afetado. A tag `tenant` transforma isso em um filtro.
 *
 * O contexto é montado no `before_send`, e não no boot da aplicação, porque o
 * tenant só existe depois do middleware `identify.tenant` — no boot ainda não
 * há nada para registrar.
 */
class SentryContextProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Sem DSN o SDK está inerte; registrar o callback só gastaria ciclos.
        if (! config('sentry.dsn')) {
            return;
        }

        configureScope(function (Scope $scope): void {
            $scope->addEventProcessor(
                fn (Event $event, ?EventHint $hint) => $this->enrich($event),
            );
        });
    }

    private function enrich(Event $event): Event
    {
        $tenant = app(TenantContext::class)->get();

        if ($tenant) {
            // Tag, e não contexto: no Sentry só tag é indexada para busca e
            // agregação. É o que permite ver "esta loja gerou 40 erros hoje".
            $event->setTag('tenant', $tenant->slug);
            $event->setTag('tenant_id', (string) $tenant->getKey());
        } else {
            // Distinguir "sem tenant" de "tenant não identificado" importa: o
            // primeiro é rota central ou job de plataforma, o segundo pode ser
            // justamente o bug — um middleware que não rodou.
            $event->setTag('tenant', 'nenhum');
        }

        /*
         * Usuário, quando houver.
         *
         * Sem e-mail nem IP de propósito: `send_default_pii` fica false e não
         * há motivo para exportar dado pessoal do lojista para um serviço
         * externo. O id e o papel bastam para saber se o erro atinge um dono
         * ou um atendente, que é a pergunta operacional real.
         */
        if ($user = Auth::user()) {
            $event->setUser(\Sentry\UserDataBag::createFromArray([
                'id' => $user->getAuthIdentifier(),
                'role' => $user->role ?? null,
            ]));
        }

        return $event;
    }
}
