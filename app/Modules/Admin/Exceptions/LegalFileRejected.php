<?php

declare(strict_types=1);

namespace App\Modules\Admin\Exceptions;

use App\Modules\Admin\Services\LegalDocumentFiles;
use RuntimeException;

/**
 * Un PDF juridique est refusé. Le message est un code (`not_pdf`, `too_large`, `empty`, `upload_failed`, `active_content`, `future_version`,
 * `store_failed`) traduit en `admin.legal_files.errors.*` : une phrase claire pour l'administrateur, jamais une erreur serveur.
 */
final class LegalFileRejected extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function reason(): string
    {
        return $this->getMessage();
    }

    public function message(): string
    {
        return __("admin.legal_files.errors.{$this->getMessage()}", ['max' => LegalDocumentFiles::MAX_MB]);
    }
}
