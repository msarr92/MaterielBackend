<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Acquisition extends Model
{
    protected $fillable = [

        'type_acquisition',

        'detail_type_acquisition',

        'numero_reference',

        'date_acquisition',

        'fournisseur_nom',

        'fournisseur_contact',

        'fournisseur_adresse',

        'montant',

        'quantite_prevue',

        'statut',

        'observation',
    ];

    public function materiels()
    {
        return $this->hasMany(Materiel::class);
    }

      /**
     * Nombre total de matériels liés à cette acquisition
     */
    public function getNombreMaterielsAttribute()
    {
        return $this->materiels()->count();
    }


    /**
     * Montant total réel des matériels acquis
     */
    public function getMontantTotalMaterielsAttribute()
    {
        return $this->materiels()
            ->sum('cout');
    }


    /**
     * Vérifier si l'acquisition est terminée
     */
    public function estTerminee()
    {
        return $this->statut === 'TERMINEE';
    }


    /**
     * Vérifier si l'acquisition est encore en cours
     */
    public function estEnCours()
    {
        return $this->statut === 'EN_COURS';
    }


    /**
     * Retourne le libellé du type d'acquisition
     */
    public function getTypeLabelAttribute()
    {
        return match ($this->type_acquisition) {

            'MARCHE' => 'Marché',

            'BON_COMMANDE' => 'Bon de commande',

            default => $this->type_acquisition,
        };
    }


    /**
     * Scope pour filtrer les acquisitions terminées
     */
    public function scopeTerminees($query)
    {
        return $query->where('statut', 'TERMINEE');
    }


    /**
     * Scope pour filtrer les acquisitions en cours
     */
    public function scopeEnCours($query)
    {
        return $query->where('statut', 'EN_COURS');
    }


    /**
     * Formatage automatique de la date
     */
    protected $casts = [

        'date_acquisition' => 'date',

        'montant' => 'decimal:2',

        'quantite_prevue' => 'integer',
    ];

}
