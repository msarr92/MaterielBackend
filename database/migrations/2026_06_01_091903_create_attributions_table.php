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
        Schema::create('attributions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('materiel_id')->constrained('materiels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('direction_id')->nullable()->constrained('directions')->nullOnDelete();

            $table->date('date_debut');
            $table->date('date_fin')->nullable();

            $table->enum('statut', ['ACTIVE', 'TERMINE', 'EN_PANNE', 'EN_MAINTENANCE'])->default('ACTIVE');

            $table->enum('type_action', ['ATTRIBUTION', 'REAFFECTATION'])->default('ATTRIBUTION');

            $table->foreignId('previous_attribution_id')->nullable()->constrained('attributions')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attributions');
    }
};
