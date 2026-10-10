<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages;

use App\Modules\Admin\Filament\Resources\SupportTicketResource;
use App\Modules\Admin\Support\SupportAccess;
use Filament\Pages\Dashboard;

/**
 * Tableau de bord d'accueil. Un agent de support n'a pas accès aux comptes ni à la facturation : il arrive directement sur les tickets.
 */
class AdminDashboard extends Dashboard
{
    public function mount(): void
    {
        if (SupportAccess::isAgent()) {
            $this->redirect(SupportTicketResource::getUrl());
        }
    }
}
