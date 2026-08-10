<?php

namespace App\Http\Controllers;

use App\Models\Direction;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Junges\Kafka\Facades\Kafka;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function register(Request $request)
{
    try {

        /*
        |--------------------------------------------------------------------------
        | UTILISATEUR CONNECTÉ
        |--------------------------------------------------------------------------
        */

        $authUser = auth('api')->user();

        if (! $authUser) {
            return response()->json([
                'success' => false,
                'message' => 'Utilisateur non authentifié.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | ADMIN UNIQUEMENT
        |--------------------------------------------------------------------------
        */

        if ($authUser->role !== 'ADMIN') {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Seul un administrateur peut créer un utilisateur.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | VALIDATION
        |--------------------------------------------------------------------------
        */

        $validated = $request->validate([

            'username' => [
                'required',
                'string',
                'min:3',
                'max:80',
                'regex:/^[A-Za-z0-9._-]+$/',
                'unique:users,username',
            ],

            'nom' => [
                'required',
                'string',
                'max:120',
            ],

            'prenom' => [
                'required',
                'string',
                'max:120',
            ],

            'role' => [
                'required',
                'in:ADMIN,GESTIONNAIRE,USER',
            ],

            /*
            |--------------------------------------------------------------------------
            | USER
            |--------------------------------------------------------------------------
            */

            'direction_id' => [
                'required_if:role,USER',
                'nullable',
                'integer',
                'exists:directions,id',
            ],

            'site_id' => [
                'required_if:role,USER',
                'nullable',
                'integer',
                'exists:sites,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | ADMIN / GESTIONNAIRE
            |--------------------------------------------------------------------------
            */

            'password' => [
                'required_if:role,ADMIN,GESTIONNAIRE',
                'nullable',
                'string',
                'min:6',
                'max:128',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | GENERATION MATRICULE
        |--------------------------------------------------------------------------
        */

        $year = now()->year;

        $lastUserId = User::max('id');

        $nextId = $lastUserId
            ? $lastUserId + 1
            : 1;

        $matricule =
            'ONAS-'
            .$year
            .'-'
            .str_pad(
                $nextId,
                4,
                '0',
                STR_PAD_LEFT
            );

        /*
        |--------------------------------------------------------------------------
        | VALEURS PAR DEFAUT
        |--------------------------------------------------------------------------
        */

        $directionId = null;
        $siteId = null;

        $directionLibelle = null;
        $siteLibelle = null;

        $temporaryPassword = null;

        /*
        |--------------------------------------------------------------------------
        | TRAITEMENT SELON LE ROLE
        |--------------------------------------------------------------------------
        */

        if ($validated['role'] === 'USER') {

            /*
            |--------------------------------------------------------------------------
            | USER
            |--------------------------------------------------------------------------
            */

            $direction = Direction::find(
                $validated['direction_id']
            );

            $site = Site::find(
                $validated['site_id']
            );

            if (! $direction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Direction introuvable.',
                ], 422);
            }

            if (! $site) {
                return response()->json([
                    'success' => false,
                    'message' => 'Site introuvable.',
                ], 422);
            }

            $directionId = $direction->id;
            $siteId = $site->id;

            /*
            |--------------------------------------------------------------------------
            | ATTENTION AUX NOMS DES COLONNES
            |--------------------------------------------------------------------------
            |
            | Si ta table directions utilise "libelle",
            | garde $direction->libelle.
            |
            | Si elle utilise "nom",
            | remplace par $direction->nom.
            |
            |--------------------------------------------------------------------------
            */

            $directionLibelle =
                $direction->libelle
                ?? $direction->nom
                ?? null;

            $siteLibelle =
                $site->libelle
                ?? $site->nom
                ?? null;

            /*
            |--------------------------------------------------------------------------
            | MOT DE PASSE TEMPORAIRE
            |--------------------------------------------------------------------------
            */

            $temporaryPassword =
                Str::random(20);

            $plainPassword =
                $temporaryPassword;

        } else {

            /*
            |--------------------------------------------------------------------------
            | ADMIN / GESTIONNAIRE
            |--------------------------------------------------------------------------
            |
            | Pas de direction/site.
            | Mot de passe obligatoire fourni par l'administrateur.
            |
            |--------------------------------------------------------------------------
            */

            $plainPassword =
                $validated['password'];
        }

        /*
        |--------------------------------------------------------------------------
        | CREATION
        |--------------------------------------------------------------------------
        */

        $user = User::create([

            'matricule' =>
                $matricule,

            'username' =>
                $validated['username'],

            'nom' =>
                $validated['nom'],

            'prenom' =>
                $validated['prenom'],

            'password' =>
                Hash::make(
                    $plainPassword
                ),

            'role' =>
                $validated['role'],

            'direction_id' =>
                $directionId,

            'site_id' =>
                $siteId,

            'direction_libelle' =>
                $directionLibelle,

            'site_libelle' =>
                $siteLibelle,

            'actif' =>
                true,
        ]);

        /*
        |--------------------------------------------------------------------------
        | REPONSE
        |--------------------------------------------------------------------------
        */

        $response = [

            'success' => true,

            'message' =>
                'Utilisateur créé avec succès.',

            'user' => $user->load([
                'direction',
                'site',
            ]),
        ];

        /*
        |--------------------------------------------------------------------------
        | MOT DE PASSE TEMPORAIRE DU USER
        |--------------------------------------------------------------------------
        */

        if ($validated['role'] === 'USER') {

            $response['temporary_password'] =
                $temporaryPassword;
        }

        return response()->json(
            $response,
            201
        );

    } catch (
        ValidationException $e
    ) {

        return response()->json([

            'success' => false,

            'message' =>
                'Les données fournies sont invalides.',

            'errors' =>
                $e->errors(),

        ], 422);

    } catch (\Throwable $e) {

        return response()->json([

            'success' => false,

            'message' =>
                'Erreur lors de la création de l\'utilisateur.',


        ], 500);
    }
}

    public function login(Request $request)
    {
        // VALIDATION
        $request->validate([
            'username' => ['required', 'string', 'max:80'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        // RATE LIMIT (REDIS - sécurité brute force)
        $key = 'login_attempts:'.$request->ip();

        if (Cache::has($key) && Cache::get($key) >= 5) {
            return response()->json([
                'message' => 'Trop de tentatives. Réessayez plus tard.',
            ], 429);
        }

        // CHECK USER
        $user = User::where('username', $request->username)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Identifiants incorrects',
            ], 401);
        }

        if (! $user->actif) {
            return response()->json([
                'message' => 'Ce compte est désactivé.',
            ], 403);
        }

        // reset login attempts
        Cache::forget($key);

        //  CACHE USER (REDIS optimisation)
        Cache::put('user:'.$user->id, $user, 3600);

        // GENERER TOKEN JWT
        $token = JWTAuth::fromUser($user);

        // KAFKA EVENT (audit sécurité)
        try {
            if (class_exists(Kafka::class)) {
                Kafka::publishOn('auth-events')
                    ->withBody([
                        'event' => 'USER_LOGIN',
                        'user_id' => $user->id,
                        'username' => $user->username,
                        'ip' => $request->ip(),
                        'timestamp' => now(),
                    ])
                    ->send();
            }
        } catch (\Exception $e) {
            // ❗ ne jamais bloquer login si Kafka échoue
        }

        // RESPONSE
        return response()->json([
            'message' => 'Connexion réussie',
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        try {

            // Récupérer le token
            $token = JWTAuth::getToken();

            if (! $token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token non fourni',
                ], 400);
            }

            // Récupérer l'utilisateur connecté
            $user = JWTAuth::authenticate($token);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur introuvable',
                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | REDIS : Suppression des données utilisateur en cache
            |--------------------------------------------------------------------------
            */
            Cache::forget('user:'.$user->id);

            /*
            |--------------------------------------------------------------------------
            | REDIS : Blacklist personnalisée du token (optionnel)
            |--------------------------------------------------------------------------
            */
            Cache::put(
                'blacklist_token:'.md5($token),
                true,
                now()->addHours(24)
            );

            /*
            |--------------------------------------------------------------------------
            | JWT : Invalidation officielle
            |--------------------------------------------------------------------------
            */
            JWTAuth::invalidate($token);

            return response()->json([
                'success' => true,
                'message' => 'Déconnexion réussie',
            ]);

        } catch (JWTException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Token invalide ou expiré',
            ], 401);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la déconnexion',
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'event' => 'required|string',
                'details' => 'nullable|array',
                'timestamp' => 'nullable|string',
                'user' => 'nullable|string',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Log enregistré',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement du log',
            ], 500);
        }
    }

    public function refreshToken(Request $request)
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | Récupérer le token actuel
            |--------------------------------------------------------------------------
            */

            $token = JWTAuth::getToken();

            if (! $token) {

                return response()->json([
                    'success' => false,
                    'message' => 'Token manquant.',
                ], 401);

            }

            /*
            |--------------------------------------------------------------------------
            | Rafraîchir le token
            |--------------------------------------------------------------------------
            */

            $newToken = JWTAuth::refresh($token);

            /*
            |--------------------------------------------------------------------------
            | Récupérer l'utilisateur connecté
            |--------------------------------------------------------------------------
            */

            $user = JWTAuth::setToken($newToken)
                ->authenticate();

            if (! $user) {

                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur introuvable.',
                ], 404);

            }

            /*
            |--------------------------------------------------------------------------
            | Mettre à jour le cache Redis
            |--------------------------------------------------------------------------
            */

            Cache::put(
                'user:'.$user->id,
                $user,
                3600
            );

            /*
            |--------------------------------------------------------------------------
            | Réponse
            |--------------------------------------------------------------------------
            */

            return response()->json([

                'success' => true,

                'message' => 'Token renouvelé avec succès.',

                'token' => $newToken,

                'user' => $user,

            ], 200);

        } catch (JWTException $e) {

            return response()->json([

                'success' => false,

                'message' => 'Impossible de renouveler le token.',

            ], 401);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur serveur lors du refresh token.',

            ], 500);

        }
    }
}
