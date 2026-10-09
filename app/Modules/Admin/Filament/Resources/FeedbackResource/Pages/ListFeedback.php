<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\FeedbackResource\Pages;

use App\Modules\Admin\Filament\Resources\FeedbackResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

class ListFeedback extends ListRecords
{
    protected static string $resource = FeedbackResource::class;

    /** Rappel permanent : ce sont des avis privés, pas des notes Google ou Trustpilot ; la moyenne ne concerne qu'eux. */
    public function getSubheading(): string|Htmlable|null
    {
        $stats = DB::connection('pgsql_platform')->table('nova_feedback')->selectRaw('count(*) as n, avg(rating) as average')->first();
        $line = ($stats->n ?? 0) > 0 ? ' '.__('admin.feedback.average', ['average' => number_format((float) $stats->average, 1), 'count' => (int) $stats->n]) : '';

        return __('admin.feedback.note').$line;
    }
}
