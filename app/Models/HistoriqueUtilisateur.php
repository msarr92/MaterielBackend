<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoriqueUtilisateur extends Model
{
    protected $fillable = [
        'user_id',
        'materiel_id',
        'direction_id',
        'site_id',
        'date_debut',
        'date_fin',
        'type_action'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function materiel()
    {
        return $this->belongsTo(Materiel::class);
    }

    public function direction()
    {
        return $this->belongsTo(Direction::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
    
}
