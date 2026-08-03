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
        Schema::create('mouvements_materiels', function (Blueprint $table) {
            $table->id();

            $table->foreignId('materiel_id')->constrained('materiels')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('direction_id')->nullable()->constrained('directions')->nullOnDelete();

            $table->enum('type_mouvement', [
                'ACQUISITION',
                'ATTRIBUTION',
                'REAFFECTATION',
                'PANNE',
                'MAINTENANCE',
                'RETOUR_STOCK',
                'CHANGEMENT_DIRECTION',
            ]);

            $table->date('date_mouvement');

            $table->string('etat_materiel')->nullable();

            $table->text('observation')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mouvement_materiels');
    }
};
