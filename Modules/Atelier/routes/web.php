<?php

use Illuminate\Support\Facades\Route;
use Modules\Atelier\Http\Controllers\Admin\CampanhaController;
use Modules\Atelier\Http\Controllers\Admin\ClienteCrmController;
use Modules\Atelier\Http\Controllers\Admin\EquipaController;
use Modules\Atelier\Http\Controllers\Admin\MedidaController;
use Modules\Atelier\Http\Controllers\Admin\OrcamentoController;
use Modules\Atelier\Http\Controllers\Admin\PedidoController;
use Modules\Atelier\Http\Controllers\Admin\PerfilController;
use Modules\Atelier\Http\Controllers\Admin\PortfolioController;
use Modules\Atelier\Http\Controllers\Admin\ProvaController;
use Modules\Atelier\Http\Controllers\Publico\LandingController;
use Modules\Atelier\Http\Controllers\Publico\PortalController;

/*
|--------------------------------------------------------------------------
| Área administrativa (autenticada)
|--------------------------------------------------------------------------
| Mesmo grupo de middleware que qualquer rota de negócio do projeto — ver
| Modules/Faturacao/routes/web.php para o precedente exato.
*/
Route::middleware(['auth', 'tenant', 'subscricao.ativa', 'servico:atelier'])
    ->prefix('atelier')
    ->name('atelier.')
    ->group(function () {
        Route::get('pedidos', [PedidoController::class, 'index'])->name('pedidos.index');
        Route::get('pedidos/criar', [PedidoController::class, 'criar'])->name('pedidos.criar');
        Route::post('pedidos', [PedidoController::class, 'guardar'])->name('pedidos.guardar');
        Route::get('pedidos/{pedido}', [PedidoController::class, 'mostrar'])->whereNumber('pedido')->name('pedidos.mostrar');
        Route::put('pedidos/{pedido}', [PedidoController::class, 'atualizar'])->whereNumber('pedido')->name('pedidos.atualizar');
        Route::post('pedidos/{pedido}/estado', [PedidoController::class, 'avancarEstado'])->whereNumber('pedido')->name('pedidos.avancar-estado');
        Route::post('pedidos/{pedido}/sinal', [PedidoController::class, 'registarSinal'])->whereNumber('pedido')->name('pedidos.registar-sinal');
        Route::post('pedidos/{pedido}/fatura-final', [PedidoController::class, 'gerarFaturaFinal'])->whereNumber('pedido')->name('pedidos.gerar-fatura-final');
        Route::post('pedidos/{pedido}/pagamento-final', [PedidoController::class, 'registarPagamentoFinal'])->whereNumber('pedido')->name('pedidos.registar-pagamento-final');

        Route::post('pedidos/{pedido}/provas', [ProvaController::class, 'guardar'])->whereNumber('pedido')->name('provas.guardar');
        Route::put('provas/{prova}', [ProvaController::class, 'atualizarEstado'])->whereNumber('prova')->name('provas.atualizar-estado');

        Route::get('clientes/{cliente}/medidas', [MedidaController::class, 'historico'])->whereNumber('cliente')->name('medidas.historico');
        Route::get('clientes/{cliente}/medidas/criar', [MedidaController::class, 'criar'])->whereNumber('cliente')->name('medidas.criar');
        Route::post('medidas', [MedidaController::class, 'guardar'])->name('medidas.guardar');

        Route::get('clientes/{cliente}/crm', [ClienteCrmController::class, 'editar'])->whereNumber('cliente')->name('crm.editar');
        Route::put('clientes/{cliente}/crm', [ClienteCrmController::class, 'atualizar'])->whereNumber('cliente')->name('crm.atualizar');

        Route::get('equipa', [EquipaController::class, 'index'])->name('equipa.index');
        Route::post('equipa/atribuir', [EquipaController::class, 'atribuir'])->name('equipa.atribuir');
        Route::post('equipa/remover', [EquipaController::class, 'remover'])->name('equipa.remover');

        Route::get('perfil', [PerfilController::class, 'editar'])->name('perfil.editar');
        Route::put('perfil', [PerfilController::class, 'atualizar'])->name('perfil.atualizar');

        Route::get('portfolio', [PortfolioController::class, 'index'])->name('portfolio.index');
        Route::post('portfolio', [PortfolioController::class, 'guardar'])->name('portfolio.guardar');
        Route::delete('portfolio/{portfolioItem}', [PortfolioController::class, 'destruir'])->whereNumber('portfolioItem')->name('portfolio.destruir');

        Route::get('campanhas', [CampanhaController::class, 'index'])->name('campanhas.index');
        Route::get('campanhas/criar', [CampanhaController::class, 'criar'])->name('campanhas.criar');
        Route::post('campanhas', [CampanhaController::class, 'guardar'])->name('campanhas.guardar');
        Route::get('campanhas/{campanha}', [CampanhaController::class, 'mostrar'])->whereNumber('campanha')->name('campanhas.mostrar');
        Route::post('campanhas/{campanha}/enviar', [CampanhaController::class, 'enviarAgora'])->whereNumber('campanha')->name('campanhas.enviar-agora');

        Route::get('orcamentos', [OrcamentoController::class, 'index'])->name('orcamentos.index');
        Route::put('orcamentos/{pedidoOrcamento}', [OrcamentoController::class, 'atualizarEstado'])->whereNumber('pedidoOrcamento')->name('orcamentos.atualizar-estado');
    });

/*
|--------------------------------------------------------------------------
| Portal do cliente (requisito E) — SEM 'auth'/'tenant'. O acesso é a
| assinatura da própria URL (middleware nativo 'signed'); ver
| PortalLinkService e o aviso no README sobre as limitações deste modelo.
|--------------------------------------------------------------------------
*/
Route::middleware(['throttle:60,1', 'signed'])
    ->prefix('portal/atelier')
    ->name('atelier.portal.')
    ->group(function () {
        Route::get('{cliente}', [PortalController::class, 'mostrar'])->whereNumber('cliente')->name('mostrar');
        Route::match(['get', 'post'], '{cliente}/provas/{prova}/reagendar', [PortalController::class, 'reagendar'])
            ->whereNumber(['cliente', 'prova'])
            ->name('provas.reagendar');
    });

/*
|--------------------------------------------------------------------------
| Landing page institucional + portfólio (requisito F) — pública, sem
| assinatura (é suposto ser indexável/partilhável livremente). O envio do
| formulário de orçamento é limitado por 'throttle' para reduzir spam.
|--------------------------------------------------------------------------
*/
Route::prefix('loja')
    ->middleware('servico.empresa:atelier')
    ->name('atelier.landing.')
    ->group(function () {
        Route::get('{empresa:slug}', [LandingController::class, 'mostrar'])->name('mostrar');
        Route::post('{empresa:slug}/orcamento', [LandingController::class, 'solicitarOrcamento'])
            ->middleware('throttle:10,1')
            ->name('solicitar-orcamento');
    });
