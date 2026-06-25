<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Package extends Model
{
    use SoftDeletes;

    protected $table = 'packages';

    protected $primaryKey = 'packagesID';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'packagesID',
        'name',
        'price',
        'billing_cycle',
        'payment_limit',
        'group_limit',
        'status',
    ];

    protected $casts = [
        'price'         => 'float',   // ← change from 'decimal:2'
        'payment_limit' => 'integer',
        'group_limit'   => 'integer',
    ];
    

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($package) {
            if (empty($package->packagesID)) {
                $package->packagesID = (string) Str::uuid();
            }
        });
    }

    public function subscriptions()
    {
        return $this->hasMany(
            UserSubscription::class,
            'package_id',
            'packagesID'
        );
    }

    public function isUnlimitedPayments(): bool
    {
        return is_null($this->payment_limit);
    }

    public function isUnlimitedGroups(): bool
    {
        return is_null($this->group_limit);
    }

    public function isLifetime(): bool
    {
        return $this->billing_cycle === 'lifetime';
    }
}