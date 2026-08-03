<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use App\Models\Materiel;
use App\Models\Attribution;
use App\Models\Restitution;

class RestitutionController extends Controller
{
    public function retournerMateriel(Request $request)
{
    $validator = Validator::make($request->all(), [
        'materiel_id' => 'required|integer|exists:materiels,id',
        'etat_retour' => 'required|in:disponible,panne,maintenance,reforme',
        'observations' => 'nullable|string|max:1000'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => $validator->errors()->first()
        ], 422);
    }

    $materielId = (int)$request->materiel_id;

    $lock = Cache::lock(
        'restitution_materiel_'.$materielId,
        10
    );

    try {

        if (!$lock->get()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce matériel est déjà en cours de traitement'
            ], 409);
        }

        DB::beginTransaction();

        /*
        |--------------------------------------------------------------------------
        | Vérifier attribution active
        |--------------------------------------------------------------------------
        */

        $attribution = Attribution::where('materiel_id', $materielId)
            ->where('statut', 'ACTIVE')
            ->latest()
            ->first();

        if (!$attribution) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Aucune attribution active trouvée'
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Matériel
        |--------------------------------------------------------------------------
        */

        $materiel = Materiel::find($materielId);

        /*
        |--------------------------------------------------------------------------
        | Créer restitution
        |--------------------------------------------------------------------------
        */

        $restitution = Restitution::create([
            'date_restitution' => now(),
            'etat_retour' => $request->etat_retour,
            'observations' => $request->observations,
            'materiel_id' => $attribution->materiel_id,
            'beneficiaire_id' => $attribution->beneficiaire_id,
            'users_id' => auth()->id()
        ]);

        /*
        |--------------------------------------------------------------------------
        | Fermer attribution
        |--------------------------------------------------------------------------
        */

        $attribution->update([
            'statut' => 'TERMINEE'
        ]);

        /*
        |--------------------------------------------------------------------------
        | Mise à jour état matériel
        |--------------------------------------------------------------------------
        */

        $materiel->update([
            'etat' => $request->etat_retour
        ]);

        /*
        |--------------------------------------------------------------------------
        | Nettoyage cache
        |--------------------------------------------------------------------------
        */

        Cache::forget('dashboard_stats');
        Cache::forget('materiel_'.$materiel->id);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Matériel restitué avec succès',
            'data' => $restitution
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la restitution'
        ], 500);

    } finally {

        optional($lock)->release();
    }
}
}
