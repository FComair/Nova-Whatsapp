<?php

declare(strict_types=1);

namespace App\Modules\Staff\Services;

use RuntimeException;

/**
 * Invitation introuvable, expirée, déjà utilisée ou remplacée : erreur E13 (« Cette invitation a expiré. »).
 * Le même message est utilisé dans tous les cas, pour ne rien révéler sur les jetons.
 */
final class InvitationInvalid extends RuntimeException {}
