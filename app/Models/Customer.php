<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Model
{
    use HasApiTokens;
    
    protected $fillable = [
        'name',
        'surname',
        'email',
        'phone',
        'password',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    public function addresses()
    {
        return $this->hasMany(CustomerAddress::class);
    }
}
