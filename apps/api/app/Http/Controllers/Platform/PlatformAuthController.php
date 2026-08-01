<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Login do staff da plataforma.
 *
 * Separado do LoginController do lojista porque a identidade é outra: lá o
 * usuário é único por (tenant_id, email) e o slug da loja faz parte das
 * credenciais; aqui não há loja nenhuma, e o e-mail é único entre as contas
 * centrais (índice parcial `users_central_email_unique`).
 */
class PlatformAuthController extends Controller
{
    /** Habilidade do token do painel da plataforma. Ver EnsurePlatformAdmin. */
    public const ABILITY = 'platform';

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::whereNull('tenant_id')
            ->where('is_platform_admin', true)
            ->where('email', $data['email'])
            ->first();

        /*
         * O Hash::check roda mesmo sem usuário, contra um hash descartável.
         *
         * Sem isso, a resposta para um e-mail inexistente volta na hora e a de
         * um e-mail válido leva o tempo do bcrypt — a diferença é medível e
         * revela quais contas de staff existem. Mesma defesa do login do
         * lojista.
         */
        $hash = $user?->password ?? '$2y$12$'.str_repeat('x', 53);

        if (! Hash::check($data['password'], $hash) || ! $user) {
            throw ValidationException::withMessages([
                'email' => 'Credenciais inválidas.',
            ]);
        }

        return response()->json([
            'token' => $user->createToken('platform', [self::ABILITY])->plainTextToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sessão encerrada.']);
    }
}
