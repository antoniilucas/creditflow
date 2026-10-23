<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    public const TYPE_CREDIT = 'credit';

    public const TYPE_DEBIT = 'debit';

    public const TYPE_REVERSAL = 'reversal';

    public const TYPES = [self::TYPE_CREDIT, self::TYPE_DEBIT, self::TYPE_REVERSAL];

    protected $fillable = [
        'account_id',
        'responsible_user_id',
        'type',
        'amount_cents',
        'idempotency_key',
        'reversal_of_id',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'responsible_user_id' => 'integer',
            'amount_cents' => 'integer',
            'reversal_of_id' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
