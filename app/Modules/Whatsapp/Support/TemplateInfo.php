<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Support;

/**
 * Un modèle de message tel que Meta le connaît. `status` : pending, approved, rejected ou paused.
 */
final readonly class TemplateInfo
{
    public function __construct(
        public string $name,
        public string $language,
        public string $status,
        public ?string $rejectionReason = null,
        public ?string $metaId = null,
    ) {}

    /** Statuts de Meta (APPROVED, PENDING, REJECTED, PAUSED, DISABLED, IN_APPEAL…) ramenés aux quatre statuts de Nova. */
    public static function fromMeta(string $name, string $language, string $status, ?string $reason, ?string $id): self
    {
        $normalised = match (strtoupper($status)) {
            'APPROVED' => 'approved',
            'REJECTED' => 'rejected',
            'PAUSED', 'DISABLED', 'FLAGGED' => 'paused',
            default => 'pending',
        };

        return new self($name, $language, $normalised, $normalised === 'rejected' || $normalised === 'paused' ? $reason : null, $id);
    }
}
