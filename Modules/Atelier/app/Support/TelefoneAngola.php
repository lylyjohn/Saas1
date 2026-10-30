<?php

namespace Modules\Atelier\Support;

/**
 * Normaliza telefones para o formato internacional só com dígitos
 * (ex.: 244923456789), que é o que a Z-API e a maioria dos gateways de SMS
 * esperam. Aceita "923 456 789", "+244 923-456-789", "00244923456789"…
 *
 * Um número de 9 dígitos a começar por 9 é tratado como móvel nacional
 * (Angola) e recebe o código do país. Devolve null se não parecer válido.
 */
final class TelefoneAngola
{
    public static function normalizar(?string $bruto, ?string $codigoPais = null): ?string
    {
        $codigoPais ??= (string) config('atelier.telefone_codigo_pais', '244');

        $digitos = preg_replace('/\D+/', '', (string) $bruto) ?? '';

        if (str_starts_with($digitos, '00')) {
            $digitos = substr($digitos, 2);
        }

        $tamanho = strlen($digitos);

        if ($tamanho === 9) {
            return $digitos[0] === '9' ? $codigoPais.$digitos : null;
        }

        if ($tamanho < 10 || $tamanho > 15 || $digitos[0] === '0') {
            return null;
        }

        if (str_starts_with($digitos, $codigoPais) && $tamanho !== strlen($codigoPais) + 9) {
            return null;
        }

        return $digitos;
    }

    /** Esconde o meio do número para os logs (24492****89). */
    public static function mascarar(string $numero): string
    {
        return strlen($numero) <= 6 ? '****' : substr($numero, 0, 5).'****'.substr($numero, -2);
    }
}
