<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;

/**
 * Nom du client dans les conversations WhatsApp (docs/41) : le nom de la fiche d'abord, le nom de profil WhatsApp seulement en dernier recours.
 */
it('suit l\'ordre : nom d\'affichage, nom, prénom et nom, puis profil WhatsApp', function (array $attributes, ?string $contact, ?string $list): void {
    $customer = new Customer($attributes);

    expect($customer->contactName())->toBe($contact)->and($customer->listName())->toBe($list);
})->with([
    'nom d\'affichage prioritaire' => [['display_name' => 'Marie D.', 'name' => 'Marie Dupont', 'whatsapp_profile_name' => 'Sunshine'], 'Marie D.', 'Marie D.'],
    'nom de la fiche avant le profil' => [['name' => 'Marie Dupont', 'whatsapp_profile_name' => 'Sunshine'], 'Marie Dupont', 'Marie Dupont'],
    'prénom et nom de la fiche' => [['first_name' => 'Marie', 'last_name' => 'Dupont', 'whatsapp_profile_name' => 'Sunshine'], 'Marie Dupont', 'Marie Dupont'],
    'profil WhatsApp en dernier recours' => [['whatsapp_profile_name' => 'Sunshine'], 'Sunshine', null],
    'espaces seuls ignorés' => [['name' => '   ', 'whatsapp_profile_name' => ' Sunshine '], 'Sunshine', null],
    'aucun nom' => [[], null, null],
]);
