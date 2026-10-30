<?php

namespace Modules\Atelier\Services\Notificacoes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Atelier\Exceptions\NotificacaoException;
use Modules\Atelier\Services\Notificacoes\Contracts\CanalNotificacaoInterface;
use Modules\Atelier\Support\TelefoneAngola;

/**
 * Dois fornecedores, escolhidos por ATELIER_WHATSAPP_DRIVER:
 *
 *  - 'zapi'  — Z-API: POST {base}/instances/{instância}/token/{token}/send-text,
 *              cabeçalho Client-Token, corpo {phone, message} (só dígitos).
 *  - 'cloud' — WhatsApp Business Cloud API da Meta (o canal original).
 *
 * Sem driver definido usa 'zapi' se ATELIER_ZAPI_INSTANCE_ID estiver
 * preenchido, senão 'cloud'. O telefone é normalizado para o formato
 * internacional (244…) antes do envio — ver TelefoneAngola.
 *
 * Nenhum dos dois foi testado aqui contra uma conta real: confirma o envio
 * com um número teu antes de produção. Com a Cloud API da Meta, fora da
 * janela de 24h de conversa é preciso um "template" pré-aprovado (texto
 * livre é recusado) — a Z-API não tem essa restrição.
 */
class WhatsAppCanal implements CanalNotificacaoInterface
{
    private const TIMEOUT_SEGUNDOS = 15;

    public function identificador(): string
    {
        return 'whatsapp';
    }

    public function enviar(string $destino, string $mensagem): void
    {
        $numero = TelefoneAngola::normalizar($destino);

        if ($numero === null) {
            throw NotificacaoException::numeroInvalido('whatsapp');
        }

        match ($this->driver()) {
            'zapi' => $this->enviarViaZApi($numero, $mensagem),
            default => $this->enviarViaCloudApi($numero, $mensagem),
        };
    }

    protected function driver(): string
    {
        $driver = strtolower(trim((string) config('atelier.whatsapp_driver')));

        if ($driver !== '') {
            return $driver;
        }

        return config('atelier.whatsapp_zapi.instance_id') ? 'zapi' : 'cloud';
    }

    protected function enviarViaZApi(string $numero, string $mensagem): void
    {
        $baseUrl = rtrim((string) config('atelier.whatsapp_zapi.base_url', 'https://api.z-api.io'), '/');
        $instancia = config('atelier.whatsapp_zapi.instance_id');
        $token = config('atelier.whatsapp_zapi.token');
        $clientToken = config('atelier.whatsapp_zapi.client_token');

        if (! $instancia || ! $token) {
            throw NotificacaoException::naoConfigurado('whatsapp');
        }

        try {
            $pedido = Http::timeout(self::TIMEOUT_SEGUNDOS)
                ->acceptJson()
                ->retry(2, 500, fn ($excecao) => $excecao instanceof ConnectionException, throw: false);

            if ($clientToken) {
                $pedido = $pedido->withHeaders(['Client-Token' => $clientToken]);
            }

            $resposta = $pedido->post("{$baseUrl}/instances/{$instancia}/token/{$token}/send-text", [
                'phone' => $numero,
                'message' => $mensagem,
            ]);
        } catch (ConnectionException) {
            throw NotificacaoException::falhaNoEnvio('whatsapp', 'não foi possível contactar o fornecedor (Z-API).');
        }

        if ($resposta->failed()) {
            Log::error('Falha ao enviar WhatsApp (Z-API)', [
                'destino' => TelefoneAngola::mascarar($numero),
                'estado_http' => $resposta->status(),
                'resposta' => $resposta->body(),
            ]);

            throw NotificacaoException::falhaNoEnvio('whatsapp', 'o fornecedor recusou o envio (HTTP '.$resposta->status().').');
        }
    }

    protected function enviarViaCloudApi(string $numero, string $mensagem): void
    {
        $baseUrl = config('atelier.whatsapp.base_url');
        $token = config('atelier.whatsapp.token');
        $numeroId = config('atelier.whatsapp.numero_remetente_id');

        if (! $baseUrl || ! $token || ! $numeroId) {
            throw NotificacaoException::naoConfigurado('whatsapp');
        }

        try {
            $resposta = Http::withToken($token)
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->post("{$baseUrl}/{$numeroId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $numero,
                    'type' => 'text',
                    'text' => ['body' => $mensagem],
                ]);
        } catch (ConnectionException) {
            throw NotificacaoException::falhaNoEnvio('whatsapp', 'não foi possível contactar o fornecedor (Cloud API).');
        }

        if ($resposta->failed()) {
            Log::error('Falha ao enviar WhatsApp (Cloud API)', [
                'destino' => TelefoneAngola::mascarar($numero),
                'estado_http' => $resposta->status(),
                'resposta' => $resposta->body(),
            ]);

            throw NotificacaoException::falhaNoEnvio('whatsapp', $resposta->body());
        }
    }
}
