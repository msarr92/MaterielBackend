<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
     protected $fillable = [
        'nom',
        'adresse'
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
