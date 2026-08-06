<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('materiels', function (Blueprint $table) {
            $table->date('date_rebut')
                ->nullable()
                ->after('date_etat_change');

            $table->text('motif_rebut')
                ->nullable()
                ->after('date_rebut');

            $table->foreignId('rebute_par')
                ->nullable()
                ->after('motif_rebut')
                ->constrained('users')
                ->nullOnDelete();
        });

        /*
        |--------------------------------------------------------------------------
        | Ajouter l'état "rebut" aux matériels
        |--------------------------------------------------------------------------
        */

        DB::statement("
            ALTER TABLE materiels
            DROP CONSTRAINT IF EXISTS materiels_etat_check
        ");

        DB::statement("
            ALTER TABLE materiels
            ADD CONSTRAINT materiels_etat_check
            CHECK (
                etat IN (
                    'disponible',
                    'attribue',
                    'panne',
                    'maintenance',
                    'rebut'
                )
            )
        ");

        /*
        |--------------------------------------------------------------------------
        | Ajouter le mouvement REBUT
        |--------------------------------------------------------------------------
        */

        DB::statement("
            ALTER TABLE mouvements_materiels
            DROP CONSTRAINT IF EXISTS mouvements_materiels_type_mouvement_check
        ");

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
                    'MODIFICATION_ETAT',
                    'REBUT'
                )
            )
        ");

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Nettoyer les valeurs avant le rollback
        |--------------------------------------------------------------------------
        */

        DB::statement("
            UPDATE materiels
            SET
                etat = 'disponible',
                date_rebut = NULL,
                motif_rebut = NULL,
                rebute_par = NULL
            WHERE etat = 'rebut'
        ");

        DB::statement("
            UPDATE mouvements_materiels
            SET type_mouvement = 'MODIFICATION_ETAT'
            WHERE type_mouvement = 'REBUT'
        ");

        /*
        |--------------------------------------------------------------------------
        | Restaurer la contrainte des états
        |--------------------------------------------------------------------------
        */

        DB::statement("
            ALTER TABLE materiels
            DROP CONSTRAINT IF EXISTS materiels_etat_check
        ");

        DB::statement("
            ALTER TABLE materiels
            ADD CONSTRAINT materiels_etat_check
            CHECK (
                etat IN (
                    'disponible',
                    'attribue',
                    'panne',
                    'maintenance'
                )
            )
        ");

        /*
        |--------------------------------------------------------------------------
        | Restaurer les types de mouvements
        |--------------------------------------------------------------------------
        */

        DB::statement("
            ALTER TABLE mouvements_materiels
            DROP CONSTRAINT IF EXISTS mouvements_materiels_type_mouvement_check
        ");

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

        /*
        |--------------------------------------------------------------------------
        | Supprimer les colonnes ajoutées
        |--------------------------------------------------------------------------
        */

        Schema::table('materiels', function (Blueprint $table) {
            $table->dropForeign(['rebute_par']);

            $table->dropColumn([
                'date_rebut',
                'motif_rebut',
                'rebute_par',
            ]);
        });
    }
    
};
