<?php

namespace Modules\Atelier\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Só o Administrador dispara envios em massa (mesma regra de GuardarCampanhaRequest). */
class EnviarCampanhaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Administrador') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
