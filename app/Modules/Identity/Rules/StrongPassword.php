<?php

declare(strict_types=1);

namespace App\Modules\Identity\Rules;

use App\Modules\Identity\Models\PasswordHistory;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

/**
 * Politique de mot de passe des clients (décision 18) : 12 caractères minimum, majuscule, minuscule, chiffre et caractère spécial ;
 * pas de mot de passe évident (liste, e-mail, nom de l'entreprise) ; pas de réutilisation des 5 derniers.
 */
final class StrongPassword implements DataAwareRule, ValidationRule
{
    /** Mots courants interdits, y compris quand ils ne sont que décorés (année, chiffres, symbole). */
    private const COMMON = [
        'password', 'motdepasse', 'wachtwoord', 'passw0rd', 'admin', 'administrateur', 'azerty', 'qwerty', 'qwertz', 'welcome', 'bienvenue',
        'letmein', 'iloveyou', 'monkey', 'dragon', 'soleil', 'nova', 'rendivoo', 'whatsapp', 'chatbot', 'changeme', 'secret', 'abc123', '123456', 'football',
    ];

    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  User|null  $user  Utilisateur existant (changement de mot de passe) : active l'interdiction de réutilisation.
     * @param  list<string>  $context  Mots propres au compte à refuser (nom de l'entreprise, etc.).
     */
    public function __construct(private readonly ?User $user = null, private readonly array $context = []) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('ui.password.weak'));

            return;
        }

        $composition = validator(['p' => $value], ['p' => Password::min((int) config('rendivoo.security.password_min_length'))->mixedCase()->numbers()->symbols()]);

        if ($composition->fails()) {
            $fail(__('ui.password.weak', ['min' => (int) config('rendivoo.security.password_min_length')]));

            return;
        }

        if ($this->isObvious($value)) {
            $fail(__('ui.password.obvious'));

            return;
        }

        if ($this->user instanceof User && PasswordHistory::reused($this->user, $value)) {
            $fail(__('ui.password.reused', ['count' => PasswordHistory::KEEP]));
        }
    }

    private function isObvious(string $password): bool
    {
        $squashed = $this->squash($password);

        $email = is_string($this->data['email'] ?? null) ? mb_strtolower($this->data['email']) : ($this->user instanceof User ? mb_strtolower($this->user->email) : '');
        $words = [...self::COMMON, ...array_map('mb_strtolower', $this->context)];

        if ($email !== '') {
            $words[] = explode('@', $email)[0];
        }

        foreach ($words as $word) {
            $word = $this->squash($word);

            if (mb_strlen($word) >= 4 && str_contains($squashed, $word)) {
                return true;
            }
        }

        return false;
    }

    /** Minuscules, sans chiffres ni symboles, « leet » défait (p@ssw0rd → password) pour repérer les mots décorés. */
    private function squash(string $text): string
    {
        $text = strtr(mb_strtolower($text), ['@' => 'a', '0' => 'o', '1' => 'i', '3' => 'e', '$' => 's', '5' => 's', '4' => 'a', '7' => 't']);

        return (string) preg_replace('/[^a-z]/', '', $text);
    }
}
