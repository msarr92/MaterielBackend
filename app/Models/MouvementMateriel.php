<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MouvementMateriel extends Model
{
    protected $table = 'mouvements_materiels';
     protected $fillable = [
        'materiel_id',
        'user_id',
        'direction_id',
        'type_mouvement',
        'date_mouvement',
        'etat_materiel',
        'observation',
        'created_by'
    ];

     // 🔥 Constantes pour les types de mouvement
    const TYPE_ATTRIBUTION = 'ATTRIBUTION';
    const TYPE_RETOUR = 'RETOUR';
    const TYPE_PANNE = 'PANNE';
    const TYPE_MAINTENANCE = 'MAINTENANCE';
    const TYPE_REAFFECTATION = 'REAFFECTATION';
    const TYPE_REFORME = 'REFORME';
    const TYPE_RETOUR_STOCK = 'RETOUR_STOCK';
    const TYPE_MODIFICATION_ETAT = 'MODIFICATION_ETAT';

    public function materiel()
    {
        return $this->belongsTo(Materiel::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function direction()
    {
        return $this->belongsTo(Direction::class);
    }

    public function site()
{
    return $this->belongsTo(Site::class);
}

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

}
