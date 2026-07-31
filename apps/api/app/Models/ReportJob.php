<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de uma exportação de relatório enfileirada.
 *
 * Existe para que o pedido de PDF sobreviva ao request: a rota só cria a linha
 * e devolve 202, e o worker preenche o resultado. O admin acompanha o estado
 * por polling neste registro, então fechar a aba não perde o trabalho.
 */
#[Fillable([
    'user_id', 'type', 'status', 'from_date', 'to_date', 'search',
    'file_path', 'file_size', 'row_count', 'error', 'started_at', 'finished_at',
])]
class ReportJob extends Model
{
    use BelongsToTenant;

    /** Arquivos mais velhos que isto são descartados pela limpeza agendada. */
    public const RETENTION_DAYS = 7;

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'file_size' => 'integer',
            'row_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDone(): bool
    {
        return $this->status === 'done' && $this->file_path !== null;
    }
}
