<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\StreamedResponse;
use Illuminate\Support\Facades\Validator;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Materiel;
use App\Models\MouvementMateriel;

class MouvementMaterielController extends Controller
{
   public function historiqueMateriel($materielId)
{
    try {

        $materiel = Materiel::with([
            'acquisition',
            'attributions.user.site:id,nom',
            'attributions.user.direction:id,nom',
            'attributions.user:id,nom,prenom,site_id,direction_id',
            'attributions.direction:id,nom',
            'attributions.site:id,nom',
        ])->find($materielId);

        if (!$materiel) {

            return response()->json([
                'success' => false,
                'message' => 'Matériel introuvable.'
            ], 404);

        }

        /*
        |--------------------------------------------------------------------------
        | Historique des mouvements
        |--------------------------------------------------------------------------
        */

        $mouvements = MouvementMateriel::with([

            'user.site:id,nom',
            'user.direction:id,nom',
            'user:id,nom,prenom,site_id,direction_id',
            'creator:id,nom,prenom',

        ])
        ->where('materiel_id', $materielId)
        ->orderBy('date_mouvement')
        ->get();

        $historique = [];

        /*
        |--------------------------------------------------------------------------
        | Acquisition
        |--------------------------------------------------------------------------
        */

        if ($materiel->acquisition) {

            $historique[] = [

                'date' => $materiel->acquisition->date_acquisition,

                'type' => 'ACQUISITION',

                'titre' => 'Acquisition du matériel',

                'description' =>
                    $materiel->acquisition->type_acquisition .
                    ' - ' .
                    $materiel->acquisition->numero_reference,

                'etat' => 'disponible',

                'utilisateur' => null,

                'site' => null,

                'direction' => null,

                'created_by' => null,

            ];

        }

        /*
        |--------------------------------------------------------------------------
        | Historique des mouvements
        |--------------------------------------------------------------------------
        */

        foreach ($mouvements as $mouvement) {

            $historique[] = [

                'date' => $mouvement->date_mouvement,

                'type' => $mouvement->type_mouvement,

                'titre' => match ($mouvement->type_mouvement) {

                    'ATTRIBUTION' => 'Attribution',

                    'REAFFECTATION' => 'Réaffectation',

                    'RETOUR' => 'Retour',

                    'PANNE' => 'Déclaration de panne',

                    'MAINTENANCE' => 'Maintenance',

                    'RETOUR_STOCK' => 'Retour au stock',

                    'REFORME' => 'Réforme',

                    'MODIFICATION_ETAT' => 'Modification d\'état',

                    default => $mouvement->type_mouvement,

                },

                'description' => $mouvement->observation,

                'etat' => $mouvement->etat_materiel,

                /*
                |--------------------------------------------------------------------------
                | Utilisateur
                |--------------------------------------------------------------------------
                */

                'utilisateur' => $mouvement->user
                    ? [
                        'id' => $mouvement->user->id,
                        'nom' => $mouvement->user->nom,
                        'prenom' => $mouvement->user->prenom,
                    ]
                    : null,

                /*
                |--------------------------------------------------------------------------
                | Site
                |--------------------------------------------------------------------------
                */

                'site' => $mouvement->user?->site
                    ? [
                        'id' => $mouvement->user->site->id,
                        'nom' => $mouvement->user->site->nom,
                    ]
                    : null,

                /*
                |--------------------------------------------------------------------------
                | Direction
                |--------------------------------------------------------------------------
                */

                'direction' => $mouvement->user?->direction
                    ? [
                        'id' => $mouvement->user->direction->id,
                        'nom' => $mouvement->user->direction->nom,
                    ]
                    : null,

                /*
                |--------------------------------------------------------------------------
                | Créateur du mouvement
                |--------------------------------------------------------------------------
                */

                'created_by' => $mouvement->creator
                    ? [
                        'id' => $mouvement->creator->id,
                        'nom' => $mouvement->creator->nom,
                        'prenom' => $mouvement->creator->prenom,
                    ]
                    : null,

            ];

        }

        /*
        |--------------------------------------------------------------------------
        | Tri chronologique
        |--------------------------------------------------------------------------
        */

        usort($historique, function ($a, $b) {

            return strtotime($a['date']) <=> strtotime($b['date']);

        });

        return response()->json([

            'success' => true,

            'message' => 'Historique récupéré avec succès.',

            'data' => [

                'materiel' => [

                    'id' => $materiel->id,

                    'code_materiel' => $materiel->code_materiel,

                    'categorie' => $materiel->categorie,

                    'numero_serie' => $materiel->numero_serie,

                    'marque' => $materiel->marque,

                    'modele' => $materiel->modele,

                    'type_materiel' => $materiel->type_materiel,

                    'etat' => $materiel->etat,

                ],

                'historique' => $historique,

            ]

        ]);

    } catch (\Throwable $e) {

        return response()->json([

            'success' => false,

            'message' => 'Erreur lors du chargement de l\'historique.',

            'error' => $e->getMessage(),

        ], 500);

    }
}

public function historiqueMouvements(Request $request)
{
    try {

        $validator = Validator::make($request->all(), [

            'materiel_id' => 'nullable|exists:materiels,id',

            'user_id' => 'nullable|exists:users,id',

            'type_mouvement' => 'nullable|in:ATTRIBUTION,REAFFECTATION,RETOUR,PANNE,MAINTENANCE,RETOUR_STOCK,REFORME,MODIFICATION_ETAT',

            'date_debut' => 'nullable|date',

            'date_fin' => 'nullable|date|after_or_equal:date_debut',

            'search' => 'nullable|string|max:100',

            'per_page' => 'nullable|integer|min:1|max:100',

        ]);


        if ($validator->fails()) {

            return response()->json([
                'success'=>false,
                'message'=>$validator->errors()->first()
            ],422);

        }


        $perPage = $request->per_page ?? 10;


        $query = MouvementMateriel::with([


            /*
            |--------------------------------------------------------------------------
            | MATERIEL
            |--------------------------------------------------------------------------
            */

            'materiel:id,code_materiel,categorie,type_materiel,numero_serie,marque,modele,acquisition_id',


            /*
            |--------------------------------------------------------------------------
            | UTILISATEUR CREATEUR DU MOUVEMENT
            |--------------------------------------------------------------------------
            */

            'creator:id,nom,prenom',



            /*
            |--------------------------------------------------------------------------
            | BENEFICIAIRE VIA ATTRIBUTION
            |--------------------------------------------------------------------------
            */

            'materiel.attributions' => function($q){

                $q->where('statut','ACTIVE')
                  ->with([

                    'user:id,nom,prenom,site_id,direction_id',

                    'user.site:id,nom',

                    'user.direction:id,nom',

                  ]);

            },


        ])
        ->orderByDesc('date_mouvement');




        /*
        |--------------------------------------------------------------------------
        | FILTRES
        |--------------------------------------------------------------------------
        */


        if($request->filled('materiel_id')){

            $query->where(
                'materiel_id',
                $request->materiel_id
            );

        }



        if($request->filled('type_mouvement')){

            $query->where(
                'type_mouvement',
                $request->type_mouvement
            );

        }



        if($request->filled('date_debut')){

            $query->whereDate(
                'date_mouvement',
                '>=',
                $request->date_debut
            );

        }



        if($request->filled('date_fin')){

            $query->whereDate(
                'date_mouvement',
                '<=',
                $request->date_fin
            );

        }



        if($request->filled('search')){


            $search = trim($request->search);


            $query->whereHas('materiel',function($q) use($search){

                $q->where('code_materiel','ILIKE',"%{$search}%")
                  ->orWhere('numero_serie','ILIKE',"%{$search}%")
                  ->orWhere('marque','ILIKE',"%{$search}%")
                  ->orWhere('modele','ILIKE',"%{$search}%");

            });


        }




        $mouvements = $query->paginate($perPage);




        $data = collect($mouvements->items())
        ->map(function($item){


            /*
            |--------------------------------------------------------------------------
            | BENEFICIAIRE ACTUEL
            |--------------------------------------------------------------------------
            */


            $attribution = $item->materiel?->attributions->first();

            //  Récupérer l'utilisateur depuis la relation 'user' de l'attribution
            $user = $attribution?->user;



            return [


                'id'=>$item->id,


                'date_mouvement'=>$item->date_mouvement,


                'type_mouvement'=>$item->type_mouvement,


                'etat_materiel'=>$item->etat_materiel,


                'quantite'=>$item->quantite,


                'observation'=>$item->observation,



                /*
                |--------------------------------------------------------------------------
                | MATERIEL
                |--------------------------------------------------------------------------
                */

                'materiel'=>[

                    'id'=>$item->materiel?->id,

                    'code_materiel'=>$item->materiel?->code_materiel,

                    'categorie'=>$item->materiel?->categorie,

                    'type_materiel'=>$item->materiel?->type_materiel,

                    'numero_serie'=>$item->materiel?->numero_serie,

                    'marque'=>$item->materiel?->marque,

                    'modele'=>$item->materiel?->modele,

                ],




                /*
                |--------------------------------------------------------------------------
                | UTILISATEUR DU MATERIEL
                |--------------------------------------------------------------------------
                */


                'utilisateur' => $user ? [
                    'id' => $user->id,
                    'nom' => $user->nom,
                    'prenom' => $user->prenom,
                    'site' => $user->site?->nom,
                    'direction' => $user->direction?->nom,
                ] : null,






                /*
                |--------------------------------------------------------------------------
                | CREATEUR DU MOUVEMENT
                |--------------------------------------------------------------------------
                */


                'effectue_par'=>$item->creator

                    ? [

                        'id'=>$item->creator->id,

                        'nom'=>$item->creator->nom,

                        'prenom'=>$item->creator->prenom,

                    ]

                    : null,



                'created_at'=>$item->created_at,

            ];


        });



        return response()->json([

            'success'=>true,

            'message'=>'Historique des mouvements récupéré avec succès.',

            'data'=>$data,


            'pagination'=>[

                'current_page'=>$mouvements->currentPage(),

                'last_page'=>$mouvements->lastPage(),

                'per_page'=>$mouvements->perPage(),

                'total'=>$mouvements->total(),

            ]

        ]);



    } catch(\Throwable $e){


        return response()->json([

            'success'=>false,

            'message'=>'Erreur lors du chargement de l’historique.',

            'error'=>$e->getMessage(),

        ],500);


    }
}

