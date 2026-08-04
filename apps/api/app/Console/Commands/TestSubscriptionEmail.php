<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionConfirmed;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Envia a confirmação de assinatura para conferir o caminho do e-mail.
 *
 * Existe porque validar isso pelo `tinker --execute` é frágil: o shell come as
 * barras e as aspas do PHP embutido, e o comando falha em silêncio — sem
 * mensagem de erro e sem enviar nada, que é o pior dos dois mundos quando se
 * está justamente tentando descobrir se o envio funciona.
 *
 * O que este comando prova, quando o e-mail chega: mailable e template
 * renderizam em produção, a fila aceita o job, o worker consome e o provedor
 * de e-mail está configurado. É o ensaio do que vai acontecer sozinho quando o
 * webhook do Stripe confirmar um pagamento de verdade.
 */
class TestSubscriptionEmail extends Command
{
    protected $signature = 'billing:test-email
        {tenant : ID ou slug da loja}
        {--sync : Envia na hora, sem passar pela fila}';

    protected $description = 'Envia a confirmação de assinatura para testar fila, template e provedor de e-mail';

    public function handle(): int
    {
        $key = $this->argument('tenant');

        // withoutGlobalScopes: comando de console não tem contexto de tenant.
        $tenant = Tenant::withoutGlobalScopes()
            ->where('id', is_numeric($key) ? $key : 0)
            ->orWhere('slug', $key)
            ->first();

        if (! $tenant) {
            $this->error("Loja \"{$key}\" não encontrada.");

            return self::FAILURE;
        }

        $owner = $tenant->users()->where('role', 'owner')->first();

        if (! $owner) {
            $this->error("A loja \"{$tenant->slug}\" não tem dono cadastrado.");

            return self::FAILURE;
        }

        $mailable = new SubscriptionConfirmed($owner, $tenant);

        /*
         * `--sync` contorna a fila de propósito.
         *
         * Com a fila no meio, "não chegou" tem duas causas possíveis (worker
         * parado ou provedor mal configurado) e o comando não distingue. No
         * modo síncrono a falha do provedor vira exceção aqui na hora, com a
         * mensagem real. Rodar os dois separa as hipóteses.
         */
        try {
            $this->option('sync')
                ? Mail::to($owner->email)->sendNow($mailable)
                : Mail::to($owner->email)->send($mailable);
        } catch (\Throwable $e) {
            $this->error('Falha no envio: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('sync')
            ? "E-mail enviado na hora para {$owner->email}."
            : "E-mail enfileirado para {$owner->email}.");

        $this->line("Loja: {$tenant->name} (id {$tenant->id})");
        $this->line('Mailer: '.config('mail.default'));

        if (! $this->option('sync')) {
            $this->newLine();
            $this->line('Se não chegar, confira se o worker está de pé e veja `queue:failed`.');
        }

        return self::SUCCESS;
    }
}
