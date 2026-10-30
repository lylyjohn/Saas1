<?php

return [

    'name' => 'Atelier',

    /*
    |--------------------------------------------------------------------------
    | Fluxo de estados do pedido
    |--------------------------------------------------------------------------
    |
    | Ordem "normal" de um pedido, usada pela timeline do portal do cliente
    | e pela PedidoStatusService para validar transições. 'cancelado' é
    | sempre alcançável a partir de qualquer estado não-final e por isso não
    | entra nesta sequência — ver PedidoStatusService::TRANSICOES_PERMITIDAS.
    |
    */
    'fluxo_estados' => [
        'pendente',
        'em_corte',
        'em_costura',
        'primeira_prova',
        'ajustes',
        'pronto_para_retirada',
        'entregue',
    ],

    'estados_finais' => ['entregue', 'cancelado'],

    /*
    |--------------------------------------------------------------------------
    | Notificações ao cliente
    |--------------------------------------------------------------------------
    |
    | Canais ativos, por ordem de tentativa. 'mail' funciona de imediato com
    | a configuração de e-mail do projeto. 'whatsapp' e 'sms' implementam
    | CanalNotificacaoInterface mas fazem chamadas HTTP ainda não testadas
    | contra uma conta real (ver README) — mantém-os fora desta lista até
    | preencheres as credenciais em baixo.
    |
    */
    'canais_notificacao' => explode(',', (string) env('ATELIER_CANAIS_NOTIFICACAO', 'mail')),

    'whatsapp' => [
        'base_url' => env('ATELIER_WHATSAPP_BASE_URL'),
        'token' => env('ATELIER_WHATSAPP_TOKEN'),
        'numero_remetente_id' => env('ATELIER_WHATSAPP_NUMERO_ID'),
    ],

    'sms' => [
        'base_url' => env('ATELIER_SMS_BASE_URL'),
        'api_key' => env('ATELIER_SMS_API_KEY'),
        'remetente' => env('ATELIER_SMS_REMETENTE', 'ATELIER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lembretes de prova
    |--------------------------------------------------------------------------
    | Quantas horas antes da data/hora agendada o lembrete deve ser
    | disparado. Ver Console\LembretesProvaCommand — corre a cada hora via
    | scheduler, tal como o Módulo de Subscrições já faz para verificar
    | subscrições expiradas (mesma razão: um Job com delay() não sobrevive
    | a um restart da fila; polling agendado sim).
    */
    'lembrete_prova_horas_antes' => (int) env('ATELIER_LEMBRETE_PROVA_HORAS', 24),

    /*
    |--------------------------------------------------------------------------
    | CRM — reativação de clientes inativos
    |--------------------------------------------------------------------------
    | Um cliente é "inativo" quando o pedido mais recente tem mais destes
    | meses. Verificado diariamente por VerificarEventosCrmCommand.
    */
    'meses_inatividade_reativacao' => (int) env('ATELIER_MESES_INATIVIDADE', 6),

    /*
    |--------------------------------------------------------------------------
    | Portal do cliente (link assinado)
    |--------------------------------------------------------------------------
    | Validade do link mágico gerado para o cliente aceder ao portal sem
    | conta/password — ver PortalLinkService e o aviso no README sobre como
    | isto NÃO é uma sessão autenticada tradicional.
    */
    'portal_link_validade_dias' => (int) env('ATELIER_PORTAL_LINK_DIAS', 7),

    /*
    |--------------------------------------------------------------------------
    | Categorias do portfólio público
    |--------------------------------------------------------------------------
    */
    'categorias_portfolio' => [
        'vestidos_noiva' => 'Vestidos de Noiva',
        'ternos' => 'Ternos',
        'roupas_casuais' => 'Roupas Casuais',
        'ajustes' => 'Ajustes',
        'outro' => 'Outro',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tipos de serviço do pedido
    |--------------------------------------------------------------------------
    */
    'tipos_servico' => [
        'confecao_medida' => 'Confeção sob medida',
        'ajuste_conserto' => 'Ajuste/Conserto',
        'restauracao' => 'Restauração',
        'figurino' => 'Figurino',
    ],

    /*
    |--------------------------------------------------------------------------
    | Papéis (roles) geridos por este módulo
    |--------------------------------------------------------------------------
    | Ver Services/EquipaService — criados sob demanda por empresa (o mesmo
    | padrão que RegistoEmpresaService já usa para 'Administrador', já que
    | spatie/laravel-permission com teams isola papéis por empresa_id).
    */
    'papel_secretaria' => 'Secretária',
    'papel_costureira' => 'Costureira',


    /*
    |--------------------------------------------------------------------------
    | Auditoria (out/2026)
    |--------------------------------------------------------------------------
    */

    // Marketing só para clientes com consentimento explícito (ClienteCrm::aceita_marketing = true).
    // false = recebem todos menos os que recusaram (comportamento antigo).
    'marketing_exigir_opt_in' => (bool) env('ATELIER_MARKETING_OPT_IN_EXPLICITO', true),

    // Pausa entre mensagens de uma campanha por WhatsApp/SMS (ms) — rajadas levam ao bloqueio do número.
    'campanha_pausa_ms' => (int) env('ATELIER_CAMPANHA_PAUSA_MS', 500),

    // true = um pedido só passa a "entregue" depois de o saldo estar pago.
    'exigir_pagamento_para_entrega' => (bool) env('ATELIER_EXIGIR_PAGAMENTO_PARA_ENTREGA', false),

    // Motivo de isenção (códigos de faturacao.motivos_isencao) usado quando o pedido tem IVA a 0%.
    'motivo_isencao' => env('ATELIER_MOTIVO_ISENCAO'),

    // Código do país acrescentado aos números nacionais de 9 dígitos (Angola = 244).
    'telefone_codigo_pais' => env('ATELIER_TELEFONE_CODIGO_PAIS', '244'),

    // WhatsApp: 'zapi' (Z-API) ou 'cloud' (Meta). Vazio = zapi se ATELIER_ZAPI_INSTANCE_ID existir, senão cloud.
    'whatsapp_driver' => env('ATELIER_WHATSAPP_DRIVER'),
    'whatsapp_zapi' => [
        'base_url' => env('ATELIER_ZAPI_BASE_URL', 'https://api.z-api.io'),
        'instance_id' => env('ATELIER_ZAPI_INSTANCE_ID'),
        'token' => env('ATELIER_ZAPI_TOKEN'),
        'client_token' => env('ATELIER_ZAPI_CLIENT_TOKEN'),
    ],
];
