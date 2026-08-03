<?php

namespace App\Http\Controllers;

use App\Models\Acquisition;
use App\Models\Materiel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AcquisitionController extends Controller
{
    public function createAcquisition(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [

                'type_acquisition' => 'required|in:MARCHE,BON_COMMANDE,AUTRE',

                'detail_type_acquisition' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::requiredIf($request->type_acquisition === 'AUTRE'),
                ],

                'numero_reference' => 'nullable|string|max:150',

                'date_acquisition' => 'required|date',

                'fournisseur_nom' => 'nullable|string|max:150',

                'fournisseur_contact' => 'nullable|string|max:150',

                'fournisseur_adresse' => 'nullable|string|max:255',

                'quantite_prevue' => 'required|integer|min:1',

                'montant' => 'nullable|numeric|min:0',

                'observation_acquisition' => 'nullable|string',

            ]);

            if ($validator->fails()) {

                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);

            }

            $acquisition = Acquisition::create([

                'type_acquisition' => $request->type_acquisition,

                'detail_type_acquisition' => $request->detail_type_acquisition,

                'numero_reference' => $request->numero_reference,

                'date_acquisition' => $request->date_acquisition,

                'fournisseur_nom' => $request->fournisseur_nom,

                'fournisseur_contact' => $request->fournisseur_contact,

                'fournisseur_adresse' => $request->fournisseur_adresse,

                'montant' => $request->montant,

                'quantite_prevue' => $request->quantite_prevue,

                'statut' => 'EN_COURS',

                'observation' => $request->observation_acquisition,

            ]);

            return response()->json([

                'success' => true,

                'message' => 'Acquisition enregistrée avec succès.',

                'data' => $acquisition,

            ], 201);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de la création de l\'acquisition.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }

    public function listAcquisitions(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [

                'search' => 'nullable|string|max:100',

                'per_page' => 'nullable|integer|min:1|max:100',

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
            | Regroupement acquisition
            |--------------------------------------------------------------------------
            */

            $query = Acquisition::select(

                DB::raw('MIN(id) as id'),

                'type_acquisition',

                'numero_reference',

                'date_acquisition',

                'fournisseur_nom',

                DB::raw(
                    'MAX(fournisseur_contact) as fournisseur_contact'
                ),

                DB::raw(
                    'MAX(fournisseur_adresse) as fournisseur_adresse'
                ),

                DB::raw(
                    'SUM(montant) as montant'
                ),

                DB::raw(
                    'SUM(quantite_prevue) as quantite_prevue'
                ),

                DB::raw(
                    'MAX(observation) as observation'
                )

            )
                ->groupBy(

                    'type_acquisition',

                    'numero_reference',

                    'date_acquisition',

                    'fournisseur_nom'

                )
                ->orderByDesc(DB::raw('MIN(id)'));

            /*
            |--------------------------------------------------------------------------
            | Recherche
            |--------------------------------------------------------------------------
            */

            if (! empty($search)) {

                $query->havingRaw("

                LOWER(COALESCE(numero_reference,'')) LIKE ?

                OR LOWER(COALESCE(fournisseur_nom,'')) LIKE ?

                OR LOWER(COALESCE(type_acquisition,'')) LIKE ?

            ", [

                    '%'.strtolower($search).'%',
                    '%'.strtolower($search).'%',
                    '%'.strtolower($search).'%',

                ]);

            }

            $acquisitions = $query->paginate($perPage);

            $data = collect($acquisitions->items())
                ->map(function ($item) {

                    /*
            |--------------------------------------------------------------------------
            | Récupération des acquisitions liées
            |--------------------------------------------------------------------------
            */

                    $ids = Acquisition::where(

                        'numero_reference',
                        $item->numero_reference

                    )
                        ->where(

                            'type_acquisition',
                            $item->type_acquisition

                        )
                        ->whereDate(

                            'date_acquisition',
                            $item->date_acquisition

                        )
                        ->where(

                            'fournisseur_nom',
                            $item->fournisseur_nom

                        )
                        ->pluck('id');

                    /*
            |--------------------------------------------------------------------------
            | Quantité enregistrée
            |--------------------------------------------------------------------------
            */

                    $materiels = Materiel::whereIn(

                        'acquisition_id',
                        $ids

                    )->get();

                    $quantiteEnregistree =
                        $materiels->sum('quantite');

                    /*
            |--------------------------------------------------------------------------
            | Brouillons
            |--------------------------------------------------------------------------
            */

                    $nombreBrouillons =
                        $materiels
                            ->where(
                                'statut_enregistrement',
                                'BROUILLON'
                            )
                            ->count();

                    $quantiteBrouillon =
                        $materiels
                            ->where(
                                'statut_enregistrement',
                                'BROUILLON'
                            )
                            ->sum('quantite');

                    /*
            |--------------------------------------------------------------------------
            | Progression
            |--------------------------------------------------------------------------
            */

                    $quantitePrevue =
                        $item->quantite_prevue ?? 0;

                    $reste = max(

                        0,

                        $quantitePrevue
                        -
                        $quantiteEnregistree

                    );

                    $progression =
                        $quantitePrevue > 0

                        ?

                        round(

                            (
                                $quantiteEnregistree
                                /
                                $quantitePrevue

                            ) * 100,

                            2

                        )

                        :

                        0;

                    /*
            |--------------------------------------------------------------------------
            | Statut final
            |--------------------------------------------------------------------------
            */

                    if ($nombreBrouillons > 0) {

                        $statut = 'A_COMPLETER';

                    } elseif ($reste > 0) {

                        $statut = 'EN_COURS';

                    } else {

                        $statut = 'TERMINEE';

                    }

                    return [

                        'id' => $item->id,

                        'type_acquisition' => $item->type_acquisition,

                        'numero_reference' => $item->numero_reference,

                        'date_acquisition' => $item->date_acquisition,

                        'fournisseur_nom' => $item->fournisseur_nom,

                        'montant' => $item->montant,

                        /*
                    | Gestion saisie
                    */

                        'quantite_prevue' => $quantitePrevue,

                        'quantite_enregistree' => $quantiteEnregistree,

                        'reste_a_saisir' => $reste,

                        'progression' => $progression,

                        /*
                    | Brouillons
                    */

                        'nombre_brouillons' => $nombreBrouillons,

                        'quantite_brouillon' => $quantiteBrouillon,

                        'statut' => $statut,

                    ];

                });

            return response()->json([

                'success' => true,

                'message' => 'Liste des acquisitions récupérée avec succès.',

                'data' => $data,

                'pagination' => [

                    'current_page' => $acquisitions->currentPage(),

                    'last_page' => $acquisitions->lastPage(),

                    'per_page' => $acquisitions->perPage(),

                    'total' => $acquisitions->total(),

                ],

            ]);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors du chargement des acquisitions.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }

    public function detailAcquisition($id)
    {
        try {

            $acquisition = Acquisition::find($id);

            if (! $acquisition) {

                return response()->json([
                    'success' => false,
                    'message' => 'Acquisition introuvable.',
                ], 404);

            }

            /*
            |--------------------------------------------------------------------------
            | TOUTES LES ACQUISITIONS IDENTIQUES
            |--------------------------------------------------------------------------
            */

            $acquisitions = Acquisition::where('numero_reference', $acquisition->numero_reference)
                ->where('type_acquisition', $acquisition->type_acquisition)
                ->whereDate('date_acquisition', $acquisition->date_acquisition)
                ->where('fournisseur_nom', $acquisition->fournisseur_nom)
                ->get();

            /*
            |--------------------------------------------------------------------------
            | IDS DES ACQUISITIONS
            |--------------------------------------------------------------------------
            */

            $ids = $acquisitions->pluck('id');

            /*
            |--------------------------------------------------------------------------
            | MATERIELS
            |--------------------------------------------------------------------------
            */

            $materiels = Materiel::with([
                'attributions' => function ($query) {
                    $query->orderBy('date_debut', 'asc');
                },
            ])
                ->whereIn('acquisition_id', $ids)
                ->orderBy('code_materiel')
                ->get()
                ->map(function ($materiel) {

                    $premiereAttribution = $materiel->attributions->first();

                    return [

                        'id' => $materiel->id,

                        'code_materiel' => $materiel->code_materiel,

                        'numero_serie' => $materiel->numero_serie,

                        'marque' => $materiel->marque,

                        'modele' => $materiel->modele,

                        'type_materiel' => $materiel->type_materiel,

                        'etat' => $materiel->etat,

                        'cout' => $materiel->cout,

                        /*
                        |--------------------------------------------------------------------------
                        | Mise en service = Première attribution
                        |--------------------------------------------------------------------------
                        */
                        'date_mise_service' => optional($premiereAttribution)->date_debut,

                        /*
                        |--------------------------------------------------------------------------
                        | Informations métier
                        |--------------------------------------------------------------------------
                        */
                        'nombre_attributions' => $materiel->attributions->count(),

                        'statut_age' => $materiel->attributions->count() > 0
                            ? 'ancien'
                            : 'nouveau',

                    ];

                });

            /*
            |--------------------------------------------------------------------------
            | STATISTIQUES
            |--------------------------------------------------------------------------
            */

            $stats = [

                'nombre_materiels' => $materiels->count(),

                'nombre_disponibles' => $materiels->where('etat', 'disponible')->count(),

                'nombre_attribues' => $materiels->where('etat', 'attribue')->count(),

                'nombre_en_panne' => $materiels->where('etat', 'panne')->count(),

                'nombre_en_maintenance' => $materiels->where('etat', 'maintenance')->count(),

                'nouveaux' => $materiels->where('statut_age', 'nouveau')->count(),

                'anciens' => $materiels->where('statut_age', 'ancien')->count(),

            ];

            return response()->json([

                'success' => true,

                'message' => 'Détails récupérés avec succès.',

                'data' => [

                    'id' => $acquisition->id,

                    'type_acquisition' => $acquisition->type_acquisition,

                    'numero_reference' => $acquisition->numero_reference,

                    'date_acquisition' => $acquisition->date_acquisition,

                    'fournisseur_nom' => $acquisition->fournisseur_nom,

                    'fournisseur_contact' => $acquisition->fournisseur_contact,

                    'fournisseur_adresse' => $acquisition->fournisseur_adresse,

                    'montant' => $acquisition->montant,

                    'nombre_lignes' => $acquisitions->count(),

                    'materiels' => $materiels,

                    'statistiques' => $stats,

                ],

            ]);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors du chargement.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }

    public function statistiquesAcquisitions()
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | Nombre total acquisitions
            |--------------------------------------------------------------------------
            */

            $nombreAcquisitions = Acquisition::count();

            /*
            |--------------------------------------------------------------------------
            | Nombre acquisitions terminées
            |--------------------------------------------------------------------------
            */

            $acquisitionsTerminees = Acquisition::where('statut', 'TERMINEE')
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Nombre acquisitions en cours
            |--------------------------------------------------------------------------
            */

            $acquisitionsEnCours = Acquisition::where('statut', 'EN_COURS')
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Nombre total matériels acquis
            | Equipement = 1
            | Accessoire = quantité
            |--------------------------------------------------------------------------
            */

            $nombreMaterielsAcquis = Materiel::sum('quantite');

            /*
            |--------------------------------------------------------------------------
            | Nombre équipements
            |--------------------------------------------------------------------------
            */

            $nombreEquipements = Materiel::where(
                'categorie',
                'EQUIPEMENT'
            )
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Nombre accessoires
            |--------------------------------------------------------------------------
            */

            $nombreAccessoires = Materiel::where(
                'categorie',
                'ACCESSOIRE'
            )
                ->sum('quantite');

            /*
            |--------------------------------------------------------------------------
            | Répartition par catégorie
            |--------------------------------------------------------------------------
            */

            $repartitionCategorie = [

                'EQUIPEMENT' => $nombreEquipements,

                'ACCESSOIRE' => $nombreAccessoires,

            ];

            /*
            |--------------------------------------------------------------------------
            | Montant total des acquisitions
            |--------------------------------------------------------------------------
            */

            $montantTotal = Acquisition::sum('montant');

            /*
            |--------------------------------------------------------------------------
            | Dernières acquisitions
            |--------------------------------------------------------------------------
            */

            $dernieresAcquisitions = Acquisition::withCount([
                'materiels',
            ])
                ->orderByDesc('id')
                ->limit(5)
                ->get()
                ->map(function ($acquisition) {

                    return [

                        'id' => $acquisition->id,

                        'reference' => $acquisition->numero_reference,

                        'type' => $acquisition->type_acquisition,

                        'date' => $acquisition->date_acquisition,

                        'nombre_materiels' => $acquisition->materiels()
                            ->sum('quantite'),

                        'statut' => $acquisition->statut,

                    ];

                });

            return response()->json([

                'success' => true,

                'message' => 'Statistiques des acquisitions récupérées.',

                'data' => [

                    'nombre_acquisitions' => $nombreAcquisitions,

                    'acquisitions_terminees' => $acquisitionsTerminees,

                    'acquisitions_en_cours' => $acquisitionsEnCours,

                    'nombre_materiels_acquis' => $nombreMaterielsAcquis,

                    'nombre_equipements' => $nombreEquipements,

                    'nombre_accessoires' => $nombreAccessoires,

                    'repartition_categorie' => $repartitionCategorie,

                    'montant_total' => $montantTotal,

                    'dernieres_acquisitions' => $dernieresAcquisitions,

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
    
}
