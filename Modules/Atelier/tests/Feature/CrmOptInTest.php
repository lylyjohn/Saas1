<?php

namespace Modules\Atelier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Atelier\Models\Campanha;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Atelier\Services\CrmService;
use Modules\Core\Models\Empresa;
use Modules\Core\Services\TenantManager;
use Modules\Faturacao\Models\Cliente;
use Tests\TestCase;

class CrmOptInTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Campanha, 1: Cliente, 2: Cliente, 3: Cliente} */
    protected function cenario(string $nif): array
    {
        $empresa = Empresa::create(['nome_comercial' => 'Atelier '.$nif, 'nif' => $nif]);
        app(TenantManager::class)->set($empresa->id);

        $aceitou = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Aceitou']);
        $semRegisto = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Sem registo']);
        $recusou = Cliente::create(['empresa_id' => $empresa->id, 'nome' => 'Recusou']);

        ClienteCrm::create(['cliente_id' => $aceitou->id, 'aceita_marketing' => true]);
        ClienteCrm::create(['cliente_id' => $recusou->id, 'aceita_marketing' => false]);

        $campanha = (new Campanha)->forceFill(['empresa_id' => $empresa->id, 'segmento' => 'todos']);

        return [$campanha, $aceitou, $semRegisto, $recusou];
    }

    public function test_por_omissao_so_recebe_marketing_quem_deu_consentimento_explicito(): void
    {
        [$campanha, $aceitou] = $this->cenario('400000401');

        $ids = app(CrmService::class)->resolverSegmento($campanha)->pluck('id')->all();

        $this->assertSame([$aceitou->id], $ids);
    }

    public function test_com_a_exigencia_desligada_so_ficam_de_fora_os_que_recusaram(): void
    {
        config(['atelier.marketing_exigir_opt_in' => false]);

        [$campanha, $aceitou, $semRegisto] = $this->cenario('400000402');

        $ids = app(CrmService::class)->resolverSegmento($campanha)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$aceitou->id, $semRegisto->id], $ids);
    }
}
