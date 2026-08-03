<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('materiels', function (Blueprint $table) {
            $table->enum('categorie', [
                'EQUIPEMENT',
                'ACCESSOIRE',
            ])
                ->default('EQUIPEMENT')
                ->after('type_materiel');

            $table->integer('quantite')
                ->default(1)
                ->after('categorie');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('materiels', function (Blueprint $table) {
            $table->dropColumn('categorie');
            $table->dropColumn('quantite');
        });
    }
};
