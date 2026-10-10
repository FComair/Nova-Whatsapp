<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

/**
 * Index de la recherche dans les Réglages : une entrée par page, section ou étape, avec son titre, sa description et ses mots-clés
 * dans les trois langues. Les textes viennent des fichiers de traduction existants (aucune copie à maintenir) ; seuls les mots-clés
 * propres à la recherche sont dans `settings_search.keywords`.
 *
 * Le chemin affiché (Groupe → Page → Section) se déduit du menu : `group` et `page` désignent les mêmes entrées que le menu des
 * Réglages. Les droits d'accès sont ceux du menu (voir `resources/js/composables/settingsNav.ts` et routes/web.php).
 */
final class SettingsSearchIndex
{
    public const LOCALES = ['fr', 'nl', 'en'];

    private const SETUP_STEPS = [
        'business' => ['company', 'locations', 'hours'],
        'team' => ['services', 'practitioners', 'policies', 'calendar'],
        'messages' => ['whatsapp', 'templates'],
        'launch' => ['test_booking', 'first_booking', 'plan', 'two_factor'],
    ];

    /**
     * Entrées accessibles à un rôle.
     *
     * @return list<array{id: string, group: string, page: string, href: string, anchor: string|null, title: array<string, string>, description: array<string, string>|null, keywords: array<string, string>}>
     */
    public function forRole(?string $role): array
    {
        return array_values(array_map(
            fn (array $entry): array => $this->expand($entry),
            array_filter($this->definitions(), fn (array $entry): bool => $this->allowed($entry, $role)),
        ));
    }

    /**
     * @param  array{page: string, owner?: bool}  $entry
     */
    private function allowed(array $entry, ?string $role): bool
    {
        if ($role === null) {
            return false;
        }

        if (($entry['owner'] ?? false) && $role !== 'owner') {
            return false;
        }

        return match ($entry['page']) {
            'profile', 'security', 'legal' => true,
            'calendars' => $role !== 'receptionist',
            default => $role === 'owner', // Mise en route, WhatsApp, Mes données
        };
    }

