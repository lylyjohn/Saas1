<?php

namespace Modules\Atelier\Support;

use Modules\Core\Models\Empresa;
use Modules\Core\Support\Servico;

/**
 * Os Jobs do Atelier correm para todas as empresas (dentro do bypass do
 * TenantManager). Esta regra impede que gastem mensagens (e dinheiro da
 * plataforma) com empresas que não contrataram o Atelier ou cuja
 * subscrição já não dá acesso.
 */
final class ElegibilidadeAutomacao
{
    public static function empresaPodeEnviar(Empresa $empresa): bool
    {
        return $empresa->temServico(Servico::Atelier) && $empresa->temAcesso();
    }
}
