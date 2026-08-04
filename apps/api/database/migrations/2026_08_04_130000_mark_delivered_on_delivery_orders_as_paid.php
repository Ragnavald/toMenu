<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Acerta o caixa dos pedidos entregues antes da sincronia existir.
 *
 * O financeiro só soma `status = delivered` E `payment_status = paid` (ver
 * FinanceReportService). Até agora, o único lugar que marcava `paid` era o
 * webhook do Stripe: o pedido pago na entrega nascia `pending` e continuava
 * `pending` depois de entregue, porque não existe gateway para avisar que o
 * dinheiro trocou de mãos na porta do cliente. Com o pagamento pela plataforma
 * removido, isso significava um relatório permanentemente vazio.
 *
 * `Order::moveToStatus()` resolve daqui para frente. Esta migration resolve o
 * passado, que o código novo não alcança — o pedido já foi entregue, ninguém
 * vai mexer no status dele de novo, e sem este UPDATE ele fica fora da receita
 * para sempre.
 *
 * O recorte é deliberadamente estreito, porque cada condição evita inventar um
 * recebimento que não houve:
 *
 *   `status = delivered`   entregue é o momento em que o dinheiro entra neste
 *                          modelo. Cancelado e em preparo não são caixa.
 *
 *   métodos na entrega     `stripe_*` fica de fora: ali quem decide é o
 *                          webhook, e um pedido online ainda `pending` é um
 *                          pagamento que de fato não foi concluído. Marcá-lo
 *                          como pago registraria dinheiro que nunca entrou.
 *
 *   `payment_status`       só o que está pendente. Um `refunded` ou `failed`
 *   restrito               é informação real que não pode ser sobrescrita.
 *
 * Não tem `down()` que valha: reverter significaria devolver a `pending`
 * pedidos que podem ter sido marcados como pagos legitimamente depois, e o
 * estado anterior não é distinguível do posterior. Deixar a receita correta é
 * mais seguro do que poder desfazer.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * SQL cru e não Eloquent: o model tem global scope de tenant, que numa
         * migration (sem tenant no contexto) filtraria tudo e faria o UPDATE
         * não pegar linha nenhuma — falhando em silêncio, que é o pior modo de
         * falhar aqui. Um UPDATE só também evita percorrer a tabela inteira em
         * PHP num banco de produção.
         */
        $updated = DB::table('orders')
            ->where('status', 'delivered')
            ->whereIn('payment_method', ['cash', 'card_on_delivery', 'pix_on_delivery'])
            ->where('payment_status', 'pending')
            ->update(['payment_status' => 'paid']);

        if ($updated > 0) {
            echo "  Pedidos entregues acertados para pago: {$updated}\n";
        }
    }

    public function down(): void
    {
        // Sem reversão: ver o cabeçalho.
    }
};
