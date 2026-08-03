<?php

namespace App\Http\Controllers;

use App\Models\Attribution;
use App\Models\Direction;
use App\Models\HistoriqueUtilisateur;
use App\Models\MouvementMateriel;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;

class UserController extends Controller
{
    public function import(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'file' => 'required|file|max:10240',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            $file = $request->file('file');
            $extension = $file->getClientOriginalExtension();

            DB::beginTransaction();

            $imported = 0;
            $ignored = 0;
            $errors = [];

            $rows = [];

            /*
            |--------------------------------------------------------------------------
            | LECTURE UNIVERSELLE
            |--------------------------------------------------------------------------
            */

            if (in_array($extension, ['xlsx', 'xls'])) {

                $spreadsheet = IOFactory::load($file->getPathname());
                $sheet = $spreadsheet->getActiveSheet()->toArray();

                $headers = array_map('strtolower', $sheet[0]);

                for ($i = 1; $i < count($sheet); $i++) {

                    if (empty(array_filter($sheet[$i] ?? []))) {
                        continue;
                    }

                    $row = [];

                    foreach ($headers as $index => $column) {
                        $row[$column] = $sheet[$i][$index] ?? null;
                    }

                    $rows[] = $row;
                }

            } else {

                $lines = file($file->getPathname());

                $headers = array_map('strtolower', str_getcsv(trim($lines[0])));

                for ($i = 1; $i < count($lines); $i++) {

                    $csvRow = str_getcsv(trim($lines[$i]));

                    if (empty($csvRow[0])) {
                        continue;
                    }

                    $row = [];

                    foreach ($headers as $index => $column) {
                        $row[$column] = $csvRow[$index] ?? null;
                    }

                    $rows[] = $row;
                }
            }

            /*
            |--------------------------------------------------------------------------
            | CHAMPS AUTORISÉS (BD USERS)
            |--------------------------------------------------------------------------
            */

            $allowedFields = [
                'matricule',
                'username',
                'nom',
                'prenom',
                'direction',
                'site',
            ];

            /*
            |--------------------------------------------------------------------------
            | CACHE
            |--------------------------------------------------------------------------
            */

            $directionCache = [];
            $siteCache = [];

            /*
            |--------------------------------------------------------------------------
            | TRAITEMENT
            |--------------------------------------------------------------------------
            */

            foreach ($rows as $i => $row) {

                try {

                    // garder uniquement champs utiles
                    $data = [];

                    foreach ($allowedFields as $field) {
                        $data[$field] = $row[$field] ?? null;
                    }

                    if (empty($data['username']) || empty($data['nom']) || empty($data['prenom'])) {
                        $ignored++;
                        $errors[] = 'Ligne '.($i + 2).' : champs obligatoires manquants';

                        continue;
                    }

                    if (User::where('username', $data['username'])->exists()) {
                        $ignored++;
                        $errors[] = 'Ligne '.($i + 2).' : username existe déjà';

                        continue;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | direction
                    |--------------------------------------------------------------------------
                    */

                    $directionId = null;

                    if (! empty($data['direction'])) {

                        if (isset($directionCache[$data['direction']])) {
                            $directionId = $directionCache[$data['direction']];
                        } else {
                            $dir = Direction::firstOrCreate(['nom' => $data['direction']]);
                            $directionCache[$data['direction']] = $dir->id;
                            $directionId = $dir->id;
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | site
                    |--------------------------------------------------------------------------
                    */

                    $siteId = null;

                    if (! empty($data['site'])) {

                        if (isset($siteCache[$data['site']])) {
                            $siteId = $siteCache[$data['site']];
                        } else {
                            $site = Site::firstOrCreate(['nom' => $data['site']]);
                            $siteCache[$data['site']] = $site->id;
                            $siteId = $site->id;
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | USER CREATE
                    |--------------------------------------------------------------------------
                    */

                    User::create([
                        'matricule' => $data['matricule'] ?? null,
                        'username' => $data['username'],
                        'nom' => $data['nom'],
                        'prenom' => $data['prenom'],
                        'password' => bcrypt('123456'),

                        'role' => 'USER',
                        'type_user' => 'USER',

                        'direction_id' => $directionId,
                        'site_id' => $siteId,

                        'actif' => true,
                    ]);

                    $imported++;

                } catch (\Exception $e) {
                    $ignored++;
                    $errors[] = 'Ligne '.($i + 2).' : '.$e->getMessage();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Import terminé : {$imported} importés, {$ignored} ignorés",
                'imported' => $imported,
                'ignored' => $ignored,
                'errors' => $errors,
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

   public function listUsers(Request $request)
{
    try {

        $all = filter_var($request->query('all', false), FILTER_VALIDATE_BOOLEAN);

        $perPage = $request->input('per_page', 10);

        $search = trim($request->input('search', ''));

        $roles = $request->filled('role')
            ? array_map('trim', explode(',', strtoupper($request->role)))
            : [];

        $query = User::query()
            ->with(['direction', 'site'])
            ->where('actif', true);

        /*
        |--------------------------------------------------------------------------
        | FILTRE ROLE
        |--------------------------------------------------------------------------
        */

        if (!empty($roles)) {

            $rolesAutorises = ['ADMIN', 'GESTIONNAIRE', 'USER'];

            $roles = array_intersect($roles, $rolesAutorises);

            if (!empty($roles)) {

                $query->whereIn('role', $roles);

            }

        }

        /*
        |--------------------------------------------------------------------------
        | RECHERCHE
        |--------------------------------------------------------------------------
        */

        if (!empty($search)) {

            $query->where(function ($q) use ($search) {

                $q->where('nom', 'ILIKE', "%{$search}%")
                    ->orWhere('prenom', 'ILIKE', "%{$search}%")
                    ->orWhere('matricule', 'ILIKE', "%{$search}%")
                    ->orWhere('username', 'ILIKE', "%{$search}%");

            });

        }

        $query->orderBy('nom')
              ->orderBy('prenom');

        /*
        |--------------------------------------------------------------------------
        | SANS PAGINATION
        |--------------------------------------------------------------------------
        */

        if ($all) {

            $users = $query->get();

            return response()->json([

                'success' => true,

                'data' => $users,

                'total' => $users->count(),

            ]);

        }

        /*
        |--------------------------------------------------------------------------
        | PAGINATION
        |--------------------------------------------------------------------------
        */

        $users = $query->paginate($perPage);

        return response()->json([

            'success' => true,

            'data' => $users->items(),

            'pagination' => [

                'current_page' => $users->currentPage(),

                'last_page' => $users->lastPage(),

                'per_page' => $users->perPage(),

                'total' => $users->total(),

                'from' => $users->firstItem(),

                'to' => $users->lastItem(),

            ],

        ]);

    } catch (\Throwable $e) {

        return response()->json([

            'success' => false,

            'message' => 'Erreur lors du chargement des utilisateurs.',

            'error' => $e->getMessage(),

        ], 500);

    }
}

    public function UpdateUsers(Request $request, $id)
    {
        try {

            $user = User::find($id);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur introuvable',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | VALIDATION
            |--------------------------------------------------------------------------
            */

            $request->validate([
                'username' => 'required|unique:users,username,'.$id,
                'nom' => 'required|string',
                'prenom' => 'required|string',
                'password' => 'nullable|min:6',
                'role' => 'nullable|in:ADMIN,GESTIONNAIRE,USER',
                'direction_id' => 'nullable|exists:directions,id',
                'site_id' => 'nullable|exists:sites,id',
                'actif' => 'nullable|boolean',
            ]);

            /*
            |--------------------------------------------------------------------------
            | MISE À JOUR
            |--------------------------------------------------------------------------
            */

            $user->username = $request->username;
            $user->nom = $request->nom;
            $user->prenom = $request->prenom;

            if ($request->filled('role')) {
                $user->role = $request->role;
            }

            if ($request->filled('direction_id')) {
                $user->direction_id = $request->direction_id;
            }

            if ($request->filled('site_id')) {
                $user->site_id = $request->site_id;
            }

            if ($request->has('actif')) {
                $user->actif = $request->actif;
            }

            /*
            |--------------------------------------------------------------------------
            | PASSWORD (OPTIONNEL)
            |--------------------------------------------------------------------------
            */

            if ($request->filled('password')) {
                $user->password = bcrypt($request->password);
            }

            $user->save();

            /*
            |--------------------------------------------------------------------------
            | RESPONSE
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,
                'message' => 'Utilisateur modifié avec succès',
                'data' => $user,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la modification',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function DetailUsers($id)
    {
        try {

            $user = User::with([
                'direction:id,nom',
                'site:id,nom',
            ])->find($id);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur introuvable',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | ATTRIBUTIONS (historique possession)
            |--------------------------------------------------------------------------
            */
            $attributions = Attribution::with([
                'materiel:id,code_materiel,marque,modele,numero_serie,type_materiel,etat',
                'direction:id,nom',
                'creator:id,nom,prenom',
            ])
                ->where('user_id', $id)
                ->orderByDesc('date_debut')
                ->get();

            /*
            |--------------------------------------------------------------------------
            | MOUVEMENTS GLOBAUX (TOUT L'HISTORIQUE)
            |--------------------------------------------------------------------------
            */
            $mouvements = MouvementMateriel::with([
                'materiel:id,code_materiel,marque,modele',
                'direction:id,nom',
                'creator:id,nom,prenom',
            ])
                ->where('user_id', $id)
                ->orderByDesc('date_mouvement')
                ->get();

            /*
            |--------------------------------------------------------------------------
            | HISTORIQUE UTILISATEUR (vue simplifiée)
            |--------------------------------------------------------------------------
            */
            $historique = HistoriqueUtilisateur::with([
                'materiel:id,code_materiel,marque,modele',
                'direction:id,nom',
                'site:id,nom',
            ])
                ->where('user_id', $id)
                ->orderByDesc('date_debut')
                ->get();

            /*
            |--------------------------------------------------------------------------
            | RÉSUMÉ STATISTIQUE
            |--------------------------------------------------------------------------
            */
            $stats = [
                'total_materiels_recus' => $attributions->count(),
                'en_cours' => $attributions->where('statut', 'ACTIVE')->count(),
                'termines' => $attributions->where('statut', 'TERMINE')->count(),
                'pannes' => $attributions->where('statut', 'EN_PANNE')->count(),
                'maintenances' => $attributions->where('statut', 'EN_MAINTENANCE')->count(),
            ];

            return response()->json([
                'success' => true,
                'user' => $user,
                'stats' => $stats,
                'attributions' => $attributions,
                'mouvements' => $mouvements,
                'historique' => $historique,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement du détail utilisateur',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function userStatistics()
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | UTILISATEURS
            |--------------------------------------------------------------------------
            */

            $totalUtilisateurs = User::count();

            /*
            |--------------------------------------------------------------------------
            | UTILISATEURS AYANT UN MATERIEL ACTUELLEMENT
            |--------------------------------------------------------------------------
            */

            $utilisateursAvecMateriel = User::whereHas('attributions', function ($query) {

                $query->whereIn('statut', [
                    'ACTIVE',
                    'EN_PANNE',
                    'EN_MAINTENANCE',
                ]);

            })->count();

            /*
            |--------------------------------------------------------------------------
            | UTILISATEURS SANS MATERIEL
            |--------------------------------------------------------------------------
            */

            $utilisateursSansMateriel = User::whereDoesntHave('attributions', function ($query) {

                $query->whereIn('statut', [
                    'ACTIVE',
                    'EN_PANNE',
                    'EN_MAINTENANCE',
                ]);

            })->count();

            /*
            |--------------------------------------------------------------------------
            | UTILISATEURS AYANT DEJA POSSEDE UN MATERIEL
            |--------------------------------------------------------------------------
            */

            $utilisateursHistorique = User::whereHas('attributions')
                ->count();

            /*
            |--------------------------------------------------------------------------
            | UTILISATEURS AVEC PLUSIEURS MATERIELS ACTIFS
            |--------------------------------------------------------------------------
            */

            $utilisateursAvecPlusieursMateriels = User::whereHas('attributions', function ($q) {
        $q->whereIn('statut', [
            'ACTIVE',
            'EN_PANNE',
            'EN_MAINTENANCE'
        ]);
    })
    ->withCount([
        'attributions as materiels_actifs' => function ($q) {
            $q->whereIn('statut', [
                'ACTIVE',
                'EN_PANNE',
                'EN_MAINTENANCE'
            ]);
        }
    ])
    ->get()
    ->filter(function ($user) {
        return $user->materiels_actifs > 1;
    })
    ->count();

            /*
            |--------------------------------------------------------------------------
            | ATTRIBUTIONS
            |--------------------------------------------------------------------------
            */

            $totalAttributions = Attribution::count();

            $attributionsActives = Attribution::whereIn('statut', [
                'ACTIVE',
                'EN_PANNE',
                'EN_MAINTENANCE',
            ])->count();

            /*
            |--------------------------------------------------------------------------
            | REPARTITION PAR DIRECTION
            |--------------------------------------------------------------------------
            */

            $parDirection = Attribution::with('direction:id,nom')
                ->whereIn('statut', [
                    'ACTIVE',
                    'EN_PANNE',
                    'EN_MAINTENANCE',
                ])
                ->selectRaw('direction_id, COUNT(*) as total')
                ->groupBy('direction_id')
                ->get()
                ->map(function ($item) {

                    return [
                        'direction' => $item->direction?->nom,
                        'total' => $item->total,
                    ];

                });

            /*
            |--------------------------------------------------------------------------
            | REPARTITION PAR SITE
            |--------------------------------------------------------------------------
            */

            $parSite = Attribution::with('site:id,nom')
                ->whereIn('statut', [
                    'ACTIVE',
                    'EN_PANNE',
                    'EN_MAINTENANCE',
                ])
                ->selectRaw('site_id, COUNT(*) as total')
                ->groupBy('site_id')
                ->get()
                ->map(function ($item) {

                    return [
                        'site' => $item->site?->nom,
                        'total' => $item->total,
                    ];

                });

            return response()->json([

                'success' => true,

                'message' => 'Statistiques utilisateurs récupérées avec succès.',

                'data' => [

                    'utilisateurs' => [

                        'total' => $totalUtilisateurs,

                        'avec_materiel' => $utilisateursAvecMateriel,

                        'sans_materiel' => $utilisateursSansMateriel,

                        'ayant_deja_possede' => $utilisateursHistorique,

                        'avec_plusieurs_materiels' => $utilisateursAvecPlusieursMateriels,

                    ],

                    'attributions' => [

                        'total' => $totalAttributions,

                        'actives' => $attributionsActives,

                    ],

                    'repartition_direction' => $parDirection,

                    'repartition_site' => $parSite,

                ],

            ]);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors du chargement des statistiques utilisateurs.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }
}
