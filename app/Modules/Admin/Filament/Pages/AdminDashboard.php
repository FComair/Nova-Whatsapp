<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages;

use App\Modules\Admin\Filament\Resources\FaqArticleResource;
use App\Modules\Admin\Filament\Resources\SupportTicketResource;
use App\Modules\Admin\Support\SupportAccess;
use Filament\Pages\Dashboard;

/**
 * Tableau de bord d'accueil. Un agent de support arrive directement sur les tickets, un éditeur de contenu sur les FAQ : ni l'un ni
 * l'autre n'a accès aux comptes ni à la facturation.
 */
class AdminDashboard extends Dashboard
{
    public function mount(): void
    {
        if (SupportAccess::isAgent()) {
            $this->redirect(SupportTicketResource::getUrl());
        } elseif (SupportAccess::isEditor()) {
            $this->redirect(FaqArticleResource::getUrl());
        }
    }
}
