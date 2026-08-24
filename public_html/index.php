<?php

declare(strict_types=1);

/**
 * Raiz pública. O widget de chat entra aqui na etapa 9 (ver ARQUITETURA.md §10);
 * por enquanto só encaminha para o painel.
 */

require_once __DIR__ . '/../app/bootstrap.php';

redirect('admin/');
