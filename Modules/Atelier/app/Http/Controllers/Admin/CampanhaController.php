<?php

namespace Modules\Atelier\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Modules\Atelier\Http\Requests\EnviarCampanhaRequest;
use Modules\Atelier\Http\Requests\GuardarCampanhaRequest;
use Modules\Atelier\Jobs\DispararCampanhaJob;
use Modules\Atelier\Models\Campanha;

/**
 * IMPORTANTE: {campanha} chega como int, nunca como Campanha tipado (route
 * model binding implícito). O Laravel resolve o binding ANTES do
 * middleware 'tenant' correr — nessa altura ainda não há empresa
 * definida, e a TenantScope (fail-closed) faria o binding devolver sempre
 * 404. O findOrFail() dentro de cada método já corre com o tenant certo.
 * Mesmo padrão de Modules\Faturacao\Http\Controllers\FaturaController.
 *
 * Autorização: as campanhas são uma ferramenta de gestão (mensagens em
 * massa + lista de contactos dos clientes) — só o Administrador. Criar e
 * enviar validam-no nos FormRequests; as páginas de leitura aqui.
 */
class CampanhaController extends Controller
{
    public function index(): View
    {
        $this->autorizarGestao();

        return view('atelier::campanhas.index', [
            'campanhas' => Campanha::latest('id')->paginate(20),
        ]);
    }

    public function criar(): View
    {
        $this->autorizarGestao();

        return view('atelier::campanhas.criar');
    }

    public function guardar(GuardarCampanhaRequest $request): RedirectResponse
    {
        $campanha = Campanha::create([
            ...$request->validated(),
            'criada_por_id' => $request->user()->id,
            'estado' => $request->validated('agendada_para') ? 'agendada' : 'rascunho',
        ]);

        return redirect()->route('atelier.campanhas.mostrar', $campanha)->with('sucesso', 'Campanha criada.');
    }

    public function mostrar(int $campanha): View
    {
        $this->autorizarGestao();

        $campanha = Campanha::findOrFail($campanha);

        return view('atelier::campanhas.mostrar', [
            'campanha' => $campanha->load('envios.cliente'),
        ]);
    }

    public function enviarAgora(EnviarCampanhaRequest $request, int $campanha): RedirectResponse
    {
        $campanha = Campanha::findOrFail($campanha);

        if ($campanha->estado === 'cancelada') {
            return back()->with('erro', 'Esta campanha foi cancelada.');
        }

        if ($campanha->estado === 'enviada') {
            // Já foi processada: só volta a correr se houver envios que
            // falharam (os já enviados são saltados pelo job — ninguém
            // recebe a mesma mensagem duas vezes).
            if (! $campanha->envios()->where('estado', 'falhou')->exists()) {
                return back()->with('erro', 'Esta campanha já foi enviada.');
            }

            Campanha::query()
                ->whereKey($campanha->id)
                ->where('estado', 'enviada')
                ->update(['estado' => 'agendada', 'iniciada_em' => null]);
        } elseif ($this->envioEmCurso($campanha)) {
            return back()->with('erro', 'O envio desta campanha já está em curso.');
        }

        DispararCampanhaJob::dispatch($campanha->id);

        return back()->with('sucesso', 'Envio iniciado — os estados de entrega vão aparecer aqui à medida que forem processados.');
    }

    protected function autorizarGestao(): void
    {
        abort_unless(request()->user()?->hasRole('Administrador'), 403);
    }

    /** Há uma reivindicação recente (job a correr ou na fila). Uma antiga é tratada como abandonada. */
    protected function envioEmCurso(Campanha $campanha): bool
    {
        return $campanha->iniciada_em !== null
            && Carbon::parse($campanha->iniciada_em)->gt(now()->subHours(DispararCampanhaJob::VALIDADE_REIVINDICACAO_HORAS));
    }
}
