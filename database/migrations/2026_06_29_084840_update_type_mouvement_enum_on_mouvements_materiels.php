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
        // 1. Remplacer anciennes valeurs existantes si besoin
        DB::statement("
            UPDATE mouvements_materiels
            SET type_mouvement = 'RESTITUTION'
            WHERE type_mouvement = 'RETOUR_DISPONIBLE'
        ");

        // 2. Modifier le CHECK constraint PostgreSQL
        DB::statement("ALTER TABLE mouvements_materiels DROP CONSTRAINT mouvements_materiels_type_mouvement_check");

        DB::statement("
            ALTER TABLE mouvements_materiels
            ADD CONSTRAINT mouvements_materiels_type_mouvement_check
            CHECK (type_mouvement IN (
                'ACQUISITION',
                'ATTRIBUTION',
                'REAFFECTATION',
                'PANNE',
                'MAINTENANCE',
                'RESTITUTION',
                'CHANGEMENT_DIRECTION'
            ))
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE mouvements_materiels DROP CONSTRAINT mouvements_materiels_type_mouvement_check");

        DB::statement("
            ALTER TABLE mouvements_materiels
            ADD CONSTRAINT mouvements_materiels_type_mouvement_check
            CHECK (type_mouvement IN (
                'ACQUISITION',
                'ATTRIBUTION',
                'REAFFECTATION',
                'PANNE',
                'MAINTENANCE',
                'RETOUR_DISPONIBLE',
                'CHANGEMENT_DIRECTION'
            ))
        ");
    }
};
