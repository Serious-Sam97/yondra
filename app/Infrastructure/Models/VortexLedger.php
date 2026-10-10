<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/** One coin movement (N-03). Balances are always sums of this table. */
class VortexLedger extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'vortex_ledger';

    protected $fillable = ['user_id', 'currency', 'amount', 'reason'];
}
