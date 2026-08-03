<?php

namespace App\Http\Controllers;

use App\Models\Beneficiaire;
use App\Models\Direction;
use App\Models\Site;
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

    /**
     * Récupérer les statistiques des bénéficiaires - Version simplifiée
     */
    public function statistics()
    {
        try {
            // Statistiques de base uniquement
            $total = Beneficiaire::count();

            // Compter les bénéficiaires avec attribution active
            $avecMateriel = DB::table('beneficiaires')
                ->join('attributions', 'beneficiaires.id', '=', 'attributions.beneficiaire_id')
                ->where('attributions.statut', 'ACTIVE')
                ->distinct('beneficiaires.id')
                ->count('beneficiaires.id');

            // Bénéficiaires sans matériel
            $sansMateriel = $total - $avecMateriel;

            // Taux d'équipement
            $tauxEquipement = $total > 0 ? round(($avecMateriel / $total) * 100, 1) : 0;

            // Répartition par direction
            $parDirection = DB::table('beneficiaires')
                ->select('direction_libelle', DB::raw('count(*) as total'))
                ->whereNotNull('direction_libelle')
                ->where('direction_libelle', '!=', '')
                ->groupBy('direction_libelle')
                ->orderBy('total', 'desc')
                ->get()
                ->map(function ($item) use ($total) {
                    return [
                        'direction' => $item->direction_libelle,
                        'total' => $item->total,
                        'pourcentage' => $total > 0 ? round(($item->total / $total) * 100, 1) : 0
                    ];
                });

            // Top 5 directions
            $topDirections = $parDirection->take(5)->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'avec_materiel' => $avecMateriel,
                    'sans_materiel' => $sansMateriel,
                    'taux_equipement' => $tauxEquipement,
                    'par_direction' => $parDirection,
                    'top_directions' => $topDirections,
                    'par_site' => [],
                    'par_fonction' => [],
                    'nouveaux_par_mois' => []
                ]
            ]);

        } catch (\Exception $e) {
            // Log::error('Erreur statistics: ' . $e->getMessage());
            // Log::error('Ligne: ' . $e->getLine());
            // Log::error('Fichier: ' . $e->getFile());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


}
