<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArchiveAttribution extends Model
{
     protected $table = 'archives_attributions';
     
     protected $fillable = [
        'attribution_id',
        'materiel_id',
        'beneficiaire_id',
        'direction_id',
        'site_id',
        'date_attribution',
        'date_restitution',
        'statut',
        'observation',
        'users_id',
    ];

    public function materiel()
    {
        return $this->belongsTo(Materiel::class);
    }

    public function beneficiaire()
    {
        return $this->belongsTo(Beneficiaire::class);
    }

    public function direction()
    {
        return $this->belongsTo(Direction::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function utilisateur()
    {
        return $this->belongsTo(User::class, 'users_id');
    }
}
