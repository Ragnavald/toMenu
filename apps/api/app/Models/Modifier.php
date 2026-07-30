<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['modifier_group_id', 'name', 'price_delta_cents', 'is_available', 'position'])]
class Modifier extends Model
{
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'price_delta_cents' => 'integer',
            'is_available' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }
}
