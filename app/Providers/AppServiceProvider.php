<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\Preflight;
use App\Modules\Admin\Console\CreatePlatformAdmin;
use App\Modules\Admin\Console\DevAdminLink;
use App\Modules\Admin\Console\SetAdminIpAllowlist;
use App\Modules\Admin\Console\WatchSupportSla;
use App\Modules\Api\Console\CreateApiClient;
use App\Modules\Billing\Console\RunBilling;
use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Billing\Support\FakeGateway;
use App\Modules\Booking\Console\ReleaseExpiredHolds;
use App\Modules\Booking\Console\SendReminders;
use App\Modules\Calendars\Console\SyncCalendars;
use App\Modules\Calendars\Contracts\HostResolver;
use App\Modules\Calendars\Support\DnsHostResolver;
use App\Modules\Catalog\Console\PurgePriceListFiles;
use App\Modules\Catalog\Contracts\PriceListReader;
use App\Modules\Catalog\Support\NullPriceListReader;
use App\Modules\Catalog\Support\OpenAiPriceListReader;
use App\Modules\Catalog\Support\SamplePriceListReader;
use App\Modules\Conversation\Contracts\LanguageModel;
use App\Modules\Conversation\Support\NullModel;
use App\Modules\Conversation\Support\OpenAiModel;
use App\Modules\Help\Console\ImportHelpFiles;
use App\Modules\Help\Support\HelpCatalog;
use App\Modules\Identity\Contracts\PhoneCodeSender;
use App\Modules\Identity\Support\LogPhoneCodeSender;
use App\Modules\Loyalty\Console\MaintainLoyalty;
use App\Modules\Privacy\Console\ProcessDeletions;
use App\Modules\Tenancy\Console\EndPauses;
use App\Modules\Tenancy\Support\CurrentTenant;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Whatsapp\Console\CheckWhatsappAccount;
use App\Modules\Whatsapp\Console\ConnectWhatsappAccount;
use App\Modules\Whatsapp\Console\SyncTemplates;
use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Support\FakeTransport;
use App\Modules\Whatsapp\Support\MetaTransport;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(CurrentTenant::class);
        $this->app->singleton(HelpCatalog::class, fn (): HelpCatalog => new HelpCatalog(resource_path('help')));

        $this->app->bind(HostResolver::class, DnsHostResolver::class);

        // Assistant de conversation : sans clé d'API, les menus suffisent (aucun appel externe).
        $this->app->bind(LanguageModel::class, fn (): LanguageModel => filled(config('conversation.ai.api_key')) ? new OpenAiModel : new NullModel);

        // Lecture des listes de prix (import du catalogue) : même service d'IA que l'assistant ; sans clé, l'import n'est pas proposé.
        $this->app->bind(PriceListReader::class, fn (): PriceListReader => match (true) {
            config('pricelist.driver') === 'fake' => new SamplePriceListReader,
            filled(config('conversation.ai.api_key')) => new OpenAiPriceListReader,
            default => new NullPriceListReader,
        });

        // Prestataire de paiement : 'fake' tant que le fondateur n'a pas choisi (aucun débit réel possible).
        $this->app->singleton(PaymentGateway::class, fn (): PaymentGateway => match (config('nova.billing.gateway')) {
            'fake' => new FakeGateway,
            default => throw new InvalidArgumentException('Prestataire de paiement inconnu : '.config('nova.billing.gateway')),
        });

        // Canal WhatsApp : 'meta' en production, 'fake' (aucun appel réseau) en développement et en test.
        $this->app->singleton(WhatsappTransport::class, fn (): WhatsappTransport => match (config('whatsapp.driver')) {
            'meta' => new MetaTransport,
            'fake' => new FakeTransport,
            default => throw new InvalidArgumentException('Pilote WhatsApp inconnu : '.config('whatsapp.driver')),
        });

        // Canal d'envoi du code téléphonique : `log` en développement. Le canal de production reste à décider.
        $this->app->bind(PhoneCodeSender::class, fn (): PhoneCodeSender => match (config('nova.phone_code_driver')) {
            'log' => new LogPhoneCodeSender,
            default => throw new InvalidArgumentException('Pilote d\'envoi de code inconnu : '.config('nova.phone_code_driver')),
        });
    }

    public function boot(): void
    {
        // Super Admin (Filament) : toutes les dates s'affichent en JJ/MM/AAAA, comme le reste de l'application.
        Table::configureUsing(fn (Table $table): Table => $table->defaultDateDisplayFormat('d/m/Y')->defaultDateTimeDisplayFormat('d/m/Y H:i:s'));
        Schema::configureUsing(fn (Schema $schema): Schema => $schema->defaultDateDisplayFormat('d/m/Y')->defaultDateTimeDisplayFormat('d/m/Y H:i:s'));

        // Super Admin : le texte des tableaux et des fiches commence par une majuscule (les formats propres à une colonne, comme
        // les dates ou les montants, passent après et ne sont pas touchés).
        $capitalised = fn (mixed $state): mixed => is_string($state) ? Str::ucfirst($state) : $state;
        TextColumn::configureUsing(fn (TextColumn $column): TextColumn => $column->formatStateUsing($capitalised));
        TextEntry::configureUsing(fn (TextEntry $entry): TextEntry => $entry->formatStateUsing($capitalised));

        // API interne : plafond par clé, et par adresse pour les clés inventées.
        RateLimiter::for('api-client', fn (Request $request): array => [
            Limit::perMinute(300)->by('key:'.($request->header('X-Nova-Key') ?: 'none')),
            Limit::perMinute(600)->by('ip:'.$request->ip()),
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([CreatePlatformAdmin::class, ReleaseExpiredHolds::class, SendReminders::class, SyncCalendars::class, ProcessDeletions::class, RunBilling::class, CreateApiClient::class, ConnectWhatsappAccount::class, SetAdminIpAllowlist::class, Preflight::class, EndPauses::class, SyncTemplates::class, CheckWhatsappAccount::class, PurgePriceListFiles::class, MaintainLoyalty::class, DevAdminLink::class, WatchSupportSla::class, ImportHelpFiles::class]);
        }
    }
}
