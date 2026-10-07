<?php

/*
 * Automatic WhatsApp messages sent by Nova to end customers (customer's language, French by default).
 */
return [
    'opt_out_confirmed' => 'You will no longer receive messages from us. Reply START to receive them again.',
    'opt_in_confirmed' => 'Thank you! You will receive our messages again. Reply STOP to stop them.',

    // Templates submitted to Meta (docs/15): variables {{1}} business, {{2}} service, {{3}} day, {{4}} time.
    // Proposed texts, to be validated first: Meta approves them as written, any change requires a new submission.
    'templates' => [
        'reminder' => 'Hello, a reminder: you have an appointment at {{1}} ({{2}}) on {{3}} at {{4}}. See you soon!',
        'confirmation' => 'Hello, {{1}} has booked your appointment ({{2}}) on {{3}} at {{4}}. See you soon!',
        'rescheduled' => 'Hello, {{1}} has moved your appointment ({{2}}): it now takes place on {{3}} at {{4}}. Thank you for your understanding.',
        'cancellation' => 'Hello, {{1}} has to cancel your appointment ({{2}}) on {{3}} at {{4}}. We apologise for the inconvenience and will contact you to arrange a new one.',
    ],

    // Example values Meta requires for each variable.
    'examples' => ['Salon Aurore', 'Haircut', 'Wednesday 3 June', '10:00'],
];
