<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Audit\Audit;
use App\Modules\Catalog\Contracts\PriceListReader;
use App\Modules\Catalog\Models\PriceListImport;
use App\Modules\Catalog\Models\Service;
use App\Modules\Tenancy\Models\Organization;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lecture d'une liste de prix : images → lignes contrôlées et rapprochées des services existants → import « analysé » à vérifier.
 *
 * Les photos sont réduites et réencodées en JPEG (ce qui retire aussi les métadonnées), gardées le temps de l'import dans le stockage
 * privé, puis effacées. Les lignes lues ne sont qu'une proposition : rien n'est écrit dans le catalogue avant la confirmation.
 */
final class PriceListAnalyzer
{
    public const MAX_LINES = 200;

    private const VAT_RATES = [0, 6, 12, 21];

    public function __construct(private readonly PriceListReader $reader, private readonly PriceListMatcher $matcher) {}

    public function available(): bool
    {
        return $this->reader->available();
    }

    /**
     * @param  list<UploadedFile>  $files  pages, dans l'ordre
     *
     * @throws PriceListException unavailable, bad_image, unreadable ou no_prices
     */
    public function analyse(Organization $organization, ?string $userId, array $files): PriceListImport
    {
        if (! $this->available()) {
            throw new PriceListException('unavailable');
        }

        $images = [];

        foreach ($files as $index => $file) {
            $images[] = $this->prepare($file, $index + 1);
        }

        $raw = $this->reader->read($images, $organization->locale);

        if ($raw === null) {
            throw new PriceListException('unreadable');
        }

        $lines = $this->normalise($raw);

        if (! collect($lines)->contains(fn (array $line): bool => $line['price_cents'] !== null)) {
            throw new PriceListException('no_prices');
        }

        $existing = Service::query()->orderBy('position')->get(['id', 'name'])->map(fn (Service $service): array => ['id' => $service->id, 'name' => $service->name])->all();
        $id = (string) Str::uuid7();
        $paths = [];
        $disk = Storage::disk('local');

        foreach ($images as $index => $bytes) {
            $paths[] = $path = "pricelists/{$organization->id}/{$id}/".($index + 1).'.jpg';
            $disk->put($path, $bytes);
        }

        $import = PriceListImport::query()->create([
            'id' => $id,
            'org_id' => $organization->id,
            'user_id' => $userId,
            'status' => 'analysed',
            'file_names' => array_map(static fn (UploadedFile $file): string => mb_substr($file->getClientOriginalName(), 0, 120), $files),
            'files' => $paths,
            'lines' => $this->matcher->match($lines, $existing),
        ]);

        Audit::record('catalog.price_list.analysed', 'user', $userId, $organization->id, 'price_list_imports', $import->id, ['pages' => count($files), 'lines' => count($lines)]);

        return $import;
    }

    /** Efface les fichiers d'un import (terminé, annulé ou expiré) et l'indique sur la ligne. */
    public function discardFiles(PriceListImport $import): void
    {
        Storage::disk('local')->deleteDirectory("pricelists/{$import->org_id}/{$import->id}");
        $import->forceFill(['files' => null])->save();
    }

    /**
     * Image réduite (2000 px au plus) et réencodée en JPEG.
     *
     * @throws PriceListException bad_image avec le numéro de la page
     */
    private function prepare(UploadedFile $file, int $page): string
    {
        $info = @getimagesize($file->getRealPath());
        $min = (int) config('pricelist.min_side_px');
        $max = (int) config('pricelist.max_side_px');

        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || min($info[0], $info[1]) < $min || max($info[0], $info[1]) > $max) {
            throw new PriceListException('bad_image', $page);
        }

        $source = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($file->getRealPath()) : @imagecreatefromjpeg($file->getRealPath());

        if (! $source instanceof GdImage) {
            throw new PriceListException('bad_image', $page);
        }

        $target = (int) config('pricelist.send_side_px');
        $scale = min(1, $target / max($info[0], $info[1]));
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));

        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);

        ob_start();
        imagejpeg($canvas, null, 85);

        return (string) ob_get_clean();
    }

    /**
     * Lignes lues → lignes contrôlées : montants en centimes, durée proposée, avertissements. Rien n'est supprimé en silence.
     *
     * @param  list<array<string, mixed>>  $raw
     * @return list<array<string, mixed>>
     */
    private function normalise(array $raw): array
    {
        $lines = [];
        $seen = [];

        foreach (array_slice($raw, 0, self::MAX_LINES) as $line) {
            $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($line['name'] ?? ''))), 0, 120);

            if ($name === '') {
                continue;
            }

            $cents = $this->cents($line['price'] ?? null);
            $duration = $this->duration($line['duration_minutes'] ?? null);
            $vat = is_numeric($line['vat_rate'] ?? null) && in_array((int) $line['vat_rate'], self::VAT_RATES, true) ? (int) $line['vat_rate'] : null;
            $confidence = is_numeric($line['confidence'] ?? null) ? max(0.0, min(1.0, (float) $line['confidence'])) : 0.8;
            $category = isset($line['category']) && is_string($line['category']) ? mb_substr(trim($line['category']), 0, 60) : '';
            $unit = isset($line['unit']) && is_string($line['unit']) ? trim($line['unit']) : '';
            $currency = strtoupper(trim((string) ($line['currency'] ?? '')));
            $currency = $currency === '€' ? 'EUR' : $currency;

            $warnings = [];
            $ignored = null;

            if ($cents === null) {
                $ignored = 'no_price';
            } elseif ($currency !== '' && $currency !== 'EUR') {
                $ignored = 'currency';
            }

            if ($cents === 0) {
                $warnings[] = 'price_zero';
            } elseif ($cents !== null && $cents < (int) round((float) config('pricelist.price_low') * 100)) {
                $warnings[] = 'price_low';
            } elseif ($cents !== null && $cents > (int) round((float) config('pricelist.price_high') * 100)) {
                $warnings[] = 'price_high';
            }

            if ($confidence < 0.75) {
                $warnings[] = 'low_confidence';
            }

            if ($duration === null) {
                $warnings[] = 'no_duration';
            }

            if ($unit !== '') {
                $warnings[] = 'unit_ignored';
            }

            $key = $this->matcher->normalize($name);

            if (isset($seen[$key])) {
                $warnings[] = 'duplicate';
            }

            $seen[$key] = true;

            $lines[] = [
                'key' => 'l'.(count($lines) + 1),
                'name' => $name,
                'price_cents' => $cents,
                'category' => $category === '' ? null : $category,
                'duration_minutes' => $duration ?? (int) config('pricelist.default_duration'),
                'duration_guessed' => $duration === null,
                'vat_rate' => $vat,
                'unit' => $unit === '' ? null : mb_substr($unit, 0, 40),
                'confidence' => round($confidence, 2),
                'warnings' => $warnings,
                'ignored_reason' => $ignored,
            ];
        }

        return $lines;
    }

    private function cents(mixed $price): ?int
    {
        if (is_string($price)) {
            $clean = str_replace(',', '.', (string) preg_replace('/[^\d,.]/', '', $price));
            $price = is_numeric($clean) ? (float) $clean : null;
        }

        if (! is_int($price) && ! is_float($price)) {
            return null;
        }

        $cents = (int) round($price * 100);

        return $cents < 0 || $cents > 9_999_999 ? null : $cents;
    }

    private function duration(mixed $minutes): ?int
    {
        return is_numeric($minutes) && (int) $minutes >= 5 && (int) $minutes <= 480 ? (int) $minutes : null;
    }
}
