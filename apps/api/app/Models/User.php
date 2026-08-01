<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['tenant_id', 'name', 'email', 'password', 'role', 'is_platform_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Deliberadamente NÃO usa BelongsToTenant.
     *
     * O login precisa buscar o usuário antes de existir contexto de tenant, e o
     * escopo global impediria essa consulta. O isolamento aqui é feito pelo
     * middleware EnsureUserBelongsToTenant, que compara tenant_id após o auth.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isPlatformStaff(): bool
    {
        return $this->tenant_id === null;
    }

    /**
     * Acesso ao painel da plataforma (admin.to-menu.com).
     *
     * Exige as duas condições, não só a flag: um usuário com tenant_id
     * preenchido é dono de uma loja, e dono de loja nunca administra a
     * plataforma. A constraint CHECK no banco impede essa combinação de
     * existir, e esta conjunção garante que, se ela existir mesmo assim
     * (restore de dump antigo, migration revertida pela metade), o acesso é
     * negado em vez de concedido.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->tenant_id === null && $this->is_platform_admin === true;
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }
}
