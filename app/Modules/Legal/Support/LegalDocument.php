<?php

declare(strict_types=1);

namespace App\Modules\Legal\Support;

/**
 * Les trois documents juridiques : code interne (colonne `type` des acceptations) et adresse publique stable (/legal/{adresse}).
 * Les adresses ne changent jamais : elles figurent dans des e-mails, des factures et des messages du support.
 */
final class LegalDocument
{
    public const TERMS = 'terms';

    public const PRIVACY = 'privacy';

    public const DPA = 'dpa';

    /** @var array<string, string> code => adresse */
    public const SLUGS = [self::TERMS => 'terms-of-use', self::PRIVACY => 'privacy-policy', self::DPA => 'dpa'];

    /** Contextes d'acceptation enregistrés (colonne `source`). */
    public const SIGNUP = 'signup';

    public const REACCEPTANCE = 'reacceptance';

    public const SETTINGS = 'settings';

    /** Acceptation à la création du compte d'une personne invitée (praticien, assistant), dans la même opération que le compte. */
    public const INVITATION = 'invitation';

    /** Acceptation retrouvée par la commande de réparation à partir du journal d'audit (jamais saisie par la personne après coup). */
    public const INVITATION_RECOVERED = 'invitation_recovered';

    /**
     * Documents que doit accepter une personne selon son rôle : conditions et confidentialité pour tous, le DPA (qui lie l'entreprise à Rendivoo)
     * seulement pour le propriétaire.
     *
     * @return list<string>
     */
    public static function requiredFor(bool $owner): array
    {
        return $owner ? self::codes() : [self::TERMS, self::PRIVACY];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::SLUGS);
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_values(self::SLUGS);
    }

    public static function slug(string $code): string
    {
        return self::SLUGS[$code] ?? $code;
    }

    public static function code(string $slug): ?string
    {
        $found = array_search($slug, self::SLUGS, true);

        return $found === false ? null : (string) $found;
    }

    public static function url(string $code, ?string $version = null): string
    {
        return '/legal/'.self::slug($code).($version === null ? '' : '/versions/'.$version);
    }
}
