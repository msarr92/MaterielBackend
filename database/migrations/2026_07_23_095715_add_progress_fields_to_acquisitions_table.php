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
        Schema::table('acquisitions', function (Blueprint $table) {
            $table->integer('quantite_prevue')
                ->default(0)
                ->after('montant');

            $table->enum('statut', [
                'EN_COURS',
                'TERMINEE',
            ])
                ->default('EN_COURS')
                ->after('quantite_prevue');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('acquisitions', function (Blueprint $table) {
            //
        });
    }
};
