<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Models\Prova;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Atelier\Support\ElegibilidadeAutomacao;
use Modules\Core\Services\TenantManager;
use Throwable;

/**
 * Corre de hora a hora (ver AtelierServiceProvider::agendarTarefas()).
 * Percorre provas de TODAS as empresas — por isso corre dentro do bypass
 * explícito do TenantManager, tal como VerificarSubscricoesExpiradasJob já
 * faz no módulo Subscricoes.
 *
 * - Só trata empresas com o serviço Atelier e acesso ativo (nada de gastar
 *   mensagens com empresas que cancelaram).
 * - O lembrete é "reivindicado" ANTES do envio (UPDATE atómica de
 *   lembrete_enviado_em): duas execuções sobrepostas nunca enviam o mesmo.
 * - Só fica marcado como enviado se algum canal o aceitou; senão a
 *   reivindicação é libertada e a próxima hora tenta de novo.
 */
class LembretesProvaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(TenantManager $tenantManager, NotificadorClienteService $notificador): void
    {
        $tenantManager->semTenant(function () use ($notificador) {
            /** @var array<int, bool> $elegiveis */
            $elegiveis = [];

            Prova::query()
                ->whereIn('estado', ['agendada', 'confirmada', 'reagendada'])
                ->whereNull('lembrete_enviado_em')
                ->where('data_hora_agendada', '>=', now())
                ->with(['pedido.cliente', 'pedido.empresa'])
                ->chunkById(100, function ($provas) use ($notificador, &$elegiveis) {
                    foreach ($provas as $prova) {
                        if (! $prova->precisaDeLembrete()) {
                            continue;
                        }

                        $empresa = $prova->pedido?->empresa;

                        if (! $empresa) {
                            continue;
                        }

                        $elegiveis[$empresa->id] ??= ElegibilidadeAutomacao::empresaPodeEnviar($empresa);

                        if ($elegiveis[$empresa->id]) {
                            $this->processar($prova, $notificador);
                        }
                    }
                });
        });
    }

    protected function processar(Prova $prova, NotificadorClienteService $notificador): void
    {
        $reivindicado = Prova::query()
            ->whereKey($prova->id)
            ->whereNull('lembrete_enviado_em')
            ->update(['lembrete_enviado_em' => now()]);

        if ($reivindicado === 0) {
            return;
        }

        try {
            $enviado = $this->enviarLembrete($prova, $notificador);
        } catch (Throwable $e) {
            $enviado = false;

            Log::error('Atelier: falha ao enviar lembrete de prova', [
                'prova_id' => $prova->id,
                'erro' => $e->getMessage(),
            ]);
        }

        if (! $enviado) {
            // Nada foi entregue: liberta a reivindicação para a próxima execução tentar de novo.
            Prova::query()->whereKey($prova->id)->update(['lembrete_enviado_em' => null]);
        }
    }

    protected function enviarLembrete(Prova $prova, NotificadorClienteService $notificador): bool
    {
        $cliente = $prova->pedido?->cliente;

        if (! $cliente) {
            return false;
        }

        return $notificador->notificar(
            $cliente,
            "Lembrete: {$prova->tipoRotulo()} — {$prova->pedido->empresa->nome_comercial}",
            "Lembramos que tem a {$prova->tipoRotulo()} da sua peça agendada para ".
                $prova->data_hora_agendada->format('d/m/Y \à\s H:i').'.',
        );
    }
}
