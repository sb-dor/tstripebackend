<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'amount',
        'currency',
        'status',
        'stripe_payment_intent_id',
        'stripe_client_secret',
    ];

    public function paymentIntents()
    {
        return $this->hasMany(PaymentIntent::class);
    }
}
