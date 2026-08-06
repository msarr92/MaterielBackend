<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Materiel extends Model
{
    protected $fillable = [
        'code_materiel',
        'numero_serie',
        'marque',
        'modele',
        'type_materiel',
        'capacite',
        'categorie',
        'quantite',
        'statut_enregistrement',
        'onduleur',
        'acquisition_id',
        'date_mise_service',
        'etat',
        'date_etat_change',
        'date_rebut',
        'motif_rebut',
        'rebute_par',
        'motif_etat',
        'cout',
        'observation',
    ];

    protected $casts = [
        'date_mise_service' => 'date',
        'date_etat_change' => 'date',
        'date_rebut' => 'date',
        'onduleur' => 'boolean',
        'cout' => 'decimal:2',
        'quantite' => 'integer',
        'capacite' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Bloquer la suppression si le matériel n'est pas au rebut
    |--------------------------------------------------------------------------
    */

    protected static function booted(): void
    {
        static::deleting(function (Materiel $materiel) {
            if ($materiel->etat !== 'rebut') {
                throw ValidationException::withMessages([
                    'materiel' => sprintf(
                        'Le matériel %s doit être mis au rebut avant de pouvoir être supprimé.',
                        $materiel->code_materiel
                    ),
                ]);
            }
        });
    }

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
        return $this->hasMany(MouvementMateriel::class, 'materiel_id');
    }

    public function historique()
    {
        return $this->hasMany(HistoriqueUtilisateur::class);
    }

    public function historiqueUtilisateur()
    {
        return $this->hasMany(HistoriqueUtilisateur::class);
    }

    public function estAuRebut(): bool
    {
        return $this->etat === 'rebut';
    }

    public function utilisateurRebut()
    {
        return $this->belongsTo(User::class, 'rebute_par');
    }
}
