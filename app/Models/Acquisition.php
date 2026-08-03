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
}
