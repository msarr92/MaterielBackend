<?php

namespace App\Http\Controllers;

use App\Models\Acquisition;
use App\Models\Attribution;
use App\Models\Materiel;
use App\Models\MouvementMateriel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MaterielController extends Controller
{
    private function verifierStatutAcquisition($acquisitionId)
    {
        $acquisition = Acquisition::find($acquisitionId);

        if (! $acquisition) {
            return;
        }

        $nombreBrouillons = Materiel::where(
            'acquisition_id',
            $acquisitionId
        )
            ->where(
                'statut_enregistrement',
                'BROUILLON'
            )
            ->count();

        if ($nombreBrouillons > 0) {

            $acquisition->update([
                'statut' => 'EN_COURS',
            ]);

        } else {

            $acquisition->update([
                'statut' => 'TERMINEE',
            ]);

        }
    }

    public function AjoutMateriel(Request $request)
    {
        $lockKey = null;

        try {

            $validator = Validator::make($request->all(), [

                'acquisition_id' => 'required|exists:acquisitions,id',

                'categorie' => [
                    'required',
                    Rule::in([
                        'EQUIPEMENT',
                        'ACCESSOIRE',
                    ]),
                ],

                'numero_serie' => [
                    'nullable',
                    'string',
                    'max:100',
                    'unique:materiels,numero_serie',
                    Rule::requiredIf(
                        $request->categorie === 'EQUIPEMENT'
                    ),
                ],

                'marque' => [
                    'nullable',
                    'string',
                    Rule::requiredIf(
                        $request->categorie === 'EQUIPEMENT'
                    ),
                ],

                'modele' => [
                    'nullable',
                    'string',
                    Rule::requiredIf(
                        $request->categorie === 'EQUIPEMENT'
                    ),
                ],

                'type_materiel' => 'required|string|max:100',

                'quantite' => [
                    'nullable',
                    'integer',
                    'min:1',
                    Rule::requiredIf(
                        $request->categorie === 'ACCESSOIRE'
                    ),
                ],

                'capacite' => 'nullable|integer|min:1',

                'etat' => 'nullable|in:disponible,attribue,panne,maintenance',

                'date_mise_service' => 'nullable|date',

                'cout' => 'nullable|numeric|min:0',

                'observation_materiel' => 'nullable|string',

            ]);

            if ($validator->fails()) {

                return response()->json([

                    'success' => false,

                    'message' => $validator->errors()->first(),

                ], 422);

            }

            /*
            |--------------------------------------------------------------------------
            | VERROU
            |--------------------------------------------------------------------------
            */

            $lockKey =
                'materiel:create:'.
                md5(
                    $request->categorie.
                    $request->numero_serie.
                    $request->type_materiel
                );

            if (Cache::has($lockKey)) {

                return response()->json([

                    'success' => false,

                    'message' => 'Création déjà en cours',

                ], 429);

            }

            Cache::put($lockKey, true, 120);

            DB::beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | ACCESSOIRE
            |--------------------------------------------------------------------------
            */

            if ($request->categorie === 'ACCESSOIRE') {

                $materiel = Materiel::where(
                    'acquisition_id',
                    $request->acquisition_id
                )
                    ->where(
                        'categorie',
                        'ACCESSOIRE'
                    )
                    ->where(
                        'type_materiel',
                        $request->type_materiel
                    )
                    ->where(
                        'marque',
                        $request->marque
                    )
                    ->where(
                        'modele',
                        $request->modele
                    )
                    ->first();

                if ($materiel) {

                    $materiel->increment(
                        'quantite',
                        $request->quantite
                    );

                } else {

                    $materiel = Materiel::create([

                        'code_materiel' => 'MAT-'.strtoupper(Str::random(8)),

                        'acquisition_id' => $request->acquisition_id,

                        'categorie' => 'ACCESSOIRE',

                        'numero_serie' => $request->numero_serie,

                        'marque' => $request->marque,

                        'modele' => $request->modele,

                        'type_materiel' => $request->type_materiel,

                        'quantite' => 1,

                        'etat' => $request->etat ?? 'disponible',

                        'statut_enregistrement' => 'VALIDE',

                        'date_etat_change' => now(),

                        'motif_etat' => 'CREATION',

                    ]);

                }

            }

            /*
            |--------------------------------------------------------------------------
            | EQUIPEMENT
            |--------------------------------------------------------------------------
            */

            else {

                $materiel = Materiel::create([

                    'code_materiel' => 'MAT-'.strtoupper(Str::random(8)),

                    'acquisition_id' => $request->acquisition_id,

                    'categorie' => 'EQUIPEMENT',

                    'numero_serie' => $request->numero_serie,

                    'marque' => $request->marque,

                    'modele' => $request->modele,

                    'type_materiel' => $request->type_materiel,

                    'quantite' => 1,

                    'etat' => $request->etat ?? 'disponible',

                    'onduleur' => $request->boolean('onduleur'),

                    'capacite' => $request->capacite,

                    'statut_enregistrement' => 'VALIDE',

                    'observation' => $request->observation_materiel,

                    'date_etat_change' => now(),

                    'motif_etat' => 'CREATION',

                ]);

            }

            MouvementMateriel::create([

                'materiel_id' => $materiel->id,

                'type_mouvement' => 'ACQUISITION',

                'quantite' => $materiel->quantite,

                'acquisition_id' => $request->acquisition_id,

                'date_mouvement' => now(),

                'observation' => 'Entrée matériel',

                'created_by' => auth()->id(),

            ]);

            /*
            Vérification acquisition
            */

            $this->verifierStatutAcquisition(
                $request->acquisition_id
            );

            DB::commit();

            Cache::forget($lockKey);

            return response()->json([

                'success' => true,

                'message' => 'Matériel enregistré.',

                'data' => $materiel,

            ], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            if ($lockKey) {

                Cache::forget($lockKey);

            }

            return response()->json([

                'success' => false,

                'message' => $e->getMessage(),

            ], 500);

        }
    }

    public function AjoutMaterielBrouillon(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [

                'categorie' => [
                    'required',
                    Rule::in([
                        'EQUIPEMENT',
                        'ACCESSOIRE',
                    ]),
                ],

                'type_materiel' => 'nullable|string|max:100',

                'numero_serie' => 'nullable|string|max:100',

                'marque' => 'nullable|string|max:100',

                'modele' => 'nullable|string|max:100',

                'quantite' => 'nullable|integer|min:1',

                'observation_materiel' => 'nullable|string',

            ]);

            if ($validator->fails()) {

                return response()->json([

                    'success' => false,

                    'message' => $validator->errors()->first(),

                ], 422);

            }

            DB::beginTransaction();

            $materiel = Materiel::create([

                'code_materiel' => $request->categorie === 'ACCESSOIRE'
                    ? 'ACC-'.strtoupper(Str::random(8))
                    : 'MAT-'.strtoupper(Str::random(8)),

                'acquisition_id' => $request->acquisition_id,

                'categorie' => $request->categorie,

                'numero_serie' => $request->numero_serie,

                'marque' => $request->marque,

                'modele' => $request->modele,

                'type_materiel' => $request->type_materiel,

                'quantite' => $request->quantite ?? 1,

                'etat' => 'disponible',

                'statut_enregistrement' => 'BROUILLON',

                'observation' => $request->observation_materiel,

                'date_etat_change' => now(),

                'motif_etat' => 'BROUILLON',

            ]);

            $this->verifierStatutAcquisition(
                $request->acquisition_id
            );

            DB::commit();

            $this->verifierStatutAcquisition(
                $request->acquisition_id
            );

            return response()->json([

                'success' => true,

                'message' => 'Matériel enregistré en brouillon.',

                'data' => $materiel,

            ], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([

                'success' => false,

                'message' => 'Erreur sauvegarde brouillon.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }

    public function ValiderMateriel(Request $request, $id)
    {
        try {

            $materiel = Materiel::find($id);

            if (! $materiel) {

                return response()->json([
                    'success' => false,
                    'message' => 'Matériel introuvable.',
                ], 404);

            }

            $validator = Validator::make($request->all(), [

                'numero_serie' => [
                    Rule::requiredIf($materiel->categorie == 'EQUIPEMENT'),
                    Rule::unique('materiels', 'numero_serie')->ignore($materiel->id),
                    'nullable',
                    'string',
                    'max:100',
                ],

                'marque' => [
                    Rule::requiredIf($materiel->categorie == 'EQUIPEMENT'),
                    'nullable',
                    'string',
                    'max:100',
                ],

                'modele' => [
                    Rule::requiredIf($materiel->categorie == 'EQUIPEMENT'),
                    'nullable',
                    'string',
                    'max:100',
                ],

                'type_materiel' => 'required|string|max:100',

                'quantite' => [
                    Rule::requiredIf($materiel->categorie == 'ACCESSOIRE'),
                    'nullable',
                    'integer',
                    'min:1',
                ],

                'capacite' => 'nullable|integer|min:1',

                'onduleur' => 'nullable|boolean',

                'etat' => [
                    'nullable',
                    Rule::in([
                        'disponible',
                        'attribue',
                        'panne',
                        'maintenance',
                    ]),
                ],

                'date_mise_service' => 'nullable|date',

                'cout' => 'nullable|numeric|min:0',

                'observation_materiel' => 'nullable|string',

            ]);

            if ($validator->fails()) {

                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);

            }

            DB::beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | Mise à jour
            |--------------------------------------------------------------------------
            */

            $materiel->update([

                'numero_serie' => $materiel->categorie == 'EQUIPEMENT'
                    ? $request->numero_serie
                    : null,

                'marque' => $request->marque,

                'modele' => $request->modele,

                'type_materiel' => $request->type_materiel,

                'quantite' => $materiel->categorie == 'ACCESSOIRE'
                    ? $request->quantite
                    : 1,

                'capacite' => $request->capacite,

                'onduleur' => $request->boolean('onduleur'),

                'etat' => $request->etat ?? 'disponible',

                'date_mise_service' => $request->date_mise_service,

                'cout' => $request->cout,

                'observation' => $request->observation_materiel,

                'date_etat_change' => now(),

                'motif_etat' => 'VALIDATION',

                'statut_enregistrement' => 'VALIDE',

            ]);

            /*
            |--------------------------------------------------------------------------
            | Mouvement d'acquisition
            |--------------------------------------------------------------------------
            */

            $mouvementExiste = MouvementMateriel::where(
                'materiel_id',
                $materiel->id
            )
                ->where('type_mouvement', 'ACQUISITION')
                ->exists();

            if (! $mouvementExiste) {

                MouvementMateriel::create([

                    'materiel_id' => $materiel->id,

                    'acquisition_id' => $materiel->acquisition_id,

                    'type_mouvement' => 'ACQUISITION',

                    'quantite' => $materiel->quantite,

                    'date_mouvement' => now(),

                    'observation' => 'Validation du matériel',

                    'created_by' => auth()->id(),

                ]);

            }

            /*
            |--------------------------------------------------------------------------
            | Vérifier si toute l'acquisition est terminée
            |--------------------------------------------------------------------------
            */

            $reste = Materiel::where(
                'acquisition_id',
                $materiel->acquisition_id
            )
                ->where(
                    'statut_enregistrement',
                    'BROUILLON'
                )
                ->exists();

            Acquisition::where(
                'id',
                $materiel->acquisition_id
            )->update([

                'statut' => $reste
                    ? 'EN_COURS'
                    : 'TERMINEE',

            ]);

            DB::commit();

            return response()->json([

                'success' => true,

                'message' => 'Matériel mis à jour avec succès.',

                'data' => $materiel->fresh(),

            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de la mise à jour.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }

    public function continuerSaisieMateriel($acquisitionId)
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | Vérifier acquisition
            |--------------------------------------------------------------------------
            */

            $acquisition = Acquisition::find($acquisitionId);

            if (! $acquisition) {

                return response()->json([

                    'success' => false,

                    'message' => 'Acquisition introuvable.',

                ], 404);

            }

            /*
            |--------------------------------------------------------------------------
            | Récupérer les matériels liés
            |--------------------------------------------------------------------------
            */

            $materiels = Materiel::where(

                'acquisition_id',

                $acquisitionId

            )
                ->orderBy('statut_enregistrement')
                ->get()
                ->map(function ($materiel) {

                    return [

                        'id' => $materiel->id,

                        'code_materiel' => $materiel->code_materiel,

                        'categorie' => $materiel->categorie,

                        'numero_serie' => $materiel->numero_serie,

                        'marque' => $materiel->marque,

                        'modele' => $materiel->modele,

                        'type_materiel' => $materiel->type_materiel,

                        'quantite' => $materiel->quantite,

                        'capacite' => $materiel->capacite,

                        'etat' => $materiel->etat,

                        'statut_enregistrement' => $materiel->statut_enregistrement,

                        'observation' => $materiel->observation,

                        /*
                    | Permet au frontend de savoir
                    | quoi afficher
                    */

                        'a_completer' => $materiel->statut_enregistrement
                            ===
                            'BROUILLON',

                    ];

                });

            /*
            |--------------------------------------------------------------------------
            | Calcul progression
            |--------------------------------------------------------------------------
            */

            $quantiteEnregistree =
                $materiels->sum('quantite');

            $quantitePrevue =
                $acquisition->quantite_prevue ?? 0;

            return response()->json([

                'success' => true,

                'message' => 'Données de reprise récupérées.',

                'data' => [

                    'acquisition' => [

                        'id' => $acquisition->id,

                        'type_acquisition' => $acquisition->type_acquisition,

                        'numero_reference' => $acquisition->numero_reference,

                        'quantite_prevue' => $quantitePrevue,

                    ],

                    'progression' => [

                        'quantite_prevue' => $quantitePrevue,

                        'quantite_enregistree' => $quantiteEnregistree,

                        'reste_a_saisir' => max(

                            0,

                            $quantitePrevue
                            -
                            $quantiteEnregistree

                        ),

                    ],

                    'materiels' => $materiels,

                ],

            ]);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur récupération saisie.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }

    public function listEquipements(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'search' => 'nullable|string|max:100',
                'etat' => 'nullable|in:disponible,attribue,panne,maintenance',
                'per_page' => 'nullable|integer|min:1|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            $search = trim($request->search ?? '');
            $etat = $request->etat;
            $perPage = $request->per_page ?? 10;

            $query = Materiel::with([
                'acquisition:id,type_acquisition,numero_reference,date_acquisition,fournisseur_nom',
            ])
                ->withCount('attributions')
                ->where('categorie', 'EQUIPEMENT')
                ->where('etat', '!=', 'rebut')
                ->orderByDesc('id');

            if (! empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('code_materiel', 'LIKE', "%{$search}%")
                        ->orWhere('numero_serie', 'LIKE', "%{$search}%")
                        ->orWhere('marque', 'LIKE', "%{$search}%")
                        ->orWhere('modele', 'LIKE', "%{$search}%")
                        ->orWhere('type_materiel', 'LIKE', "%{$search}%");
                });
            }

            if (! empty($etat)) {
                $query->where('etat', $etat);
            }

            $materiels = $query->paginate($perPage);

            $data = $materiels->through(function ($item) {
                return [
                    'id' => $item->id,
                    'code_materiel' => $item->code_materiel,
                    'numero_serie' => $item->numero_serie,
                    'marque' => $item->marque,
                    'modele' => $item->modele,
                    'type_materiel' => $item->type_materiel,
                    'etat' => $item->etat,
                    'onduleur' => $item->onduleur,
                    'capacite' => $item->capacite,
                    'cout' => $item->cout,
                    'date_mise_service' => $item->date_mise_service,
                    'acquisition' => $item->acquisition,
                    'total_attributions' => $item->attributions_count,

                    'statut_age' => $item->attributions_count > 0
                        ? 'ancien'
                        : 'nouveau',

                    'created_at' => $item->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Liste des équipements.',
                'data' => $data,
                'pagination' => [
                    'current_page' => $materiels->currentPage(),
                    'last_page' => $materiels->lastPage(),
                    'per_page' => $materiels->perPage(),
                    'total' => $materiels->total(),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function listAccessoires(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [

                'search' => 'nullable|string|max:100',
                'per_page' => 'nullable|integer|min:1|max:1000',

            ]);

            if ($validator->fails()) {

                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);

            }

            $search = trim($request->search ?? '');
            $perPage = $request->per_page ?? 10;

            /*
            |--------------------------------------------------------------------------
            | ACCESSOIRES REGROUPÉS
            |--------------------------------------------------------------------------
            */

            $query = Materiel::select(
                DB::raw('MIN(id) as id'),
                DB::raw('MIN(code_materiel) as code_materiel'),
                'type_materiel',
                'marque',
                'modele',
                'capacite',
                'etat',
                DB::raw('SUM(quantite) as quantite'),
                DB::raw('AVG(cout) as cout'),
                DB::raw('MAX(created_at) as created_at'),
                DB::raw('MIN(acquisition_id) as acquisition_id')
            )
                ->where('categorie', 'ACCESSOIRE')
                ->groupBy(
                    'type_materiel',
                    'marque',
                    'modele',
                    'capacite',
                    'etat'
                )
                ->orderByDesc(DB::raw('MAX(created_at)'));

            /*
            |--------------------------------------------------------------------------
            | RECHERCHE
            |--------------------------------------------------------------------------
            */

            if (! empty($search)) {

                $query->where(function ($q) use ($search) {

                    $q->where('type_materiel', 'ILIKE', "%{$search}%")
                        ->orWhere('marque', 'ILIKE', "%{$search}%")
                        ->orWhere('modele', 'ILIKE', "%{$search}%")
                        ->orWhere('code_materiel', 'ILIKE', "%{$search}%");

                });

            }

            $accessoires = $query->paginate($perPage);

            /*
            |--------------------------------------------------------------------------
            | FORMATAGE
            |--------------------------------------------------------------------------
            */

            $data = collect($accessoires->items())->map(function ($item) {

                return [

                    'id' => $item->id,

                    'code_materiel' => $item->code_materiel,

                    'type_materiel' => $item->type_materiel,

                    'marque' => $item->marque,

                    'modele' => $item->modele,

                    'capacite' => $item->capacite,

                    // quantité totale
                    'quantite' => (int) $item->quantite,

                    'etat' => $item->etat,

                    'cout' => round($item->cout, 2),

                    'acquisition' => Acquisition::select(
                        'id',
                        'type_acquisition',
                        'numero_reference',
                        'date_acquisition',
                        'fournisseur_nom'
                    )->find($item->acquisition_id),

                    'created_at' => $item->created_at,

                ];

            });

            return response()->json([

                'success' => true,

                'message' => 'Liste des accessoires récupérée avec succès.',

                'data' => $data,

                'pagination' => [

                    'current_page' => $accessoires->currentPage(),

                    'last_page' => $accessoires->lastPage(),

                    'per_page' => $accessoires->perPage(),

                    'total' => $accessoires->total(),

                ],

            ]);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors du chargement des accessoires.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }

    public function detailMateriel($id)
    {
        try {

            if (! is_numeric($id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifiant invalide',
                ], 400);
            }

            /* =========================
             | MATERIEL COMPLET
            ==========================*/
            $materiel = Materiel::with([
                'acquisition:id,type_acquisition,numero_reference,date_acquisition,fournisseur_nom,fournisseur_contact,fournisseur_adresse',
            ])
                ->find($id);

            if (! $materiel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Matériel introuvable',
                ], 404);
            }

            /* =========================
             | HISTORIQUE ATTRIBUTIONS
            ==========================*/
            $attributions = Attribution::with([
                'user:id,nom,prenom,matricule',
                'direction:id,nom',
                'creator:id,nom,prenom',
            ])
                ->where('materiel_id', $id)
                ->orderByDesc('date_debut')
                ->get();

            /* =========================
             | MOUVEMENTS COMPLETS
            ==========================*/
            $mouvements = MouvementMateriel::with([
                'user:id,nom,prenom',
                'direction:id,nom',
                'creator:id,nom,prenom',
            ])
                ->where('materiel_id', $id)
                ->orderByDesc('date_mouvement')
                ->get();

            /* =========================
             | STATISTIQUES
            ==========================*/
            $stats = [
                'total_attributions' => $attributions->count(),
                'utilisateurs_successifs' => $attributions->pluck('user_id')->unique()->count(),
                'pannes' => $mouvements->where('type_mouvement', 'PANNE')->count(),
                'maintenances' => $mouvements->where('type_mouvement', 'MAINTENANCE')->count(),
                'reaffectations' => $mouvements->where('type_mouvement', 'REAFFECTATION')->count(),
            ];

            /* =========================
             | RESPONSE
            ==========================*/
            return response()->json([
                'success' => true,
                'data' => [
                    'materiel' => $materiel,
                    'attributions' => $attributions,
                    'mouvements' => $mouvements,
                    'stats' => $stats,
                ],
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération du matériel',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateMateriel(Request $request, $id)
    {
        try {

            $materiel = Materiel::find($id);

            if (! $materiel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Matériel introuvable.',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | VALIDATION
            |--------------------------------------------------------------------------
            */

            $validator = Validator::make($request->all(), [

                'acquisition_id' => 'required|exists:acquisitions,id',

                'categorie' => [
                    'required',
                    Rule::in([
                        'EQUIPEMENT',
                        'ACCESSOIRE',
                    ]),
                ],

                'numero_serie' => [

                    'nullable',
                    'string',
                    'max:100',

                    Rule::unique('materiels', 'numero_serie')
                        ->ignore($materiel->id),

                    Rule::requiredIf($request->categorie == 'EQUIPEMENT'),

                ],

                'marque' => [

                    'nullable',
                    'string',
                    'max:100',

                    Rule::requiredIf($request->categorie == 'EQUIPEMENT'),

                ],

                'modele' => [

                    'nullable',
                    'string',
                    'max:100',

                    Rule::requiredIf($request->categorie == 'EQUIPEMENT'),

                ],

                'type_materiel' => 'required|string|max:100',

                'quantite' => [

                    'nullable',
                    'integer',
                    'min:1',

                    Rule::requiredIf($request->categorie == 'ACCESSOIRE'),

                ],

                'capacite' => 'nullable|integer|min:1',

                'onduleur' => 'nullable|boolean',

                'etat' => [
                    'required',
                    Rule::in([
                        'disponible',
                        'attribue',
                        'panne',
                        'maintenance',
                    ]),
                ],

                'date_mise_service' => 'nullable|date',

                'cout' => 'nullable|numeric|min:0',

                'observation_materiel' => 'nullable|string',

            ]);

            if ($validator->fails()) {

                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);

            }

            DB::beginTransaction();

            $ancienEtat = $materiel->etat;

            /*
            |--------------------------------------------------------------------------
            | MISE A JOUR
            |--------------------------------------------------------------------------
            */

            $materiel->update([

                'acquisition_id' => $request->acquisition_id,

                'categorie' => $request->categorie,

                'numero_serie' => $request->categorie == 'EQUIPEMENT'
                    ? $request->numero_serie
                    : null,

                'marque' => $request->categorie == 'EQUIPEMENT'
                    ? $request->marque
                    : null,

                'modele' => $request->categorie == 'EQUIPEMENT'
                    ? $request->modele
                    : null,

                'type_materiel' => $request->type_materiel,

                'quantite' => $request->categorie == 'ACCESSOIRE'
                    ? $request->quantite
                    : 1,

                'capacite' => $request->capacite,

                'onduleur' => $request->boolean('onduleur'),

                'etat' => $request->etat,

                'date_mise_service' => $request->date_mise_service,

                'cout' => $request->cout,

                'observation' => $request->observation_materiel,

                'motif_etat' => $request->motif_etat,

                'date_etat_change' => $ancienEtat != $request->etat
                    ? now()
                    : $materiel->date_etat_change,

            ]);

            /*
            |--------------------------------------------------------------------------
            | ATTRIBUTION ACTIVE
            |--------------------------------------------------------------------------
            */

            $attribution = Attribution::where('materiel_id', $materiel->id)
                ->whereIn('statut', [
                    'ACTIVE',
                    'EN_PANNE',
                    'EN_MAINTENANCE',
                ])
                ->latest()
                ->first();

            if ($attribution) {

                switch ($request->etat) {

                    case 'attribue':

                        $attribution->update([
                            'statut' => 'ACTIVE',
                        ]);

                        break;

                    case 'panne':

                        $attribution->update([
                            'statut' => 'EN_PANNE',
                        ]);

                        break;

                    case 'maintenance':

                        $attribution->update([
                            'statut' => 'EN_MAINTENANCE',
                        ]);

                        break;

                    case 'disponible':

                        $attribution->update([
                            'statut' => 'TERMINE',
                            'date_fin' => now(),
                        ]);

                        break;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | HISTORIQUE
            |--------------------------------------------------------------------------
            */

            if ($ancienEtat != $request->etat) {

                $typeMouvement = match ($request->etat) {

                    'attribue' => 'ATTRIBUTION',

                    'panne' => 'PANNE',

                    'maintenance' => 'MAINTENANCE',

                    'disponible' => 'RETOUR_STOCK',

                    default => 'MODIFICATION_ETAT',

                };

                MouvementMateriel::create([

                    'materiel_id' => $materiel->id,

                    'user_id' => $attribution?->user_id,

                    'direction_id' => $attribution?->direction_id,

                    'type_mouvement' => $typeMouvement,

                    'date_mouvement' => now(),

                    'etat_materiel' => $request->etat,

                    'observation' => $request->observation_materiel,

                    'created_by' => auth()->id(),

                ]);
            }

            DB::commit();

            return response()->json([

                'success' => true,

                'message' => 'Matériel modifié avec succès.',

                'data' => $materiel->fresh()->load('acquisition'),

            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de la modification.',

                'error' => $e->getMessage(),

            ], 500);
        }
    }

    public function deleteMateriel($id)
{
    if (! is_numeric($id)) {
        return response()->json([
            'success' => false,
            'message' => 'Identifiant invalide.',
        ], 400);
    }

    try {
        DB::beginTransaction();

        /*
        |--------------------------------------------------------------------------
        | 1. Récupérer et verrouiller le matériel
        |--------------------------------------------------------------------------
        */

        $materiel = Materiel::query()
            ->lockForUpdate()
            ->find($id);

        if (! $materiel) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Matériel introuvable.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Autoriser uniquement la suppression d'un matériel au rebut
        |--------------------------------------------------------------------------
        */

        if ($materiel->etat !== 'rebut') {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Suppression impossible. Le matériel doit d’abord être mis au rebut.',
                'data' => [
                    'materiel_id' => $materiel->id,
                    'code_materiel' => $materiel->code_materiel,
                    'etat_actuel' => $materiel->etat,
                ],
            ], 409);
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Vérifier qu'il n'existe aucune attribution encore active
        |--------------------------------------------------------------------------
        */

        $hasActiveAttribution = Attribution::query()
            ->where('materiel_id', $materiel->id)
            ->where(function ($query) {
                $query->whereNull('date_fin')
                    ->orWhereIn('statut', [
                        'ACTIVE',
                        'EN_PANNE',
                        'EN_MAINTENANCE',
                    ]);
            })
            ->exists();

        if ($hasActiveAttribution) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Suppression impossible. Une attribution liée à ce matériel est encore active.',
            ], 409);
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Supprimer les mouvements liés
        |--------------------------------------------------------------------------
        |
        | Cette suppression est facultative si la clé étrangère utilise
        | cascadeOnDelete(), mais elle reste explicite ici.
        |
        */

        MouvementMateriel::query()
            ->where('materiel_id', $materiel->id)
            ->delete();

        /*
        |--------------------------------------------------------------------------
        | 5. Supprimer les anciennes attributions
        |--------------------------------------------------------------------------
        |
        | La table attributions utilise cascadeOnDelete() sur materiel_id.
        | Laravel/PostgreSQL peut donc les supprimer automatiquement avec le
        | matériel. Cette suppression explicite n'est pas obligatoire.
        |
        */

        Attribution::query()
            ->where('materiel_id', $materiel->id)
            ->delete();

        /*
        |--------------------------------------------------------------------------
        | 6. Supprimer le matériel
        |--------------------------------------------------------------------------
        */

        $codeMateriel = $materiel->code_materiel;

        $materiel->delete();

        /*
        |--------------------------------------------------------------------------
        | Important : ne pas supprimer l'acquisition
        |--------------------------------------------------------------------------
        |
        | Une acquisition peut être associée à plusieurs matériels.
        |
        */

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => "Le matériel {$codeMateriel} a été supprimé avec succès.",
        ]);

    } catch (\Throwable $e) {
        DB::rollBack();

        report($e);

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la suppression du matériel.',
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

    /**
     * Récupérer les statistiques des matériels
     */
    public function statistiquesMateriels()
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | Comptages principaux
            |--------------------------------------------------------------------------
            */

            $total = Materiel::count();

            $disponibles = Materiel::where('etat', 'disponible')->count();

            $attribues = Materiel::where('etat', 'attribue')->count();

            $pannes = Materiel::where('etat', 'panne')->count();

            $maintenances = Materiel::where('etat', 'maintenance')->count();

            /*
            |--------------------------------------------------------------------------
            | Valeur du parc
            |--------------------------------------------------------------------------
            */

            $valeurParc = Materiel::sum('cout');

            /*
            |--------------------------------------------------------------------------
            | Matériels jamais attribués
            |--------------------------------------------------------------------------
            */

            $jamaisAttribues = Materiel::whereDoesntHave('attributions')
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Matériels déjà attribués
            |--------------------------------------------------------------------------
            */

            $dejaAttribues = Materiel::whereHas('attributions')
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Réaffectations
            |--------------------------------------------------------------------------
            */

            $reaffectes = Attribution::select('materiel_id')
                ->groupBy('materiel_id')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Acquisitions
            |--------------------------------------------------------------------------
            */

            $totalAcquisitions = Acquisition::count();

            /*
            |--------------------------------------------------------------------------
            | Pourcentages
            |--------------------------------------------------------------------------
            */

            $pourcentageDisponible = $total > 0
                ? round(($disponibles / $total) * 100, 2)
                : 0;

            $pourcentageAttribue = $total > 0
                ? round(($attribues / $total) * 100, 2)
                : 0;

            $pourcentagePanne = $total > 0
                ? round(($pannes / $total) * 100, 2)
                : 0;

            $pourcentageMaintenance = $total > 0
                ? round(($maintenances / $total) * 100, 2)
                : 0;

            return response()->json([

                'success' => true,

                'message' => 'Statistiques récupérées avec succès.',

                'data' => [

                    'total_materiels' => $total,

                    'disponibles' => [
                        'nombre' => $disponibles,
                        'pourcentage' => $pourcentageDisponible,
                    ],

                    'attribues' => [
                        'nombre' => $attribues,
                        'pourcentage' => $pourcentageAttribue,
                    ],

                    'pannes' => [
                        'nombre' => $pannes,
                        'pourcentage' => $pourcentagePanne,
                    ],

                    'maintenance' => [
                        'nombre' => $maintenances,
                        'pourcentage' => $pourcentageMaintenance,
                    ],

                    'jamais_attribues' => $jamaisAttribues,

                    'deja_attribues' => $dejaAttribues,

                    'reaffectes' => $reaffectes,

                    'total_acquisitions' => $totalAcquisitions,

                    'valeur_totale_parc' => round($valeurParc, 2),

                ],

            ]);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors du chargement des statistiques.',

                'error' => $e->getMessage(),

            ], 500);
        }
    }

    public function getMaterielsDisponibles()
    {
        try {
            $materiels = Materiel::query()
                ->select([
                    'id',
                    'code_materiel',
                    'numero_serie',
                    'marque',
                    'modele',
                    'type_materiel',
                    'etat',
                ])
                ->where('etat', 'disponible')
                ->orderBy('marque', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $materiels,
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des matériels disponibles',
            ], 500);
        }
    }

    public function mettreAuRebut(Request $request, int $id)
    {
        $validated = $request->validate([
            'date_rebut' => [
                'nullable',
                'date',
                'before_or_equal:today',
            ],

            'motif_rebut' => [
                'required',
                'string',
                'min:3',
                'max:2000',
            ],

            'observation' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ]);

        $resultat = DB::transaction(function () use ($id, $validated) {
            /*
            |--------------------------------------------------------------------------
            | Récupérer et verrouiller le matériel
            |--------------------------------------------------------------------------
            */

            $materiel = Materiel::query()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($materiel->etat === 'rebut') {
                throw ValidationException::withMessages([
                    'materiel' => 'Ce matériel est déjà au rebut.',
                ]);
            }

            $dateRebut = $validated['date_rebut'] ?? now()->toDateString();

            /*
            |--------------------------------------------------------------------------
            | Rechercher une attribution active
            |--------------------------------------------------------------------------
            */

            $attributionActive = Attribution::query()
                ->where('materiel_id', $materiel->id)
                ->whereNull('date_fin')
                ->whereIn('statut', [
                    'ACTIVE',
                    'EN_PANNE',
                    'EN_MAINTENANCE',
                ])
                ->latest('date_debut')
                ->first();

            /*
            |--------------------------------------------------------------------------
            | Terminer l'attribution active
            |--------------------------------------------------------------------------
            */

            if ($attributionActive) {
                $attributionActive->update([
                    'date_fin' => $dateRebut,
                    'statut' => 'TERMINE',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Mettre le matériel au rebut
            |--------------------------------------------------------------------------
            */

            $materiel->update([
                'etat' => 'rebut',
                'date_etat_change' => $dateRebut,
                'date_rebut' => $dateRebut,
                'motif_rebut' => $validated['motif_rebut'],
                'rebute_par' => auth()->id(),
                'motif_etat' => $validated['motif_rebut'],
            ]);

            /*
            |--------------------------------------------------------------------------
            | Ajouter le mouvement dans l'historique
            |--------------------------------------------------------------------------
            */

            $mouvement = MouvementMateriel::create([
                'materiel_id' => $materiel->id,
                'user_id' => $attributionActive?->user_id,
                'direction_id' => $attributionActive?->direction_id,
                'type_mouvement' => 'REBUT',
                'date_mouvement' => $dateRebut,
                'etat_materiel' => 'rebut',
                'observation' => $validated['observation']
                    ?? $validated['motif_rebut'],
                'created_by' => auth()->id(),
            ]);

            return [
                'materiel' => $materiel->fresh([
                    'acquisition',
                    'utilisateurRebut',
                ]),
                'mouvement' => $mouvement,
                'ancienne_attribution' => $attributionActive,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Le matériel a été mis au rebut avec succès.',
            'data' => $resultat,
        ]);
    }

    public function listMaterielsRebut(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'search' => 'nullable|string|max:100',
                'categorie' => 'nullable|in:EQUIPEMENT,ACCESSOIRE',
                'per_page' => 'nullable|integer|min:1|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),
                ], 422);
            }

            $search = trim($request->input('search', ''));
            $categorie = $request->input('categorie');
            $perPage = (int) $request->input('per_page', 10);

            $query = Materiel::query()
                ->with([
                    'acquisition:id,type_acquisition,numero_reference,date_acquisition,fournisseur_nom',
                    'utilisateurRebut:id,nom,prenom,matricule,username',
                ])
                ->withCount('attributions')
                ->where('etat', 'rebut')
                ->orderByDesc('date_rebut')
                ->orderByDesc('id');

            /*
            |--------------------------------------------------------------------------
            | Recherche
            |--------------------------------------------------------------------------
            */

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('code_materiel', 'LIKE', "%{$search}%")
                        ->orWhere('numero_serie', 'LIKE', "%{$search}%")
                        ->orWhere('marque', 'LIKE', "%{$search}%")
                        ->orWhere('modele', 'LIKE', "%{$search}%")
                        ->orWhere('type_materiel', 'LIKE', "%{$search}%")
                        ->orWhere('motif_rebut', 'LIKE', "%{$search}%");
                });
            }

            /*
            |--------------------------------------------------------------------------
            | Filtre par catégorie
            |--------------------------------------------------------------------------
            */

            if (! empty($categorie)) {
                $query->where('categorie', $categorie);
            }

            $materiels = $query->paginate($perPage);

            $data = $materiels->through(function ($item) {
                return [
                    'id' => $item->id,

                    'code_materiel' => $item->code_materiel,
                    'numero_serie' => $item->numero_serie,

                    'marque' => $item->marque,
                    'modele' => $item->modele,
                    'type_materiel' => $item->type_materiel,

                    'categorie' => $item->categorie,
                    'quantite' => $item->quantite,
                    'capacite' => $item->capacite,
                    'onduleur' => $item->onduleur,

                    'etat' => $item->etat,
                    'date_etat_change' => $item->date_etat_change,

                    'date_rebut' => $item->date_rebut,
                    'motif_rebut' => $item->motif_rebut,

                    'rebute_par' => $item->utilisateurRebut
                        ? [
                            'id' => $item->utilisateurRebut->id,
                            'matricule' => $item->utilisateurRebut->matricule,
                            'username' => $item->utilisateurRebut->username,
                            'nom' => $item->utilisateurRebut->nom,
                            'prenom' => $item->utilisateurRebut->prenom,
                            'nom_complet' => trim(
                                $item->utilisateurRebut->prenom.' '.
                                $item->utilisateurRebut->nom
                            ),
                        ]
                        : null,

                    'cout' => $item->cout,
                    'date_mise_service' => $item->date_mise_service,
                    'observation' => $item->observation,

                    'acquisition' => $item->acquisition,

                    'total_attributions' => $item->attributions_count,

                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Liste des matériels mis au rebut.',
                'data' => $data,
                'pagination' => [
                    'current_page' => $materiels->currentPage(),
                    'last_page' => $materiels->lastPage(),
                    'per_page' => $materiels->perPage(),
                    'total' => $materiels->total(),
                    'from' => $materiels->firstItem(),
                    'to' => $materiels->lastItem(),
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la récupération des matériels mis au rebut.',
            ], 500);
        }
    }
}
