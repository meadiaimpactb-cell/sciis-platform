<?php

declare(strict_types=1);

/** AGR-01..05 — agreements module texts (errors, signature consent, field names). */
return [
    'errors' => [
        'not_connected' => 'An agreement can be drafted only after the investor is connected and the opportunity has reached the connected or negotiating stage.',
        'exists' => 'This connection already has an agreement; open it from the agreements list.',
        'template_inactive' => 'This template is not active; activate an approved version first.',
        'template_key_taken' => 'This key is already used by another template.',
        'not_editable' => 'The agreement text cannot be edited in its current status.',
        'no_changes' => 'The text is unchanged from the current version.',
        'no_version' => 'The agreement has no text version.',
        'not_in_review' => 'The agreement is not in legal review.',
        'approve_forbidden' => 'Approving agreements requires the legal approval permission.',
        'self_approval' => 'You cannot approve a version you drafted; another reviewer is required.',
        'not_approved' => 'The current version must be legally approved first.',
        'not_open_for_signature' => 'The agreement is not open for signature in its current status.',
        'text_changed' => 'The agreement text changed since you opened it. Reload the page and review the current text before signing.',
        'already_signed' => 'This version was already signed for this party.',
        'not_party' => 'You are not a party to this agreement.',
        'no_signed_copy' => 'No signed copy was uploaded for this agreement.',
        'invalid_transition' => 'The agreement cannot move from ":from" to ":to".',
    ],

    'version' => [
        'from_template' => 'First version from the template ":title" (version :version)',
    ],

    'sign' => [
        'consent' => 'I, :name, confirm that I have read the full text of agreement :number (version :version), accept all of its clauses, and agree that my simple electronic signature binds me as my handwritten signature would.',
    ],

    'wet' => [
        'document_title' => 'Wet-signed copy — :number (version :version)',
    ],

    'placeholders' => [
        'agreement_no' => 'Agreement number',
        'date' => 'Agreement date',
        'project_name' => 'Project name',
        'reference_no' => 'Opportunity reference',
        'owner_name' => 'Project owner name',
        'organization_name' => 'Project owner entity',
        'investor_name' => 'Investor or investor entity',
        'amount' => 'Investment amount (SAR)',
    ],

    'attributes' => [
        'key' => 'template key',
        'title' => 'Arabic title',
        'title_en' => 'English title',
        'body' => 'Arabic text',
        'body_en' => 'English text',
        'description' => 'description',
        'change_note' => 'reason for the change',
        'interest_id' => 'connection',
        'template_id' => 'template',
        'placeholders' => 'field values',
        'placeholders.*' => 'field value',
        'decision' => 'decision',
        'note' => 'note',
        'reason' => 'reason',
        'file' => 'file',
        'consent' => 'consent',
        'version' => 'version',
        'text_hash' => 'text fingerprint',
    ],
];
