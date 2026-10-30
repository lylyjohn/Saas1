<?php

namespace Modules\Atelier\Exceptions;

use Exception;

/**
 * Mesma ideia de AssinaturaFiscalException: uma exceção de domínio com
 * fábricas estáticas nomeadas, para que quem apanha a exceção (controller,
 * job) saiba exatamente que erro de negócio aconteceu sem parsear mensagens.
 */
class PedidoException extends Exception
{
    public static function transicaoInvalida(string $de, string $para): self
    {
        return new self("Não é possível mudar o pedido de \"{$de}\" para \"{$para}\".");
    }

    public static function sinalJaRegistado(): self
    {
        return new self('Este pedido já tem um sinal/adiantamento registado.');
    }

    public static function faturaFinalJaGerada(): self
    {
        return new self('Este pedido já tem uma fatura final gerada.');
    }

    public static function clienteSemDadosFaturacao(): self
    {
        return new self('Este cliente não tem NIF/dados suficientes para faturação — verifica a ficha do cliente no módulo Faturação.');
    }

    public static function valorInvalido(): self
    {
        return new self('O valor indicado tem de ser maior que zero.');
    }

    public static function pedidoCancelado(): self
    {
        return new self('Este pedido foi cancelado — não é possível registar documentos financeiros nele.');
    }

    public static function pedidoJaEntregue(): self
    {
        return new self('Este pedido já foi entregue — não é possível registar um sinal.');
    }

    public static function sinalNaoSuportadoComAgt(): self
    {
        return new self('Com a comunicação AGT ativa não é possível registar sinais (recibos de adiantamento). Gera e emite a fatura final e regista o pagamento do valor total.');
    }

    public static function semSaldoPorReceber(): self
    {
        return new self('Não há saldo por receber neste pedido — o sinal já cobre o total da fatura.');
    }

    public static function pagamentoPendente(): self
    {
        return new self('Este pedido ainda tem saldo por pagar — regista o pagamento antes de o marcar como entregue.');
    }

    public static function isencaoSemMotivo(): self
    {
        return new self('Este pedido tem IVA a 0% e falta o motivo de isenção (variável ATELIER_MOTIVO_ISENCAO) — configura-o antes de gerar a fatura.');
    }
}
