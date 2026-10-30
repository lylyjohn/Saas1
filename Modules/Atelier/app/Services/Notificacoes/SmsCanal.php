<?php

namespace Modules\Atelier\Services\Notificacoes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Services\Notificacoes\Contracts\CanalNotificacaoInterface;
use Modules\Atelier\Support\TelefoneAngola;

/**
 * Genérico de propósito: o requisito não especifica qual gateway de SMS
 * angolano usar, ao contrário do pagamento (onde o README do Subscricoes
 * já tinha escolhido ProxyPay/Multicaixa). Assume um endpoint REST simples
 * (destino + mensagem + api_key) — troca o corpo do pedido pelo formato
 * exato do fornecedor escolhido antes de usar. O destino segue em formato
 * internacional só com dígitos (244923456789) — se o fornecedor exigir "+",
 * acrescenta-o aqui. Ver aviso equivalente em WhatsAppCanal.
 */
class SmsCanal implements CanalNotificacaoInterface
{
    public function identificador(): string
    {
        return 'sms';
    }

    public function enviar(string $destino, string $mensagem): void
    {
        $baseUrl = config('atelier.sms.base_url');
        $apiKey = config('atelier.sms.api_key');

        if (! $baseUrl || ! $apiKey) {
            throw NotificacaoException::naoConfigurado('sms');
        }

        $numero = TelefoneAngola::normalizar($destino);

        if ($numero === null) {
            throw NotificacaoException::numeroInvalido('sms');
        }

        try {
            $resposta = Http::withToken($apiKey)
                ->timeout(15)
                ->post($baseUrl, [
                    'remetente' => config('atelier.sms.remetente'),
                    'destino' => $numero,
                    'mensagem' => $mensagem,
                ]);
        } catch (ConnectionException) {
            throw NotificacaoException::falhaNoEnvio('sms', 'não foi possível contactar o fornecedor.');
        }

        if ($resposta->failed()) {
            Log::error('Falha ao enviar SMS', [
                'destino' => TelefoneAngola::mascarar($numero),
                'estado_http' => $resposta->status(),
                'resposta' => $resposta->body(),
            ]);

            throw NotificacaoException::falhaNoEnvio('sms', $resposta->body());
        }
    }
}
