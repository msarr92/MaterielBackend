<?php

use App\Http\Controllers\AcquisitionController;
use App\Http\Controllers\AttributionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BeneficiaireController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DirectionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\MaterielController;
use App\Http\Controllers\MouvementMaterielController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);


Route::middleware(['auth:api','role:ADMIN'])->group(function () {
    Route::post('/register',[AuthController::class, 'register']);

});

// DashboardController
Route::middleware('auth:api')->group(function () {

    Route::get('/statistics', [DashboardController::class, 'statistics']);
    Route::get('/recent-assignments', [DashboardController::class, 'recentAssignments']);
    Route::post('/refresh-cache', [DashboardController::class, 'refreshCache']);
    Route::get('/attribution-evolution', [DashboardController::class, 'evolutionAttributions']);
});

// AuthController
Route::middleware('auth:api')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/security-log', [AuthController::class, 'store']);
    Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
});

// UserController BeneficiaireController
Route::middleware(['auth:api'])->group(function () {

    Route::post('/import-employes', [UserController::class, 'import']);
    Route::get('/users', [UserController::class, 'listUsers']);
    Route::get('/statistics/users', [UserController::class, 'userStatistics']);

    Route::put('/users/{id}', [UserController::class, 'UpdateUsers']);
    Route::get('/users/{id}', [UserController::class, 'DetailUsers']);

    Route::get('/beneficiaires/all', [BeneficiaireController::class, 'getBeneficiairesSelect']);

});

// MaterielController
Route::middleware('auth:api')->group(function () {

    Route::get('/materiels/disponibles', [MaterielController::class, 'getMaterielsDisponibles']);
    Route::get('/materiels/statistics', [MaterielController::class, 'statistiquesMateriels']);

    Route::get('/materiels/equipements', [MaterielController::class, 'listEquipements']);
    Route::get('/materiels/accessoires', [MaterielController::class, 'listAccessoires']);

    Route::post('/materiels', [MaterielController::class, 'AjoutMateriel']);
    Route::post('/brouillon', [MaterielController::class, 'AjoutMaterielBrouillon']);
    Route::get('/materiels/rebuts',[MaterielController::class, 'listMaterielsRebut']);

    Route::get('/materiels/{id}', [MaterielController::class, 'detailMateriel']);
    Route::put('/materiels/{id}', [MaterielController::class, 'updateMateriel']);
    Route::delete('/materiels/{id}', [MaterielController::class, 'deleteMateriel']);
    Route::put('/materiels/{id}/valider', [MaterielController::class, 'ValiderMateriel']);
    Route::get('/acquisitions/{id}/continuer-saisie', [MaterielController::class, 'continuerSaisieMateriel']);
     Route::patch('/materiels/{id}/mettre-au-rebut',[MaterielController::class, 'mettreAuRebut']);
});

// AttributionController
Route::middleware('auth:api')->group(function () {

    // Attributions
    Route::post('/assignments', [AttributionController::class, 'attribuerMateriel']);
    Route::post('/attributions/direction', [AttributionController::class, 'attribuerDirection']);
    Route::get('/attributions', [AttributionController::class, 'listAttributions']);
    Route::get('/attributions/historique', [AttributionController::class, 'listHistoriqueAttributions']);
    Route::get('/statistiques-attributions', [AttributionController::class, 'statistiqueAttribution']);

    // Sites et Directions
    Route::get('/sites', [AttributionController::class, 'getSites']);
    Route::get('/directions', [AttributionController::class, 'getDirections']);

    Route::get('/attributions/statistics', [AttributionController::class, 'statistics']);

    Route::post('/attributions/{id}/remettre-service', [AttributionController::class, 'remettreEnService']);

    Route::get('/attributions/materiel/{materielId}/history', [AttributionController::class, 'getMaterialHistory']);

    // Routes pour attributions avec ID
    Route::get('/attributions/{id}', [AttributionController::class, 'detailAttribution']);
    Route::patch('/attributions/{id}/retour', [AttributionController::class, 'retournerMateriel']);
    Route::post('/attributions/{id}/remise-service', [AttributionController::class, 'remettreEnService']);
    Route::delete('/attributions/{id}', [AttributionController::class, 'deleteAttribution']);
    Route::put('/attributions/{id}', [AttributionController::class, 'updateAttribution']);
    Route::put('/attributions/{id}/changer-etat', [AttributionController::class, 'changerEtatMateriel']);
    // Route::post('/documents/{id}/importer',[AttributionController::class, 'importerDocument']);
    Route::post(
        '/documents/importer',
        [AttributionController::class, 'importerDocument']);

});

// AcquisitionController
Route::middleware('auth:api')->group(function () {
    Route::post('/acquisitions', [AcquisitionController::class, 'createAcquisition']);
    Route::get('/acquisitions', [AcquisitionController::class, 'listAcquisitions']);
    Route::get('/acquisitions/statistiques', [AcquisitionController::class, 'statistiquesAcquisitions']);
    Route::get('/acquisitions/{id}', [AcquisitionController::class, 'detailAcquisition']);
});

//DashboardController
Route::middleware('auth:api')->group(function () {
    Route::get('/dashboard/statistics', [DashboardController::class, 'dashboardStatistics']);
    Route::get('/statistiques/situation-materiels', [DashboardController::class, 'situationMateriels']);
    Route::get('/dashboard/materiels-par-site', [DashboardController::class, 'materielsParSite']);
});

//DirectionController
Route::middleware(['auth:api', 'role:ADMIN,GESTIONNAIRE'])->group(function () {
    Route::post('/directions', [DirectionController::class, 'AjoutDirection']);
    Route::get('/directions', [DirectionController::class, 'listDirections']);
    Route::get('/directions/statistics', [DirectionController::class, 'directionStatistics']);
    Route::put('/directions/{id}', [DirectionController::class, 'updateDirection']);
    Route::delete('/directions/{id}', [DirectionController::class, 'deleteDirection']);
});


//DocumentController
Route::middleware('auth:api')->group(function () {

    Route::get('/documents', [DocumentController::class, 'listeDocuments']);

    Route::get('/documents/statistiques', [DocumentController::class, 'statistiquesDocuments']);

    Route::get('/documents/{id}/apercu', [DocumentController::class, 'apercuDocument']);

    Route::get('/documents/{id}/telecharger', [DocumentController::class, 'telechargerDocument']);

    Route::delete('/documents/{id}',[DocumentController::class, 'supprimerDocument']);

    Route::get('/documents/{id}', [DocumentController::class, 'detailDocument']);
});


//MouvementMaterielController
Route::middleware('auth:api')->group(function () {

    Route::get('/mouvements/historique', [MouvementMaterielController::class, 'historiqueMouvements']);

    Route::get('/materiels/{id}/historique', [MouvementMaterielController::class, 'historiqueMateriel']);

    Route::get('/mouvements/export/csv', [MouvementMaterielController::class, 'exportHistoriqueCSV']);
    Route::get('/mouvements/export/pdf', [MouvementMaterielController::class, 'exportHistoriquePDF']);

    Route::get('/mouvements/statistiques', [MouvementMaterielController::class, 'statistiquesHistorique']);

});
