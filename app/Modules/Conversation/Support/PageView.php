<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Support;

/**
 * Une page d'un menu numérotée, avec la règle de numérotation du flux n8n :
 * « voir plus » = nombre d'éléments affichés + 1 ; « page précédente » = affichés + (suite ? 2 : 1) ; les choix supplémentaires
 * (par exemple « Aucune préférence ») viennent en dernier.
 */
final readonly class PageView
{
    /**
     * @param  list<string>  $items  éléments de la page
     */
    public function __construct(
        public array $items,
        public int $page,
        public ?int $more,
        public ?int $previous,
        public int $extraStart,
    ) {}

    /**
     * @param  list<string>  $all  tous les éléments (identifiants ou valeurs)
     */
    public static function of(array $all, int $page, int $size): self
    {
        $total = count($all);
        $pages = $total > 0 ? (int) ceil($total / $size) : 0;
        $page = max(0, min($page, max(0, $pages - 1)));
        $items = array_slice($all, $page * $size, $size);
        $shown = count($items);
        $hasMore = ($page + 1) * $size < $total;
        $hasPrevious = $page > 0;

        return new self(
            $items,
            $page,
            $hasMore ? $shown + 1 : null,
            $hasPrevious ? $shown + ($hasMore ? 2 : 1) : null,
            $shown + 1 + ($hasMore ? 1 : 0) + ($hasPrevious ? 1 : 0),
        );
    }

    /**
     * Ce que le client a tapé : un élément (`select`), la suite (`more`), le retour (`previous`), un choix supplémentaire (`extra`) ou rien de valable.
     *
     * @return array{0: 'select'|'more'|'previous'|'extra'|'invalid', 1: int}
     */
    public function resolve(string $text, int $extras = 0): array
    {
        $number = Text::number($text);

        if ($number === null) {
            return ['invalid', 0];
        }

        return match (true) {
            $number >= 1 && $number <= count($this->items) => ['select', $number - 1],
            $this->more !== null && $number === $this->more => ['more', 0],
            $this->previous !== null && $number === $this->previous => ['previous', 0],
            $extras > 0 && $number >= $this->extraStart && $number < $this->extraStart + $extras => ['extra', $number - $this->extraStart],
            default => ['invalid', 0],
        };
    }
}
