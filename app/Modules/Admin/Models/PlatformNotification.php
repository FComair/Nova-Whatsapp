<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use Illuminate\Notifications\DatabaseNotification;

/**
 * Notification affichée dans la cloche du panel Super Admin. Table réservée au rôle `nova_platform` : le rôle client n'y a aucun droit.
 */
final class PlatformNotification extends DatabaseNotification
{
    protected $connection = 'pgsql_platform';

    protected $table = 'platform_notifications';
}
