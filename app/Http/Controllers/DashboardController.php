<?php

namespace App\Http\Controllers;

use App\Models\Acquisition;
use App\Models\Attribution;
use App\Models\Direction;
use App\Models\Materiel;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function dashboardStatistics()
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | MATERIELS
            |--------------------------------------------------------------------------
            */

            $totalMateriels = Materiel::count();

            $materielsDisponibles = Materiel::where('etat', 'disponible')->count();

            $materielsAttribues = Materiel::where('etat', 'attribue')->count();

            $materielsPanne = Materiel::where('etat', 'panne')->count();

            $materielsMaintenance = Materiel::where('etat', 'maintenance')->count();

            /*
            |--------------------------------------------------------------------------
            | NOUVEAUX / ANCIENS
            |--------------------------------------------------------------------------
            */

            $nouveauxMateriels = Materiel::doesntHave('attributions')->count();

            $anciensMateriels = Materiel::has('attributions')->count();

            /*
            |--------------------------------------------------------------------------
            | REPARTITION PAR TYPE DE MATERIEL
            |--------------------------------------------------------------------------
            */

            $typesMateriels = Materiel::select(
                'type_materiel',
                DB::raw('COUNT(*) as total')
            )
                ->groupBy('type_materiel')
                ->orderBy('type_materiel')
                ->get()
                ->map(function ($item) use ($totalMateriels) {

                    return [
                        'type_materiel' => $item->type_materiel,
                        'total' => (int) $item->total,
                        'pourcentage' => $totalMateriels > 0
                            ? round(($item->total / $totalMateriels) * 100, 2)
                            : 0,
                    ];
                });

            /*
            |--------------------------------------------------------------------------
            | ATTRIBUTIONS
            |--------------------------------------------------------------------------
            */

            $totalAttributions = Attribution::count();

            $attributionsActives = Attribution::where('statut', 'ACTIVE')->count();

            $attributionsPanne = Attribution::where('statut', 'EN_PANNE')->count();

            $attributionsMaintenance = Attribution::where('statut', 'EN_MAINTENANCE')->count();

            $attributionsTerminees = Attribution::where('statut', 'TERMINE')->count();

            $reaffectations = Attribution::where('type_action', 'REAFFECTATION')->count();

            /*
            |--------------------------------------------------------------------------
            | AUTRES
            |--------------------------------------------------------------------------
            */

            $totalUtilisateurs = User::count();

            $totalAcquisitions = Acquisition::count();

            $totalSites = Site::count();

            $totalDirections = Direction::count();

            /*
            |--------------------------------------------------------------------------
            | REPONSE
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'success' => true,
                'message' => 'Statistiques du tableau de bord récupérées avec succès.',
                'data' => [

                    'materiels' => [
                        'total' => $totalMateriels,
                        'disponibles' => $materielsDisponibles,
                        'attribues' => $materielsAttribues,
                        'panne' => $materielsPanne,
                        'maintenance' => $materielsMaintenance,
                        'nouveaux' => $nouveauxMateriels,
                        'anciens' => $anciensMateriels,
                    ],

                    'types_materiels' => $typesMateriels,

                    'attributions' => [
                        'total' => $totalAttributions,
                        'actives' => $attributionsActives,
                        'en_panne' => $attributionsPanne,
                        'en_maintenance' => $attributionsMaintenance,
                        'terminees' => $attributionsTerminees,
                        'reaffectations' => $reaffectations,
                    ],

                    'utilisateurs' => [
                        'total' => $totalUtilisateurs,
                    ],

                    'acquisitions' => [
                        'total' => $totalAcquisitions,
                    ],

                    'sites' => [
                        'total' => $totalSites,
                    ],

                    'directions' => [
                        'total' => $totalDirections,
                    ],

                ],
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques du tableau de bord.',
                'error' => $e->getMessage(),
            ], 500);

        }
    }

    public function materielsParSite()
    {
        try {

            $sites = Site::orderBy('nom')->get();

            $data = $sites->map(function ($site) {

                $materielIds = Attribution::where('site_id', $site->id)
                    ->whereIn('statut', [
                        'ACTIVE',
                        'EN_PANNE',
                        'EN_MAINTENANCE',
                    ])
                    ->pluck('materiel_id')
                    ->unique();

                return [

                    'site_id' => $site->id,

                    'site' => $site->nom,

                    'total_materiels' => $materielIds->count(),

                    'attribues' => Materiel::whereIn('id', $materielIds)
                        ->where('etat', 'attribue')
                        ->count(),

                    'en_panne' => Materiel::whereIn('id', $materielIds)
                        ->where('etat', 'panne')
                        ->count(),

                    'en_maintenance' => Materiel::whereIn('id', $materielIds)
                        ->where('etat', 'maintenance')
                        ->count(),

                    'disponibles' => Materiel::whereIn('id', $materielIds)
                        ->where('etat', 'disponible')
                        ->count(),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Situation des matériels par site récupérée avec succès.',
                'data' => $data,
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération de la situation des matériels par site.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Fonction Dernières attributions

    /**
     * Dernières attributions
     */
    public function recentAssignments()
    {
        try {

            $data = Attribution::with([
                'materiel:id,code_materiel,marque,modele',
                'beneficiaire:id,nom,prenom,login',
            ])
                ->latest()
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des attributions',
            ], 500);
        }
    }

    // Fonction Refresh Cache

    /**
     * Rafraîchir cache dashboard
     */
    public function refreshCache()
    {
        try {

            Cache::forget('dashboard:statistics');

            return response()->json([
                'success' => true,
                'message' => 'Cache dashboard vidé avec succès',
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du rafraîchissement du cache',
            ], 500);
        }
    }

    public function evolutionAttributions()
    {
        try {

            $data = Cache::remember('dashboard:evolution_attributions', now()->addMinutes(10), function () {

                $fromDate = now()->subMonths(5)->startOfMonth();

                $rawData = Attribution::selectRaw("
                    DATE_TRUNC('month', created_at) as mois,
                    COUNT(*) as total
                ")
                    ->where('created_at', '>=', $fromDate)
                    ->groupBy('mois')
                    ->orderBy('mois', 'asc')
                    ->get();

                // Normalisation sur 6 mois (même si mois vide)
                $result = [];

                for ($i = 5; $i >= 0; $i--) {

                    $month = now()->subMonths($i)->format('Y-m');

                    $found = $rawData->first(function ($item) use ($month) {
                        return date('Y-m', strtotime($item->mois)) === $month;
                    });

                    $result[] = [
                        'mois' => $month,
                        'total' => $found ? $found->total : 0,
                    ];
                }

                return $result;
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement de l’évolution des attributions',
            ], 500);
        }
    }

    public function situationMateriels(Request $request)
    {
        try {

            $query = Attribution::with([
                'materiel',
                'direction:id,nom',
                'site:id,nom',
            ])
                ->where('statut', 'ACTIVE');

            /*
            |--------------------------------------------------------------------------
            | FILTRES
            |--------------------------------------------------------------------------
            */

            if ($request->filled('direction_id')) {

                $query->where('direction_id', $request->direction_id);

            }

            if ($request->filled('site_id')) {

                $query->where('site_id', $request->site_id);

            }

            $attributions = $query->get();

            $resultats = [];

            foreach ($attributions as $attribution) {

                if (! $attribution->materiel) {

                    continue;

                }

                /*
                |--------------------------------------------------------------------------
                | Uniquement les équipements
                |--------------------------------------------------------------------------
                */

                if ($attribution->materiel->categorie != 'EQUIPEMENT') {

                    continue;

                }

                $direction = $attribution->direction?->nom ?? 'Sans direction';

                $site = $attribution->site?->nom ?? 'Sans site';

                $cle = $direction.'-'.$site;

                if (! isset($resultats[$cle])) {

                    $resultats[$cle] = [

                        'direction' => $direction,

                        'site' => $site,

                        /*
                        |--------------------------------------------------------------------------
                        | KPI
                        |--------------------------------------------------------------------------
                        */

                        'total' => 0,

                        'ondules' => 0,

                        'moins_5_ans' => 0,

                        'plus_5_ans' => 0,

                        'disponibles' => 0,

                        'indisponibles' => 0,

                    ];

                }

                $materiel = $attribution->materiel;

                /*
                |--------------------------------------------------------------------------
                | TOTAL
                |--------------------------------------------------------------------------
                */

                $resultats[$cle]['total']++;

                /*
                |--------------------------------------------------------------------------
                | ONDULE
                |--------------------------------------------------------------------------
                */

                if ($materiel->onduleur) {

                    $resultats[$cle]['ondules']++;

                }

                /*
                |--------------------------------------------------------------------------
                | DISPONIBILITE
                |--------------------------------------------------------------------------
                */

                if ($materiel->etat == 'disponible') {

                    $resultats[$cle]['disponibles']++;

                } else {

                    $resultats[$cle]['indisponibles']++;

                }

                /*
                |--------------------------------------------------------------------------
                | AGE
                |--------------------------------------------------------------------------
                */

                if (! empty($materiel->date_mise_service)) {

                    $age = Carbon::parse(
                        $materiel->date_mise_service
                    )->diffInYears(now());

                    if ($age < 5) {

                        $resultats[$cle]['moins_5_ans']++;

                    } else {

                        $resultats[$cle]['plus_5_ans']++;

                    }

                }

            }
            /*
        |--------------------------------------------------------------------------
        | Calcul des KPI
        |--------------------------------------------------------------------------
        */

            foreach ($resultats as &$item) {

                $total = $item['total'];

                /*
                |--------------------------------------------------------------------------
                | Taux de disponibilité
                |--------------------------------------------------------------------------
                */

                $item['taux_disponibilite'] = $total > 0
                    ? round(($item['disponibles'] / $total) * 100, 2)
                    : 0;

                /*
                |--------------------------------------------------------------------------
                | Taux de vétusté
                |--------------------------------------------------------------------------
                */

                $item['taux_vetuste'] = $total > 0
                    ? round(($item['plus_5_ans'] / $total) * 100, 2)
                    : 0;

                /*
                |--------------------------------------------------------------------------
                | Objectif & Seuil critique
                |--------------------------------------------------------------------------
                */

                $item['objectif'] = 95;

                $item['seuil_critique'] = 80;

                /*
                |--------------------------------------------------------------------------
                | Statut disponibilité
                |--------------------------------------------------------------------------
                */

                if ($item['taux_disponibilite'] >= $item['objectif']) {

                    $item['statut_disponibilite'] = 'BON';

                } elseif ($item['taux_disponibilite'] >= $item['seuil_critique']) {

                    $item['statut_disponibilite'] = 'ATTENTION';

                } else {

                    $item['statut_disponibilite'] = 'CRITIQUE';

                }

                /*
                |--------------------------------------------------------------------------
                | Statut vétusté
                |--------------------------------------------------------------------------
                */

                if ($item['taux_vetuste'] >= 50) {

                    $item['statut_vetuste'] = 'CRITIQUE';

                } elseif ($item['taux_vetuste'] >= 30) {

                    $item['statut_vetuste'] = 'ATTENTION';

                } else {

                    $item['statut_vetuste'] = 'BON';

                }

            }

            unset($item);

            /*
            |--------------------------------------------------------------------------
            | Totaux globaux
            |--------------------------------------------------------------------------
            */

            $resume = [

                'total' => collect($resultats)->sum('total'),

                'ondules' => collect($resultats)->sum('ondules'),

                'moins_5_ans' => collect($resultats)->sum('moins_5_ans'),

                'plus_5_ans' => collect($resultats)->sum('plus_5_ans'),

                'disponibles' => collect($resultats)->sum('disponibles'),

                'indisponibles' => collect($resultats)->sum('indisponibles'),

            ];

            $resume['taux_disponibilite'] = $resume['total'] > 0
                ? round(($resume['disponibles'] / $resume['total']) * 100, 2)
                : 0;

            $resume['taux_vetuste'] = $resume['total'] > 0
                ? round(($resume['plus_5_ans'] / $resume['total']) * 100, 2)
                : 0;

            $resume['objectif'] = 95;

            $resume['seuil_critique'] = 80;

            /*
            |--------------------------------------------------------------------------
            | Réponse
            |--------------------------------------------------------------------------
            */

            return response()->json([

                'success' => true,

                'message' => 'Situation des équipements récupérée avec succès.',

                'data' => [

                    'resume' => $resume,

                    'par_direction_site' => array_values($resultats),

                ],

            ]);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors du calcul de la situation.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }
}
