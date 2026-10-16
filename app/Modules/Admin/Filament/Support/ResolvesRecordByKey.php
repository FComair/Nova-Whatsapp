<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Filament (5.10) relit l'enregistrement d'une page à chaque requête Livewire, en lisant l'attribut nommé par
 * getRecordRouteKeyName(). Les ressources bâties sur une requête jointe (ClientDirectory, FAQ) déclarent un nom qualifié
 * (« organizations.id ») pour éviter une colonne « id » ambiguë : ce nom n'est pas un attribut du modèle, la clé lue était
 * vide et chaque action de la page répondait 404. On relit donc l'enregistrement par la clé du modèle ; la requête de la
 * ressource (et son nom qualifié) reste celle utilisée pour le retrouver.
 */
trait ResolvesRecordByKey
{
    protected function resolveRecordFromLivewire(Model $model): Model
    {
        $record = $this->resolveRecord($model->getKey());

        abort_unless((string) $record->getKey() === (string) $model->getKey(), 404);

        return $record;
    }
}
