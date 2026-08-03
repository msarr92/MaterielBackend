<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Materiel extends Model
{
    protected $fillable = [

        'code_materiel',

        'acquisition_id',

        'categorie',

        'numero_serie',

        'marque',

        'modele',

        'type_materiel',

        'quantite',

        'onduleur',

        'etat',

        'date_mise_service',

        'cout',

        'observation',

        'date_etat_change',

        'motif_etat',

        'capacite',

        'statut_enregistrement',

    ];

    protected $casts = [
        'onduleur' => 'boolean', // 🔥 Ajouter ce cast
    ];

    public function acquisition()
    {
        return $this->belongsTo(Acquisition::class);
    }

    public function attributions()
    {
        return $this->hasMany(Attribution::class);
    }

    public function mouvements()
    {
        return $this->hasMany(MouvementMateriel::class);
    }

    public function historique()
    {
        return $this->hasMany(HistoriqueUtilisateur::class);
    }

    public function historiqueUtilisateur()
    {
        return $this->hasMany(HistoriqueUtilisateur::class);
    }

    public function misesRebut()
    {
        return $this->hasMany(MisesRebut::class);
    }
}
