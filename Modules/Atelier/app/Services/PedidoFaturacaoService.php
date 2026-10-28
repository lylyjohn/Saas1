<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Atelier\Exceptions\PedidoException;
use Modules\Atelier\Models\Pedido;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Fatura;
use Modules\Faturacao\Models\Recibo;
use Modules\Faturacao\Services\AssinaturaFiscalService;
use Modules\Faturacao\Services\FaturaService;
use Modules\Faturacao\Services\NumeracaoService;
use Modules\Faturacao\Services\ReciboService;
use Modules\Faturacao\Support\Dinheiro;
use Throwable;

/**
 * A ponte entre o Atelier e o motor fiscal do Faturacao.
 *
 * - O SINAL vira um Recibo com fatura_id nulo (uma proforma não é documento
 *   fiscal AGT, não entra na cadeia de numeração/hash). Com a comunicação
 *   AGT ativa o ReciboService não suporta adiantamentos — daí a recusa
 *   antecipada e com mensagem clara em registarSinal().
 * - A fatura final é sempre criada como RASCUNHO; a emissão fica no ecrã
 *   normal de faturas do módulo Faturacao.
 * - Toda a emissão de Recibos passa pelo ReciboService (caminho único).
 * - Cada operação relê o Pedido com lock: um duplo clique não emite dois
 *   recibos nem gera duas faturas finais.
 * - Nenhum documento financeiro é registado num pedido cancelado.
 */
class PedidoFaturacaoService
{
    public function __construct(
        protected NumeracaoService $numeracaoService,
        protected AssinaturaFiscalService $assinaturaFiscalService,
        protected FaturaService $faturaService,
        protected TenantManager $tenantManager,
        protected ?ReciboService $reciboService = null,
    ) {}

    /**
     * @param  string|int|float  $valor  em unidades monetárias (ex.: '3000.00')
     *
     * @throws PedidoException|Throwable
     */
    public function registarSinal(Pedido $pedido, string|int|float $valor, string $meioPagamento): Recibo
    {
        return DB::transaction(function () use ($pedido, $valor, $meioPagamento) {
            $original = $pedido;
            $pedido = $this->bloquear($pedido);

            if ($pedido->status === 'cancelado') {
                throw PedidoException::pedidoCancelado();
            }

            if ($pedido->status === 'entregue') {
                throw PedidoException::pedidoJaEntregue();
            }

            if ($pedido->jaTemSinalRegistado()) {
                throw PedidoException::sinalJaRegistado();
            }

            // O ReciboService recusa recibos de adiantamento com a AGT ativa:
            // falhar aqui dá a causa real em vez de um erro genérico.
            if (config('faturacao.agt.ativo')) {
                throw PedidoException::sinalNaoSuportadoComAgt();
            }

            try {
                $sinal = Dinheiro::centimos($valor);
            } catch (\InvalidArgumentException) {
                throw PedidoException::valorInvalido();
            }

            if ($sinal <= 0) {
                throw PedidoException::valorInvalido();
            }

            if ($sinal > $pedido->totalComIvaCentimos()) {
                throw new PedidoException('O sinal não pode exceder o valor total do pedido.');
            }

            $valorFormatado = Dinheiro::formatar($sinal);

            $recibo = $this->recibos()->emitir(
                (int) $pedido->empresa_id,
                (int) $pedido->cliente_id,
                $valorFormatado,
                $meioPagamento,
                null,
            );

            $pedido->update([
                'valor_sinal' => $valorFormatado,
                'recibo_sinal_id' => $recibo->id,
            ]);

            $original->setRawAttributes($pedido->getAttributes(), true);

            return $recibo;
        });
    }

