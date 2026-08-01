<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Registro imutável de uma ação do staff da plataforma.
 *
 * Deliberadamente NÃO usa BelongsToTenant: é tabela central, e o global scope
 * esconderia as linhas justamente na consulta que importa — a listagem no
 * painel, que roda sem tenant no contexto.
 *
 * `UPDATED_AT = null` porque a tabela não tem a coluna: auditoria não se
 * reescreve. O REVOKE na migration garante isso no banco também.
 */
#[Fillable([
    'action', 'actor_id', 'actor_email',
    'tenant_id', 'tenant_slug', 'tenant_name',
    'context', 'ip',
])]
class PlatformAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    /**
     * Grava a ação com os dados da loja copiados para dentro da linha.
     *
     * O tenant é lido agora, antes de qualquer purga, porque depois dela não
     * haverá linha para consultar. Ver o cabeçalho da migration.
     */
    public static function record(
        string $action,
        Tenant $tenant,
        ?User $actor,
        array $context = [],
        ?Request $request = null,
    ): self {
        return self::create([
            'action' => $action,
            'actor_id' => $actor?->getKey(),
            'actor_email' => $actor?->email,
            'tenant_id' => $tenant->getKey(),
            'tenant_slug' => $tenant->slug,
            'tenant_name' => $tenant->name,
            'context' => $context ?: null,
            'ip' => $request?->ip(),
        ]);
    }
}
