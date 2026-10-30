<?php

namespace Modules\Atelier\Services;

use Illuminate\Support\Collection;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Models\Pedido;
use Modules\Faturacao\Models\Cliente;

/**
 * Resolve "segmentos" de clientes para o CRM/Marketing (requisito D).
 * Consulta sempre a partir de Pedido/ClienteCrm (models do Atelier) e nunca
 * acrescenta relações a Modules\Faturacao\Models\Cliente — mantém o módulo
 * Faturacao intocado (ver ClienteCrm e a migration correspondente).
 *
 * Consentimento: por omissão (atelier.marketing_exigir_opt_in) só recebem
 * marketing os clientes com ClienteCrm::aceita_marketing = true. Com a opção
 * desligada, recebem todos menos os que recusaram explicitamente.
 */
class CrmService
{
    public function aniversariantesHoje(int $empresaId): Collection
    {
        $hoje = now();
        $ano = (int) $hoje->year;

        $clienteIds = ClienteCrm::query()
            ->where('empresa_id', $empresaId)
            ->where('aceita_marketing', true)
            ->whereNotNull('data_nascimento')
            ->where(function ($q) use ($ano) {
                $q->whereNull('aniversario_enviado_ano')->orWhere('aniversario_enviado_ano', '<', $ano);
            })
            ->where(function ($q) use ($hoje) {
                $q->where(function ($d) use ($hoje) {
                    $d->whereMonth('data_nascimento', $hoje->month)->whereDay('data_nascimento', $hoje->day);
                });

                // Quem nasceu a 29/02 festeja a 28/02 nos anos não bissextos.
                if (! $hoje->isLeapYear() && $hoje->month === 2 && $hoje->day === 28) {
                    $q->orWhere(function ($d) {
                        $d->whereMonth('data_nascimento', 2)->whereDay('data_nascimento', 29);
                    });
                }
            })
            ->pluck('cliente_id');

        return Cliente::whereIn('id', $clienteIds)->get();
    }

    /** Clientes com pelo menos um pedido, mas nenhum recente nem ainda em curso. */
    public function clientesInativos(int $empresaId, int $meses): Collection
    {
        $dataLimite = now()->subMonths($meses);

        $todosComPedido = Pedido::where('empresa_id', $empresaId)->pluck('cliente_id')->unique();

        // Um pedido ainda em curso conta como atividade, por antigo que seja.
        $comAtividade = Pedido::where('empresa_id', $empresaId)
            ->where(function ($q) use ($dataLimite) {
                $q->where('created_at', '>=', $dataLimite)
                    ->orWhereNotIn('status', (array) config('atelier.estados_finais'));
            })
            ->pluck('cliente_id')
            ->unique();

        $idsInativos = $todosComPedido->diff($comAtividade);

        return $this->filtrarOptIn(Cliente::whereIn('id', $idsInativos)->get());
    }

    public function resolverSegmento(Campanha $campanha): Collection
    {
        return match ($campanha->segmento) {
            'todos' => $this->filtrarOptIn(Cliente::where('empresa_id', $campanha->empresa_id)->get()),
            'aniversariantes_mes' => $this->filtrarOptIn($this->clientesComAniversarioNoMes($campanha->empresa_id)),
            'inativos' => $this->clientesInativos($campanha->empresa_id, (int) config('atelier.meses_inatividade_reativacao')),
            default => collect(),
        };
    }

    protected function clientesComAniversarioNoMes(int $empresaId): Collection
    {
        $clienteIds = ClienteCrm::query()
            ->where('empresa_id', $empresaId)
            ->whereNotNull('data_nascimento')
            ->whereMonth('data_nascimento', now()->month)
            ->pluck('cliente_id');

        return Cliente::whereIn('id', $clienteIds)->get();
    }

    protected function filtrarOptIn(Collection $clientes): Collection
    {
        if (config('atelier.marketing_exigir_opt_in', true)) {
            $aceitam = ClienteCrm::whereIn('cliente_id', $clientes->pluck('id'))
                ->where('aceita_marketing', true)
                ->pluck('cliente_id');

            return $clientes->whereIn('id', $aceitam)->values();
        }

        $recusaram = ClienteCrm::whereIn('cliente_id', $clientes->pluck('id'))
            ->where('aceita_marketing', false)
            ->pluck('cliente_id');

        return $clientes->whereNotIn('id', $recusaram)->values();
    }
}
