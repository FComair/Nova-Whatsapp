<?php

declare(strict_types=1);

namespace App\Modules\Support\Exceptions;

use RuntimeException;

/**
 * Le ticket a été modifié par quelqu'un d'autre depuis son ouverture à l'écran (docs/29 §6) : rien n'est écrit, l'agent revoit l'état à jour.
 */
final class TicketChanged extends RuntimeException {}
