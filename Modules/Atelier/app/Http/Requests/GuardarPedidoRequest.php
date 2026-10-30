<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Administrador', 'Secretária']) ?? false;
    }

    public function rules(): array
    {
        $empresaId = $this->user()->empresa_id;

        // before_or_equal:<outro campo> falha se o outro campo estiver vazio,
        // por isso a regra só entra quando há data prevista de entrega.
        $prazoInterno = ['nullable', 'date'];

        if ($this->filled('data_prevista_entrega')) {
            $prazoInterno[] = 'before_or_equal:data_prevista_entrega';
        }

        return [
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('empresa_id', $empresaId)],
            // A medida tem de ser do MESMO cliente do pedido (e da mesma empresa).
            'medida_id' => [
                'nullable',
                'integer',
                Rule::exists('atelier_medidas', 'id')
                    ->where('empresa_id', $empresaId)
                    ->where('cliente_id', $this->input('cliente_id')),
            ],
            'responsavel_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('empresa_id', $empresaId)],
            'tipo_servico' => ['required', 'string', 'in:confecao_medida,ajuste_conserto,restauracao,figurino'],
            'descricao' => ['required', 'string', 'max:2000'],
            'tecido_cor' => ['nullable', 'string', 'max:255'],
            'aviamentos_necessarios' => ['nullable', 'string', 'max:2000'],
            'data_prevista_entrega' => ['nullable', 'date', 'after_or_equal:today'],
            'prazo_interno' => $prazoInterno,
            // Limites abaixo das colunas decimal(14,2)/decimal(10,2): um valor
            // gigante dava erro 500 da base de dados em vez de erro de validação.
            'valor_orcamento' => ['required', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],
            'percentual_sinal' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'materiais' => ['nullable', 'array', 'max:50'],
            'materiais.*.descricao' => ['required_with:materiais', 'string', 'max:255'],
            'materiais.*.quantidade' => ['required_with:materiais', 'numeric', 'min:0.01', 'max:9999.99', 'decimal:0,2'],
            'materiais.*.valor_unitario' => ['required_with:materiais', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'fotos' => ['nullable', 'array', 'max:10'],
            'fotos.*' => ['nullable', 'image', 'max:4096'],
        ];
    }
}
