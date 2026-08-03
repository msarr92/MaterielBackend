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
        Schema::create('materiels', function (Blueprint $table) {
            $table->id();

            $table->string('code_materiel')->unique();
            $table->string('numero_serie')->unique();

            $table->string('marque');
            $table->string('modele');
            $table->string('type_materiel');

            $table->foreignId('acquisition_id')->nullable()->constrained('acquisitions')->nullOnDelete();

            $table->date('date_mise_service')->nullable();

            $table->enum('etat', ['disponible', 'attribue', 'panne', 'maintenance'])->default('disponible');

            $table->date('date_etat_change')->nullable();
            $table->string('motif_etat')->nullable();

            $table->decimal('cout', 12, 2)->nullable();
            $table->text('observation')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('materiels');
    }
};
