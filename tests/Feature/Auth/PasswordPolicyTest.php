<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Rules\StrongPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Politique de mot de passe (décision 18).
 */
function passwordErrors(string $password, ?User $user = null, array $data = []): array
{
    return Validator::make(['password' => $password, ...$data], ['password' => [new StrongPassword($user)]])->errors()->get('password');
}

it('accepte un mot de passe conforme', function (): void {
    expect(passwordErrors('Sol1de-Phrase-Pour-Test!'))->toBe([]);
});

it('refuse ce qui ne respecte pas la composition', function (string $password): void {
    expect(passwordErrors($password))->not->toBe([]);
})->with(['trop court' => 'Ab1!', 'sans majuscule' => 'sol1de-phrase-pour-test!', 'sans minuscule' => 'SOL1DE-PHRASE-POUR-TEST!', 'sans chiffre' => 'Solide-Phrase-Pour-Test!', 'sans caractere special' => 'Sol1dePhrasePourTest']);

it('refuse les mots de passe évidents, même décorés', function (string $password): void {
    expect(passwordErrors($password))->toBe([__('ui.password.obvious')]);
})->with(['Password123!x', 'P@ssw0rd-2026-xx', 'Admin2026-Secure!', 'Azerty-Qwerty-12!', 'N0va-Chatbot-2026!']);

it('refuse la partie locale de l\'e-mail', function (): void {
    expect(passwordErrors('Jeanne.Dupont-2026!', data: ['email' => 'jeanne.dupont@example.com']))->toBe([__('ui.password.obvious')]);
});

it('interdit la réutilisation des 5 derniers mots de passe, le mot de passe actuel compris', function (): void {
    ['user' => $user] = $this->activatedAccount();
    $passwords = ['Premier-Mdp-Solide1!', 'Second-Mdp-Solide2!', 'Tiers-Mdp-Solide3!', 'Quart-Mdp-Solide4!', 'Cinq-Mdp-Solide5!'];

    foreach ($passwords as $password) {
        $user->forceFill(['password' => $password])->save();
    }

    expect(DB::table('password_histories')->where('user_id', $user->id)->count())->toBe(5);

    foreach ($passwords as $password) {
        expect(passwordErrors($password, $user->refresh()))->toBe([__('ui.password.reused', ['count' => 5])]);
    }

    $user->forceFill(['password' => 'Sixieme-Mdp-Solide6!'])->save();

    expect(DB::table('password_histories')->where('user_id', $user->id)->count())->toBe(5)
        ->and(passwordErrors($passwords[0], $user->refresh()))->toBe([])
        ->and(passwordErrors($passwords[1], $user))->toBe([__('ui.password.reused', ['count' => 5])]);
});

it('mémorise la date du dernier changement', function (): void {
    ['user' => $user] = $this->activatedAccount();
    $this->travel(2)->days();

    $user->forceFill(['password' => 'Nouveau-Mdp-Solide7!'])->save();

    expect($user->refresh()->password_changed_at->isToday())->toBeTrue()
        ->and($user->password_changed_at->gt($user->created_at))->toBeTrue();
});

it('refuse à la réinitialisation le mot de passe déjà utilisé', function (): void {
    ['user' => $user] = $this->activatedAccount();
    $user->forceFill(['password' => 'Ancien-Mdp-Solide8!'])->save();
    $token = Password::createToken($user);

    $this->post('/reinitialiser-mot-de-passe', ['email' => $user->email, 'token' => $token, 'password' => 'Ancien-Mdp-Solide8!', 'password_confirmation' => 'Ancien-Mdp-Solide8!'])
        ->assertSessionHasErrors('password');
});
