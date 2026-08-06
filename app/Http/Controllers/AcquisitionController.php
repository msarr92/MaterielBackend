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

                'success'=>false,

                'message'=>$validator->errors()->first()

            ],422);

        }


        $search = trim($request->search ?? '');

        $perPage = $request->per_page ?? 10;



        /*
        |--------------------------------------------------------------------------
        | Chargement acquisitions
        |--------------------------------------------------------------------------
        */

        $query = Acquisition::query()
            ->withCount('materiels')
            ->withSum('materiels','quantite');



        /*
        |--------------------------------------------------------------------------
        | Recherche
        |--------------------------------------------------------------------------
        */

        if($search){

            $query->where(function($q) use ($search){

                $q->where(
                    'numero_reference',
                    'ILIKE',
                    "%{$search}%"
                )

                ->orWhere(
                    'fournisseur_nom',
                    'ILIKE',
                    "%{$search}%"
                )

                ->orWhere(
                    'type_acquisition',
                    'ILIKE',
                    "%{$search}%"
                );

            });

        }



        /*
        |--------------------------------------------------------------------------
        | Récupération
        |--------------------------------------------------------------------------
        */

        $acquisitions = $query
            ->orderByDesc('id')
            ->get();



        /*
        |--------------------------------------------------------------------------
        | Regroupement Laravel
        |--------------------------------------------------------------------------
        */

        $groupes = $acquisitions->groupBy(function($item){

            return

                $item->type_acquisition.'|'.
                $item->numero_reference.'|'.
                $item->date_acquisition.'|'.
                $item->fournisseur_nom;

        });



        /*
        |--------------------------------------------------------------------------
        | Pagination manuelle
        |--------------------------------------------------------------------------
        */

        $page = request('page',1);


        $collection = $groupes
            ->slice(
                ($page-1)*$perPage,
                $perPage
            );



        $data = $collection->map(function($items){


            $premier = $items->first();



            /*
            |--------------------------------------------------------------------------
            | Tous les IDs du groupe
            |--------------------------------------------------------------------------
            */

            $ids = $items->pluck('id');



            /*
            |--------------------------------------------------------------------------
            | Matériels enregistrés
            |--------------------------------------------------------------------------
            */

            $materiels = Materiel::whereIn(
                'acquisition_id',
                $ids
            )->get();



            $quantiteEnregistree =
                $materiels->sum('quantite');



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



            $quantitePrevue =
                $items->sum('quantite_prevue');



            $reste = max(
                0,
                $quantitePrevue-$quantiteEnregistree
            );



            $progression =
                $quantitePrevue > 0

                ?

                round(
                    ($quantiteEnregistree/$quantitePrevue)*100,
                    2
                )

                :

                0;



            if($nombreBrouillons > 0){

                $statut='A_COMPLETER';

            }
            elseif($reste > 0){

                $statut='EN_COURS';

            }
            else{

                $statut='TERMINEE';

            }



            return [

                'id'=>$premier->id,

                'type_acquisition'=>$premier->type_acquisition,

                'numero_reference'=>$premier->numero_reference,

                'date_acquisition'=>$premier->date_acquisition,


                'fournisseur_nom'=>$premier->fournisseur_nom,

                'fournisseur_contact'=>$premier->fournisseur_contact,

                'fournisseur_adresse'=>$premier->fournisseur_adresse,


                'montant'=>$items->sum('montant'),


                'quantite_prevue'=>$quantitePrevue,

                'quantite_enregistree'=>$quantiteEnregistree,

                'reste_a_saisir'=>$reste,

                'progression'=>$progression,


                'nombre_brouillons'=>$nombreBrouillons,

                'quantite_brouillon'=>$quantiteBrouillon,


                'nombre_materiels'=>$materiels->count(),


                'statut'=>$statut,


            ];


        })->values();



        return response()->json([


            'success'=>true,


            'message'=>'Liste des acquisitions récupérée avec succès.',


            'data'=>$data,


            'pagination'=>[

                'current_page'=>(int)$page,

                'per_page'=>$perPage,

                'total'=>$groupes->count(),

                'last_page'=>ceil(
                    $groupes->count()/$perPage
                ),

            ]


        ]);



    }
    catch(\Throwable $e){


        return response()->json([

            'success'=>false,

            'message'=>'Erreur lors du chargement des acquisitions.',

            'error'=>$e->getMessage()

        ],500);

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
        | Toutes les acquisitions ayant la même référence
        |--------------------------------------------------------------------------
        */
        $acquisitions = Acquisition::where('numero_reference', $acquisition->numero_reference)
            ->where('type_acquisition', $acquisition->type_acquisition)
            ->whereDate('date_acquisition', $acquisition->date_acquisition)
            ->where('fournisseur_nom', $acquisition->fournisseur_nom)
            ->get();

        $ids = $acquisitions->pluck('id');

        /*
        |--------------------------------------------------------------------------
        | Chargement complet des matériels
        |--------------------------------------------------------------------------
        */
        $materiels = Materiel::with([
            /*
            | Historique des attributions
            */
            'attributions' => function ($query) {
                $query->with([
                    'user:id,nom,prenom',
                    'direction:id,nom',
                    'site:id,nom',
                ])
                ->orderBy('date_debut', 'asc');
            },
            /*
            | Historique des mouvements
            */
            'mouvements' => function ($query) {
                $query->with([
                    'user:id,nom,prenom',
                    'creator:id,nom,prenom',
                    'direction:id,nom',
                    'site:id,nom',
                ])
                ->orderBy('date_mouvement', 'asc');
            },
        ])
            ->whereIn('acquisition_id', $ids)
            ->orderBy('code_materiel')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Transformation réponse
        |--------------------------------------------------------------------------
        */
        $materiels = $materiels->map(function ($materiel) {
            /*
            | Dernière attribution active
            */
            $attributionActive = $materiel->attributions
                ->where('statut', 'ACTIVE')
                ->first();

            // 🔥 CORRECTION : Vérifier si la relation user existe avant d'y accéder
            $possesseurActuel = null;
            if ($attributionActive && $attributionActive->user) {
                $possesseurActuel = [
                    'user' => [
                        'id' => $attributionActive->user->id,
                        'nom' => $attributionActive->user->nom,
                        'prenom' => $attributionActive->user->prenom,
                    ],
                    'direction' => $attributionActive->direction ? [
                        'id' => $attributionActive->direction->id,
                        'nom' => $attributionActive->direction->nom,
                    ] : null,
                    'site' => $attributionActive->site ? [
                        'id' => $attributionActive->site->id,
                        'nom' => $attributionActive->site->nom,
                    ] : null,
                    'attribue_par' => [
                        'id' => $attributionActive->user->id,
                        'nom' => $attributionActive->user->nom,
                        'prenom' => $attributionActive->user->prenom,
                    ],
                    'date_attribution' => $attributionActive->date_debut,
                ];
            }

            return [
                'id' => $materiel->id,
                'code_materiel' => $materiel->code_materiel,
                'numero_serie' => $materiel->numero_serie,
                'marque' => $materiel->marque,
                'modele' => $materiel->modele,
                'type_materiel' => $materiel->type_materiel,
                'etat' => $materiel->etat,
                'cout' => $materiel->cout,
                'date_mise_service' => $materiel->date_mise_service,
                'categorie' => $materiel->categorie ?? null,
                'est_accessoire' => $materiel->est_accessoire ?? false,

                'possesseur_actuel' => $possesseurActuel,

                'historique_attributions' => $materiel->attributions->map(function ($attr) {
                    return [
                        'user' => $attr->user ? [
                            'id' => $attr->user->id,
                            'nom' => $attr->user->nom,
                            'prenom' => $attr->user->prenom,
                        ] : null,
                        'direction' => $attr->direction ? [
                            'id' => $attr->direction->id,
                            'nom' => $attr->direction->nom,
                        ] : null,
                        'site' => $attr->site ? [
                            'id' => $attr->site->id,
                            'nom' => $attr->site->nom,
                        ] : null,
                        'date_debut' => $attr->date_debut,
                        'date_fin' => $attr->date_fin,
                        'statut' => $attr->statut,
                        'effectue_par' => $attr->user ? [
                            'id' => $attr->user->id,
                            'nom' => $attr->user->nom,
                            'prenom' => $attr->user->prenom,
                        ] : null,
                    ];
                })->values(),

                'historique_mouvements' => $materiel->mouvements->map(function ($mvt) {
                    return [
                        'type_mouvement' => $mvt->type_mouvement,
                        'date' => $mvt->date_mouvement,
                        'ancienne_valeur' => $mvt->ancienne_valeur,
                        'nouvelle_valeur' => $mvt->nouvelle_valeur,
                        'observation' => $mvt->observation,
                        'effectue_par' => $mvt->user ? [
                            'id' => $mvt->user->id,
                            'nom' => $mvt->user->nom,
                            'prenom' => $mvt->user->prenom,
                        ] : null,
                        'cree_par' => $mvt->creator ? [
                            'id' => $mvt->creator->id,
                            'nom' => $mvt->creator->nom,
                            'prenom' => $mvt->creator->prenom,
                        ] : null,
                        'direction' => $mvt->direction ? [
                            'id' => $mvt->direction->id,
                            'nom' => $mvt->direction->nom,
                        ] : null,
                        'site' => $mvt->site ? [
                            'id' => $mvt->site->id,
                            'nom' => $mvt->site->nom,
                        ] : null,
                    ];
                })->values(),
            ];
        });

        /*
        |--------------------------------------------------------------------------
        | Statistiques
        |--------------------------------------------------------------------------
        */
        $stats = [
            'nombre_materiels' => $materiels->count(),
            'disponibles' => $materiels->where('etat', 'disponible')->count(),
            'attribues' => $materiels->where('etat', 'attribue')->count(),
            'pannes' => $materiels->where('etat', 'panne')->count(),
            'maintenance' => $materiels->where('etat', 'maintenance')->count(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Détails acquisition récupérés.',
            'data' => [
                'acquisition' => [
                    'id' => $acquisition->id,
                    'type_acquisition' => $acquisition->type_acquisition,
                    'numero_reference' => $acquisition->numero_reference,
                    'date_acquisition' => $acquisition->date_acquisition,
                    'fournisseur_nom' => $acquisition->fournisseur_nom,
                    'fournisseur_contact' => $acquisition->fournisseur_contact,
                    'fournisseur_adresse' => $acquisition->fournisseur_adresse,
                    'montant' => $acquisition->montant,
                    'observation' => $acquisition->observation_acquisition ?? null,
                ],
                'nombre_acquisitions' => $acquisitions->count(),
                'materiels' => $materiels,
                'statistiques' => $stats,
            ],
        ]);

    } catch (\Throwable $e) {
        // 🔥 Log l'erreur complète pour le débogage
        // \Log::error('Erreur detailAcquisition', [
        //     'id' => $id,
        //     'message' => $e->getMessage(),
        //     'trace' => $e->getTraceAsString(),
        // ]);

        return response()->json([
            'success' => false,
            'message' => 'Erreur chargement détail acquisition',
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
