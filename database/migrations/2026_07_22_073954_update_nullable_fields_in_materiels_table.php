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
             $table->string('numero_serie')
                ->nullable()
                ->change();

            $table->string('marque')
                ->nullable()
                ->change();

            $table->string('modele')
                ->nullable()
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('materiels', function (Blueprint $table) {
            $table->string('numero_serie')
                ->nullable(false)
                ->change();

            $table->string('marque')
                ->nullable(false)
                ->change();

            $table->string('modele')
                ->nullable(false)
                ->change();
        });
    }
};
