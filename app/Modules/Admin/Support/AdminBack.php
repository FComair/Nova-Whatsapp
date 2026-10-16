<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use Illuminate\Routing\Route;

/**
 * Bouton « Retour » des écrans du Super Admin : où il mène et comment il s'appelle, d'après la route de l'écran affiché.
 *
 *  - Écran de détail, de création ou de modification : retour à sa liste (« Retour aux clients », « Retour aux demandes »…).
 *  - Liste ou page de premier niveau : retour au tableau de bord. Le tableau de bord n'a pas de retour.
 *  - Les écrans « Documents juridiques » ont leur propre retour, plus précis (docs/35), et la connexion n'en a pas.
 * Le retour reste dans l'espace Super Admin : il n'est jamais construit à partir d'une adresse fournie par la requête.
 */
final class AdminBack
{
    private const PREFIX = 'filament.admin.';

    /** Routes sans bouton : tableau de bord, connexion, pages qui portent déjà leur retour, téléchargements. */
    private const NONE = ['pages.admin-dashboard', 'auth.', 'pages.legal-document', 'pages.legal-documents', 'legal.file', 'support.attachment'];

    /**
     * @return array{label: string, url: string}|null
     */
    public static function for(?Route $route): ?array
    {
        $name = $route?->getName();

        if ($name === null || ! str_starts_with($name, self::PREFIX)) {
            return null;
        }

        $name = substr($name, strlen(self::PREFIX));

        foreach (self::NONE as $skip) {
            if ($name === $skip || (str_ends_with($skip, '.') && str_starts_with($name, $skip)) || str_starts_with($name, 'pages.legal-document-')) {
                return null;
            }
        }

        $parent = match ($name) {
            'resources.organizations.view' => ['clients', 'resources.organizations.index', []],
            'resources.support-tickets.view' => ['tickets', 'resources.support-tickets.index', []],
            'resources.support-team.view' => ['support_team', 'resources.support-team.index', []],
            'resources.faq.create' => ['faq', 'resources.faq.index', []],
            'resources.faq.edit' => ['faq', 'resources.faq.index', []],
            'resources.faq.preview' => ['faq_editor', 'resources.faq.edit', ['record' => $route->parameter('record')]],
            'resources.plans.create' => ['plans', 'resources.plans.index', []],
            'resources.plans.edit' => ['plans', 'resources.plans.index', []],
            'resources.admins.view' => ['admins', 'resources.admins.index', []],
            'resources.admins.create' => ['admins', 'resources.admins.index', []],
            'resources.admins.edit' => ['admin_profile', 'resources.admins.view', ['record' => $route->parameter('record')]],
            'resources.legal-versions.index' => ['legal', 'pages.legal-documents', []],
            default => ['dashboard', 'pages.admin-dashboard', []],
        };

        return ['label' => __("admin.back.{$parent[0]}"), 'url' => route(self::PREFIX.$parent[1], $parent[2])];
    }
}
