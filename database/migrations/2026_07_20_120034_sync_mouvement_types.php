<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
{
    /*
    |--------------------------------------------------------------------------
    | 1. Supprimer ancienne contrainte
    |--------------------------------------------------------------------------
    */

    DB::statement("
        ALTER TABLE mouvements_materiels
        DROP CONSTRAINT IF EXISTS mouvements_materiels_type_mouvement_check
    ");


    /*
    |--------------------------------------------------------------------------
    | 2. Nettoyer les anciennes valeurs
    |--------------------------------------------------------------------------
    */

    DB::statement("
        UPDATE mouvements_materiels
        SET type_mouvement = 'MODIFICATION_ETAT'
        WHERE type_mouvement NOT IN (
            'ACQUISITION',
            'ATTRIBUTION',
            'RETOUR',
            'PANNE',
            'MAINTENANCE',
            'REAFFECTATION',
            'REFORME',
            'RETOUR_STOCK',
            'MODIFICATION_ETAT'
        )
    ");


    /*
    |--------------------------------------------------------------------------
    | 3. Ajouter la nouvelle contrainte
    |--------------------------------------------------------------------------
    */

    DB::statement("
        ALTER TABLE mouvements_materiels
        ADD CONSTRAINT mouvements_materiels_type_mouvement_check
        CHECK (
            type_mouvement IN (
                'ACQUISITION',
                'ATTRIBUTION',
                'RETOUR',
                'PANNE',
                'MAINTENANCE',
                'REAFFECTATION',
                'REFORME',
                'RETOUR_STOCK',
                'MODIFICATION_ETAT'
            )
        )
    ");
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mouvements_materiels', function (Blueprint $table) {});
    }
};
