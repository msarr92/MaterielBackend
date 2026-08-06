<?php

namespace App\Http\Controllers;

use App\Models\Attribution;
use App\Models\Direction;
use App\Models\Document;
use App\Models\Materiel;
use App\Models\MouvementMateriel;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AttributionController extends Controller
{
    public function attribuerMateriel(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [

                'materiels' => 'required|array|min:1',

                'materiels.*.materiel_id' => 'required|exists:materiels,id',

                'materiels.*.quantite' => 'nullable|integer|min:1',

                'user_id' => 'required|exists:users,id',

                'direction_id' => 'nullable|exists:directions,id',

                'site_id' => 'nullable|exists:sites,id',

                'date_fin' => 'nullable|date|after_or_equal:today',

            ]);

            if ($validator->fails()) {

                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);

            }

            $connectedUser = auth('api')->user();

            if (! $connectedUser) {

                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non authentifié',
                ], 401);

            }

            DB::beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | Récupération matériel avec verrou
            |--------------------------------------------------------------------------
            */

            $attributions = [];

            foreach ($request->materiels as $item) {

                $materiel = Materiel::lockForUpdate()
                    ->find($item['materiel_id']);

                if (! $materiel) {

                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Matériel introuvable',
                    ], 404);

                }

                /*
                |--------------------------------------------------------------------------
                | CAS EQUIPEMENT
                |--------------------------------------------------------------------------
                */

                if ($materiel->categorie === 'EQUIPEMENT') {

                    if ($materiel->etat !== 'disponible') {

                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'Cet équipement est indisponible.',
                        ], 409);

                    }

                    /*
                    Vérifier attribution active
                    */

                    $exist = Attribution::where('materiel_id', $materiel->id)
                        ->whereIn('statut', [
                            'ACTIVE',
                            'EN_PANNE',
                            'EN_MAINTENANCE',
                        ])
                        ->exists();

                    if ($exist) {

                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'Cet équipement est déjà attribué.',
                        ], 409);

                    }

                    $quantiteAttribuee = 1;

                }

                /*
                |--------------------------------------------------------------------------
                | CAS ACCESSOIRE
                |--------------------------------------------------------------------------
                */

                else {

                    $quantiteAttribuee = $request->quantite ?? 1;

                    if ($materiel->quantite < $quantiteAttribuee) {

                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => 'Stock insuffisant.',
                            'stock_disponible' => $materiel->quantite,
                        ], 409);

                    }

                }

                /*
                |--------------------------------------------------------------------------
                | Type action
                |--------------------------------------------------------------------------
                */

                $typeAction = Attribution::where(
                    'materiel_id',
                    $materiel->id
                )->exists()
                    ? 'REAFFECTATION'
                    : 'ATTRIBUTION';

                /*
                |--------------------------------------------------------------------------
                | Création attribution
                |--------------------------------------------------------------------------
                */

                $attribution = Attribution::create([

                    'materiel_id' => $materiel->id,

                    'user_id' => $request->user_id,

                    'direction_id' => $request->direction_id,

                    'site_id' => $request->site_id,

                    'quantite' => $quantiteAttribuee,

                    'date_debut' => now(),

                    'date_fin' => $request->date_fin,

                    'statut' => 'ACTIVE',

                    'type_action' => $typeAction,

                    'created_by' => $connectedUser->id,

                ]);

                /*
                |--------------------------------------------------------------------------
                | Mise à jour matériel
                |--------------------------------------------------------------------------
                */

                if ($materiel->categorie === 'EQUIPEMENT') {

                    $dataUpdate = [

                        'etat' => 'attribue',

                        'date_etat_change' => now(),

                        'motif_etat' => $typeAction,

                    ];

                    /*
                    |--------------------------------------------------------------------------
                    | Première mise en service
                    |--------------------------------------------------------------------------
                    | On renseigne la date uniquement si le matériel
                    | n'a jamais été mis en service.
                    |--------------------------------------------------------------------------
                    */

                    if (! $materiel->date_mise_service) {

                        $dataUpdate['date_mise_service'] = now();

                    }

                    $materiel->update($dataUpdate);

                } else {

                    /*
                    Diminution stock accessoire
                    */

                    $materiel->decrement(
                        'quantite',
                        $quantiteAttribuee
                    );

                    /*
                    Si stock épuisé
                    */

                    if ($materiel->quantite == 0) {

                        $materiel->update([

                            'etat' => 'attribue',

                            'date_etat_change' => now(),

                        ]);

                    }

                }

                /*
                |--------------------------------------------------------------------------
                | Historique mouvement
                |--------------------------------------------------------------------------
                */

                MouvementMateriel::create([

                    'materiel_id' => $materiel->id,

                    'user_id' => $request->user_id,

                    'direction_id' => $request->direction_id,

                    'type_mouvement' => $typeAction,

                    'date_mouvement' => now(),

                    'etat_materiel' => $materiel->etat,

                    'quantite' => $quantiteAttribuee,

                    'observation' => $materiel->categorie === 'ACCESSOIRE'
                        ? 'Sortie stock accessoire'
                        : 'Attribution équipement',

                    'created_by' => $connectedUser->id,

                ]);

            }

            DB::commit();

            return response()->json([

                'success' => true,

                'message' => $materiel->categorie === 'ACCESSOIRE'
                    ? 'Accessoire attribué et stock diminué avec succès.'
                    : 'Matériel attribué avec succès.',

                'data' => $attribution->load([

                    'materiel',

                    'user',

                    'direction',

                    'site',

                    'creator',

                ]),

            ], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de l’attribution.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }

    public function retournerMateriel(Request $request, $id)
    {
        try {

            $validator = Validator::make($request->all(), [
                'etat_retour' => 'required|in:disponible,panne,maintenance',
                'observation' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            DB::beginTransaction();

            $attribution = Attribution::lockForUpdate()->find($id);

            if (! $attribution) {

                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Attribution introuvable',
                ], 404);
            }

            if ($attribution->statut !== 'ACTIVE') {

                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Cette attribution est déjà clôturée',
                ], 409);
            }

            $materiel = Materiel::lockForUpdate()->find($attribution->materiel_id);

            if (! $materiel) {

                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Matériel introuvable',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Détermination du statut et du type de mouvement
            |--------------------------------------------------------------------------
            */

            $statutAttribution = 'TERMINE';
            $typeMouvement = 'RETOUR';

            switch ($request->etat_retour) {

                case 'panne':
                    $statutAttribution = 'EN_PANNE';
                    $typeMouvement = 'PANNE';
                    break;

                case 'maintenance':
                    $statutAttribution = 'EN_MAINTENANCE';
                    $typeMouvement = 'MAINTENANCE';
                    break;

                case 'disponible':
                default:
                    $statutAttribution = 'TERMINE';
                    $typeMouvement = 'RETOUR';
                    break;
            }

            /*
            |--------------------------------------------------------------------------
            | Mise à jour attribution
            |--------------------------------------------------------------------------
            */

            $attribution->update([
                'statut' => $statutAttribution,
                'date_fin' => $statutAttribution === 'TERMINE'
                    ? now()
                    : null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Mise à jour matériel
            |--------------------------------------------------------------------------
            */

            $materiel->update([
                'etat' => $request->etat_retour,
                'date_etat_change' => now(),
                'motif_etat' => $request->observation ?? ucfirst($request->etat_retour),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Historique des mouvements
            |--------------------------------------------------------------------------
            */

            MouvementMateriel::create([
                'materiel_id' => $materiel->id,
                'user_id' => $attribution->user_id,
                'direction_id' => $attribution->direction_id,
                'type_mouvement' => $typeMouvement,
                'date_mouvement' => now(),
                'etat_materiel' => $request->etat_retour,
                'observation' => $request->observation,
                'created_by' => auth()->id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => match ($typeMouvement) {
                    'RETOUR' => 'Matériel retourné avec succès.',
                    'PANNE' => 'Le matériel a été déclaré en panne.',
                    'MAINTENANCE' => 'Le matériel a été envoyé en maintenance.',
                    default => 'Retour traité avec succès.',
                },
                'data' => $attribution->load([
                    'materiel',
                    'user',
                    'direction',
                    'site',
                    'creator',
                ]),
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du retour.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function remettreEnService(Request $request, $id)
    {
        try {

            DB::beginTransaction();

            $attribution = Attribution::lockForUpdate()->find($id);

            if (! $attribution) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attribution introuvable',
                ], 404);
            }

            // 🔥 Vérification état autorisé
            if (! in_array($attribution->statut, ['EN_PANNE', 'EN_MAINTENANCE'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Seules les attributions en panne ou maintenance peuvent être remises en service',
                ], 409);
            }

            $materiel = Materiel::lockForUpdate()->find($attribution->materiel_id);

            if (! $materiel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Matériel introuvable',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | REMISE EN SERVICE
            |--------------------------------------------------------------------------
            */

            // Attribution redevient ACTIVE
            $attribution->update([
                'statut' => 'ACTIVE',
            ]);

            // Matériel redevient attribué
            $materiel->update([
                'etat' => 'attribue',
                'date_etat_change' => now(),
                'motif_etat' => 'Remise en service',
            ]);

            // Historique
            MouvementMateriel::create([
                'materiel_id' => $materiel->id,
                'user_id' => $attribution->user_id,
                'direction_id' => $attribution->direction_id,
                'type_mouvement' => 'ATTRIBUTION',
                'date_mouvement' => now(),
                'etat_materiel' => 'attribue',
                'observation' => 'Remise en service',
                'created_by' => auth()->id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Matériel remis en service avec succès',
                'data' => $attribution->load(['materiel', 'user', 'direction']),
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur remise en service',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function detailAttribution($id)
    {
        try {
            if (! is_numeric($id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifiant invalide.',
                ], 400);
            }

            // 🔥 Charger l'attribution avec toutes les relations
            $attribution = Attribution::with([
                'materiel.acquisition',
                'user:id,nom,prenom,username,matricule',
                'direction:id,nom',
                'site:id,nom',
                'creator:id,nom,prenom',
            ])->find($id);

            if (! $attribution) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attribution introuvable.',
                ], 404);
            }

            //  Récupérer l'ID du matériel
            $materielId = $attribution->materiel_id ?? $attribution->materiel->id ?? null;

            //  Récupérer les mouvements du matériel
            $mouvements = [];
            if ($materielId) {
                $mouvements = MouvementMateriel::with([
                    'user:id,nom,prenom',
                    'direction:id,nom',
                    'creator:id,nom,prenom',
                ])
                    ->where('materiel_id', $materielId)
                    ->orderByDesc('date_mouvement')
                    ->get();
            }

            //  Récupérer l'historique des attributions du matériel
            $anciensUtilisateurs = [];
            if ($materielId) {
                $anciensUtilisateurs = Attribution::with([
                    'user:id,nom,prenom',
                    'direction:id,nom',
                    'site:id,nom',
                ])
                    ->where('materiel_id', $materielId)
                    ->orderBy('date_debut')
                    ->get();
            }

            //  Construire la réponse simplifiée
            return response()->json([
                'success' => true,
                'message' => 'Détails de l\'attribution récupérés avec succès.',
                'data' => [
                    // Attribution
                    'id' => $attribution->id,
                    'date_debut' => $attribution->date_debut,
                    'date_fin' => $attribution->date_fin,
                    'statut' => $attribution->statut,
                    'type_action' => $attribution->type_action,
                    'created_at' => $attribution->created_at,
                    'updated_at' => $attribution->updated_at,

                    // Utilisateur (bénéficiaire)
                    'utilisateur' => $attribution->user,

                    // Direction
                    'direction' => $attribution->direction,

                    // Site
                    'site' => $attribution->site,

                    // Matériel
                    'materiel' => $attribution->materiel,

                    // Acquisition
                    'acquisition' => $attribution->materiel?->acquisition,

                    // Créateur
                    'created_by' => $attribution->creator,

                    // Historique des mouvements
                    'mouvements' => $mouvements,

                    // Historique des attributions
                    'historique_attributions' => $anciensUtilisateurs,
                ],
            ]);

        } catch (\Throwable $e) {
            // 🔥 Log l'erreur pour le debugging
            // \Log::error('Erreur dans detailAttribution: ' . $e->getMessage());
            // \Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des détails.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function listAttributions(Request $request)
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
            | UNIQUEMENT LES MATERIELS ACTUELLEMENT ATTRIBUES
            |--------------------------------------------------------------------------
            */

            $query = Attribution::with([

                'materiel:id,code_materiel,numero_serie,marque,modele,type_materiel,categorie',

                'user:id,nom,prenom',

                'direction:id,nom',

                'site:id,nom',

                'creator:id,nom,prenom',

            ])
                ->whereIn('statut', [
                    'ACTIVE',
                    'EN_PANNE',
                    'EN_MAINTENANCE',
                ])
                ->orderByDesc('id');

            /*
            |--------------------------------------------------------------------------
            | RECHERCHE
            |--------------------------------------------------------------------------
            */

            if (! empty($search)) {

                $query->where(function ($q) use ($search) {

                    $q->whereHas('materiel', function ($m) use ($search) {

                        $m->where('code_materiel', 'like', "%{$search}%")
                            ->orWhere('numero_serie', 'like', "%{$search}%")
                            ->orWhere('marque', 'like', "%{$search}%")
                            ->orWhere('modele', 'like', "%{$search}%")
                            ->orWhere('type_materiel', 'like', "%{$search}%");

                    })
                        ->orWhereHas('user', function ($u) use ($search) {

                            $u->where('nom', 'like', "%{$search}%")
                                ->orWhere('prenom', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");

                        })
                        ->orWhereHas('direction', function ($d) use ($search) {

                            $d->where('nom', 'like', "%{$search}%");

                        })
                        ->orWhereHas('site', function ($s) use ($search) {

                            $s->where('nom', 'like', "%{$search}%");

                        });

                });

            }

            $attributions = $query->paginate($perPage);

            /*
            |--------------------------------------------------------------------------
            | FORMAT REPONSE
            |--------------------------------------------------------------------------
            */

            $data = collect($attributions->items())->map(function ($item) {

                return [

                    'id' => $item->id,

                    'materiel' => [

                        'id' => $item->materiel?->id,

                        'code_materiel' => $item->materiel?->code_materiel,

                        'categorie' => $item->materiel?->categorie,

                        'numero_serie' => $item->materiel?->numero_serie,

                        'marque' => $item->materiel?->marque,

                        'modele' => $item->materiel?->modele,

                        'type_materiel' => $item->materiel?->type_materiel,

                    ],

                    'utilisateur' => $item->user,

                    'direction' => $item->direction,

                    'site' => $item->site,

                    'date_debut' => $item->date_debut,

                    'statut_attribution' => $item->statut,

                    'type_action' => $item->type_action,

                    /*
                    |--------------------------------------------------------------------------
                    | Le matériel est-il encore détenu ?
                    |--------------------------------------------------------------------------
                    */

                    'is_actif' => true,

                    /*
                    |--------------------------------------------------------------------------
                    | Badge Front
                    |--------------------------------------------------------------------------
                    */

                    'badge' => match ($item->statut) {

                        'ACTIVE' => [

                            'label' => 'Attribué',

                            'color' => 'primary',

                        ],

                        'EN_PANNE' => [

                            'label' => 'En panne',

                            'color' => 'danger',

                        ],

                        'EN_MAINTENANCE' => [

                            'label' => 'En maintenance',

                            'color' => 'warning',

                        ],

                        default => [

                            'label' => $item->statut,

                            'color' => 'secondary',

                        ],

                    },

                    'created_by' => $item->creator

                        ? $item->creator->nom.' '.$item->creator->prenom

                        : null,

                    'created_at' => $item->created_at,

                ];

            });

            return response()->json([

                'success' => true,

                'message' => 'Liste des matériels actuellement attribués.',

                'data' => $data,

                'pagination' => [

                    'current_page' => $attributions->currentPage(),

                    'last_page' => $attributions->lastPage(),

                    'per_page' => $attributions->perPage(),

                    'total' => $attributions->total(),

                    'from' => $attributions->firstItem(),

                    'to' => $attributions->lastItem(),

                ],

            ]);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors du chargement des attributions.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }

    public function getDirections(): JsonResponse
    {
        try {

            $directions = Direction::select(
                'id',
                'nom',
                'description'
            )
                ->orderBy('nom', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Liste des directions récupérée avec succès',
                'data' => $directions,
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des directions',
            ], 500);
        }
    }

    public function getSites(): JsonResponse
    {
        try {

            $sites = Site::select('id', 'nom', 'adresse')
                ->orderBy('nom', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Liste des sites récupérée avec succès',
                'data' => $sites,
            ], 200);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des sites',
            ], 500);
        }
    }

    public function deleteAttribution($id)
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | LOCK REDIS (anti double clic / double suppression)
            |--------------------------------------------------------------------------
            */
            $lockKey = "attribution:delete:lock:$id";

            if (Cache::has($lockKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Suppression déjà en cours',
                ], 429);
            }

            Cache::put($lockKey, true, now()->addSeconds(10));

            DB::beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | Récupération attribution
            |--------------------------------------------------------------------------
            */
            $attribution = Attribution::find($id);

            if (! $attribution) {
                Cache::forget($lockKey);

                return response()->json([
                    'success' => false,
                    'message' => 'Attribution introuvable',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | REMISE DU MATERIEL DISPONIBLE
            |--------------------------------------------------------------------------
            */
            $materiel = Materiel::find($attribution->materiel_id);

            if ($materiel) {
                $materiel->update([
                    'etat' => 'disponible',
                ]);

                // cache update matériel
                Cache::put("materiel:{$materiel->id}", $materiel, now()->addHours(1));
            }

            /*
            |--------------------------------------------------------------------------
            | SOFT DELETE attribution
            |--------------------------------------------------------------------------
            */
            $attribution->delete();

            DB::commit();

            /*
            |--------------------------------------------------------------------------
            | INVALIDATION CACHE
            |--------------------------------------------------------------------------
            */
            Cache::forget('attribution:list');
            Cache::forget('materiels:list');
            Cache::forget($lockKey);

            return response()->json([
                'success' => true,
                'message' => 'Attribution supprimée et matériel remis disponible',
            ]);

        } catch (\Exception $e) {

            DB::rollBack();
            Cache::forget("attribution:delete:lock:$id");

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression',
            ], 500);
        }
    }

    public function updateAttribution(Request $request, $id)
    {
        try {

            $validator = Validator::make($request->all(), [
                'materiel_id' => 'required|exists:materiels,id',
                'user_id' => 'required|exists:users,id',
                'direction_id' => 'nullable|exists:directions,id',
                'site_id' => 'nullable|exists:sites,id', //  AJOUT

                'date_debut' => 'required|date',
                'date_fin' => 'nullable|date|after_or_equal:date_debut',

                'statut' => 'required|in:ACTIVE,TERMINE,EN_PANNE,EN_MAINTENANCE',
                'type_action' => 'required|in:ATTRIBUTION,REAFFECTATION',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            DB::beginTransaction();

            $attribution = Attribution::lockForUpdate()->find($id);

            if (! $attribution) {

                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Attribution introuvable',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Si le matériel change
            |--------------------------------------------------------------------------
            */
            if ($attribution->materiel_id != $request->materiel_id) {

                // Ancien matériel → disponible
                Materiel::where('id', $attribution->materiel_id)
                    ->update([
                        'etat' => 'disponible',
                        'date_etat_change' => now(),
                        'motif_etat' => 'Réaffectation',
                    ]);

                // Nouveau matériel
                $nouveauMateriel = Materiel::lockForUpdate()->find($request->materiel_id);

                if (! $nouveauMateriel) {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Nouveau matériel introuvable',
                    ], 404);
                }

                if ($nouveauMateriel->etat != 'disponible') {
                    DB::rollBack();

                    return response()->json([
                        'success' => false,
                        'message' => 'Le nouveau matériel n\'est pas disponible',
                    ], 409);
                }

                $nouveauMateriel->update([
                    'etat' => 'attribue',
                    'date_etat_change' => now(),
                    'motif_etat' => 'Réaffectation',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Mise à jour attribution
            |--------------------------------------------------------------------------
            */
            $attribution->update([
                'materiel_id' => $request->materiel_id,
                'user_id' => $request->user_id,
                'direction_id' => $request->direction_id,
                'site_id' => $request->site_id, // ✅ AJOUT ICI

                'date_debut' => $request->date_debut,
                'date_fin' => $request->date_fin,

                'statut' => $request->statut,
                'type_action' => $request->type_action,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Attribution modifiée avec succès',
                'data' => $attribution->load([
                    'materiel',
                    'user',
                    'direction',
                    'site', // ✅ AJOUT relation
                    'creator',
                ]),
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function statistiqueAttribution()
    {
        try {

            $debutMois = now()->startOfMonth();
            $finMois = now()->endOfMonth();

            /*
            |--------------------------------------------------------------------------
            | Total des matériels actuellement attribués
            |--------------------------------------------------------------------------
            */

            $totalAttribues = Attribution::where('statut', 'ACTIVE')
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Attributions effectuées ce mois-ci
            |--------------------------------------------------------------------------
            */

            $attribuesMois = Attribution::whereBetween(
                'date_debut',
                [$debutMois, $finMois]
            )->count();

            /*
            |--------------------------------------------------------------------------
            | Total matériels en service
            | (Disponible + Attribué)
            |--------------------------------------------------------------------------
            */

            $materielsEnService = Materiel::whereIn('etat', [
                'disponible',
                'attribue',
            ])->count();

            /*
            |--------------------------------------------------------------------------
            | Total matériels en panne
            |--------------------------------------------------------------------------
            */

            $materielsPanne = Materiel::where('etat', 'panne')
                ->count();

            /*
            |--------------------------------------------------------------------------
            | Total matériels retournés
            |--------------------------------------------------------------------------
            */

            $materielsRetournes = Attribution::where('statut', 'TERMINE')
                ->count();

            return response()->json([

                'success' => true,

                'message' => 'Statistiques des attributions récupérées avec succès.',

                'data' => [

                    'total_attribues' => $totalAttribues,

                    'attribues_ce_mois' => $attribuesMois,

                    'materiels_en_service' => $materielsEnService,

                    'materiels_en_panne' => $materielsPanne,

                    'materiels_retournes' => $materielsRetournes,

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

    public function importerDocument(Request $request)
    {
        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | Validation
            |--------------------------------------------------------------------------
            */

            $validator = Validator::make($request->all(), [

                'document' => [
                    'required',
                    'file',
                    'mimes:pdf,jpg,jpeg,png',
                    'max:10240',
                ],

                'attribution_id' => [
                    'nullable',
                    'exists:attributions,id',
                ],

                'attributions' => [
                    'nullable',
                    'array',
                ],

                'attributions.*' => [
                    'exists:attributions,id',
                ],

                'observation' => [
                    'nullable',
                    'string',
                    'max:500',
                ],

            ]);

            if ($validator->fails()) {

                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);

            }

            /*
            |--------------------------------------------------------------------------
            | Récupération des attributions
            |--------------------------------------------------------------------------
            */

            $attributionIds = [];

            // Une seule attribution

            if ($request->filled('attribution_id')) {

                $attributionIds[] = $request->attribution_id;

            }

            // Plusieurs attributions

            if ($request->filled('attributions')) {

                $attributionIds = array_merge(
                    $attributionIds,
                    $request->attributions
                );

            }

            $attributionIds = array_unique($attributionIds);

            if (count($attributionIds) == 0) {

                return response()->json([
                    'success' => false,
                    'message' => 'Aucune attribution sélectionnée.',
                ], 422);

            }

            /*
            |--------------------------------------------------------------------------
            | Vérification des attributions
            |--------------------------------------------------------------------------
            */

            $attributions = Attribution::whereIn(
                'id',
                $attributionIds
            )->get();

            if ($attributions->count() != count($attributionIds)) {

                return response()->json([
                    'success' => false,
                    'message' => 'Une attribution est introuvable.',
                ], 404);

            }

            /*
            |--------------------------------------------------------------------------
            | Upload document
            |--------------------------------------------------------------------------
            */

            $file = $request->file('document');

            $nomFichier =
                time().'_'.$file->getClientOriginalName();

            $chemin = $file->storeAs(
                'documents/attributions',
                $nomFichier,
                'public'
            );

            /*
            |--------------------------------------------------------------------------
            | Génération numéro document
            |--------------------------------------------------------------------------
            */

            $annee = date('Y');

            $compteur = Document::whereYear(
                'created_at',
                $annee
            )->count() + 1;

            $numeroDocument =
                'DOC-'.
                date('Ymd').
                '-'.
                str_pad(
                    $compteur,
                    5,
                    '0',
                    STR_PAD_LEFT
                );

            /*
            |--------------------------------------------------------------------------
            | Création document
            |--------------------------------------------------------------------------
            */

            $document = Document::create([

                'numero_document' => $numeroDocument,

                'type_document' => 'FICHE_DEPLACEMENT',

                'fichier_scan' => $chemin,

                'date_generation' => now(),

                'date_televersement' => now(),

                'created_by' => auth()->id(),

                'observation' => $request->observation,

            ]);

            /*
            |--------------------------------------------------------------------------
            | Liaison document -> attributions
            |--------------------------------------------------------------------------
            |
            | Ici on met le document_id dans chaque attribution
            |
            */

            Attribution::whereIn(
                'id',
                $attributionIds
            )
                ->update([

                    'document_id' => $document->id,

                ]);

            DB::commit();

            return response()->json([

                'success' => true,

                'message' => 'Document importé avec succès.',

                'data' => $document->load([

                    'attributions.user',
                    'attributions.direction',
                    'attributions.site',
                    'attributions.materiel',

                ]),

            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de l’import.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }
}
