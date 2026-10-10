<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Exceptions\TeamRefused;
use App\Modules\Admin\Services\SupportTeam;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Page publique d'invitation / de réinitialisation du mot de passe d'un membre de l'équipe de support (docs/32 §2). Le lien contient
 * un jeton à usage unique ; le mot de passe suit la règle du Super Admin (16 caractères, lettres, chiffres, symboles). Le 2FA est
 * configuré à la première connexion au panel.
 */
final class SupportInvitationController
{
    public function show(string $admin, string $token, SupportTeam $team): View
    {
        $agent = $team->byToken($admin, $token);

        return view('admin.invitation', ['agent' => $agent, 'admin' => $admin, 'token' => $token]);
    }

    public function store(Request $request, string $admin, string $token, SupportTeam $team): RedirectResponse|View
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(16)->letters()->numbers()->symbols()],
        ]);

        try {
            $team->accept($admin, $token, (string) $data['password']);
        } catch (TeamRefused) {
            return view('admin.invitation', ['agent' => null, 'admin' => $admin, 'token' => $token]);
        }

        return redirect()->to(Filament::getPanel('admin')->getLoginUrl())->with('status', __('team.invitation.done'));
    }
}
