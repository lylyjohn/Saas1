<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Atelier\Models\Pedido;
use Modules\Atelier\Models\PedidoMaterial;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

/**
 * O total do pedido tem de coincidir, ao cêntimo, com o da fatura final:
 * a AGT exige o IVA arredondado por excesso LINHA A LINHA (Dinheiro::calcularLinha).
 */
class PedidoTotalPorLinhaTest extends TestCase
{
    use RefreshDatabase;

    protected function novoPedido(string $nif, float $valor, int $taxa = 14): Pedido
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif]);
        app(TenantManager::class)->set($empresa->id);
        $cliente = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Cliente '.$nif]);

        return Pedido::create([
            'cliente_id' => $cliente->id,
            'tipo_servico' => 'confecao_medida',
            'descricao' => 'Teste',
            'valor_orcamento' => $valor,
            'taxa_iva_aplicada' => $taxa,
        ])->fresh();
    }

    public function test_o_iva_e_arredondado_por_excesso_como_na_fatura(): void
    {
        $pedido = $this->novoPedido('400000301', 100.01);

        // 100,01 × 14% = 14,0014 → IVA por excesso 14,01 → total 114,02
        // (um round() simples do total daria 114,01 e discordava da fatura).
        $this->assertSame(11402, $pedido->totalComIvaCentimos());
        $this->assertEquals(114.02, $pedido->valorTotalComIva());
    }

    public function test_os_materiais_entram_linha_a_linha_no_total(): void
    {
        $pedido = $this->novoPedido('400000302', 8000);

        foreach ([['Tecido', 3, 150], ['Botões', 10, 25]] as $indice => [$descricao, $quantidade, $valorUnitario]) {
            $material = new PedidoMaterial([
                'pedido_id' => $pedido->id,
                'descricao' => $descricao,
                'quantidade' => $quantidade,
                'valor_unitario' => $valorUnitario,
                'ordem' => $indice,
            ]);
            $material->calcularValores()->save();
        }

        // (8000 + 450 + 250) × 1,14 = 9918 — todas as linhas têm IVA exato.
        $this->assertSame(991800, $pedido->fresh()->totalComIvaCentimos());
    }

    public function test_o_saldo_em_falta_nunca_e_negativo(): void
    {
        $pedido = $this->novoPedido('400000303', 100); // total 114,00

        $pedido->valor_sinal = 50;
        $this->assertEquals(64.0, $pedido->saldoEmFalta());

        $pedido->valor_sinal = 500; // mais do que o total: nunca pode dar negativo
        $this->assertEquals(0.0, $pedido->saldoEmFalta());
    }
}