    /**
     * @return list<array{id: string, group: string, page: string, href: string, anchor?: string|null, title: string, description?: string|null, owner?: bool}>
     */
    private function definitions(): array
    {
        $entries = [];
        $setupBase = '/reglages/demarrage';

        foreach (self::SETUP_STEPS as $category => $steps) {
            $page = "setup-{$category}";
            $entries[] = ['id' => $page, 'group' => 'setup', 'page' => $page, 'href' => "{$setupBase}/{$category}", 'title' => "setup.categories.{$category}.title", 'description' => "setup.categories.{$category}.intro"];

            foreach ($steps as $step) {
                $entries[] = ['id' => "setup-step-{$step}", 'group' => 'setup', 'page' => $page, 'href' => "{$setupBase}/{$category}", 'anchor' => "step-{$step}", 'title' => "setup.steps.{$step}.title", 'description' => "setup.steps.{$step}.body"];
            }
        }

        $entries[] = ['id' => 'price-import', 'group' => 'setup', 'page' => 'setup-team', 'href' => '/configuration/services/import', 'title' => 'price_import.title', 'description' => 'price_import.intro'];

        $profile = ['group' => 'account', 'page' => 'profile', 'href' => '/reglages/profil'];
        $entries[] = $profile + ['id' => 'profile', 'title' => 'account_profile.title', 'description' => 'account_profile.intro'];
        $entries[] = $profile + ['id' => 'profile-logo', 'anchor' => 'logo', 'title' => 'logo.title', 'description' => 'logo.hint', 'owner' => true];
        $entries[] = $profile + ['id' => 'profile-personal', 'anchor' => 'personal', 'title' => 'account_profile.personal_title', 'description' => 'account_profile.personal_intro'];
        $entries[] = $profile + ['id' => 'profile-language', 'anchor' => 'language', 'title' => 'account_profile.language', 'description' => 'account_profile.language_hint'];
        $entries[] = $profile + ['id' => 'profile-email', 'anchor' => 'email', 'title' => 'security.email_section'];
        $entries[] = $profile + ['id' => 'profile-phone', 'anchor' => 'phone', 'title' => 'security.phone_section'];
        $entries[] = $profile + ['id' => 'profile-company', 'anchor' => 'company', 'title' => 'account_profile.company_title', 'description' => 'account_profile.company_intro'];
        $entries[] = $profile + ['id' => 'profile-billing', 'anchor' => 'identity', 'title' => 'billing.identity_title', 'description' => 'billing.identity_hint', 'owner' => true];

        $calendars = ['group' => 'integrations', 'page' => 'calendars', 'href' => '/calendriers'];
        $entries[] = $calendars + ['id' => 'calendars', 'title' => 'calendars.title', 'description' => 'calendars.intro'];
        $entries[] = $calendars + ['id' => 'calendars-add', 'title' => 'calendars.add', 'description' => 'calendars.providers'];
        $entries[] = $calendars + ['id' => 'calendars-feed', 'title' => 'calendars.feed_title', 'description' => 'calendars.feed_help'];

        $whatsapp = ['group' => 'integrations', 'page' => 'whatsapp', 'href' => '/whatsapp'];
        $entries[] = $whatsapp + ['id' => 'whatsapp', 'title' => 'whatsapp.title', 'description' => 'whatsapp.intro'];
        $entries[] = $whatsapp + ['id' => 'whatsapp-connection', 'anchor' => 'connection', 'title' => 'whatsapp.connection_title', 'description' => 'whatsapp.help'];
        $entries[] = $whatsapp + ['id' => 'whatsapp-templates', 'anchor' => 'templates', 'title' => 'whatsapp.templates_title', 'description' => 'whatsapp.templates_intro'];

        $qr = ['group' => 'integrations', 'page' => 'whatsapp-qr', 'href' => '/qr-whatsapp'];
        $entries[] = $qr + ['id' => 'whatsapp-qr', 'title' => 'whatsapp_qr.title', 'description' => 'whatsapp_qr.intro'];
        $entries[] = $qr + ['id' => 'whatsapp-qr-link', 'anchor' => 'link', 'title' => 'whatsapp_qr.link_title', 'description' => 'whatsapp_qr.link_help'];
        $entries[] = $qr + ['id' => 'whatsapp-qr-message', 'anchor' => 'message', 'title' => 'whatsapp_qr.message_label', 'description' => 'whatsapp_qr.message_hint'];
        $entries[] = $qr + ['id' => 'whatsapp-qr-download', 'anchor' => 'download', 'title' => 'whatsapp_qr.download_png', 'description' => 'whatsapp_qr.stable'];

        $data = ['group' => 'privacy', 'page' => 'data', 'href' => '/compte/donnees'];
        $entries[] = $data + ['id' => 'data', 'title' => 'account_data.title', 'description' => 'account_data.intro'];
        $entries[] = $data + ['id' => 'data-export', 'anchor' => 'export', 'title' => 'account_data.export_title', 'description' => 'account_data.export_body'];
        $entries[] = ['id' => 'data-pause', 'group' => 'privacy', 'page' => 'data', 'href' => '/compte/pause', 'title' => 'pause.title', 'description' => 'pause.intro'];
        $entries[] = $data + ['id' => 'data-delete', 'anchor' => 'delete', 'title' => 'account_data.delete_title'];

        $entries[] = ['id' => 'legal', 'group' => 'privacy', 'page' => 'legal', 'href' => '/reglages/juridique', 'title' => 'legal.settings.title', 'description' => 'legal.settings.intro'];

        $security = ['group' => 'privacy', 'page' => 'security', 'href' => '/securite'];
        $entries[] = $security + ['id' => 'security', 'title' => 'security.title', 'description' => 'security.intro'];
        $entries[] = $security + ['id' => 'security-two-factor', 'anchor' => 'two-factor', 'title' => 'security.two_factor_section', 'description' => 'security.two_factor_body'];
        $entries[] = $security + ['id' => 'security-recovery', 'anchor' => 'two-factor', 'title' => 'security.recovery_title', 'description' => 'security.recovery_body'];
        $entries[] = $security + ['id' => 'security-password', 'anchor' => 'password', 'title' => 'security.password_section', 'description' => 'security.password_hint'];
        $entries[] = $security + ['id' => 'security-sessions', 'anchor' => 'sessions', 'title' => 'security.sessions_section', 'description' => 'security.sessions_body'];

        return $entries;
    }

    /**
     * @param  array{id: string, group: string, page: string, href: string, anchor?: string|null, title: string, description?: string|null}  $entry
     * @return array{id: string, group: string, page: string, href: string, anchor: string|null, title: array<string, string>, description: array<string, string>|null, keywords: array<string, string>}
     */
    private function expand(array $entry): array
    {
        $in = static fn (string $key): array => array_combine(
            self::LOCALES,
            array_map(static fn (string $locale): string => (string) __("ui.{$key}", [], $locale), self::LOCALES),
        );

        return [
            'id' => $entry['id'],
            'group' => $entry['group'],
            'page' => $entry['page'],
            'href' => $entry['href'],
            'anchor' => $entry['anchor'] ?? null,
            'title' => $in($entry['title']),
            'description' => isset($entry['description']) ? $in($entry['description']) : null,
            'keywords' => array_combine(
                self::LOCALES,
                array_map(static function (string $locale) use ($entry): string {
                    $key = "ui.settings_search.keywords.{$entry['id']}";
                    $text = (string) trans($key, [], $locale);

                    // Un mot-clé non traduit renvoie la clé elle-même : pas de mot-clé.
                    return $text === $key ? '' : $text;
                }, self::LOCALES),
            ),
        ];
    }
}
