<?php

namespace Modules\Atelier\Tests\Feature;

use Modules\Atelier\Support\TelefoneAngola;
use Tests\TestCase;

class TelefoneAngolaTest extends TestCase
{
    public function test_normaliza_os_formatos_angolanos_mais_comuns(): void
    {
        foreach (['923456789', '923 456 789', '+244 923 456 789', '00244923456789', '244923456789', '(+244) 923-456-789'] as $bruto) {
            $this->assertSame('244923456789', TelefoneAngola::normalizar($bruto, '244'), "Falhou com: {$bruto}");
        }
    }

    public function test_aceita_numeros_internacionais_de_outros_paises(): void
    {
        $this->assertSame('351912345678', TelefoneAngola::normalizar('+351 912 345 678', '244'));
    }

    public function test_rejeita_numeros_invalidos(): void
    {
        foreach ([null, '', 'abc', '12345', '222123456', '2449234567', '0123456789'] as $invalido) {
            $this->assertNull(TelefoneAngola::normalizar($invalido, '244'), 'Devia ser inválido: '.var_export($invalido, true));
        }
    }

    public function test_mascara_o_meio_do_numero_para_os_logs(): void
    {
        $this->assertSame('24492****89', TelefoneAngola::mascarar('244923456789'));
    }
}
