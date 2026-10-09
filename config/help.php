<?php

declare(strict_types=1);

/**
 * Centre d'aide (docs/22). Les articles sont des fichiers Markdown : resources/help/{fr,nl,en}/{catégorie}/{article}.md.
 * Ici : les catégories dans l'ordre d'affichage, avec le nom de leur icône ; leurs textes sont dans lang/*\/ui.php (help.categories).
 */
return [
    'categories' => [
        'getting-started' => 'rocket',
        'appointments' => 'calendar',
        'whatsapp' => 'chat',
        'customers' => 'users',
        'services-schedules' => 'clock',
        'billing' => 'card',
        'account-security' => 'shield',
        'troubleshooting' => 'wrench',
    ],

    /** Nombre d'articles « populaires » affichés sur la page d'accueil. */
    'popular' => 6,
];
