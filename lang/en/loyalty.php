<?php

/*
 * Loyalty programme (docs/27): WhatsApp messages (default texts, sample values for the preview, Meta templates).
 * Variables: {name} {business} {program} {progress} {stamps} {required} {remaining} {reward} {expiry_date}.
 */
return [
    'messages' => [
        'default' => [
            'joined' => 'Hello {name}, welcome to "{program}" at {business}! After {required} visits you receive: {reward}. Reply STOP to stop receiving our messages.',
            'progress' => 'Thank you for your visit, {name}! Your progress: {progress}. {remaining} more visit(s) until your reward: {reward}.',
            'reward_earned' => 'Congratulations {name}! Your reward at {business}: {reward}. It is waiting for you at your next appointment.',
            'reward_redeemed' => 'Thank you {name}! Your reward "{reward}" has been used at {business}.',
            'expiry_reminder' => 'Hello {name}, your reward "{reward}" at {business} expires on {expiry_date}. Book an appointment before then!',
        ],
        'samples' => [
            'name' => 'Camille', 'business' => 'Salon Aurore', 'program' => 'Loyalty programme', 'progress' => '6/8', 'stamps' => '6',
            'required' => '8', 'remaining' => '2', 'reward' => 'A free haircut', 'expiry_date' => '31/12/2026',
        ],
    ],

    // Templates to be approved by Meta (marketing category): {{1}} business, {{2}} first name, {{3}} progress, {{4}} reward, {{5}} expiry date.
    'templates' => [
        'joined' => 'Hello {{2}}, welcome to the loyalty programme at {{1}}! Your progress: {{3}}. Reward: {{4}}. Reply STOP to stop receiving our messages.',
        'progress' => 'Thank you for your visit to {{1}}, {{2}}! Your progress: {{3}}. Reward to earn: {{4}}.',
        'reward_earned' => 'Congratulations {{2}}! Your reward at {{1}}: {{4}}. It is waiting for you at your next appointment.',
        'reward_redeemed' => 'Thank you {{2}}! Your reward "{{4}}" has been used at {{1}}.',
        'expiry_reminder' => 'Hello {{2}}, your reward "{{4}}" at {{1}} expires on {{5}}. Book an appointment before then!',
    ],
];
