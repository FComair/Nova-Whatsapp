<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le produit s'appelle Rendivoo (anciennement Nova, docs/42). Les articles d'aide importés en base (docs/30) priment sur les fichiers Markdown :
 * ils gardaient donc l'ancien nom à l'écran même après le changement des fichiers. Cette migration remplace le NOM DE MARQUE dans le contenu
 * courant (questions, résumés, textes, étiquettes, noms et descriptions de catégories).
 *
 * Volontairement laissés tels quels : l'historique des versions d'articles (journal en ajout seul) et les identifiants techniques
 * (« X-Nova-Key », « nova_app »…) : le motif ne touche pas un mot collé à un tiret, un chiffre, un souligné ou une autre lettre.
 */
return new class extends Migration
{
    private const WORD = '(?<![-A-Za-z0-9_])Nova';

    private const END = '(?![-A-Za-z0-9_])';

    public function up(): void
    {
        // « Nova WhatsApp », « Nova-whatsapp » d'abord, puis « Nova » seul.
        $replace = fn (string $column): string => "regexp_replace(regexp_replace({$column}, '".self::WORD."[ -]?[Ww]hats[Aa]pp".self::END."', 'Rendivoo', 'g'), '".self::WORD.self::END."', 'Rendivoo', 'g')";
        $mentions = "'".self::WORD."'";

        // Les colonnes de longueur limitée sont coupées plutôt que de faire échouer la migration (le nouveau nom est plus long de quatre lettres).
        DB::statement(<<<SQL
            UPDATE faq_translations
               SET question = left({$replace('question')}, 160),
                   summary = left({$replace('summary')}, 300),
                   body = {$replace('body')},
                   updated_at = now()
             WHERE question ~ {$mentions} OR summary ~ {$mentions} OR body ~ {$mentions}
        SQL);

        DB::statement(<<<SQL
            UPDATE faq_categories
               SET names = {$replace('names::text')}::jsonb,
                   descriptions = {$replace('descriptions::text')}::jsonb,
                   updated_at = now()
             WHERE names::text ~ {$mentions} OR descriptions::text ~ {$mentions}
        SQL);

        DB::statement(<<<SQL
            UPDATE faq_articles
               SET tags = {$replace('tags::text')}::jsonb,
                   updated_at = now()
             WHERE tags::text ~ {$mentions}
        SQL);
    }

    public function down(): void
    {
        // Remplacement de marque : rien à restaurer.
    }
};