    /**
     * Rascunho da fatura final: uma linha para o serviço/peça (taxa guardada
     * no pedido, nunca a "atual" da config) e uma por material cobrado.
     * Com IVA a 0% o Faturacao exige motivo de isenção: vem de
     * config('atelier.motivo_isencao').
     *
     * @throws PedidoException
     */
    public function gerarFaturaFinalRascunho(Pedido $pedido, ?string $observacoes = null): Fatura
    {
        return DB::transaction(function () use ($pedido, $observacoes) {
            $original = $pedido;
            $pedido = $this->bloquear($pedido);

            if ($pedido->status === 'cancelado') {
                throw PedidoException::pedidoCancelado();
            }

            if ($pedido->jaTemFaturaFinal()) {
                throw PedidoException::faturaFinalJaGerada();
            }

            $cliente = $pedido->cliente;

            if (! $cliente) {
                throw PedidoException::clienteSemDadosFaturacao();
            }

            $taxa = (float) $pedido->taxa_iva_aplicada;
            $motivoIsencao = null;

            if ($taxa <= 0) {
                $motivoIsencao = config('atelier.motivo_isencao') ?: null;

                if ($motivoIsencao === null) {
                    throw PedidoException::isencaoSemMotivo();
                }
            }

            $linhas = [
                $this->linha(
                    "{$pedido->tipoServicoRotulo()}: {$pedido->descricao}",
                    1,
                    (float) $pedido->valor_orcamento,
                    $taxa,
                    $motivoIsencao,
                ),
            ];

            foreach ($pedido->materiais as $material) {
                $linhas[] = $this->linha(
                    $material->descricao,
                    (float) $material->quantidade,
                    (float) $material->valor_unitario,
                    $taxa,
                    $motivoIsencao,
                );
            }

            $fatura = $this->faturaService->criarRascunho($pedido->empresa, $cliente, $linhas, $observacoes);

            $pedido->update(['fatura_id' => $fatura->id]);

            $original->setRawAttributes($pedido->getAttributes(), true);

            return $fatura;
        });
    }

    /**
     * Recebimento do saldo = valor_total da fatura JÁ EMITIDA menos o sinal.
     * Usa a fatura como fonte de verdade (evita diferenças de cêntimos).
     *
     * @throws PedidoException|Throwable
     */
    public function registarPagamentoFinal(Pedido $pedido, string $meioPagamento): Recibo
    {
        return DB::transaction(function () use ($pedido, $meioPagamento) {
            $original = $pedido;
            $pedido = $this->bloquear($pedido);

            if ($pedido->status === 'cancelado') {
                throw PedidoException::pedidoCancelado();
            }

            if (! $pedido->jaTemFaturaFinal()) {
                throw new PedidoException('É preciso gerar e emitir a fatura final antes de registar o pagamento do saldo.');
            }

            if ($pedido->recibo_saldo_final_id) {
                throw new PedidoException('O pagamento final deste pedido já foi registado.');
            }

            $fatura = Fatura::withoutGlobalScopes()
                ->where('empresa_id', $pedido->empresa_id)
                ->findOrFail($pedido->fatura_id);

            if (! $fatura->estaEmitido()) {
                throw new PedidoException('A fatura final ainda não foi emitida — emite-a no módulo Faturação antes de registar o pagamento.');
            }

            $saldo = Dinheiro::centimos($fatura->valor_total) - Dinheiro::centimos($pedido->valor_sinal);

            if ($saldo <= 0) {
                throw PedidoException::semSaldoPorReceber();
            }

            $recibo = $this->recibos()->emitir(
                (int) $pedido->empresa_id,
                (int) $pedido->cliente_id,
                Dinheiro::formatar($saldo),
                $meioPagamento,
                (int) $fatura->id,
            );

            $pedido->update(['recibo_saldo_final_id' => $recibo->id]);

            $original->setRawAttributes($pedido->getAttributes(), true);

            return $recibo;
        });
    }

    protected function bloquear(Pedido $pedido): Pedido
    {
        return Pedido::withoutGlobalScopes()
            ->where('empresa_id', $pedido->empresa_id)
            ->lockForUpdate()
            ->findOrFail($pedido->id);
    }

    protected function recibos(): ReciboService
    {
        return $this->reciboService ?? app(ReciboService::class);
    }

    /** @return array<string, mixed> */
    protected function linha(string $descricao, float|int $quantidade, float $preco, float $taxa, ?string $motivoIsencao): array
    {
        $linha = [
            'descricao' => $this->descricaoDaLinha($descricao),
            'quantidade' => $quantidade,
            'preco_unitario' => $preco,
            'taxa_iva' => $taxa,
        ];

        if ($motivoIsencao !== null) {
            $linha['motivo_isencao'] = $motivoIsencao;
        }

        return $linha;
    }

    /** Uma só linha de texto com no máximo 200 caracteres (a descrição do pedido pode ter 2000). */
    protected function descricaoDaLinha(string $texto): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);

        return Str::limit($texto, 197, '...');
    }
}
