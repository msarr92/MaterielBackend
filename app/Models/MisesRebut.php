<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MisesRebut extends Model
{
    protected $fillable = [
        'materiel_id',
        'date_rebut',
        'motif',
        'decisionnaire',
        'observation',
        'created_by'
    ];

    public function materiel()
    {
        return $this->belongsTo(Materiel::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
