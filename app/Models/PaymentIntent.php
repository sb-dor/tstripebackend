<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentIntent extends Model
{
    protected $fillable = [
        'order_id',
        'stripe_id',
        'client_secret',
        'status',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
