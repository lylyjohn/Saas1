<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarClienteCrmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['Administrador', 'Secretária']) ?? false;
    }

    public function rules(): array
    {
        return [
            'data_nascimento' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'aceita_marketing' => ['sometimes', 'boolean'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
