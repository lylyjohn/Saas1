<?php

namespace Modules\Atelier\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\CampanhaEnvio;
use Modules\Atelier\Services\CrmService;
use Modules\Atelier\Services\Notificacoes\NotificadorClienteService;
use Modules\Atelier\Support\ElegibilidadeAutomacao;
use Modules\Core\Services\TenantManager;
use Throwable;

/**
 * Processa UMA campanha (requisito D.1) — disparado tanto pelo botão
 * "Enviar agora" (Admin\CampanhaController) como por
 * EnviarCampanhasAgendadasJob quando agendada_para chega. Recebe só o ID
 * (nunca o model) no construtor: um Job ShouldQueue é serializado para a
 * fila, e passar o ID evita problemas de serialização/dados desatualizados
 * se o worker só processar o Job mais tarde.
 *
 * Anti-duplicados em DOIS níveis:
 *  1. A campanha é "reivindicada" com uma UPDATE atómica (iniciada_em passa
 *     de NULL para agora): só um job avança, por muitos que estejam na fila
 *     (duplo clique, job horário, retry). Uma reivindicação com mais de
 *     VALIDADE_REIVINDICACAO_HORAS é tratada como abandonada (worker morto).
 *  2. Por destinatário: a unique constraint em atelier_campanha_envios
 *     (campanha_id, cliente_id) + firstOrCreate() + o estado 'enviado'
 *     garantem que, num reenvio dos falhados, ninguém recebe duas vezes.
 */
class DispararCampanhaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const VALIDADE_REIVINDICACAO_HORAS = 2;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(protected int $campanhaId) {}

    public function handle(TenantManager $tenantManager, CrmService $crmService, NotificadorClienteService $notificador): void
    {
        $tenantManager->semTenant(function () use ($crmService, $notificador) {
            $campanha = Campanha::with('empresa')->find($this->campanhaId);

            if (! $campanha || ! in_array($campanha->estado, ['rascunho', 'agendada'], true)) {
                return;
            }

            if (! $campanha->empresa || ! ElegibilidadeAutomacao::empresaPodeEnviar($campanha->empresa)) {
                Log::warning('Atelier: campanha não enviada — a empresa já não tem o serviço Atelier ativo.', [
                    'campanha_id' => $campanha->id,
                ]);

                return;
            }

            $reivindicada = Campanha::query()
                ->whereKey($campanha->id)
                ->whereIn('estado', ['rascunho', 'agendada'])
                ->where(function ($q) {
                    $q->whereNull('iniciada_em')
                        ->orWhere('iniciada_em', '<', now()->subHours(self::VALIDADE_REIVINDICACAO_HORAS));
                })
                ->update(['iniciada_em' => now()]);

            if ($reivindicada === 0) {
                return; // outro job já pegou nesta campanha
            }

            $assunto = $campanha->assunto ?: $campanha->nome;

            // WhatsApp/SMS em rajada levam ao bloqueio do número: pausa entre mensagens.
            $pausaMs = $campanha->canal === 'mail' ? 0 : (int) config('atelier.campanha_pausa_ms', 0);
            $enviados = 0;
            $falhados = 0;

            foreach ($crmService->resolverSegmento($campanha) as $cliente) {
                $envio = CampanhaEnvio::firstOrCreate(
                    ['campanha_id' => $campanha->id, 'cliente_id' => $cliente->id],
                    ['canal' => $campanha->canal, 'estado' => 'pendente'],
                );

                if ($envio->estado === 'enviado') {
                    continue;
                }

                try {
                    $notificador->notificarViaCanalUnico($cliente, $campanha->canal, $assunto, $campanha->mensagem);
                    $envio->update(['estado' => 'enviado', 'enviado_em' => now(), 'erro' => null]);
                    $enviados++;
                } catch (Throwable $e) {
                    $envio->update(['estado' => 'falhou', 'erro' => $e->getMessage()]);
                    $falhados++;

                    Log::warning('Atelier: falha ao enviar campanha para um cliente', [
                        'campanha_id' => $campanha->id,
                        'cliente_id' => $cliente->id,
                        'erro' => $e->getMessage(),
                    ]);
                }

                if ($pausaMs > 0) {
                    usleep($pausaMs * 1000);
                }
            }

            $campanha->update(['estado' => 'enviada', 'enviada_em' => now()]);

            Log::info('Atelier: campanha processada', [
                'campanha_id' => $campanha->id,
                'enviados' => $enviados,
                'falhados' => $falhados,
            ]);
        });
    }

    /** Falha geral (exceção ou timeout): liberta a reivindicação para permitir novo envio. */
    public function failed(Throwable $e): void
    {
        app(TenantManager::class)->semTenant(function () {
            Campanha::query()
                ->whereKey($this->campanhaId)
                ->where('estado', '!=', 'enviada')
                ->update(['iniciada_em' => null]);
        });

        Log::error('Atelier: o envio da campanha falhou', [
            'campanha_id' => $this->campanhaId,
            'erro' => $e->getMessage(),
        ]);
    }
}
