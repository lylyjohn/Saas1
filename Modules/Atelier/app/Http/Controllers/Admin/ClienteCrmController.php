<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\AtualizarClienteCrmRequest;
use Modules\Atelier\Models\ClienteCrm;
use Modules\Faturacao\Models\Cliente;

/**
 * Único sítio onde se regista a data de nascimento e o CONSENTIMENTO de
 * marketing do cliente (ClienteCrm). Sem isto, os aniversários automáticos
 * e as campanhas (com consentimento explícito) não têm a quem enviar.
 *
 * {cliente} chega como int, nunca tipado: o binding implícito corre antes
 * do middleware 'tenant' (mesmo padrão dos restantes controllers).
 */
class ClienteCrmController extends Controller
{
    public function editar(int $cliente): View
    {
        abort_unless(request()->user()?->hasAnyRole(['Administrador', 'Secretária']), 403);

        $cliente = Cliente::findOrFail($cliente);

        return view('atelier::crm.editar', [
            'cliente' => $cliente,
            'crm' => ClienteCrm::firstOrNew(['cliente_id' => $cliente->id]),
        ]);
    }

    public function atualizar(AtualizarClienteCrmRequest $request, int $cliente): RedirectResponse
    {
        $cliente = Cliente::findOrFail($cliente);
        $crm = ClienteCrm::firstOrNew(['cliente_id' => $cliente->id]);
        $aceita = $request->boolean('aceita_marketing');

        if ($aceita && ! $crm->aceita_marketing) {
            $crm->consentimento_registado_em = now();
        } elseif (! $aceita) {
            $crm->consentimento_registado_em = null;
        }

        $crm->data_nascimento = $request->validated('data_nascimento');
        $crm->aceita_marketing = $aceita;
        $crm->notas = $request->validated('notas');
        $crm->save();

        return redirect()->route('atelier.crm.editar', $cliente->id)
            ->with('sucesso', 'Dados do cliente guardados.');
    }
}
