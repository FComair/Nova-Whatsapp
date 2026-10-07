<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Support;

use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use Illuminate\Support\Str;

/**
 * Canal simulé (développement et tests) : consigne les envois en mémoire, aucun appel réseau.
 */
final class FakeTransport implements WhatsappTransport
{
    /** @var list<array{account: string, to: string, text: string}> */
    public array $sent = [];

    /** @var list<array{account: string, to: string, template: string, language: string, parameters: list<string>}> */
    public array $templates = [];

    public ?string $failWith = null;

    /** Réponse simulée de la vérification du numéro ;
ull : numéro valide. */
    public ?string $failVerifyWith = null;

    /** @var list<TemplateInfo> Modèles connus de Meta (statut modifiable par les tests). */
    public array $library = [];

    /** @var list<array{account: string, name: string, language: string, body: string, examples: list<string>}> */
    public array $created = [];

    /** Statut que Meta donne aux modèles qu'on lui soumet. */
    public string $createdStatus = 'pending';

    public function sendText(WhatsappAccount $account, string $toE164, string $text): string
    {
        if ($this->failWith !== null) {
            throw new TransportFailed($this->failWith);
        }

        $this->sent[] = ['account' => $account->phone_number_id, 'to' => $toE164, 'text' => $text];

        return 'wamid.fake.'.Str::random(16);
    }

    public function sendTemplate(WhatsappAccount $account, string $toE164, string $template, string $language, array $parameters): string
    {
        if ($this->failWith !== null) {
            throw new TransportFailed($this->failWith);
        }

        $this->templates[] = ['account' => $account->phone_number_id, 'to' => $toE164, 'template' => $template, 'language' => $language, 'parameters' => $parameters];

        return 'wamid.fake.'.Str::random(16);
    }

    public function verifyNumber(WhatsappAccount $account): array
    {
        if ($this->failVerifyWith !== null) {
            throw new TransportFailed($this->failVerifyWith);
        }

        return ['display_phone' => '+32 470 12 34 56', 'verified_name' => 'Salon Test'];
    }

    public function createTemplate(WhatsappAccount $account, string $name, string $language, string $body, array $examples): TemplateInfo
    {
        if ($this->failWith !== null) {
            throw new TransportFailed($this->failWith);
        }

        $this->created[] = ['account' => $account->phone_number_id, 'name' => $name, 'language' => $language, 'body' => $body, 'examples' => $examples];
        $info = new TemplateInfo($name, $language, $this->createdStatus, null, 'tpl-'.count($this->created));
        $this->library[] = $info;

        return $info;
    }

    public function listTemplates(WhatsappAccount $account): array
    {
        if ($this->failWith !== null) {
            throw new TransportFailed($this->failWith);
        }

        return $this->library;
    }
}
