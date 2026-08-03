<?php

namespace App\Http\Controllers;

use App\Models\Attribution;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DocumentController extends Controller
{
    public function listeDocuments(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [

                'search' => 'nullable|string|max:100',

                'type_document' => 'nullable|in:REMISE_NEUF,FICHE_DEPLACEMENT,REBUT',

                'date_debut' => 'nullable|date',

                'date_fin' => 'nullable|date',

                'per_page' => 'nullable|integer|min:1|max:100',

            ]);

            if ($validator->fails()) {

                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                ], 422);

            }

            $perPage = $request->per_page ?? 10;

            $documents = Document::with([

                // Utilisateur qui a importé le document

                'creator:id,nom,prenom',

                // Utilisateurs concernés par le document

                'attributions.user:id,id,nom,prenom,matricule',

                'attributions.direction:id,nom',

                'attributions.site:id,nom',

                'attributions.materiel:id,marque,modele,code_materiel',

            ])
                ->when($request->search, function ($query) use ($request) {

                    $search = $request->search;

                    $query->where(function ($q) use ($search) {

                        $q->where(
                            'numero_document',
                            'ILIKE',
                            "%{$search}%"
                        )
                            ->orWhere(
                                'observation',
                                'ILIKE',
                                "%{$search}%"
                            );

                    });

                })
                ->when($request->type_document, function ($query) use ($request) {

                    $query->where(
                        'type_document',
                        $request->type_document
                    );

                })
                ->when($request->date_debut, function ($query) use ($request) {

                    $query->whereDate(
                        'created_at',
                        '>=',
                        $request->date_debut
                    );

                })
                ->when($request->date_fin, function ($query) use ($request) {

                    $query->whereDate(
                        'created_at',
                        '<=',
                        $request->date_fin
                    );

                })
                ->latest()
                ->paginate($perPage);

            return response()->json([

                'success' => true,

                'data' => $documents,

            ]);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors du chargement des documents.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }

    public function statistiquesDocuments()
    {
        try {

            $stats = [

                'total_documents' => Document::count(),

                'documents_importes' => Document::whereNotNull('fichier_scan')->count(),

                'documents_generes' => Document::whereNotNull('fichier_pdf')->count(),

                'documents_non_importes' => Document::whereNull('fichier_scan')->count(),

                'par_type' => [
                    'REMISE_NEUF' => Document::where('type_document', 'REMISE_NEUF')->count(),
                    'FICHE_DEPLACEMENT' => Document::where('type_document', 'FICHE_DEPLACEMENT')->count(),
                    'REBUT' => Document::where('type_document', 'REBUT')->count(),
                ],

                'documents_ce_mois' => Document::whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count(),

                'documents_aujourd_hui' => Document::whereDate('created_at', today())
                    ->count(),

            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du calcul des statistiques.',
                'error' => $e->getMessage(),
            ], 500);

        }
    }

    public function detailDocument($id)
    {
        try {

            $document = Document::with([

                'creator:id,nom,prenom',

                'attributions' => function ($query) {

                    $query->with([

                        'user:id,nom,prenom,matricule',

                        'direction:id,nom',

                        'site:id,nom',

                        'materiel:id,code_materiel,numero_serie,marque,modele,type_materiel',

                    ]);

                },

            ])->find($id);

            if (! $document) {

                return response()->json([
                    'success' => false,
                    'message' => 'Document introuvable.',
                ], 404);

            }

            return response()->json([
                'success' => true,
                'data' => $document,
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement du document.',
                'error' => $e->getMessage(),
            ], 500);

        }
    }

    public function apercuDocument($id)
    {
        try {

            $document = Document::find($id);

            if (! $document) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document introuvable.',
                ], 404);
            }

            $fichier = $document->fichier_scan ?: $document->fichier_pdf;

            if (! $fichier) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun fichier associé à ce document.',
                ], 404);
            }

            if (! Storage::disk('public')->exists($fichier)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le fichier est introuvable sur le serveur.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $document->id,
                    'numero_document' => $document->numero_document,
                    'type_document' => $document->type_document,
                    'url' => asset('storage/'.$fichier),
                    'extension' => strtolower(pathinfo($fichier, PATHINFO_EXTENSION)),
                    'nom_fichier' => basename($fichier),
                ],
            ]);

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => "Erreur lors de l'aperçu du document.",
                'error' => $e->getMessage(),
            ], 500);

        }
    }

    public function telechargerDocument($id)
    {
        try {

            $document = Document::find($id);

            if (! $document) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document introuvable.',
                ], 404);
            }

            if (empty($document->fichier_scan)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun fichier associé à ce document.',
                ], 404);
            }

            if (! Storage::disk('public')->exists($document->fichier_scan)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le fichier n\'existe plus sur le serveur.',
                ], 404);
            }

            $extension = pathinfo($document->fichier_scan, PATHINFO_EXTENSION);

            $nomFichier = $document->numero_document.'.'.$extension;

            return Storage::disk('public')->download(
                $document->fichier_scan,
                $nomFichier
            );

        } catch (\Throwable $e) {

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du téléchargement.',
                'error' => $e->getMessage(),
            ], 500);

        }
    }

    public function supprimerDocument($id)
    {
        try {

            /*
            |--------------------------------------------------------------------------
            | Recherche du document
            |--------------------------------------------------------------------------
            */

            $document = Document::find($id);

            if (! $document) {

                return response()->json([
                    'success' => false,
                    'message' => 'Document introuvable.',
                ], 404);

            }

            /*
            |--------------------------------------------------------------------------
            | Suppression du fichier physique
            |--------------------------------------------------------------------------
            */

            if ($document->fichier_scan) {

                if (Storage::disk('public')->exists($document->fichier_scan)) {

                    Storage::disk('public')->delete(
                        $document->fichier_scan
                    );

                }

            }

            if ($document->fichier_pdf) {

                if (Storage::disk('public')->exists($document->fichier_pdf)) {

                    Storage::disk('public')->delete(
                        $document->fichier_pdf
                    );

                }

            }

            /*
            |--------------------------------------------------------------------------
            | Suppression de la liaison avec les attributions
            |--------------------------------------------------------------------------
            */

            Attribution::where(
                'document_id',
                $document->id
            )
                ->update([
                    'document_id' => null,
                ]);

            /*
            |--------------------------------------------------------------------------
            | Suppression du document
            |--------------------------------------------------------------------------
            */

            $document->delete();

            return response()->json([

                'success' => true,

                'message' => 'Document supprimé avec succès.',

            ]);

        } catch (\Throwable $e) {

            return response()->json([

                'success' => false,

                'message' => 'Erreur lors de la suppression du document.',

                'error' => $e->getMessage(),

            ], 500);

        }
    }

    
}
