<?php
// Configuração da demo. Valores sensíveis vêm de variáveis de ambiente (nunca fixe segredo aqui).
declare(strict_types=1);

return [
    // Clínica FICTÍCIA. A página é sempre marcada como demonstração.
    'clinica'      => 'Esmalte',
    'clinica_desc' => 'Estúdio de odontologia',
    'endereco'     => 'Rua Exemplo, 000 · Centro · Cidade Demo/SP',
    'responsavel'  => 'Responsável técnico: Dra. Exemplo · CRO-SP 00000 (fictício)',

    // WhatsApp que recebe as confirmações (DDI+DDD+número, só dígitos).
    // Na demo é o da NEXTECH. Troque pelo da clínica.
    'whatsapp' => getenv('AGENDA_WHATSAPP') ?: '5517997565260',

    'db_path' => __DIR__ . '/../data/agenda.sqlite',

    // Regras da agenda.
    'slot_min'           => 30,   // granularidade da agenda, em minutos
    'antecedencia_min'   => 120,  // paciente só marca com 2 h de antecedência
    'janela_dias'        => 30,   // até quantos dias à frente aparece
    'max_ativos_por_tel' => 2,    // consultas futuras ativas por WhatsApp
    'reter_dias'         => 180,  // depois disso, dados do paciente são anonimizados

    // Anti-abuso.
    'login_max_tentativas' => 5,
    'login_janela_seg'     => 900,
    'agendar_max_por_ip'   => 5,
    'agendar_janela_seg'   => 3600,

    // Webhook opcional para o n8n (lembrete, planilha, etc.).
    // Só é usado se as DUAS variáveis existirem. O segredo assina o corpo com HMAC-SHA256.
    'webhook_url'     => getenv('AGENDA_WEBHOOK_URL') ?: '',
    'webhook_segredo' => getenv('AGENDA_WEBHOOK_SECRET') ?: '',
];
