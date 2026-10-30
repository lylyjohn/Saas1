<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Services\CrmService;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Atelier\Support\ElegibilidadeAutomacao;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Throwable;

/**
 * Único gatilho de CRM verdadeiramente AUTOMÁTICO (requisito D.1: "Mensagens
 * automáticas em datas comemorativas") — corre uma vez por dia. As
 * promoções sazonais e a reativação de inativos são, de propósito,
 * campanhas manuais (ver Admin\CampanhaController) e não entram aqui.
 *
 * Só empresas com o Atelier ativo. Cada aniversário é "reivindicado" com
 * uma UPDATE atómica de aniversario_enviado_ano antes do envio: um retry da
 * fila ou uma segunda execução no mesmo dia nunca felicita duas vezes. Se
 * nenhum canal aceitar a mensagem, a reivindicação é libertada.
 */
class VerificarAniversariosJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function handle(TenantManager $tenantManager, CrmService $crmService, NotificadorClienteService $notificador): void
    {
        $tenantManager->semTenant(function () use ($crmService, $notificador) {
            Empresa::query()->with('servicos')->chunkById(100, function ($empresas) use ($crmService, $notificador) {
                foreach ($empresas as $empresa) {
                    if (! ElegibilidadeAutomacao::empresaPodeEnviar($empresa)) {
                        continue;
                    }

                    try {
                        foreach ($crmService->aniversariantesHoje($empresa->id) as $cliente) {
                            $this->felicitar($empresa, $cliente, $notificador);
                        }
                    } catch (Throwable $e) {
                        Log::error('Atelier: falha ao processar aniversários da empresa', [
                            'empresa_id' => $empresa->id,
                            'erro' => $e->getMessage(),
                        ]);
                    }
                }
            });
        });
    }

    protected function felicitar(Empresa $empresa, Cliente $cliente, NotificadorClienteService $notificador): void
    {
        $ano = (int) now()->year;

        $reivindicado = ClienteCrm::query()
            ->where('cliente_id', $cliente->id)
            ->where(function ($q) use ($ano) {
                $q->whereNull('aniversario_enviado_ano')->orWhere('aniversario_enviado_ano', '<', $ano);
            })
            ->update(['aniversario_enviado_ano' => $ano]);

        if ($reivindicado === 0) {
            return;
        }

        try {
            $enviado = $notificador->notificar(
                $cliente,
                "Feliz Aniversário! — {$empresa->nome_comercial}",
                "Hoje é um dia especial! A equipa do {$empresa->nome_comercial} deseja-lhe um feliz aniversário.",
            );
        } catch (Throwable $e) {
            $enviado = false;

            Log::error('Atelier: falha ao enviar mensagem de aniversário', [
                'cliente_id' => $cliente->id,
                'erro' => $e->getMessage(),
            ]);
        }

        if (! $enviado) {
            ClienteCrm::query()->where('cliente_id', $cliente->id)->update(['aniversario_enviado_ano' => null]);
        }
    }
}
