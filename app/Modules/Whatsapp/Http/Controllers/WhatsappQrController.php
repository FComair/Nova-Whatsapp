<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Http\Controllers;

use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use App\Modules\Whatsapp\Http\Requests\QrCodeRequest;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Models\WhatsappQrCode;
use App\Modules\Whatsapp\Services\QrCodeManager;
use App\Modules\Whatsapp\Services\QrRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Réglages > QR code WhatsApp : codes imprimables qui ouvrent une conversation avec l'assistant de l'établissement.
 *
 * Le code contient un lien wa.me (numéro connecté + message pré-rempli) : rien n'est stocké côté image, tout est recalculé.
 * Propriétaire uniquement (imposé par les routes). L'isolation entre établissements est assurée par la RLS : un code d'un autre
 * établissement est introuvable.
 */
final class WhatsappQrController
{
    public function __construct(
        private readonly CurrentTenant $current,
        private readonly QrCodeManager $codes,
        private readonly QrRenderer $renderer,
    ) {}

    public function show(Request $request): Response
    {
        $organization = $this->organization();
        $account = WhatsappAccount::query()->orderBy('created_at')->first();
        $state = $this->state($account);

        $codes = $state === 'ready' ? $this->codes->all($organization) : [];
        $asked = $request->query('code');
        $ids = array_map(static fn (WhatsappQrCode $code): string => $code->id, $codes);
        $selected = is_string($asked) && in_array($asked, $ids, true) ? $asked : ($ids[0] ?? null);

        return Inertia::render('Account/WhatsappQr', [
            'state' => $state,
            'phone' => $account?->display_phone,
            'hasLogo' => $organization->logo_updated_at !== null,
            'colors' => QrRenderer::COLORS,
            'maxCodes' => QrCodeManager::MAX_CODES,
            'selected' => $selected,
            'codes' => array_map(fn (WhatsappQrCode $code): array => $this->present($organization, $account, $code), $codes),
        ]);
    }

    public function store(QrCodeRequest $request): RedirectResponse
    {
        $this->readyOrAbort();

        try {
            $code = $this->codes->create($this->organization(), $request->validated(), (string) $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors($this->error($e));
        }

        return to_route('whatsapp.qr.show', ['code' => $code->id])->with('status', __('ui.whatsapp_qr.created'));
    }

    public function update(QrCodeRequest $request, string $code): RedirectResponse
    {
        $this->readyOrAbort();

        try {
            $this->codes->update($this->organization(), $this->find($code), $request->validated(), (string) $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors($this->error($e));
        }

        return back()->with('status', __('ui.whatsapp_qr.saved'));
    }

    public function destroy(Request $request, string $code): RedirectResponse
    {
        $this->readyOrAbort();

        try {
            $this->codes->delete($this->organization(), $this->find($code), (string) $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors($this->error($e));
        }

        return to_route('whatsapp.qr.show')->with('status', __('ui.whatsapp_qr.deleted'));
    }

    /** Image du code : `svg` (aperçu et impression vectorielle), `png` (1000 × 1000 px) ou `pdf` (affiche A4 ou A5). */
    public function image(Request $request, string $code, string $format): HttpResponse
    {
        $organization = $this->organization();
        $account = $this->readyOrAbort();
        $qr = $this->find($code);
        $link = QrRenderer::link($account->display_phone, $qr->message) ?? abort(404);
        $logo = $this->codes->logoFor($organization, $qr);
        $slug = Str::slug($qr->name) ?: 'qr';

        [$body, $type] = match ($format) {
            'svg' => [$this->renderer->svg($link, $qr->color, $logo), 'image/svg+xml'],
            'png' => [$this->renderer->png($link, $qr->color, $logo), 'image/png'],
            'pdf' => [
                $this->renderer->pdf(
                    $link,
                    $qr->color,
                    $organization->name,
                    (string) __('ui.whatsapp_qr.poster_caption', [], $organization->locale),
                    $request->query('size') === 'a5' ? 'a5' : 'a4',
                ),
                'application/pdf',
            ],
            default => abort(404),
        };

        $headers = ['Content-Type' => $type, 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff'];

        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="qr-whatsapp-'.$slug.'.'.$format.'"';
        } elseif ($format === 'svg') {
            // Un SVG affiché directement ne doit jamais pouvoir exécuter de script.
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src data:; style-src 'unsafe-inline'";
        }

        return response($body, 200, $headers);
    }

    /** not_connected, paused (numéro désactivé), no_number (numéro illisible) ou ready. */
    private function state(?WhatsappAccount $account): string
    {
        return match (true) {
            $account === null => 'not_connected',
            $account->status !== 'active' => 'paused',
            QrRenderer::link($account->display_phone, null) === null => 'no_number',
            default => 'ready',
        };
    }

    private function readyOrAbort(): WhatsappAccount
    {
        $account = WhatsappAccount::query()->orderBy('created_at')->first();

        abort_unless($account !== null && $this->state($account) === 'ready', 409);

        return $account;
    }

    private function find(string $id): WhatsappQrCode
    {
        abort_unless(Str::isUuid($id), 404);

        return WhatsappQrCode::query()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Organization $organization, ?WhatsappAccount $account, WhatsappQrCode $code): array
    {
        $link = QrRenderer::link($account?->display_phone, $code->message);

        return [
            'id' => $code->id,
            'name' => $code->name,
            'message' => $code->message ?? '',
            'color' => $code->color,
            'show_logo' => $code->show_logo && $organization->logo_updated_at !== null,
            'is_default' => $code->is_default,
            'link' => $link,
            // Change dès qu'un élément du dessin change : le navigateur recharge l'aperçu, sinon il le garde en cache.
            'version' => substr(md5($link.$code->color.(int) $code->show_logo.$organization->logo_updated_at?->getTimestamp()), 0, 10),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function error(InvalidArgumentException $e): array
    {
        return match ($e->getMessage()) {
            'limit_reached' => ['name' => __('ui.whatsapp_qr.errors.limit', ['max' => QrCodeManager::MAX_CODES])],
            'no_logo' => ['show_logo' => __('ui.whatsapp_qr.errors.no_logo')],
            default => ['name' => __('ui.whatsapp_qr.errors.default_code')],
        };
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }
}
