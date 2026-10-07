<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use RuntimeException;

/**
 * Identifiants incorrects : le message ne précise jamais lequel des deux est faux (flowchart 2A).
 */
final class InvalidCredentials extends RuntimeException {}
