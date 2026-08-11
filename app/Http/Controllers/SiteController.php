<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SiteController extends Controller
{
    public function storeSite(Request $request)
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | VALIDATION
            |--------------------------------------------------------------------------
            */

            $validated = $request->validate([

                'nom' => [
                    'required',
                    'string',
                    'max:150',
                    'unique:sites,nom',
                ],

                'adresse' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

            ]);

            /*
            |--------------------------------------------------------------------------
            | CREATION
            |--------------------------------------------------------------------------
            */

            $site = Site::create([

                'nom' => $validated['nom'],

                'adresse' => $validated['adresse'] ?? null,

            ]);

            /*
            |--------------------------------------------------------------------------
            | REPONSE
            |--------------------------------------------------------------------------
            */

            return response()->json([

                'success' => true,

                'message' => 'Site ajouté avec succès.',

                'site' => $site,

            ], 201);

        } catch (
            ValidationException $e
        ) {

            return response()->json([

                'success' => false,

                'message' => 'Les données fournies sont invalides.',

                'errors' => $e->errors(),

            ], 422);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de l\'ajout du site.',

            ], 500);
        }
    }

    public function updateSite(Request $request, $id)
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | VERIFICATION ID
            |--------------------------------------------------------------------------
            */

            if (! is_numeric($id)) {

                return response()->json([

                    'success' => false,

                    'message' => 'Identifiant invalide.',

                ], 400);
            }

            /*
            |--------------------------------------------------------------------------
            | RECHERCHE SITE
            |--------------------------------------------------------------------------
            */

            $site = Site::find($id);

            if (! $site) {

                return response()->json([

                    'success' => false,

                    'message' => 'Site introuvable.',

                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | VALIDATION
            |--------------------------------------------------------------------------
            */

            $validated = $request->validate([

                'nom' => [
                    'required',
                    'string',
                    'max:150',

                    Rule::unique(
                        'sites',
                        'nom'
                    )->ignore($site->id),
                ],

                'adresse' => [
                    'nullable',
                    'string',
                    'max:255',
            ],

            ]);

            /*
            |--------------------------------------------------------------------------
            | MODIFICATION
            |--------------------------------------------------------------------------
            */

            $site->update([

                'nom' => $validated['nom'],

                'adresse' => $validated['adresse'] ?? null,

            ]);

            /*
            |--------------------------------------------------------------------------
            | REPONSE
            |--------------------------------------------------------------------------
            */

            return response()->json([

                'success' => true,

                'message' => 'Site modifié avec succès.',

                'site' => $site->fresh(),

            ], 200);

        } catch (
            ValidationException $e
        ) {

            return response()->json([

                'success' => false,

                'message' => 'Les données fournies sont invalides.',

                'errors' => $e->errors(),

            ], 422);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de la modification du site.',

            ], 500);
        }
    }

    public function deleteSite($id)
{
    try {

        /*
        |--------------------------------------------------------------------------
        | VERIFICATION ID
        |--------------------------------------------------------------------------
        */

        if (! is_numeric($id)) {

            return response()->json([
                'success' => false,
                'message' => 'Identifiant invalide.',
            ], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | RECHERCHE DU SITE
        |--------------------------------------------------------------------------
        */

        $site = Site::find($id);

        if (! $site) {

            return response()->json([
                'success' => false,
                'message' => 'Site introuvable.',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | VERIFICATION DES UTILISATEURS
        |--------------------------------------------------------------------------
        |
        | Si au moins un utilisateur appartient au site,
        | la suppression est refusée.
        |
        |--------------------------------------------------------------------------
        */

        if ($site->users()->exists()) {

            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer ce site car des utilisateurs y sont rattachés.',
            ], 409);
        }

        /*
        |--------------------------------------------------------------------------
        | SUPPRESSION
        |--------------------------------------------------------------------------
        */

        $site->delete();

        /*
        |--------------------------------------------------------------------------
        | REPONSE
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'success' => true,
            'message' => 'Site supprimé avec succès.',
        ], 200);

    } catch (\Throwable $e) {

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la suppression du site.',
        ], 500);
    }
}

public function statistiques()
{
    try {

        /*
        |--------------------------------------------------------------------------
        | NOMBRE TOTAL DE SITES
        |--------------------------------------------------------------------------
        */

        $totalSites = Site::count();

        /*
        |--------------------------------------------------------------------------
        | NOMBRE TOTAL D'UTILISATEURS RATTACHÉS À UN SITE
        |--------------------------------------------------------------------------
        */

        $totalUtilisateurs = User::whereNotNull('site_id')->count();

        /*
        |--------------------------------------------------------------------------
        | SITES AVEC UTILISATEURS
        |--------------------------------------------------------------------------
        */

        $sitesAvecUtilisateurs = Site::whereHas('users')->count();

        /*
        |--------------------------------------------------------------------------
        | SITES SANS UTILISATEURS
        |--------------------------------------------------------------------------
        */

        $sitesSansUtilisateurs = Site::whereDoesntHave('users')->count();

        /*
        |--------------------------------------------------------------------------
        | NOMBRE D'UTILISATEURS PAR SITE
        |--------------------------------------------------------------------------
        */

        $utilisateursParSite = Site::withCount('users')
            ->orderByDesc('users_count')
            ->get()
            ->map(function ($site) {

                return [

                    'site_id' => $site->id,

                    'nom' => $site->nom,

                    'adresse' => $site->adresse,

                    'nombre_utilisateurs' => $site->users_count,

                ];

            });

        /*
        |--------------------------------------------------------------------------
        | REPONSE
        |--------------------------------------------------------------------------
        */

        return response()->json([

            'success' => true,

            'message' => 'Statistiques récupérées avec succès.',

            'statistiques' => [

                'total_sites' => $totalSites,

                'total_utilisateurs' => $totalUtilisateurs,

                'sites_avec_utilisateurs' => $sitesAvecUtilisateurs,

                'sites_sans_utilisateurs' => $sitesSansUtilisateurs,

                'utilisateurs_par_site' => $utilisateursParSite,

            ],

        ], 200);

    } catch (\Throwable $e) {

        return response()->json([

            'success' => false,

            'message' => 'Erreur lors de la récupération des statistiques.',

        ], 500);
    }
}

}
