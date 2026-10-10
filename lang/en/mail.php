<?php

return [
    'greeting' => 'Hello,',
    'ignore_if_not_you' => 'If you did not make this request, simply ignore this email.',

    'invitation' => [
        'subject' => ':organization invites you to Nova',
        'greeting' => 'Hello :name,',
        'intro' => ':organization invites you to join its team on Nova. Create your account to enter your services and availability.',
        'intro_assistant' => ':organization invites you to join its team on Nova. Create your account to help run the calendar and customers.',
        'action' => 'Accept the invitation',
        'expires' => 'This link is valid for :days days. After that, ask for a new invitation.',
    ],

    'verify_email' => [
        'subject' => 'Verify your email address',
        'intro' => 'Welcome to Nova. Confirm your email address to activate your account.',
        'action' => 'Verify my email address',
        'expires' => 'This link is valid for :hours hours.',
    ],

    'reset_password' => [
        'subject' => 'Reset your password',
        'intro' => 'You asked to reset your Nova password.',
        'action' => 'Choose a new password',
        'expires' => 'This link is valid for :minutes minutes and can only be used once.',
    ],

    'confirm_new_email' => [
        'subject' => 'Confirm your new e-mail address',
        'intro' => 'You asked to use this address for your Nova account.',
        'action' => 'Confirm this address',
        'expires' => 'This link is valid for :hours hours.',
    ],

    'email_change' => [
        'requested' => [
            'subject' => 'E-mail address change requested',
            'intro' => 'A change of e-mail address to :email was requested for your Nova account. Nothing changes until the new address is confirmed.',
        ],
        'done' => [
            'subject' => 'Your e-mail address has been changed',
            'intro' => 'The e-mail address of your Nova account is now :email. This old address can no longer be used to sign in.',
        ],
        'not_you' => 'If you did not make this change, reset your password immediately and contact Nova support.',
    ],
    'access_restored' => [
        'subject' => 'Your Nova access has been unblocked',
        'intro' => 'At your request, Nova support has reset the two-factor authentication of your account.',
        'next_login' => 'At your next sign-in you will need to set up two-factor authentication again and save new recovery codes.',
        'password_reset' => 'Your open sessions have been closed: another e-mail lets you choose a new password.',
        'not_you' => 'If you did not make this request, contact Nova support immediately.',
    ],
    'pause' => [
        'started' => [
            'subject' => 'The Nova account “:name” is paused',
            'intro' => 'Your Nova account is paused until :date. The WhatsApp assistant no longer takes appointments and no payment is charged.',
            'hint' => 'You can reactivate your account at any time from Nova.',
        ],
        'ending' => [
            'subject' => 'The pause of “:name” ends soon',
            'intro' => 'The pause of your Nova account ends on :date: on that date the account will be suspended.',
            'hint' => 'Reactivate it before then, or delete your account from Nova if you do not want to resume.',
        ],
        'ended' => [
            'subject' => 'The Nova account “:name” is reactivated',
            'intro' => 'The pause has ended: your Nova account is suspended and the WhatsApp assistant stays unavailable.',
            'hint' => 'Reactivate it from Nova to resume, or ask for its deletion. Your data is kept.',
        ],
        'suspension_ending' => [
            'subject' => 'The Nova account “:name” will soon be deleted',
            'intro' => 'Your Nova account has been suspended since the end of its pause. Unless you act, its deletion will be scheduled on :date.',
            'hint' => 'Reactivate your account from Nova to keep it. A scheduled deletion can still be cancelled during the usual delay.',
        ],
    ],

    'deletion_confirm' => [
        'subject' => 'Confirm the deletion of your Nova account',
        'intro' => 'You asked to delete the Nova account “:name”. To schedule it, confirm with the button below.',
        'action' => 'Confirm the deletion',
        'expires' => 'This link is valid for :hours hours and can only be used once.',
        'not_you' => 'If you did not make this request, ignore this e-mail and change your password.',
    ],
    'password_changed' => [
        'subject' => 'Your password has been changed',
        'intro' => 'The password of your Nova account has just been changed.',
        'other_sessions' => 'For your security, all other sessions have been closed.',
        'not_you' => 'If you did not make this change, reset your password immediately and contact Nova support.',
    ],

    'suspicious_login' => [
        'subject' => 'Unusual sign-in attempts',
        'intro' => 'Several failed sign-in attempts were detected on your account. Sign-in is locked for :minutes minutes.',
        'advice' => 'If this was not you, we recommend resetting your password.',
    ],

    'account_status' => [
        'suspended' => [
            'subject' => 'Your Nova account « :name » has been suspended',
            'intro' => 'The Nova account of « :name » has just been suspended: your dashboard is read-only and the WhatsApp assistant is unavailable.',
        ],
        'suspension_lifted' => [
            'subject' => 'The suspension of your Nova account « :name » has been lifted',
            'intro' => 'The Nova account of « :name » is fully usable again.',
        ],
        'deletion_scheduled' => [
            'subject' => 'The deletion of your Nova account « :name » is scheduled',
            'intro' => 'The deletion of the Nova account of « :name » has been scheduled.',
        ],
        'deletion_cancelled' => [
            'subject' => 'The deletion of your Nova account « :name » has been cancelled',
            'intro' => 'The scheduled deletion of the Nova account of « :name » has been cancelled: your data is kept.',
        ],
        'trial_extended' => [
            'subject' => 'The trial of your Nova account « :name » has been extended',
            'intro' => 'Nova has extended the free trial of « :name »: your account is fully usable.',
        ],
        'trial_end' => 'Your trial now ends on :date. Choose a plan before then to carry on without interruption.',
        'reason' => 'Reason: :reason',
        'deletion_date' => 'Your data will be erased on :date. You can export your data until then.',
        'contact' => 'For any question, contact Nova support by replying to this email.',
    ],

    'deletion_completed' => [
        'subject' => 'The data of « :name » has been deleted',
        'intro' => 'As requested, the Nova account of « :name » and its data (customers, appointments, conversations, team) have been permanently deleted.',
        'retention' => 'Only the data strictly required for accounting and security obligations is kept, for as long as the law requires.',
    ],

    'deletion_failed' => [
        'subject' => 'Nova: an account deletion is stuck',
        'intro' => 'The deletion of organization :org is stuck at step « :step » (error :error) after 3 attempts.',
        'action' => 'Open the Super Admin, Deletions section, and retry the deletion once fixed.',
    ],

    'billing' => [
        'button' => 'Open billing',
        'payment_failed' => [
            'subject' => 'Your Nova payment was declined',
            'intro' => 'The payment of :amount for your Nova subscription was declined (attempt :attempt of :max).',
            'action' => 'We will try again on :date. You can also update your payment method right now.',
        ],
        'payment_failed_final' => [
            'subject' => 'Your Nova account is suspended: payment failed',
            'intro' => 'The payment of :amount was declined :max times. Your account is suspended: the dashboard is read-only and the WhatsApp assistant is unavailable.',
            'action' => 'Update your payment method to fix this: the account is restored as soon as the payment goes through.',
        ],
        'trial_ending' => [
            'subject' => 'Your Nova trial is ending soon',
            'intro' => 'Your trial period ends on :date.',
            'action' => 'Choose a plan before then to continue without interruption.',
        ],
        'trial_ended' => [
            'subject' => 'Your Nova trial has ended',
            'intro' => 'Your trial period has ended: your account is suspended (read-only, WhatsApp assistant unavailable). Your data is kept.',
            'action' => 'Choose a plan to restore your account right away.',
        ],
        'subscription_ended' => [
            'subject' => 'Your Nova subscription has ended',
            'intro' => 'Your subscription has ended as requested: your account is suspended (read-only). Your data is kept.',
            'action' => 'You can subscribe again at any time to restore the account.',
        ],
    ],

    'support' => [
        'action' => 'View my ticket',
        'created' => [
            'subject' => 'Your request has been sent (#:reference)',
            'intro' => 'We received your request. Here is how to find it again.',
            'reference' => 'Reference: :reference',
            'summary' => 'Subject: :subject. Category: :category. Urgency: :urgency.',
            'response_time' => 'We aim to respond within 1 business day. Complex issues may take longer.',
        ],
        'reply' => [
            'subject' => 'New reply on your request #:reference',
            'intro' => 'Our team replied to your request “:subject”.',
            'how' => 'To answer, go to your tickets page: replying to this email does not work.',
        ],
        'resolved' => [
            'subject' => 'Your request #:reference is resolved',
            'intro' => 'We believe we have settled your request “:subject”.',
            'reopen' => 'If not, reply within :days days: the ticket will be reopened. Otherwise it will close by itself.',
        ],
        'status' => [
            'subject' => 'Your request #:reference has been updated',
            'intro' => 'The status of your request “:subject” has changed.',
            'open' => 'Your request is open again and our team will pick it up.',
            'in_progress' => 'Our team is working on it.',
            'waiting_on_you' => 'We need more information or an action from you. Please reply from your tickets page.',
        ],
        'closed' => [
            'subject' => 'Your request #:reference is closed',
            'intro' => 'Our team has closed your request “:subject”.',
            'new_ticket' => 'You can no longer reply to this ticket. If you still need help, please open a new request.',
        ],
    ],

    'team_access' => [
        'contact' => 'If this is unexpected, contact the account owner.',
        'access_changed' => ['subject' => 'Your access to :organization has changed', 'intro' => 'The owner of :organization changed what you can see and do on Nova. Sign in to see your access.'],
        'suspended' => ['subject' => 'Your access to :organization is suspended', 'intro' => 'Your access to :organization on Nova has been suspended. You will not be able to sign in until it is reactivated.'],
        'reactivated' => ['subject' => 'Your access to :organization is back', 'intro' => 'Your access to :organization on Nova has been reactivated. You can sign in again.'],
        'removed' => ['subject' => 'Your access to :organization was removed', 'intro' => 'Your access to :organization on Nova has been removed. You can no longer sign in to this account.'],
    ],
];
