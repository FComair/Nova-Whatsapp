<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Contracts\PriceListReader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lecture d'une liste de prix par un modèle d'IA qui comprend les images (API compatible « chat completions », la même que l'assistant).
 * Les images ne sont envoyées qu'à ce fournisseur, pour cette lecture ; ni les images ni les lignes lues ne sont écrites dans les journaux.
 */
final class OpenAiPriceListReader implements PriceListReader
{
    private const INSTRUCTIONS = <<<'TXT'
You read photographed or scanned price lists of small businesses (hairdressers, beauty salons, clinics, garages…).
Return ONLY a JSON object: {"lines":[{"name":string,"price":number|null,"category":string|null,"duration_minutes":integer|null,"vat_rate":integer|null,"unit":string|null,"currency":string|null,"confidence":number}]}
Rules:
- One line per service or product that has its own price. Keep the original wording and language of the name; do not translate or invent.
- "price": the amount as a plain number (38.5), without currency symbol. If a range is printed (from 25 to 40) use the lowest amount. null if no price is readable.
- "category": the section heading the line sits under (for example "Haircuts"), or null.
- "duration_minutes": only if a duration is printed (for example "45 min", "1h"), as minutes. Otherwise null. Never guess.
- "vat_rate": only if a VAT percentage is printed (0, 6, 12 or 21). Otherwise null.
- "unit": a printed unit such as "per hour" or "per item", otherwise null.
- "currency": the ISO code of the printed currency (EUR for the euro sign), or null if none is printed.
- "confidence": between 0 and 1: how sure you are of the name AND the price together. Use a low value for blurry, cut or ambiguous text.
- Ignore addresses, phone numbers, opening hours, slogans and legal text.
- If there is nothing that looks like a price list, return {"lines":[]}.
TXT;

    public function available(): bool
    {
        return (string) config('conversation.ai.api_key') !== '';
    }

    public function read(array $images, string $locale): ?array
    {
        if (! $this->available() || $images === []) {
            return null;
        }

        $content = [['type' => 'text', 'text' => "Language of the business: {$locale}. The price list has ".count($images).' page(s), in order.']];

        foreach ($images as $bytes) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.base64_encode($bytes), 'detail' => 'high']];
        }

        try {
            $response = Http::withToken((string) config('conversation.ai.api_key'))
                ->baseUrl((string) config('conversation.ai.base_url'))
                ->timeout((int) config('pricelist.timeout_seconds'))
                ->post('/chat/completions', [
                    'model' => (string) (config('pricelist.model') ?: config('conversation.ai.model')),
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => self::INSTRUCTIONS],
                        ['role' => 'user', 'content' => $content],
                    ],
                ]);
        } catch (Throwable) {
            Log::warning('Import de liste de prix : le service de lecture est injoignable.');

            return null;
        }

        if ($response->failed()) {
            Log::warning('Import de liste de prix : le service de lecture a refusé la demande (HTTP '.$response->status().').');

            return null;
        }

        return self::parse($response->json('choices.0.message.content'));
    }

    /**
     * Lignes d'une réponse du modèle (JSON, éventuellement entouré de balises de code) ; null si elle est inexploitable.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function parse(mixed $content): ?array
    {
        if (! is_string($content)) {
            return null;
        }

        $json = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content)));
        $data = json_decode($json, true);

        if (! is_array($data) || ! isset($data['lines']) || ! is_array($data['lines'])) {
            return null;
        }

        $lines = [];

        foreach ($data['lines'] as $line) {
            if (is_array($line) && is_string($line['name'] ?? null)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
