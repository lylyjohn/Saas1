<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Support\ElegibilidadeAutomacao;
use Modules\Core\Services\TenantManager;

/**
 * Corre de hora a hora (ver AtelierServiceProvider::agendarTarefas()) e
 * despacha DispararCampanhaJob para cada campanha agendada cuja hora já
 * chegou. Ignora as que já foram reivindicadas por um job (iniciada_em
 * recente) — antes, uma fila atrasada mais de uma hora fazia com que a
 * mesma campanha fosse despachada outra vez — e as de empresas sem o
 * Atelier ativo. Percorre campanhas de TODAS as empresas, por isso corre
 * dentro do bypass explícito do TenantManager.
 */
class EnviarCampanhasAgendadasJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(TenantManager $tenantManager): void
    {
        $tenantManager->semTenant(function () {
            /** @var array<int, bool> $elegiveis */
            $elegiveis = [];

            Campanha::query()
                ->with('empresa')
                ->where('estado', 'agendada')
                ->where('agendada_para', '<=', now())
                ->where(function ($q) {
                    $q->whereNull('iniciada_em')
                        ->orWhere('iniciada_em', '<', now()->subHours(DispararCampanhaJob::VALIDADE_REIVINDICACAO_HORAS));
                })
                ->chunkById(100, function ($campanhas) use (&$elegiveis) {
                    foreach ($campanhas as $campanha) {
                        $empresa = $campanha->empresa;

                        if (! $empresa) {
                            continue;
                        }

                        $elegiveis[$empresa->id] ??= ElegibilidadeAutomacao::empresaPodeEnviar($empresa);

                        if ($elegiveis[$empresa->id]) {
                            DispararCampanhaJob::dispatch($campanha->id);
                        }
                    }
                });
        });
    }
}
