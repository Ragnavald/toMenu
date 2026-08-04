<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim($request->string('search')->toString());

        $orders = Order::query()
            ->with(['items:id,order_id,product_name,quantity,total_cents', 'customer:id,name,phone'])
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $cleanSearch = ltrim($search, '#');
                    $sub->where('number', 'like', "%{$cleanSearch}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($search) {
                            $customerQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        })
                        ->orWhereHas('items', function ($itemQuery) use ($search) {
                            $itemQuery->where('product_name', 'like', "%{$search}%");
                        });
                });
            })
            /*
             * O painel é o movimento corrente: o que foi arquivado saiu de
             * cena e só reaparece na aba de histórico. Sem este filtro o botão
             * "Finalizar" não teria efeito visível nenhum — o card continuaria
             * na coluna Entregues depois de arquivado.
             */
            ->whereNull('archived_at')
            ->latest('placed_at')
            ->paginate(min(100, max(1, $request->integer('per_page', 50))));

        return response()->json($orders);
    }

    /**
     * Histórico: o que já saiu do painel.
     *
     * Espelho exato do `index` — mesma busca, mesmo eager loading — invertendo
     * apenas o lado do `archived_at`, mais o recorte por data. A ordenação é
     * por `archived_at` e não por `placed_at` porque aqui a pergunta do lojista
     * é "o que eu finalizei", e num mutirão de limpeza vários pedidos de dias
     * diferentes são arquivados na mesma hora.
     */
    public function archived(Request $request): JsonResponse
    {
        $search = trim($request->string('search')->toString());

        $orders = Order::query()
            ->with(['items:id,order_id,product_name,quantity,total_cents', 'customer:id,name,phone'])
            ->whereNotNull('archived_at')
            /*
             * Datas vêm como `YYYY-MM-DD` do <input type="date">, sem hora. Sem
             * o startOfDay/endOfDay o dia final ficaria de fora: `2026-08-04`
             * vira meia-noite, e um pedido arquivado às 14h daquele dia cairia
             * depois do limite.
             */
            ->when(
                $request->date('from'),
                fn ($q, $from) => $q->where('archived_at', '>=', $from->startOfDay()),
            )
            ->when(
                $request->date('to'),
                fn ($q, $to) => $q->where('archived_at', '<=', $to->endOfDay()),
            )
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $cleanSearch = ltrim($search, '#');
                    $sub->where('number', 'like', "%{$cleanSearch}%")
                        ->orWhereHas('customer', function ($customerQuery) use ($search) {
                            $customerQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        })
                        ->orWhereHas('items', function ($itemQuery) use ($search) {
                            $itemQuery->where('product_name', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('archived_at')
            ->paginate(min(100, max(1, $request->integer('per_page', 50))));

        return response()->json($orders);
    }

    /**
     * Tira o pedido do painel sem mexer no que ele foi.
     *
     * Devolve 422 e não 403 quando o pedido ainda não está entregue: não é
     * falta de permissão, é estado errado — e a mensagem é o que a tela mostra
     * se o card ficou obsoleto entre a renderização e o clique.
     */
    public function archive(Order $order): JsonResponse
    {
        if ($order->status !== 'delivered') {
            return response()->json([
                'message' => 'Só é possível finalizar um pedido já entregue.',
            ], 422);
        }

        // Já arquivado é sucesso, não erro: dois cliques rápidos ou duas abas
        // abertas não deveriam produzir um alerta vermelho para o lojista.
        if ($order->archived_at === null) {
            $order->update(['archived_at' => now()]);
        }

        return response()->json($order);
    }

    /**
     * Limpa o histórico de vez.
     *
     * Apaga as linhas, e é por isso que o recorte é obrigatoriamente o dos
     * arquivados: um pedido em preparo nunca pode ser alcançado por aqui. Os
     * itens e os pagamentos saem junto pelas FKs em cascata de `order_items` e
     * `payments`.
     *
     * O financeiro lê a mesma tabela `orders`, então isto some com a receita do
     * período apagado — a tela avisa antes de chamar, e a exportação em CSV é o
     * caminho para quem quer guardar os números antes de limpar.
     */
    public function clearArchived(Request $request): JsonResponse
    {
        $deleted = Order::query()
            ->whereNotNull('archived_at')
            ->when(
                $request->date('from'),
                fn ($q, $from) => $q->where('archived_at', '>=', $from->startOfDay()),
            )
            ->when(
                $request->date('to'),
                fn ($q, $to) => $q->where('archived_at', '<=', $to->endOfDay()),
            )
            ->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /**
     * Muda o status do pedido — e, com ele, o que o financeiro enxerga.
     *
     * A sincronia do pagamento mora em `Order::moveToStatus()`, não aqui: o
     * mesmo movimento vai precisar acontecer em qualquer outro caminho que
     * mude status (importação, automação, tela do entregador), e duplicar a
     * regra no controller é como o número da tela passaria a divergir do CSV.
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
        ]);

        $order->moveToStatus($data['status']);

        return response()->json($order);
    }
}
