<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'segment', 'theme', 'logo_url', 'cover_url', 'business_hours',
    'delivery_config', 'payment_methods', 'is_open_override',
    'phone', 'whatsapp', 'address', 'description',
])]
class TenantSettings extends Model
{
    use HasFactory;

    protected $table = 'tenant_settings';

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'business_hours' => 'array',
            'delivery_config' => 'array',
            'payment_methods' => 'array',
            'is_open_override' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
