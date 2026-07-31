<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TenantDeleter;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Exclusão da loja pelo próprio lojista.
 *
 * Separado do SettingsController porque a operação não é uma configuração: é
 * irreversível pela interface, encerra o acesso de todos os usuários da loja e
 * tem regra de autorização própria (só o owner).
 */
class StoreDeletionController extends Controller
{
    /**
     * Resumo do que será perdido, para a tela de confirmação.
     *
     * Existe para que a confirmação seja informada: o lojista precisa ver o
     * volume de pedidos e o estado do recebimento antes de decidir.
     */
    public function preview(TenantContext $context): JsonResponse
    {
        $tenant = $context->getOrFail();

        return response()->json([
            'store' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'willLose' => [
                'products' => $tenant->products()->count(),
                'orders' => $tenant->orders()->count(),
                'users' => $tenant->users()->count(),
            ],
            // O lojista precisa saber que a conta Stripe dele NÃO é apagada e
            // que eventual saldo continua sendo dele.
            'stripeConnected' => $tenant->stripe_account_id !== null,
        ]);
    }

    public function destroy(
        Request $request,
        TenantContext $context,
        TenantDeleter $deleter,
    ): JsonResponse {
        $tenant = $context->getOrFail();
        $user = $request->user();

        // Só o dono encerra a loja. Um gerente com token válido não pode.
        abort_unless($user->isOwner(), 403, 'Apenas o dono da loja pode excluí-la.');

        $data = $request->validate([
            'password' => ['required', 'string'],
            'confirmation' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        // Senha atual: o token pode estar numa sessão esquecida aberta.
        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Senha incorreta.',
            ]);
        }

        // Digitar o slug evita o clique acidental no botão vermelho.
        if ($data['confirmation'] !== $tenant->slug) {
            throw ValidationException::withMessages([
                'confirmation' => "Digite exatamente “{$tenant->slug}” para confirmar.",
            ]);
        }

        $outcome = $deleter->delete($tenant, $data['reason'] ?? null);

        return response()->json([
            'deleted' => true,
            'stripe' => $outcome,
        ]);
    }
}
