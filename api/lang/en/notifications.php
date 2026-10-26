<?php

declare(strict_types=1);

return [
    'greeting' => 'Hello :name,',

    // OPP-07 — newly listed opportunities matching a saved search or the investor profile.
    'opportunity_alert' => [
        'title' => 'New opportunities matching your interests',
        'subject' => 'New opportunities matching your interests (:count)',
        'line' => 'New opportunities were listed on the platform that match your saved searches or investor profile:',
        'line_one' => 'A new opportunity matching your interests was listed: ":name".',
        'item_filter' => '• :name — matches your search ":filter"',
        'item_profile' => '• :name — matches your profile (:score%)',
        'more' => 'And :count more on the platform.',
        'manage' => 'Manage alerts from the opportunities page (saved searches) or turn e-mails off in your notification preferences.',
        'action' => 'View opportunities',
        'action_one' => 'View opportunity',
    ],

    // INV-07 — the investor profile was not updated for a long time.
    'profile_reminder' => [
        'title' => 'Update your investor profile',
        'subject' => 'Reminder: update your investor profile',
        'line' => 'Your investor profile was last updated more than :days days ago (:date).',
        'why' => 'Keeping your preferred sectors, stages, regions and ticket size current helps the council nominate better-fitting opportunities.',
        'action' => 'Update profile',
        'sms' => 'SCIIS reminder: update your investor profile for better-fitting nominations.',
    ],

    // MSG-03 — weekly digest for approved investors.
    'digest' => [
        'title' => 'Your weekly summary',
        'subject' => 'Your weekly summary from the Saudi Council for Impact Investors',
        'intro' => 'Here is what concerns you on the platform over the last seven days:',
        'matches' => 'New opportunities matching your interests: :count',
        'nominations' => 'Nominations awaiting your reply: :count',
        'nominations_new' => 'New nominations this week: :count',
        'info_requests' => 'Information requests awaiting an answer: :count',
        'item' => '• :name',
        'body' => 'New opportunities: :matches · Nominations awaiting reply: :nominations · Open information requests: :info',
        'opt_out' => 'To stop this summary: My profile → Notification preferences → Weekly summary.',
        'action' => 'Open my dashboard',
    ],
    'otp' => [
        'subject' => 'Verification code — :app',
        'line' => 'Your verification code for :purpose:',
        'expires' => 'The code is valid for :minutes minutes.',
        'ignore' => 'If you did not request this code, ignore this message.',
        'sms' => 'Verification code: :code (valid :minutes min) — SCIIS',
        'purposes' => ['verify' => 'account verification', 'login' => 'login', 'reset' => 'password reset'],
    ],
    'status_changed' => [
        'title' => 'Opportunity status update',
        'subject' => 'Status update for opportunity :ref',
        'line' => 'Your opportunity ":name" is now: :status.',
        'reason' => 'Reason: :reason',
        'action' => 'View opportunity',
        'sms' => 'Update: opportunity :ref is now ":status".',
    ],
    'completion' => [
        'title' => 'Required now',
        'subject' => 'Completion required — opportunity :ref',
        'line' => 'The council asks you to complete the following for ":name":',
        'due' => 'Due date: :date',
        'action' => 'Complete now',
        'sms' => 'Completion required for opportunity :ref. Check "Required now" in the platform.',
    ],
    'nomination' => [
        'title' => 'New nomination',
        'subject' => 'A social investment opportunity was nominated for you',
        'line' => 'The council nominated the opportunity ":name" for you.',
        'rationale' => 'Rationale: :rationale',
        'score' => 'Match score: :score (matched: :criteria)',
        'action' => 'View nominations',
        'sms' => 'You have a new opportunity nomination on the SCIIS platform.',
    ],
    'interest_received' => [
        'title' => 'Full-file access request',
        'subject' => 'New access request — opportunity :ref',
        'line' => 'An approved investor is interested in ":name" and requests full-file access.',
        'privacy' => 'The investor identity is not disclosed before connection. Your decision is non-binding.',
        'action' => 'Review request',
        'sms' => 'New access request for your opportunity :ref.',
    ],
    'interest_decision' => [
        'approved' => [
            'subject' => 'The owner approved full-file sharing',
            'line' => 'The owner approved sharing the full file for ":name".',
            'nda' => 'Accept the NDA in the platform to view the documents.',
            'action' => 'Go to opportunity',
            'sms' => 'Access approved. Accept the NDA to view the full file.',
        ],
        'declined' => [
            'subject' => 'Update on your access request',
            'line' => 'The owner declined to share the full file for ":name" at this time.',
            'sms' => 'Update: the owner declined full-file sharing for now.',
        ],
    ],
    'info_request' => [
        'title' => 'Your question was answered',
        'subject' => 'Answer to your question: :subject',
        'line' => 'An answer arrived for your question about ":name":',
        'action' => 'View questions',
        'sms' => 'Your question on the SCIIS platform was answered.',
    ],
    'info_request_forwarded' => [
        'title' => 'New investor question',
        'subject' => 'Investor question — opportunity :ref',
        'line' => 'A question from an approved investor arrived through the council (identity withheld):',
        'action' => 'Answer',
        'sms' => 'You have a new investor question via the council.',
    ],
    'meeting' => [
        'title' => 'Meeting invitation',
        'invite' => ['subject' => 'Meeting invitation: :title', 'line' => 'You are invited to ":title". Calendar file attached.'],
        'updated' => ['subject' => 'Meeting updated: :title', 'line' => 'The meeting ":title" was updated. Updated calendar file attached.'],
        'when' => 'When: :start to :end (Riyadh time)',
        'video' => 'Meeting link: :url',
        'location' => 'Location: :location',
        'sms' => 'Meeting invitation: :title on :when. Details in your email.',
        'ics_description' => 'Meeting via the SCIIS platform — opportunity :ref',
    ],
    'membership' => [
        'subject' => 'Membership application update',
        'under_review' => 'Your membership application is under review.',
        'needs_completion' => 'Your membership application needs completion.',
        'approved' => 'Congratulations! Your membership was approved. You can now browse the opportunities nominated for you.',
        'rejected' => 'We are sorry, your membership application was not approved at this time.',
        'suspended' => 'Your membership was suspended. Please contact the council.',
        'registered' => 'Your account was registered.',
        'note' => 'Council note: :note',
        'action' => 'View application',
        'sms' => 'Update: membership status is now ":status".',
    ],
    'sla' => [
        'subject' => 'Alert: :count overdue items',
        'line' => 'The following items exceeded their SLA and need action:',
        'action' => 'View overdue items',
        'kinds' => ['review' => 'Screening', 'completion' => 'Completion', 'evaluation' => 'Evaluation', 'owner_decision' => 'Owner decision', 'matching' => 'Matching', 'connection' => 'Follow-up after connection', 'negotiation' => 'Negotiation'],
    ],

    // ENT-06 — the owner revoked a previously approved full-file access.
    'access_revoked' => [
        'subject' => 'Your access to the full file was revoked — :name',
        'line' => 'The owner of ":name" revoked your access to the full file; its documents are no longer available to you.',
        'reason' => 'Reason: :reason',
        'action' => 'View my interests',
        'sms' => 'Your access to the full file of an opportunity was revoked. Details on the platform.',
        'staff_subject' => 'An owner revoked an investor\'s access — :ref',
        'staff_line' => 'The owner of ":name" revoked :investor\'s access to the full file; the interest was closed.',
    ],

    // EVL-03 — the evaluation result reaches the owner.
    'evaluation_decided' => [
        'title' => 'Evaluation result issued',
        'subject' => 'Evaluation result — opportunity :ref',
        'line' => 'The evaluation of your opportunity ":name" is complete: :result.',
        'conditions' => 'Conditions: :conditions',
        'reasons' => 'Reasons: :reasons',
        'plan' => 'Suggested improvement plan:',
        'plan_due' => '(target: :date)',
        'action' => 'View the result',
        'sms' => 'The evaluation result of your opportunity :ref is available on the platform.',
    ],

    // §6.3 — the council closed an information request before it was answered.
    'info_request_closed' => [
        'title' => 'Information request closed',
        'subject' => 'Information request closed: :subject',
        'line' => 'The council team closed the information request ":subject" about ":name".',
        'action' => 'View information requests',
        'sms' => 'One of your information requests was closed on the council platform.',
    ],

    // Journey 11.3 — owner reminders before / on the completion due date.
    'completion_reminder' => [
        'title' => 'Completion due date reminder',
        'subject' => 'Reminder: complete the information for :ref',
        'line_upcoming' => 'The deadline to complete the requested information for ":name" is approaching: :date.',
        'line_today' => 'Today is the deadline to complete the requested information for ":name".',
        'auto_close' => 'If the information is not completed, the opportunity will be closed automatically on :date.',
        'action' => 'Complete the information',
        'sms' => 'Reminder: the completion deadline for opportunity :ref is :date.',
    ],

    // EVL-05 — the two evaluators diverge on at least one criterion.
    'evaluation_divergence' => [
        'title' => 'Evaluators diverge',
        'subject' => 'Diverging evaluation scores — opportunity :ref',
        'line' => 'Both evaluations of ":name" are in and the evaluators\' scores differ by :threshold or more on these criteria:',
        'item' => '• :criterion — difference :delta',
        'action' => 'Review the evaluation',
    ],
    // AGR-03/05 — agreement workflow (:number = AGR-YYYY-NNNN, :title, :name = project, :version).
    'agreement' => [
        'title' => 'Agreement notifications',
        'note' => 'Note: :note',
        'review_requested' => [
            'subject' => 'Agreement awaiting legal review — :number',
            'line' => 'Version :version of the agreement ":title" for the project ":name" was sent for legal review.',
            'action' => 'Review the agreement',
        ],
        'approved' => [
            'subject' => 'Agreement approved — :number',
            'line' => 'Version :version of the agreement ":title" was legally approved and can now be sent to the parties for signature.',
            'action' => 'Open the agreement',
        ],
        'changes_requested' => [
            'subject' => 'Changes requested on the agreement — :number',
            'line' => 'The legal reviewer requested changes to version :version of the agreement ":title".',
            'action' => 'Open the agreement',
        ],
        'sent_for_signature' => [
            'subject' => 'Agreement ready for your signature — :number',
            'line' => 'The agreement ":title" for the project ":name" is ready. Review the approved text, then sign it on the platform.',
            'action' => 'Review and sign',
            'sms' => 'Agreement :number is ready for your signature on the SCIIS platform.',
        ],
        'signed' => [
            'subject' => 'Agreement signed — :number',
            'line' => 'The agreement ":title" for the project ":name" was signed by both parties. A read-only copy is kept on the platform.',
            'action' => 'View the agreement',
            'sms' => 'Agreement :number was signed by both parties.',
        ],
    ],
    'payment' => [
        'subject' => 'Payment confirmation',
        'line' => 'We received :amount :currency for: :purpose.',
        'reference' => 'Reference: :ref',
        'sms' => 'Payment of :amount :currency received. Thank you.',
    ],
    'contact_received' => [
        'subject' => 'We received your message — reference :ref',
        'line' => 'Thank you for contacting the Saudi Council for Impact Investors. Your message was received and the relevant team will get back to you within business days.',
        'reference' => 'Your reference number: :ref — please quote it in any follow-up.',
        'category' => 'Category: :category',
        'footer' => 'This is an automated message; please do not reply directly.',
        'staff_title' => 'New contact message assigned to you',
        'staff_line' => 'A new contact message (:ref) in category ":category" was assigned to you.',
    ],

    // PRJ-01..03, IMP-02, ENT-10 — follow-up & impact.
    'follow_up' => [
        'action' => 'Open the follow-up file',
        'note' => 'Council team note: :note',
        'sms' => 'There is a new follow-up update for project :ref on the SCIIS platform.',
        'plan_submitted' => ['subject' => 'Milestone plan awaiting approval — :ref', 'line' => 'The owner of ":name" submitted the milestone and deliverables plan for approval.'],
        'plan_approved' => ['subject' => 'Your milestone plan for :ref was approved', 'line' => 'The council approved the milestone and deliverables plan of ":name". You can now update milestones and attach completion evidence.'],
        'plan_changes_requested' => ['subject' => 'Changes requested on your milestone plan — :ref', 'line' => 'The council asked for changes to the milestone plan of ":name" before approving it.'],
        'milestone_completed' => ['subject' => 'Milestone completed — :ref', 'line' => 'The owner of ":name" marked the milestone ":title" as done and attached the evidence.'],
        'milestone_reopened' => ['subject' => 'A milestone of :ref was reopened', 'line' => 'The council reopened the milestone ":title" of ":name"; acceptable completion evidence is needed.'],
        'report_submitted' => ['subject' => 'Progress report awaiting review — :ref', 'line' => 'The owner of ":name" submitted the progress report for :period.'],
        'report_accepted' => ['subject' => 'Progress report accepted — :ref', 'line' => 'The council accepted the progress report of ":name" for :period.'],
        'report_changes_requested' => ['subject' => 'Changes requested on your progress report — :ref', 'line' => 'The council asked for changes to the progress report of ":name" for :period.'],
        'report_due' => ['subject' => 'Reminder: progress report for :ref', 'line' => 'The progress report of ":name" for :period is due on :date.'],
        'report_overdue' => ['subject' => 'Progress report overdue — :ref', 'line' => 'The progress report of ":name" for :period is :days days past its due date (:date). Please submit it as soon as possible.'],
        'indicator_submitted' => ['subject' => 'Indicator value awaiting verification — :ref', 'line' => 'The owner of ":name" entered a new value for the indicator ":indicator".'],
        'indicator_verified' => ['subject' => 'Indicator value verified — :ref', 'line' => 'The council verified the value entered for the indicator ":indicator" of ":name".'],
        'indicator_needs_clarification' => ['subject' => 'Indicator value needs clarification — :ref', 'line' => 'The council needs clarification on the value entered for the indicator ":indicator" of ":name".'],
        'update_posted' => ['subject' => 'New update from :ref', 'line' => 'The owner of ":name" posted a new update: :title.', 'untitled' => 'Periodic update'],
        'update_pending_review' => ['subject' => 'Investor update awaiting review — :ref', 'line' => 'The owner of ":name" posted an update for the connected investors (:title) that awaits your review before it is sent.'],
        'update_published' => ['subject' => 'Your update was sent to investors — :ref', 'line' => 'The council reviewed your update ":title" for ":name" and sent it to the connected investors.'],
        'update_rejected' => ['subject' => 'Your update was not sent to investors — :ref', 'line' => 'The council did not approve sending your update ":title" for ":name".'],
        'status_changed' => ['subject' => 'Execution status of :ref changed', 'line' => 'The execution status of ":name" is now: :status.'],
        'stalled_escalation' => ['subject' => 'Escalation: stalled project — :ref', 'line' => 'The execution of ":name" is now "stalled": a milestone or report is late beyond the approved threshold. Action is needed.'],
        'remediation_updated' => ['subject' => 'Remediation plan updated — :ref', 'line' => 'The owner of ":name" updated the remediation plan for the delay.'],
    ],

    // ACC-08 — invitation to join an organisation account.
    'org_invitation' => [
        'subject' => 'Invitation to join :organization on the SCIIS platform',
        'line' => ':inviter invited you to join the ":organization" account on the Saudi Council for Impact Investors platform.',
        'can_act' => 'You will see the organisation\'s files and act on its behalf.',
        'read_only' => 'You will see the organisation\'s files without acting on its behalf.',
        'action' => 'Accept the invitation',
        'expires' => 'The link is valid until :date.',
        'ignore' => 'If you were not expecting this invitation, ignore this e-mail.',
    ],

    // MTG-03 — new in-platform message on a record.
    'message_received' => [
        'title' => 'New message',
        'subject' => 'New message about :title',
        'line' => 'You have a new message from :sender about ":title".',
        'privacy' => 'For confidentiality the message text is not e-mailed; sign in to read and reply.',
        'action' => 'Read the message',
        'council' => 'the council team',
    ],

    // MTG-04 — event registration confirmation.
    'event_registration' => [
        'subject' => 'Registration confirmed — :title',
        'line' => 'You are registered for ":title". We look forward to seeing you.',
        'when' => 'When: :start (Riyadh time)',
        'where' => 'Where: :location',
        'calendar' => 'The calendar file (.ics) is attached so you can add the event to your calendar.',
        'action' => 'Event details',
    ],
];
