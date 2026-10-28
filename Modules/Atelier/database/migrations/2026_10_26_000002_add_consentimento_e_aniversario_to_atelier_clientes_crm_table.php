<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atelier_clientes_crm', function (Blueprint $table) {
            if (! Schema::hasColumn('atelier_clientes_crm', 'aniversario_enviado_ano')) {
                // Ano em que a mensagem de aniversário já foi enviada — impede duplicados.
                $table->unsignedSmallInteger('aniversario_enviado_ano')->nullable()->after('aceita_marketing');
            }

            if (! Schema::hasColumn('atelier_clientes_crm', 'consentimento_registado_em')) {
                // Quando o consentimento de marketing foi registado (prova de consentimento).
                $table->timestamp('consentimento_registado_em')->nullable()->after('aniversario_enviado_ano');
            }
        });
    }

    public function down(): void
    {
        Schema::table('atelier_clientes_crm', function (Blueprint $table) {
            $table->dropColumn(['aniversario_enviado_ano', 'consentimento_registado_em']);
        });
    }
};