 /**
     * Exporter l'historique en CSV
     */
    public function exportHistoriqueCSV(Request $request)
    {
        try {
            $query = MouvementMateriel::with([
                'materiel',
                'user.site',
                'user.direction',
                'creator',
            ]);

            // Filtres
            if ($request->filled('materiel_id')) {
                $query->where('materiel_id', $request->materiel_id);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->filled('type_mouvement')) {
                $query->where('type_mouvement', $request->type_mouvement);
            }

            if ($request->filled('date_debut')) {
                $query->whereDate('date_mouvement', '>=', $request->date_debut);
            }

            if ($request->filled('date_fin')) {
                $query->whereDate('date_mouvement', '<=', $request->date_fin);
            }

            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->whereHas('materiel', function ($q) use ($search) {
                    $q->where('code_materiel', 'LIKE', "%{$search}%")
                        ->orWhere('numero_serie', 'LIKE', "%{$search}%")
                        ->orWhere('marque', 'LIKE', "%{$search}%")
                        ->orWhere('modele', 'LIKE', "%{$search}%");
                });
            }

            $mouvements = $query->orderByDesc('date_mouvement')->get();

            $headers = [
                "Content-type" => "text/csv; charset=UTF-8",
                "Content-Disposition" => "attachment; filename=historique_mouvements_" . date('Y-m-d') . ".csv",
                "Pragma" => "no-cache",
                "Cache-Control" => "must-revalidate",
            ];

            return response()->stream(function () use ($mouvements) {
                $handle = fopen('php://output', 'w');
                fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

                fputcsv($handle, [
                    'Date',
                    'Type',
                    'Code matériel',
                    'Catégorie',
                    'Type matériel',
                    'N° Série',
                    'Utilisateur',
                    'Site',
                    'Direction',
                    'État',
                    'Quantité',
                    'Observation',
                    'Effectué par',
                ]);

                foreach ($mouvements as $mouvement) {
                    $user = $mouvement->user;
                    $materiel = $mouvement->materiel;

                    fputcsv($handle, [
                        $mouvement->date_mouvement,
                        $mouvement->type_mouvement,
                        $materiel?->code_materiel ?? '',
                        $materiel?->categorie ?? '',
                        $materiel?->type_materiel ?? '',
                        $materiel?->numero_serie ?? '',
                        $user ? $user->nom . ' ' . $user->prenom : '',
                        $user?->site?->nom ?? '',
                        $user?->direction?->nom ?? '',
                        $mouvement->etat_materiel,
                        $mouvement->quantite ?? 1,
                        $mouvement->observation,
                        $mouvement->creator ? $mouvement->creator->nom . ' ' . $mouvement->creator->prenom : '',
                    ]);
                }

                fclose($handle);
            }, 200, $headers);

        } catch (\Throwable $e) {
            \Log::error('❌ Export CSV erreur: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'export CSV.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Exporter l'historique en PDF
     */
    public function exportHistoriquePDF(Request $request)
    {
        try {
            $query = MouvementMateriel::with([
                'materiel',
                'user.site',
                'user.direction',
                'creator',
            ]);

            // Filtres
            if ($request->filled('materiel_id')) {
                $query->where('materiel_id', $request->materiel_id);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->filled('type_mouvement')) {
                $query->where('type_mouvement', $request->type_mouvement);
            }

            if ($request->filled('date_debut')) {
                $query->whereDate('date_mouvement', '>=', $request->date_debut);
            }

            if ($request->filled('date_fin')) {
                $query->whereDate('date_mouvement', '<=', $request->date_fin);
            }

            if ($request->filled('search')) {
                $search = trim($request->search);
                $query->whereHas('materiel', function ($q) use ($search) {
                    $q->where('code_materiel', 'LIKE', "%{$search}%")
                        ->orWhere('numero_serie', 'LIKE', "%{$search}%")
                        ->orWhere('marque', 'LIKE', "%{$search}%")
                        ->orWhere('modele', 'LIKE', "%{$search}%");
                });
            }

            $mouvements = $query->orderByDesc('date_mouvement')->get();

            // 🔥 Générer le PDF directement sans vue
            $html = $this->generatePDFHTML($mouvements);

            $pdf = Pdf::loadHTML($html);
            $pdf->setPaper('A4', 'landscape');

            return $pdf->download('historique_mouvements_' . date('Y-m-d') . '.pdf');

        } catch (\Throwable $e) {
            \Log::error('❌ Export PDF erreur: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'export PDF.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Générer le HTML pour le PDF
     */
    private function generatePDFHTML($mouvements)
    {
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Historique des mouvements</title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 10px; margin: 20px; }
                h1 { text-align: center; color: #1a5490; font-size: 16px; margin-bottom: 5px; }
                .subtitle { text-align: center; color: #666; font-size: 10px; margin-bottom: 15px; }
                table { width: 100%; border-collapse: collapse; font-size: 8px; }
                th {
                    background: #1a5490;
                    color: white;
                    padding: 6px 8px;
                    text-align: left;
                    font-weight: bold;
                }
                td {
                    padding: 4px 8px;
                    border-bottom: 1px solid #ddd;
                }
                tr:nth-child(even) { background: #f9f9f9; }
                tr:hover { background: #f1f1f1; }
                .text-center { text-align: center; }
                .badge-success { color: #28a745; font-weight: bold; }
                .badge-danger { color: #dc3545; font-weight: bold; }
                .badge-warning { color: #ffc107; font-weight: bold; }
                .badge-info { color: #17a2b8; font-weight: bold; }
                .footer {
                    text-align: center;
                    font-size: 8px;
                    color: #999;
                    margin-top: 15px;
                    border-top: 1px solid #ddd;
                    padding-top: 10px;
                }
                .page-break { page-break-after: always; }
            </style>
        </head>
        <body>
            <h1>📋 HISTORIQUE DES MOUVEMENTS</h1>
            <div class="subtitle">Généré le ' . date('d/m/Y à H:i') . ' | Total: ' . $mouvements->count() . ' mouvement(s)</div>

            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Code</th>
                        <th>Catégorie</th>
                        <th>Type</th>
                        <th>N° Série</th>
                        <th>Utilisateur</th>
                        <th>Site</th>
                        <th>Direction</th>
                        <th>État</th>
                        <th>Quantité</th>
                        <th>Effectué par</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($mouvements as $mouvement) {
            $user = $mouvement->user;
            $materiel = $mouvement->materiel;

            // Couleur pour l'état
            $etatClass = '';
            switch ($mouvement->etat_materiel) {
                case 'disponible': $etatClass = 'badge-success'; break;
                case 'panne': $etatClass = 'badge-danger'; break;
                case 'maintenance': $etatClass = 'badge-warning'; break;
                case 'attribue': $etatClass = 'badge-info'; break;
                default: $etatClass = '';
            }

            $html .= '
                <tr>
                    <td>' . $mouvement->date_mouvement . '</td>
                    <td>' . $mouvement->type_mouvement . '</td>
                    <td>' . ($materiel?->code_materiel ?? '') . '</td>
                    <td>' . ($materiel?->categorie ?? '') . '</td>
                    <td>' . ($materiel?->type_materiel ?? '') . '</td>
                    <td>' . ($materiel?->numero_serie ?? '') . '</td>
                    <td>' . ($user ? $user->nom . ' ' . $user->prenom : '') . '</td>
                    <td>' . ($user?->site?->nom ?? '') . '</td>
                    <td>' . ($user?->direction?->nom ?? '') . '</td>
                    <td class="' . $etatClass . '">' . $mouvement->etat_materiel . '</td>
                    <td class="text-center">' . ($mouvement->quantite ?? 1) . '</td>
                    <td>' . ($mouvement->creator ? $mouvement->creator->nom . ' ' . $mouvement->creator->prenom : '') . '</td>
                </tr>';
        }

        $html .= '
                </tbody>
            </table>

            <div class="footer">
                Document généré automatiquement par le système de gestion du matériel ONAS
            </div>
        </body>
        </html>';

        return $html;
    }

    public function statistiquesHistorique(Request $request)
{
    try {

        /*
        |--------------------------------------------------------------------------
        | FILTRES COMMUNS
        |--------------------------------------------------------------------------
        */

        $query = MouvementMateriel::query();


        if ($request->filled('date_debut')) {

            $query->whereDate(
                'date_mouvement',
                '>=',
                $request->date_debut
            );

        }


        if ($request->filled('date_fin')) {

            $query->whereDate(
                'date_mouvement',
                '<=',
                $request->date_fin
            );

        }


        if ($request->filled('materiel_id')) {

            $query->where(
                'materiel_id',
                $request->materiel_id
            );

        }


        if ($request->filled('user_id')) {

            $query->where(
                'user_id',
                $request->user_id
            );

        }


        /*
        |--------------------------------------------------------------------------
        | TOTAL MOUVEMENTS
        |--------------------------------------------------------------------------
        */

        $totalMouvements = (clone $query)->count();



        /*
        |--------------------------------------------------------------------------
        | TOTAL MATERIELS CONCERNES
        |--------------------------------------------------------------------------
        */

        $totalMateriels = (clone $query)
            ->distinct('materiel_id')
            ->count('materiel_id');



        /*
        |--------------------------------------------------------------------------
        | REPARTITION PAR TYPE DE MOUVEMENT
        |--------------------------------------------------------------------------
        */

        $parType = (clone $query)
            ->selectRaw(
                'type_mouvement, COUNT(*) as total'
            )
            ->groupBy('type_mouvement')
            ->orderByDesc('total')
            ->get();



        /*
        |--------------------------------------------------------------------------
        | REPARTITION PAR ETAT
        |--------------------------------------------------------------------------
        */

        $parEtat = (clone $query)
            ->selectRaw(
                'etat_materiel, COUNT(*) as total'
            )
            ->whereNotNull('etat_materiel')
            ->groupBy('etat_materiel')
            ->orderByDesc('total')
            ->get();



        /*
        |--------------------------------------------------------------------------
        | EVOLUTION PAR MOIS
        |--------------------------------------------------------------------------
        */

        $evolutionMensuelle = (clone $query)
            ->selectRaw(
                "
                TO_CHAR(date_mouvement, 'YYYY-MM') as mois,
                COUNT(*) as total
                "
            )
            ->groupBy('mois')
            ->orderBy('mois')
            ->get();



        /*
        |--------------------------------------------------------------------------
        | UTILISATEURS LES PLUS CONCERNES
        |--------------------------------------------------------------------------
        */

        $utilisateurs = (clone $query)
            ->with('user:id,nom,prenom')
            ->selectRaw(
                'user_id, COUNT(*) as total'
            )
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(function($item){

                return [

                    'utilisateur' =>
                        $item->user
                        ? $item->user->nom.' '.$item->user->prenom
                        : null,

                    'total'=>$item->total

                ];

            });



        /*
        |--------------------------------------------------------------------------
        | DIRECTIONS LES PLUS CONCERNEES
        |--------------------------------------------------------------------------
        */

        $directions = (clone $query)
            ->with('direction:id,nom')
            ->selectRaw(
                'direction_id, COUNT(*) as total'
            )
            ->whereNotNull('direction_id')
            ->groupBy('direction_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(function($item){

                return [

                    'direction'=>$item->direction?->nom,

                    'total'=>$item->total

                ];

            });



        /*
        |--------------------------------------------------------------------------
        | DERNIERS MOUVEMENTS
        |--------------------------------------------------------------------------
        */

        $derniers = (clone $query)
            ->with([
                'materiel:id,code_materiel',
                'user:id,nom,prenom'
            ])
            ->orderByDesc('date_mouvement')
            ->limit(5)
            ->get();



        return response()->json([

            'success'=>true,

            'message'=>'Statistiques de l\'historique récupérées.',


            'data'=>[


                'resume'=>[

                    'total_mouvements'=>$totalMouvements,

                    'total_materiels'=>$totalMateriels,

                ],



                'mouvements_par_type'=>$parType,


                'mouvements_par_etat'=>$parEtat,


                'evolution_mensuelle'=>$evolutionMensuelle,


                'utilisateurs_actifs'=>$utilisateurs,


                'directions_concernees'=>$directions,


                'derniers_mouvements'=>$derniers,


            ]

        ]);


    } catch(\Throwable $e){


        return response()->json([

            'success'=>false,

            'message'=>'Erreur lors du calcul des statistiques.',

            'error'=>$e->getMessage()

        ],500);


    }
}



}
