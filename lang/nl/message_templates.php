<?php

/*
 * Super Admin › WhatsApp-berichten per sector (docs/39).
 */
return [
    'singular' => 'berichtsjabloon',
    'plural' => 'WhatsApp-berichten',
    'subtitle' => 'Een onafhankelijke set berichten per sector. Alleen een goedgekeurd en geactiveerd sjabloon wordt naar eindklanten gestuurd.',
    'copy_suffix' => '(kopie)',
    'close' => 'Sluiten',
    'edit_title' => '“:name” bewerken',
    'edit_resets' => 'Dit sjabloon is in afwachting of goedgekeurd: de tekst of het type wijzigen zet het terug op concept, het moet opnieuw worden ingediend en goedgekeurd.',

    'columns' => [
        'name' => 'Sjabloon',
        'sector' => 'Sector',
        'type' => 'Type',
        'category' => 'Meta-categorie',
        'status' => 'Status',
        'enabled' => 'Actief',
        'languages' => 'Talen',
        'updated' => 'Gewijzigd',
    ],

    'types' => [
        'welcome' => 'Welkomstbericht',
        'confirmation' => 'Bevestiging van afspraak',
        'reminder' => 'Herinnering aan afspraak',
        'modification' => 'Wijziging van afspraak',
        'cancellation' => 'Annulering van afspraak',
        'payment_reminder' => 'Betalingsherinnering',
        'payment_confirmation' => 'Betalingsbevestiging',
        'follow_up' => 'Opvolging na de dienst',
        'review_request' => 'Vraag om een beoordeling',
        're_engagement' => 'Heractivering van inactieve klant',
        'promotional' => 'Promotiebericht',
        'informational' => 'Informatief bericht',
        'custom' => 'Aangepast bericht',
    ],

    'categories' => ['utility' => 'Utility', 'marketing' => 'Marketing'],

    'statuses' => [
        'draft' => 'Concept',
        'pending_approval' => 'In afwachting van goedkeuring',
        'approved' => 'Goedgekeurd',
        'rejected' => 'Afgewezen',
        'archived' => 'Gearchiveerd',
    ],

    'languages' => ['fr' => 'Frans', 'nl' => 'Nederlands', 'en' => 'Engels'],

    'actions' => [
        'create' => 'Nieuw sjabloon',
        'import' => 'Standaardset importeren',
        'preview' => 'Voorbeeld',
        'edit' => 'Bewerken',
        'submit' => 'Indienen ter goedkeuring',
        'approve' => 'Goedkeuren',
        'reject' => 'Afwijzen',
        'duplicate' => 'Dupliceren',
        'enable' => 'Activeren',
        'disable' => 'Deactiveren',
        'archive' => 'Archiveren',
        'restore' => 'Terugzetten als concept',
        'history' => 'Geschiedenis',
        'delete' => 'Verwijderen',
    ],

    'filters' => [
        'sector' => 'Sector',
        'status' => 'Status',
        'type' => 'Berichttype',
        'category' => 'Categorie',
        'language' => 'Taal',
        'enabled' => 'Activering',
        'all' => 'Alle',
        'enabled_yes' => 'Actief',
        'enabled_no' => 'Inactief',
    ],

    'form' => [
        'sector' => 'Sector',
        'sector_help' => 'Het sjabloon hoort bij deze sector alleen: het verschijnt niet in andere sectoren en is er niet bruikbaar.',
        'sector_locked' => 'De sector verandert niet meer na het aanmaken. Dupliceer het sjabloon voor een andere sector.',
        'type' => 'Berichttype',
        'type_help' => 'Bevestiging, herinnering, wijziging en annulering worden automatisch door Rendivoo verstuurd; de andere zijn klaar voor gebruik.',
        'name' => 'Interne naam',
        'category' => 'Meta-categorie',
        'category_help' => 'Laat leeg voor de gebruikelijke categorie van het type. “Marketing” is voor aanbiedingen en heractivering.',
        'enabled' => 'Sjabloon actief',
        'enabled_help' => 'Deactiveren haalt het sjabloon uit gebruik zonder het te verwijderen.',
        'translations' => 'Tekst per taal',
        'translations_help' => 'Beschikbare variabelen: :variables. Elk slechts één keer, nooit aan het begin of einde van het bericht.',
        'body' => 'Tekst (:language)',
        'body_required' => 'Verplicht: dit is de terugvaltaal.',
        'body_optional' => 'Optioneel. Zonder tekst is de taal niet beschikbaar voor dit sjabloon.',
    ],

    'confirm' => [
        'submit_title' => 'Dit sjabloon indienen?',
        'submit_body' => 'Alle teksten worden gecontroleerd. Het sjabloon wacht dan op goedkeuring.',
        'approve_title' => 'Dit sjabloon goedkeuren?',
        'approve_body' => 'Eenmaal goedgekeurd en actief wordt het gebruikt voor de echte berichten van bedrijven in de sector “:sector”.',
        'reject_title' => 'Dit sjabloon afwijzen',
        'archive_title' => 'Dit sjabloon archiveren?',
        'archive_body' => 'Het wordt niet meer gebruikt. U kunt het terugzetten als concept.',
        'restore_title' => 'Dit sjabloon terugzetten?',
        'restore_body' => 'Het wordt weer een concept: het moet opnieuw worden ingediend en goedgekeurd.',
        'delete_title' => '“:name” verwijderen?',
        'delete_body' => 'Definitieve verwijdering. De geschiedenis van het sjabloon blijft raadpleegbaar in het auditlogboek.',
    ],

    'reject' => [
        'reason' => 'Reden van afwijzing',
        'reason_help' => 'Zichtbaar in de lijst en de geschiedenis; zeg wat er moet worden aangepast.',
    ],

    'duplicate' => [
        'title' => 'Dit sjabloon dupliceren',
        'help' => 'De kopie is een onafhankelijk concept. Kies dezelfde of een andere sector.',
        'target' => 'Sector van de kopie',
    ],

    'import' => [
        'title' => 'Standaardset importeren',
        'help' => 'Voegt, als concept, de berichten van het klanttraject toe (welkom, bevestiging, herinnering, wijziging, annulering, betaling, opvolging, beoordeling, heractivering, promotie, informatie) in het Frans, Nederlands en Engels. Bestaande sjablonen worden nooit gewijzigd.',
        'sector' => 'Sector',
        'all_sectors' => 'Alle sectoren',
    ],

    'preview' => [
        'title' => 'Voorbeeld van “:name”',
        'sample' => 'Voorbeeld met voorbeeldgegevens (bedrijf “Salon Aurore”, klant “Marie”).',
        'meta_text' => 'Tekst die naar Meta gaat (genummerde variabelen)',
        'valid' => 'Geldige tekst.',
    ],

    'history' => [
        'title' => 'Geschiedenis van “:name”',
        'empty' => 'Geen gebeurtenissen.',
        'reason' => 'Reden',
        'by' => 'Door :actor',
        'system' => 'Automatische import',
        'unknown' => 'Verwijderde beheerder',
    ],

    'events' => [
        'created' => 'Aangemaakt',
        'imported' => 'Import van de standaardset',
        'updated' => 'Gewijzigd',
        'duplicated' => 'Gedupliceerd',
        'submitted' => 'Ingediend ter goedkeuring',
        'approved' => 'Goedgekeurd',
        'rejected' => 'Afgewezen',
        'archived' => 'Gearchiveerd',
        'restored' => 'Teruggezet als concept',
        'enabled' => 'Geactiveerd',
        'disabled' => 'Gedeactiveerd',
        'deleted' => 'Verwijderd',
    ],

    'saved' => [
        'created' => 'Sjabloon aangemaakt.',
        'updated' => 'Sjabloon opgeslagen.',
        'duplicated' => 'Sjabloon gedupliceerd (concept).',
        'submitted' => 'Sjabloon ingediend ter goedkeuring.',
        'approved' => 'Sjabloon goedgekeurd.',
        'rejected' => 'Sjabloon afgewezen.',
        'archived' => 'Sjabloon gearchiveerd.',
        'restored' => 'Sjabloon teruggezet als concept.',
        'enabled' => 'Sjabloon geactiveerd.',
        'disabled' => 'Sjabloon gedeactiveerd.',
        'deleted' => 'Sjabloon verwijderd.',
        'imported' => ':created sjabloon(en) toegevoegd, :skipped reeds aanwezig.',
    ],

    'errors' => [
        'no_translation' => 'Geef minstens één tekst op.',
        'invalid_text' => 'De tekst (:language) is niet geldig: :problem',
        'reason_required' => 'Geef de reden van de afwijzing.',
        'wrong_status' => '“:action” is niet mogelijk: het sjabloon is “:status”.',
        'not_deletable' => 'Alleen een concept, afgewezen of gearchiveerd sjabloon kan worden verwijderd. Archiveer het eerst.',
        'unknown_sector' => 'Onbekende sector.',
        'unknown_type' => 'Onbekend berichttype.',
    ],

    'problems' => [
        'empty' => 'de tekst is leeg.',
        'too_long' => 'de tekst is langer dan :max tekens.',
        'too_many' => 'meer dan :count variabelen.',
        'unknown' => 'de variabele {{:name}} bestaat niet.',
        'duplicate' => 'de variabele {{:name}} wordt meer dan eens gebruikt.',
        'starts_with_variable' => 'het bericht mag niet met een variabele beginnen.',
        'ends_with_variable' => 'het bericht mag niet met een variabele eindigen.',
        'malformed' => 'sommige {{ }}-accolades zijn onjuist.',
    ],
];
