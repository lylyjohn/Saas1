<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Events\PedidoMudouEstado;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Models\Pedido;

class PedidoStatusService
{
    private const TRANSICOES_PERMITIDAS = [
        'pendente' => ['em_corte', 'cancelado'],
        'em_corte' => ['em_costura', 'cancelado'],
        'em_costura' => ['primeira_prova', 'cancelado'],
        'primeira_prova' => ['ajustes', 'pronto_para_retirada', 'cancelado'],
        'ajustes' => ['primeira_prova', 'pronto_para_retirada', 'cancelado'],
        'pronto_para_retirada' => ['entregue', 'cancelado'],
        'entregue' => [],
        'cancelado' => [],
    ];

    public function avancarPara(Pedido $pedido, string $novoEstado, ?string $motivoCancelamento = null): Pedido
    {
        return DB::transaction(function () use ($pedido, $novoEstado, $motivoCancelamento) {
            // Recarrega o estado dentro da transação e bloqueia a linha.
            // Sem isto, dois pedidos concorrentes podiam validar a mesma
            // transição antiga e depois sobrescrever um ao outro.
            $bloqueado = Pedido::query()
                ->where('empresa_id', $pedido->empresa_id)
                ->lockForUpdate()
                ->findOrFail($pedido->id);

            $estadoAnterior = $bloqueado->status;
            $permitidos = self::TRANSICOES_PERMITIDAS[$estadoAnterior] ?? [];

            if (! in_array($novoEstado, $permitidos, true)) {
                throw PedidoException::transicaoInvalida($estadoAnterior, $novoEstado);
            }

            // Política opcional (ATELIER_EXIGIR_PAGAMENTO_PARA_ENTREGA, desligada
            // por omissão): só entrega depois de o saldo estar pago.
            if (
                $novoEstado === 'entregue'
                && config('atelier.exigir_pagamento_para_entrega')
                && ! $bloqueado->recibo_saldo_final_id
                && $bloqueado->saldoEmFalta() > 0
            ) {
                throw PedidoException::pagamentoPendente();
            }

            $bloqueado->status = $novoEstado;

            if ($novoEstado === 'cancelado') {
                $bloqueado->motivo_cancelamento = $motivoCancelamento;

                if ($bloqueado->jaTemSinalRegistado()) {
                    Log::warning('Pedido cancelado com sinal já recebido — a devolução ou regularização tem de ser tratada manualmente.', [
                        'pedido_id' => $bloqueado->id,
                        'empresa_id' => $bloqueado->empresa_id,
                        'recibo_sinal_id' => $bloqueado->recibo_sinal_id,
                    ]);
                }
            }

            $bloqueado->save();

            // Mantém a instância que o controller entregou sincronizada,
            // mas o evento usa os dados relidos e gravados sob lock.
            $pedido->setRawAttributes($bloqueado->getAttributes(), true);

            Log::info('Pedido mudou de estado', [
                'pedido_id' => $bloqueado->id,
                'empresa_id' => $bloqueado->empresa_id,
                'de' => $estadoAnterior,
                'para' => $novoEstado,
            ]);

            event(new PedidoMudouEstado($pedido, $estadoAnterior));

            return $pedido;
        });
    }

    /** @return array<int, string> Estados para os quais este pedido pode avançar agora — usado para desenhar os botões de ação na view. */
    public function proximosEstadosPossiveis(Pedido $pedido): array
    {
        return self::TRANSICOES_PERMITIDAS[$pedido->status] ?? [];
    }
}
