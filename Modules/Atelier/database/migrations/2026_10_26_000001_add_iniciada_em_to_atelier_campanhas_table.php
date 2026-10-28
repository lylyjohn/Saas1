<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Reivindicação" atómica de uma campanha: DispararCampanhaJob só avança se
 * conseguir passar iniciada_em de NULL para agora numa única UPDATE. Evita o
 * duplo envio (duplo clique, job horário a re-despachar, retries da fila)
 * sem mexer no ENUM de `estado`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('atelier_campanhas', 'iniciada_em')) {
            return;
        }

        Schema::table('atelier_campanhas', function (Blueprint $table) {
            $table->timestamp('iniciada_em')->nullable()->after('enviada_em');
        });
    }

    public function down(): void
    {
        Schema::table('atelier_campanhas', function (Blueprint $table) {
            $table->dropColumn('iniciada_em');
        });
    }
};
