<?php

declare(strict_types=1);

/**
 * Livewire : seule la réception des fichiers est réglée ici (le reste suit les valeurs par défaut du paquet).
 * Les PDF juridiques du Super Admin peuvent peser 20 Mo (docs/35) ; la limite par défaut de Livewire est de 12 Mo.
 */
return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        'rules' => ['required', 'file', 'max:20480'],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => ['png', 'gif', 'bmp', 'svg', 'wav', 'mp4', 'mov', 'avi', 'wmv', 'mp3', 'm4a', 'jpg', 'jpeg', 'mpga', 'webp', 'wma'],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
