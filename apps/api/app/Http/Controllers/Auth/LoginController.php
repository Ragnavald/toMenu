<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'tenant' => ['required', 'string'], // slug da loja
        ]);

        $tenant = Tenant::where('slug', $data['tenant'])->first();

        $user = $tenant
            ? User::where('tenant_id', $tenant->id)->where('email', $data['email'])->first()
            : null;

        // Comparação executada mesmo sem usuário encontrado, para que o tempo de
        // resposta não revele quais e-mails existem (timing attack).
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Credenciais inválidas.',
            ]);
        }

        /*
         * E-mail não confirmado barra o login.
         *
         * Depois da checagem de senha, e não antes: responder "confirme seu
         * e-mail" a quem errou a senha revelaria que a conta existe, desfazendo
         * o cuidado da mensagem única acima.
         *
         * 403 e não 422 para que o painel distinga este caso de credencial
         * errada sem depender do texto da mensagem — é ele que decide levar o
         * lojista à tela de confirmação em vez de mostrar erro no formulário.
         * O `code` é o contrato: a mensagem pode mudar, ele não.
         *
         * Contas anteriores à confirmação de e-mail não são afetadas: a
         * migration marcou `email_verified_at` para todas elas, porque trancar
         * fora do painel quem já usava a plataforma seria uma quebra sem aviso.
         */
        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'code' => 'email_unverified',
                'message' => 'Confirme seu e-mail antes de entrar. Enviamos um link para '.$user->email.'.',
                'email' => $user->email,
                'tenant' => $tenant->slug,
            ], 403);
        }

        return response()->json([
            'token' => $user->createToken('admin')->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sessão encerrada.']);
    }
}
