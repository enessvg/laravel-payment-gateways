<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAddress extends Model
{
    protected $fillable = [
        'customer_id',
        'address',
        'city',
        'country',
        'zip_code',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
