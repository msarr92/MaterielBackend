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
        // Modifier l'ENUM
        DB::statement("
            ALTER TABLE acquisitions
            ALTER COLUMN type_acquisition
            TYPE VARCHAR(30)
        ");

        DB::statement("
            ALTER TABLE acquisitions
            ALTER COLUMN type_acquisition
            DROP DEFAULT
        ");

        DB::statement("
            ALTER TABLE acquisitions
            DROP CONSTRAINT IF EXISTS acquisitions_type_acquisition_check
        ");

        DB::statement("
            ALTER TABLE acquisitions
            ADD CONSTRAINT acquisitions_type_acquisition_check
            CHECK (type_acquisition IN ('MARCHE', 'BON_COMMANDE', 'AUTRE'))
        ");

        // Nouveau champ
        Schema::table('acquisitions', function (Blueprint $table) {
            $table->string('detail_type_acquisition')
                ->nullable()
                ->after('type_acquisition');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('acquisitions', function (Blueprint $table) {
            $table->dropColumn('detail_type_acquisition');
        });

        DB::statement("
            ALTER TABLE acquisitions
            DROP CONSTRAINT IF EXISTS acquisitions_type_acquisition_check
        ");

        DB::statement("
            ALTER TABLE acquisitions
            ADD CONSTRAINT acquisitions_type_acquisition_check
            CHECK (type_acquisition IN ('MARCHE', 'BON_COMMANDE'))
        ");
    }
};
