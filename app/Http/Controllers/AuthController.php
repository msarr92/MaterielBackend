<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
        | VALIDATION
        |--------------------------------------------------------------------------
        */

        $request->validate([

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
                'exists:directions,id',
            ],

            'site_id' => [
                'required_if:role,USER',
                'nullable',
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

        $lastUser = User::latest('id')->first();

        $nextId = $lastUser ? $lastUser->id + 1 : 1;

        $matricule = 'ONAS-' . $year . '-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);

        /*
        |--------------------------------------------------------------------------
        | PASSWORD
        |--------------------------------------------------------------------------
        | USER -> mot de passe aléatoire
        | ADMIN/GESTIONNAIRE -> mot de passe fourni
        |--------------------------------------------------------------------------
        */

        if ($request->role === 'USER') {

            $password = bcrypt(Str::random(20));

        } else {

            $password = bcrypt($request->password);

        }

        /*
        |--------------------------------------------------------------------------
        | CREATION UTILISATEUR
        |--------------------------------------------------------------------------
        */

        $user = User::create([

            'matricule' => $matricule,

            'username' => $request->username,

            'nom' => $request->nom,

            'prenom' => $request->prenom,

            'role' => $request->role,

            'password' => $password,

            'direction_id' => $request->direction_id,

            'site_id' => $request->site_id,

            'actif' => true,

        ]);

        /*
        |--------------------------------------------------------------------------
        | CACHE
        |--------------------------------------------------------------------------
        */

        Cache::put(
            'user_' . $user->id,
            $user,
            now()->addHour()
        );

        /*
        |--------------------------------------------------------------------------
        | KAFKA (OPTIONNEL)
        |--------------------------------------------------------------------------
        */

        try {

            if (class_exists(Kafka::class)) {

                Kafka::publishOn('auth-events')
                    ->withBody([

                        'event' => 'USER_REGISTERED',

                        'user_id' => $user->id,

                        'matricule' => $user->matricule,

                        'role' => $user->role,

                        'timestamp' => now(),

                    ])
                    ->send();

            }

        } catch (\Throwable $e) {

            // Ne bloque pas la création

        }

        /*
        |--------------------------------------------------------------------------
        | JWT UNIQUEMENT ADMIN/GESTIONNAIRE
        |--------------------------------------------------------------------------
        */



        /*
        |--------------------------------------------------------------------------
        | REPONSE
        |--------------------------------------------------------------------------
        */

        return response()->json([

            'success' => true,

            'message' => 'Utilisateur créé avec succès.',

            'user' => $user->load(['direction', 'site']),

            //'token' => $token,

        ], 201);

    } catch (\Throwable $e) {

        return response()->json([

            'success' => false,

            'message' => 'Erreur lors de la création de l\'utilisateur.',

            'error' => $e->getMessage(),

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

            // incrémenter tentatives dans Redis
            Cache::increment($key);
            Cache::put($key, Cache::get($key, 0), now()->addMinutes(10));

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
                'user' => 'nullable|string'
            ]);


            return response()->json([
                'success' => true,
                'message' => 'Log enregistré'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement du log'
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


        if (!$token) {

            return response()->json([
                'success' => false,
                'message' => 'Token manquant.'
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



        if (!$user) {

            return response()->json([
                'success'=>false,
                'message'=>'Utilisateur introuvable.'
            ],404);

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

            'success'=>true,

            'message'=>'Token renouvelé avec succès.',

            'token'=>$newToken,

            'user'=>$user,

        ],200);



    } catch (JWTException $e) {


        return response()->json([

            'success'=>false,

            'message'=>'Impossible de renouveler le token.',


        ],401);


    } catch(\Throwable $e){


        return response()->json([

            'success'=>false,

            'message'=>'Erreur serveur lors du refresh token.',


        ],500);

    }
}

}
