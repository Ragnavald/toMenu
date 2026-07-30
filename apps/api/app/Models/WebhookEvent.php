<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de idempotência para webhooks.
 *
 * Sem tenant_id e sem BelongsToTenant: eventos chegam fora de qualquer
 * contexto de tenant, e a rota do webhook não passa pelo IdentifyTenant.
 */
#[Fillable(['provider', 'event_id', 'type', 'payload', 'processed_at'])]
class WebhookEvent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
