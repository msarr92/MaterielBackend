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

            $today = Carbon::now();

            /*
            |--------------------------------------------------------------------------
            | Récupération des équipements attribués
            |--------------------------------------------------------------------------
            */

            $materiels = Materiel::with([

                'attributions' => function ($query) {

                    $query->where('statut', 'ACTIVE')
                        ->with([
                            'direction:id,nom',
                            'site:id,nom',
                        ]);

                },

            ])
                ->where('categorie', 'EQUIPEMENT')
                ->get();

            $statistiques = [];

            foreach ($materiels as $materiel) {

                /*
                |--------------------------------------------------------------------------
                | Dernière attribution active
                |--------------------------------------------------------------------------
                */

                $attribution = $materiel->attributions->first();

                /*
                | Si le matériel n'a pas encore été attribué
                | on le met dans STOCK CENTRAL
                */

                if (! $attribution) {

                    $direction = 'STOCK CENTRAL';

                    $site = 'STOCK CENTRAL';

                } else {

                    $direction = $attribution->direction?->nom
                        ?? 'Sans direction';

                    $site = $attribution->site?->nom
                        ?? 'Sans site';

                }

                $cle = $direction.'_'.$site;

                if (! isset($statistiques[$cle])) {

                    $statistiques[$cle] = [

                        'direction' => $direction,

                        'site' => $site,

                        'nombre_equipements' => 0,

                        'nombre_equipements_ondules' => 0,

                        'equipements_disponibles' => 0,

                        'equipements_moins_5_ans' => 0,

                        'equipements_plus_5_ans' => 0,

                    ];

                }

                /*
                |--------------------------------------------------------------------------
                | Nombre total équipements
                |--------------------------------------------------------------------------
                */

                $statistiques[$cle]['nombre_equipements']++;

                /*
                |--------------------------------------------------------------------------
                | Equipements ondulés
                |--------------------------------------------------------------------------
                */

                if ($materiel->onduleur == true) {

                    $statistiques[$cle]['nombre_equipements_ondules']++;

                }

                /*
                |--------------------------------------------------------------------------
                | Disponibilité
                |--------------------------------------------------------------------------
                */

                if ($materiel->etat == 'disponible') {

                    $statistiques[$cle]['equipements_disponibles']++;

                }

                /*
                |--------------------------------------------------------------------------
                | Calcul âge matériel
                |--------------------------------------------------------------------------
                */

                if ($materiel->date_mise_service) {

                    $age = Carbon::parse(
                        $materiel->date_mise_service
                    )
                        ->diffInYears($today);

                    if ($age < 5) {

                        $statistiques[$cle]['equipements_moins_5_ans']++;

                    } else {

                        $statistiques[$cle]['equipements_plus_5_ans']++;

                    }

                }

            }

            /*
            |--------------------------------------------------------------------------
            | Calcul des taux
            |--------------------------------------------------------------------------
            */

            foreach ($statistiques as &$item) {

                $total = $item['nombre_equipements'];

                /*
                | Taux disponibilité
                */

                $item['taux_disponibilite'] = $total > 0

                    ? round(
                        ($item['equipements_disponibles'] / $total) * 100,
                        2
                    )

                    : 0;

                /*
                | Taux vétusté
                */

                $item['taux_vetuste'] = $total > 0

                    ? round(
                        ($item['equipements_plus_5_ans'] / $total) * 100,
                        2
                    )

                    : 0;

                /*
                |--------------------------------------------------------------------------
                | Objectifs KPI
                |--------------------------------------------------------------------------
                */

                $item['objectif'] = 95;

                $item['seuil_critique'] = 80;

                /*
                | Alerte
                */

                $item['statut_disponibilite'] =

                    $item['taux_disponibilite'] >= $item['objectif']

                        ? 'NORMAL'

                        : (

                            $item['taux_disponibilite'] < $item['seuil_critique']

                            ? 'CRITIQUE'

                            : 'A_SURVEILLER'

                        );

                $item['statut_vetuste'] =

                    $item['taux_vetuste'] > 50

                        ? 'CRITIQUE'

                        : 'NORMAL';

            }

            return response()->json([

                'success' => true,

                'message' => 'Situation des équipements récupérée avec succès.',

                'data' => array_values($statistiques),

            ]);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors du calcul de la situation des équipements.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }
}
