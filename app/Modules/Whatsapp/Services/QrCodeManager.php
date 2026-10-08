<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Services;

use App\Modules\Audit\Audit;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Services\OrganizationLogo;
use App\Modules\Whatsapp\Models\WhatsappQrCode;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Gestion des QR codes WhatsApp d'un établissement : liste, création, modification, retrait, avec trace dans le journal d'audit.
 * Le premier code est le code principal : il existe toujours (créé à la première visite) et ne peut pas être retiré.
 */
final class QrCodeManager
{
    public const MAX_CODES = 10;

    public function __construct(private readonly OrganizationLogo $logo) {}

    /**
     * Codes de l'établissement, le principal d'abord. Crée le code principal s'il n'existe pas encore.
     *
     * @return list<WhatsappQrCode>
     */
    public function all(Organization $organization): array
    {
        if (! WhatsappQrCode::query()->exists()) {
            WhatsappQrCode::query()->create([
                'org_id' => $organization->id,
                'name' => (string) __('ui.whatsapp_qr.default_name', [], $organization->locale),
                'message' => (string) __('ui.whatsapp_qr.default_message', ['business' => $organization->name], $organization->locale),
                'color' => QrRenderer::COLORS[0],
                'is_default' => true,
            ]);
        }

        return WhatsappQrCode::query()->orderByDesc('is_default')->orderBy('created_at')->get()->all();
    }

    /**
     * @param  array{name: string, message?: string|null, color: string, show_logo?: bool}  $data
     *
     * @throws InvalidArgumentException limit_reached ou no_logo
     */
    public function create(Organization $organization, array $data, string $actorId): WhatsappQrCode
    {
        if (WhatsappQrCode::query()->count() >= self::MAX_CODES) {
            throw new InvalidArgumentException('limit_reached');
        }

        $code = WhatsappQrCode::query()->create($this->fields($organization, $data) + ['org_id' => $organization->id, 'is_default' => false]);
        Audit::record('whatsapp.qr.created', 'user', $actorId, $organization->id, 'whatsapp_qr_codes', $code->id, ['name' => $code->name]);

        return $code;
    }

    /**
     * @param  array{name: string, message?: string|null, color: string, show_logo?: bool}  $data
     *
     * @throws InvalidArgumentException no_logo
     */
    public function update(Organization $organization, WhatsappQrCode $code, array $data, string $actorId): void
    {
        $before = $code->only(['name', 'message', 'color', 'show_logo']);
        $code->forceFill($this->fields($organization, $data))->save();
        $changes = array_filter(
            $code->only(['name', 'message', 'color', 'show_logo']),
            static fn (mixed $value, string $key): bool => $value !== $before[$key],
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changes !== []) {
            Audit::record('whatsapp.qr.updated', 'user', $actorId, $organization->id, 'whatsapp_qr_codes', $code->id, ['before' => array_intersect_key($before, $changes), 'after' => $changes]);
        }
    }

    /**
     * @throws InvalidArgumentException default_code
     */
    public function delete(Organization $organization, WhatsappQrCode $code, string $actorId): void
    {
        if ($code->is_default) {
            throw new InvalidArgumentException('default_code');
        }

        $code->delete();
        Audit::record('whatsapp.qr.deleted', 'user', $actorId, $organization->id, 'whatsapp_qr_codes', $code->id, ['name' => $code->name]);
    }

    /** Octets du logo de l'établissement s'il en a un et que le code l'utilise (sinon null). */
    public function logoFor(Organization $organization, WhatsappQrCode $code): ?string
    {
        if (! $code->show_logo || $organization->logo_updated_at === null) {
            return null;
        }

        $disk = Storage::disk('local');
        $path = $this->logo->path($organization->id, 256);

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    /**
     * @param  array{name: string, message?: string|null, color: string, show_logo?: bool}  $data
     * @return array{name: string, message: string|null, color: string, show_logo: bool}
     */
    private function fields(Organization $organization, array $data): array
    {
        $showLogo = (bool) ($data['show_logo'] ?? false);

        if ($showLogo && $organization->logo_updated_at === null) {
            throw new InvalidArgumentException('no_logo');
        }

        $message = trim((string) ($data['message'] ?? ''));

        return [
            'name' => trim($data['name']),
            'message' => $message === '' ? null : $message,
            'color' => $data['color'],
            'show_logo' => $showLogo,
        ];
    }
}
