<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\TenantRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cadastro público de novas lojas.
 *
 * Fora do escopo de tenant por definição: a loja está sendo criada agora.
 */
class RegistrationController extends Controller
{
    public function __construct(private TenantRegistrar $registrar) {}

    /** Checagem de disponibilidade usada ao vivo no formulário de cadastro. */
    public function checkSlug(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9-]+$/'],
        ]);

        return response()->json([
            'slug' => $data['slug'],
            'available' => $this->registrar->isSlugAvailable($data['slug']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required', 'string', 'min:3', 'max:40',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                // Unicidade checada contra registros soft-deleted também: o
                // slug vira subdomínio e reaproveitá-lo confundiria links
                // antigos e caches de CDN.
                Rule::unique('tenants', 'slug'),
                Rule::notIn(TenantRegistrar::RESERVED_SLUGS),
            ],
            'owner_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            // Ausente = Pro, o plano que existia quando o cadastro só tinha uma
            // opção. Validado contra a lista de contratáveis e não contra a
            // tabela: um plano interno não deve virar contratável por existir.
            'plan' => ['nullable', Rule::in(Plan::PUBLIC_SLUGS)],
            /*
             * Aceite dos Termos e da Privacidade.
             *
             * `accepted` e não `boolean`: a regra exige que o valor seja
             * verdadeiro, então "não marquei a caixa" e "não mandei o campo"
             * caem os dois em 422. Um `boolean` aceitaria `false` como entrada
             * válida e deixaria a conta nascer sem consentimento.
             *
             * A checagem vive aqui, e não só no formulário: o checkbox do
             * front é conveniência de UX: qualquer cliente pode postar direto
             * neste endpoint, e é o servidor que precisa poder afirmar que
             * nenhuma loja foi criada sem aceite.
             */
            'accepted_terms' => ['accepted'],
        ], [
            'slug.regex' => 'Use apenas letras minúsculas, números e hífens.',
            'slug.unique' => 'Este endereço já está em uso.',
            'slug.not_in' => 'Este endereço é reservado pela plataforma.',
            'password.confirmed' => 'A confirmação de senha não confere.',
            'accepted_terms.accepted' => 'É preciso aceitar os Termos de Uso e a Política de Privacidade.',
        ]);

        // O IP é lido do request e não vem do corpo: é evidência do aceite, e
        // evidência que o cliente escolhe sozinho não prova nada.
        $data['ip'] = $request->ip();

        $result = $this->registrar->register($data);
        $tenant = $result['tenant'];

        return response()->json([
            'token' => $result['token'],
            'user' => [
                'name' => $result['user']->name,
                'email' => $result['user']->email,
                'role' => $result['user']->role,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'onboardingStep' => $tenant->onboarding_step,
                'storefrontUrl' => $this->storefrontUrl($tenant->slug),
                'plan' => $tenant->plan?->slug,
            ],
        ], 201);
    }

    private function storefrontUrl(string $slug): string
    {
        $root = config('tenancy.root_domain');
        $scheme = config('tenancy.storefront_scheme', 'https');
        $port = config('tenancy.storefront_port');

        return $port
            ? "{$scheme}://{$slug}.{$root}:{$port}"
            : "{$scheme}://{$slug}.{$root}";
    }
}
