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
        Schema::create('mises_rebut', function (Blueprint $table) {
            $table->id();

            $table->foreignId('materiel_id')->constrained('materiels')->cascadeOnDelete();

            $table->date('date_rebut');
            $table->string('motif');

            $table->string('decisionnaire')->nullable();

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
        Schema::dropIfExists('mises_rebuts');
    }
};
