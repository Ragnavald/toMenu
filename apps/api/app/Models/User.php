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

#[Fillable(['tenant_id', 'name', 'email', 'password', 'role'])]
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

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }
}
