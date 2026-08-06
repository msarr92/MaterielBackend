<?php

namespace App\Http\Controllers;

use App\Models\Beneficiaire;
use App\Models\Direction;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;

class BeneficiaireController extends Controller
{


    public function getBeneficiairesSelect(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'search' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);
            }

            $search = trim($request->input('search', ''));

            $query = Beneficiaire::query()
                ->select([
                    'id',
                    'matricule',
                    'nom',
                    'prenom',
                    'direction_libelle',
                    'site_libelle',
                ]);

            if (! empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('matricule', 'like', "%{$search}%")
                        ->orWhere('nom', 'like', "%{$search}%")
                        ->orWhere('prenom', 'like', "%{$search}%");
                });
            }

            $beneficiaires = $query
                ->orderBy('nom')
                ->orderBy('prenom')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $beneficiaires,
                'total' => $beneficiaires->count(),
            ]);

        } catch (\Exception $e) {
            \Log::error('Erreur getBeneficiairesSelect: '.$e->getMessage());
            \Log::error('Ligne: '.$e->getLine());
            \Log::error('Fichier: '.$e->getFile());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des bénéficiaires: '.$e->getMessage(),
            ], 500);
        }
    }

    



}
