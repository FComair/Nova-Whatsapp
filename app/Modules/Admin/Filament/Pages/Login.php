<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Component;

/**
 * Connexion du Super Admin sans « Se souvenir de moi » : un compte à privilèges ne doit pas rester connecté
 * au-delà de la session (docs/02 §3). La valeur est forcée à `false` côté serveur, même si le client la modifie.
 */
class Login extends BaseLogin
{
    protected function getRememberFormComponent(): Component
    {
        return Hidden::make('remember')
            ->default(false)
            ->dehydrateStateUsing(fn (): bool => false);
    }
}
