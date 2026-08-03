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
        Schema::create('documents', function (Blueprint $table) {

            $table->id();

            $table->string('numero_document')->unique();

            $table->enum('type_document', [
                'REMISE_NEUF',
                'FICHE_DEPLACEMENT',
                'REBUT',
            ]);

            $table->foreignId('attribution_id')
                ->nullable()
                ->constrained('attributions')
                ->nullOnDelete();

            $table->string('fichier_pdf')->nullable();

            $table->string('fichier_scan')->nullable();

            $table->dateTime('date_generation');

            $table->dateTime('date_televersement')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('observation')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
