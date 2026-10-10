<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Services\SupportConsole;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Support\Models\SupportAttachment;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Support\Services\AttachmentStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Téléchargement d'une pièce jointe d'un ticket par l'équipe (docs/29 §8). Même règle que pour le ticket : un agent ne télécharge que les
 * pièces des tickets qu'il peut voir. Le fichier reste sur le disque privé, jamais d'adresse publique ; chaque téléchargement est tracé.
 */
final class SupportAttachmentController
{
    public function __invoke(string $attachment, SupportConsole $console): StreamedResponse
    {
        $admin = SupportAccess::admin() ?? abort(403);
        $file = SupportAttachment::on(PlatformAudit::CONNECTION)->whereKey($attachment)->first() ?? abort(404);
        $message = SupportMessage::on(PlatformAudit::CONNECTION)->whereKey($file->message_id)->first() ?? abort(404);

        try {
            $ticket = $console->find($admin, $message->ticket_id);
        } catch (AuthorizationException) {
            abort(403);
        }

        abort_unless(Storage::disk(AttachmentStore::DISK)->exists($file->path), 404);
        PlatformAudit::record($admin->id, 'support.attachment.downloaded', $ticket->org_id, 'support_ticket', $ticket->id, ['reference' => $ticket->reference(), 'file' => $file->original_name]);

        return Storage::disk(AttachmentStore::DISK)->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
