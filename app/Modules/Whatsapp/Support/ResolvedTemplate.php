<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Support;

/**
 * Modèle de la bibliothèque prêt à l'envoi : nom chez Meta, langue retenue et paramètres dans l'ordre des variables du texte.
 */
final readonly class ResolvedTemplate
{
    /**
     * @param  list<string>  $parameters
     */
    public function __construct(public string $name, public string $language, public array $parameters, public string $category) {}
}
