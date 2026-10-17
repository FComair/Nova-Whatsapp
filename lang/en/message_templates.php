<?php

/*
 * Super Admin › WhatsApp messages by sector (docs/39).
 */
return [
    'singular' => 'message template',
    'plural' => 'WhatsApp messages',
    'subtitle' => 'An independent set of messages for each sector. Only an approved and enabled template is sent to end customers.',
    'copy_suffix' => '(copy)',
    'close' => 'Close',
    'edit_title' => 'Edit “:name”',
    'edit_resets' => 'This template is pending or approved: changing its text or type puts it back to draft, and it must be submitted and approved again.',

    'columns' => [
        'name' => 'Template',
        'sector' => 'Sector',
        'type' => 'Type',
        'category' => 'Meta category',
        'status' => 'Status',
        'enabled' => 'Enabled',
        'languages' => 'Languages',
        'updated' => 'Updated',
    ],

    'types' => [
        'welcome' => 'Welcome message',
        'confirmation' => 'Booking confirmation',
        'reminder' => 'Appointment reminder',
        'modification' => 'Appointment modification',
        'cancellation' => 'Appointment cancellation',
        'payment_reminder' => 'Payment reminder',
        'payment_confirmation' => 'Payment confirmation',
        'follow_up' => 'Post-service follow-up',
        'review_request' => 'Review request',
        're_engagement' => 'Re-engagement of inactive customer',
        'promotional' => 'Promotional message',
        'informational' => 'Informational message',
        'custom' => 'Custom message',
    ],

    'categories' => ['utility' => 'Utility', 'marketing' => 'Marketing'],

    'statuses' => [
        'draft' => 'Draft',
        'pending_approval' => 'Pending approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'archived' => 'Archived',
    ],

    'languages' => ['fr' => 'French', 'nl' => 'Dutch', 'en' => 'English'],

    'actions' => [
        'create' => 'New template',
        'import' => 'Import standard set',
        'preview' => 'Preview',
        'edit' => 'Edit',
        'submit' => 'Submit for approval',
        'approve' => 'Approve',
        'reject' => 'Reject',
        'duplicate' => 'Duplicate',
        'enable' => 'Enable',
        'disable' => 'Disable',
        'archive' => 'Archive',
        'restore' => 'Restore to draft',
        'history' => 'History',
        'delete' => 'Delete',
    ],

    'filters' => [
        'sector' => 'Sector',
        'status' => 'Status',
        'type' => 'Message type',
        'category' => 'Category',
        'language' => 'Language',
        'enabled' => 'Activation',
        'all' => 'All',
        'enabled_yes' => 'Enabled',
        'enabled_no' => 'Disabled',
    ],

    'form' => [
        'sector' => 'Sector',
        'sector_help' => 'The template belongs to this sector only: it does not appear in, and cannot be used by, any other sector.',
        'sector_locked' => 'The sector cannot change after creation. For another sector, duplicate the template.',
        'type' => 'Message type',
        'type_help' => 'Confirmation, reminder, modification and cancellation are sent automatically by Rendivoo; the others are ready to use.',
        'name' => 'Internal name',
        'category' => 'Meta category',
        'category_help' => 'Leave empty for the type’s usual category. “Marketing” is for offers and re-engagement.',
        'enabled' => 'Template enabled',
        'enabled_help' => 'Disabling takes the template out of use without deleting it.',
        'translations' => 'Text by language',
        'translations_help' => 'Available variables: :variables. Each only once, never at the start or the end of the message.',
        'body' => 'Text (:language)',
        'body_required' => 'Required: this is the fallback language.',
        'body_optional' => 'Optional. Without text, the language is not available for this template.',
    ],

    'confirm' => [
        'submit_title' => 'Submit this template?',
        'submit_body' => 'All texts are checked. The template becomes pending approval.',
        'approve_title' => 'Approve this template?',
        'approve_body' => 'Once approved and enabled, it will be used for the real messages of businesses in the “:sector” sector.',
        'reject_title' => 'Reject this template',
        'archive_title' => 'Archive this template?',
        'archive_body' => 'It will no longer be used. You can restore it to draft.',
        'restore_title' => 'Restore this template?',
        'restore_body' => 'It goes back to draft: it must be submitted and approved again.',
        'delete_title' => 'Delete “:name”?',
        'delete_body' => 'Permanent deletion. The template’s history stays available in the audit log.',
    ],

    'reject' => [
        'reason' => 'Reason for rejection',
        'reason_help' => 'Shown in the list and the history; say what needs fixing.',
    ],

    'duplicate' => [
        'title' => 'Duplicate this template',
        'help' => 'The copy is an independent draft. Pick the same sector or another one.',
        'target' => 'Sector of the copy',
    ],

    'import' => [
        'title' => 'Import the standard set',
        'help' => 'Adds, as drafts, the customer-journey messages (welcome, confirmation, reminder, modification, cancellation, payment, follow-up, review, re-engagement, promotion, information) in French, Dutch and English. Templates that already exist are never changed.',
        'sector' => 'Sector',
        'all_sectors' => 'All sectors',
    ],

    'preview' => [
        'title' => 'Preview of “:name”',
        'sample' => 'Preview with sample data (business “Salon Aurore”, customer “Marie”).',
        'meta_text' => 'Text sent to Meta (numbered variables)',
        'valid' => 'Valid text.',
    ],

    'history' => [
        'title' => 'History of “:name”',
        'empty' => 'No events.',
        'reason' => 'Reason',
        'by' => 'By :actor',
        'system' => 'Automatic import',
        'unknown' => 'Deleted administrator',
    ],

    'events' => [
        'created' => 'Created',
        'imported' => 'Standard set import',
        'updated' => 'Edited',
        'duplicated' => 'Duplicated',
        'submitted' => 'Submitted for approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'archived' => 'Archived',
        'restored' => 'Restored to draft',
        'enabled' => 'Enabled',
        'disabled' => 'Disabled',
        'deleted' => 'Deleted',
    ],

    'saved' => [
        'created' => 'Template created.',
        'updated' => 'Template saved.',
        'duplicated' => 'Template duplicated (draft).',
        'submitted' => 'Template submitted for approval.',
        'approved' => 'Template approved.',
        'rejected' => 'Template rejected.',
        'archived' => 'Template archived.',
        'restored' => 'Template restored to draft.',
        'enabled' => 'Template enabled.',
        'disabled' => 'Template disabled.',
        'deleted' => 'Template deleted.',
        'imported' => ':created template(s) added, :skipped already present.',
    ],

    'errors' => [
        'no_translation' => 'Enter at least one text.',
        'invalid_text' => 'The text (:language) is not valid: :problem',
        'reason_required' => 'Give the reason for the rejection.',
        'wrong_status' => 'Cannot “:action”: the template is “:status”.',
        'not_deletable' => 'Only a draft, rejected or archived template can be deleted. Archive it first.',
        'unknown_sector' => 'Unknown sector.',
        'unknown_type' => 'Unknown message type.',
    ],

    'problems' => [
        'empty' => 'the text is empty.',
        'too_long' => 'the text is longer than :max characters.',
        'too_many' => 'more than :count variables.',
        'unknown' => 'the variable {{:name}} does not exist.',
        'duplicate' => 'the variable {{:name}} is used more than once.',
        'starts_with_variable' => 'the message cannot start with a variable.',
        'ends_with_variable' => 'the message cannot end with a variable.',
        'malformed' => 'some {{ }} braces are malformed.',
    ],
];
