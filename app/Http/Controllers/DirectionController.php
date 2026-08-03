<?php

namespace App\Http\Controllers;

use App\Models\Attribution;
use App\Models\Direction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DirectionController extends Controller
{
    public function listDirections(Request $request)
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
            | REQUETE - Version corrigée sans withCount
            |--------------------------------------------------------------------------
            */
            $query = Direction::orderBy('nom');

            /*
            |--------------------------------------------------------------------------
            | RECHERCHE
            |--------------------------------------------------------------------------
            */
            if (! empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('nom', 'like', "%{$search}%");
                    if (is_numeric($search)) {
                        $q->orWhere('id', $search);
                    }
                });
            }

            /*
            |--------------------------------------------------------------------------
            | PAGINATION
            |--------------------------------------------------------------------------
            */
            $directions = $query->paginate($perPage);

            /*
            |--------------------------------------------------------------------------
            | FORMATAGE - Calcul des attributions après récupération
            |--------------------------------------------------------------------------
            */
            $data = collect($directions->items())->map(function ($direction) {
                // 🔥 Compter les attributions de cette direction
                $nombreAttributions = Attribution::where('direction_id', $direction->id)->count();

                $attributionsActives = Attribution::where('direction_id', $direction->id)
                    ->whereIn('statut', [
                        'ACTIVE',
                        'EN_PANNE',
                        'EN_MAINTENANCE',
                    ])
                    ->count();

                return [
                    'id' => $direction->id,
                    'nom' => $direction->nom,
                    'nombre_attributions' => $nombreAttributions,
                    'attributions_actives' => $attributionsActives,
                    'created_at' => $direction->created_at,
                    'updated_at' => $direction->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Liste des directions récupérée avec succès.',
                'data' => $data,
                'pagination' => [
                    'current_page' => $directions->currentPage(),
                    'last_page' => $directions->lastPage(),
                    'per_page' => $directions->perPage(),
                    'total' => $directions->total(),
                    'from' => $directions->firstItem(),
                    'to' => $directions->lastItem(),
                ],
            ]);

        } catch (\Throwable $e) {
            // Log::error('LIST DIRECTIONS ERROR', [
            //     'message' => $e->getMessage(),
            //     'line' => $e->getLine(),
            // ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des directions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function AjoutDirection(Request $request)
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | VALIDATION
            |--------------------------------------------------------------------------
            */

            $validator = Validator::make($request->all(), [

                'nom' => 'required|string|max:150|unique:directions,nom',

                'description' => 'nullable|string|max:1000',

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
            | CREATION
            |--------------------------------------------------------------------------
            */

            $direction = Direction::create([

                'nom' => strtoupper(trim($request->nom)),

                'description' => $request->description,

            ]);

            DB::commit();

            return response()->json([

                'success' => true,

                'message' => 'Direction créée avec succès.',

                'data' => [

                    'id' => $direction->id,

                    'nom' => $direction->nom,

                    'description' => $direction->description,

                    'created_at' => $direction->created_at,

                    'updated_at' => $direction->updated_at,

                ],

            ], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('AJOUT DIRECTION ERROR', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de la création de la direction.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }

    public function updateDirection(Request $request, $id)
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | Vérification de la direction
            |--------------------------------------------------------------------------
            */

            $direction = Direction::find($id);

            if (! $direction) {

                return response()->json([
                    'success' => false,
                    'message' => 'Direction introuvable.',
                ], 404);

            }

            /*
            |--------------------------------------------------------------------------
            | VALIDATION
            |--------------------------------------------------------------------------
            */

            $validator = Validator::make($request->all(), [

                'nom' => [
                    'required',
                    'string',
                    'max:150',
                    Rule::unique('directions', 'nom')->ignore($direction->id),
                ],

                'description' => 'nullable|string|max:1000',

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
            | MISE A JOUR
            |--------------------------------------------------------------------------
            */

            $direction->update([

                'nom' => strtoupper(trim($request->nom)),

                'description' => $request->description,

            ]);

            DB::commit();

            return response()->json([

                'success' => true,

                'message' => 'Direction modifiée avec succès.',

                'data' => [

                    'id' => $direction->id,

                    'nom' => $direction->nom,

                    'description' => $direction->description,

                    'created_at' => $direction->created_at,

                    'updated_at' => $direction->updated_at,

                ],

            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error('UPDATE DIRECTION ERROR', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de la modification de la direction.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }

    public function deleteDirection($id)
    {
        try {

            if (! is_numeric($id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifiant invalide.',
                ], 400);
            }

            DB::beginTransaction();

            $direction = Direction::find($id);

            if (! $direction) {

                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Direction introuvable.',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | Vérifier si utilisée par des utilisateurs
            |--------------------------------------------------------------------------
            */

            if ($direction->utilisateurs()->exists()) {

                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer cette direction car elle est associée à un ou plusieurs utilisateurs.',
                ], 409);
            }

            /*
            |--------------------------------------------------------------------------
            | Vérifier si utilisée dans les attributions
            |--------------------------------------------------------------------------
            */

            if ($direction->attributions()->exists()) {

                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Impossible de supprimer cette direction car elle est utilisée dans une ou plusieurs attributions.',
                ], 409);
            }

            /*
            |--------------------------------------------------------------------------
            | Suppression
            |--------------------------------------------------------------------------
            */

            $direction->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Direction supprimée avec succès.',
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression de la direction.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function directionStatistics()
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | TOTAL DIRECTIONS
            |--------------------------------------------------------------------------
            */

            $totalDirections = Direction::count();

            /*
            |--------------------------------------------------------------------------
            | ATTRIBUTIONS GLOBALES
            |--------------------------------------------------------------------------
            */

            $totalAttributions = Attribution::count();

            $attributionsActives = Attribution::whereIn('statut', [
                'ACTIVE',
                'EN_PANNE',
                'EN_MAINTENANCE',
            ])->count();

            $attributionsTerminees = Attribution::where(
                'statut',
                'TERMINE'
            )->count();

            /*
            |--------------------------------------------------------------------------
            | STATISTIQUES PAR DIRECTION
            |--------------------------------------------------------------------------
            */

            $directions = Direction::withCount([

                // Nombre utilisateurs dans la direction
                'utilisateurs',

                // Toutes les attributions
                'attributions',

                // Attributions actives
                'attributions as attributions_actives_count' => function ($query) {

                    $query->whereIn('statut', [
                        'ACTIVE',
                        'EN_PANNE',
                        'EN_MAINTENANCE',
                    ]);

                },

                // Attributions terminées
                'attributions as attributions_terminees_count' => function ($query) {

                    $query->where('statut', 'TERMINE');

                },

            ])
                ->orderBy('nom')
                ->get();

            /*
            |--------------------------------------------------------------------------
            | FORMATAGE
            |--------------------------------------------------------------------------
            */

            $dataDirections = $directions->map(function ($direction) {

                return [

                    'id' => $direction->id,

                    'nom' => $direction->nom,

                    'description' => $direction->description,

                    'nombre_utilisateurs' => $direction->utilisateurs_count,

                    'nombre_attributions' => $direction->attributions_count,

                    'attributions_actives' => $direction->attributions_actives_count,

                    'attributions_terminees' => $direction->attributions_terminees_count,

                ];

            });

            return response()->json([

                'success' => true,

                'message' => 'Statistiques des directions récupérées avec succès.',

                'data' => [

                    /*
                    |--------------------------------------------------------------------------
                    | GLOBAL
                    |--------------------------------------------------------------------------
                    */

                    'global' => [

                        'total_directions' => $totalDirections,

                        'total_attributions' => $totalAttributions,

                        'attributions_actives' => $attributionsActives,

                        'attributions_terminees' => $attributionsTerminees,

                    ],

                    /*
                    |--------------------------------------------------------------------------
                    | DETAIL PAR DIRECTION
                    |--------------------------------------------------------------------------
                    */

                    'directions' => $dataDirections,

                ],

            ]);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors du chargement des statistiques directions.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }
}
