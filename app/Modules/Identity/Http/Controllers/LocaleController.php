<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sélecteur de langue FR / NL / EN. Mémorisé en session, et dans le profil si l'utilisateur est connecté.
 */
final class LocaleController
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'string', Rule::in(config('rendivoo.locales'))]]);

        $request->session()->put('locale', $data['locale']);

        if ($request->user() !== null) {
            $request->user()->forceFill(['locale' => $data['locale']])->save();
        }

        return back();
    }
}
