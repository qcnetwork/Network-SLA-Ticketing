<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event in a customer's material log: material installed, removed (back to the office or written off),
 * or an install cancelled because it was entered by mistake.
 */
class CustomerMaterialLog extends Model
{
    public const PASANG = 'pasang';

    public const LEPAS = 'lepas';

    public const KEMBALI = 'kembali';

    public const BATAL = 'batal';

    /** label, icon, badge classes */
    public const ACTIONS = [
        self::PASANG => ['Dipasang', 'plug', 'border-emerald-300 bg-emerald-50 text-emerald-700'],
        self::KEMBALI => ['Dilepas, kembali ke gudang', 'undo-2', 'border-sky-300 bg-sky-50 text-sky-700'],
        self::LEPAS => ['Dilepas', 'unplug', 'border-rose-300 bg-rose-50 text-rose-700'],
        self::BATAL => ['Dibatalkan', 'x-circle', 'border-slate-300 bg-slate-50 text-slate-600'],
    ];

    protected $table = 'gudang_pelanggan_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['happened_at' => 'datetime'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(CustomerMaterial::class, 'gudang_pelanggan_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function usage(): BelongsTo
    {
        return $this->belongsTo(MaterialUsage::class, 'material_usage_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::ACTIONS[$this->action][0] ?? $this->action;
    }

    public function icon(): string
    {
        return self::ACTIONS[$this->action][1] ?? 'circle';
    }

    public function badgeClasses(): string
    {
        return self::ACTIONS[$this->action][2] ?? self::ACTIONS[self::BATAL][2];
    }
}
