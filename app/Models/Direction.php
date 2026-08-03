<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Direction extends Model
{
      protected $fillable = [
        'nom',
        'description'
    ];

    /**
     * Relation avec les attributions
     */
    public function attributions()
    {
        return $this->hasMany(Attribution::class);
    }

    /**
     * Relation avec les utilisateurs
     */
    public function utilisateurs()
    {
        return $this->hasMany(User::class);
    }
}
