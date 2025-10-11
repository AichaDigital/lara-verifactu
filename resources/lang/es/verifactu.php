<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Verifactu Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used throughout the Verifactu package.
    |
    */

    'commands' => [
        'install' => [
            'welcome' => 'Instalando Lara Verifactu...',
            'publishing_config' => 'Publicando archivo de configuración...',
            'publishing_migrations' => 'Publicando migraciones...',
            'ask_migrations' => '¿Desea ejecutar las migraciones ahora?',
            'running_migrations' => 'Ejecutando migraciones...',
            'success' => '¡Lara Verifactu instalado correctamente!',
            'next_steps' => 'Próximos pasos:',
            'step_1' => '1. Configure sus variables de entorno en .env',
            'step_2' => '2. Configure su certificado AEAT',
            'step_3' => '3. Revise la configuración en config/verifactu.php',
        ],

        'send_pending' => [
            'starting' => 'Enviando facturas pendientes...',
            'processing' => 'Procesando :count factura(s)...',
            'success' => ':count factura(s) enviada(s) correctamente.',
            'partial_success' => ':success factura(s) enviada(s), :failed falló/fallaron.',
            'no_pending' => 'No hay facturas pendientes de enviar.',
        ],

        'retry_failed' => [
            'starting' => 'Reintentando facturas rechazadas...',
            'processing' => 'Procesando :count factura(s)...',
            'success' => ':count factura(s) reenviada(s) correctamente.',
            'no_failed' => 'No hay facturas rechazadas para reintentar.',
        ],

        'validate_chain' => [
            'starting' => 'Validando cadena de bloques...',
            'validating' => 'Validando :count registro(s)...',
            'valid' => '✓ Cadena válida. Todos los hashes son correctos.',
            'invalid' => '✗ Cadena inválida. Se encontraron :count error(es).',
            'errors_found' => 'Errores encontrados:',
        ],

        'sync' => [
            'starting' => 'Sincronizando con AEAT...',
            'checking' => 'Verificando :count registro(s)...',
            'updated' => ':count registro(s) actualizado(s).',
            'no_updates' => 'Todos los registros están sincronizados.',
        ],
    ],

    'status' => [
        'pending' => 'Pendiente de envío',
        'sent' => 'Enviado a AEAT',
        'accepted' => 'Aceptado por AEAT',
        'rejected' => 'Rechazado por AEAT',
        'error' => 'Error en procesamiento',
    ],

    'errors' => [
        'certificate_not_found' => 'Certificado no encontrado',
        'invalid_certificate' => 'Certificado inválido',
        'certificate_expired' => 'Certificado expirado',
        'connection_error' => 'Error de conexión con AEAT',
        'timeout' => 'Tiempo de espera agotado',
        'invalid_response' => 'Respuesta inválida de AEAT',
        'validation_failed' => 'Validación fallida',
        'hash_mismatch' => 'Los hashes no coinciden',
        'chain_broken' => 'Cadena de bloques rota',
    ],

    'messages' => [
        'registered' => 'Factura registrada correctamente',
        'sent' => 'Factura enviada a AEAT',
        'accepted' => 'Factura aceptada por AEAT',
        'rejected' => 'Factura rechazada por AEAT',
        'cancelled' => 'Registro cancelado',
        'retrying' => 'Reintentando envío...',
    ],

];
