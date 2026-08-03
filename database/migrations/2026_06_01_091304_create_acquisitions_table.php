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
        Schema::create('acquisitions', function (Blueprint $table) {
            $table->id();

            $table->enum('type_acquisition', ['MARCHE', 'BON_COMMANDE']);
            $table->string('numero_reference')->nullable();

            $table->date('date_acquisition');

            // fournisseur intégré
            $table->string('fournisseur_nom')->nullable();
            $table->string('fournisseur_contact')->nullable();
            $table->string('fournisseur_adresse')->nullable();

            $table->decimal('montant', 12, 2)->nullable();
            $table->text('observation')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acquisitions');
    }
};
