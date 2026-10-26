<?php

declare(strict_types=1);

/** RPT-03..06, journey 11.10, ADM-08/09, EVL-04 — reporting & admin productivity strings. */
return [
    'yes' => 'Yes',
    'no' => 'No',

    'attributes' => [
        'dataset' => 'data source', 'columns' => 'columns', 'group_by' => 'grouping', 'filters.from' => 'from date', 'filters.to' => 'to date',
        'definition' => 'report definition', 'frequency' => 'frequency', 'format' => 'format', 'recipients' => 'recipients',
        'period_type' => 'period type', 'anchor_date' => 'date within the period', 'summary' => 'summary', 'audience' => 'audience', 'note' => 'note',
        'uuids' => 'selected projects', 'ids' => 'selected memberships', 'reviewer_user_id' => 'reviewer',
        'advisory_user_id' => 'advisory member', 'question' => 'question', 'expires_at' => 'access expiry', 'opinion' => 'opinion',
    ],

    'datasets' => [
        'opportunities' => 'Projects',
        'memberships' => 'Memberships & investors',
        'interests' => 'Interests & access requests',
        'nominations' => 'Nominations',
        'meetings' => 'Meetings',
        'evaluations' => 'Evaluations',
        'accounts' => 'Registered accounts',
    ],

    'columns' => [
        'reference_no' => 'Reference', 'name' => 'Name', 'status' => 'Status', 'sector' => 'Sector', 'stage' => 'Stage',
        'regions' => 'Regions', 'funding_range' => 'Funding range', 'funding_amount' => 'Funding amount (SAR)', 'organization' => 'Organization',
        'owner' => 'Owner', 'assignee' => 'Assignee', 'evaluation_result' => 'Evaluation result', 'submitted_at' => 'Submitted',
        'review_due_at' => 'SLA due', 'overdue' => 'Overdue', 'month' => 'Month', 'investor' => 'Investor', 'investor_type' => 'Investor type',
        'approved_at' => 'Approved', 'completion' => 'Profile completion (%)', 'sectors' => 'Preferred sectors', 'investment_range' => 'Ticket range',
        'how_heard' => 'Acquisition channel', 'opportunity_ref' => 'Project ref', 'opportunity' => 'Project', 'owner_decision' => 'Owner decision',
        'source' => 'Source', 'created_at' => 'Created', 'updated_at' => 'Updated', 'owner_decision_due_at' => 'Owner decision due',
        'owner_decided_at' => 'Owner decided', 'closed_at' => 'Closed', 'response' => 'Response', 'match_score' => 'Match score',
        'nominator' => 'Nominated by', 'sent_at' => 'Sent', 'responded_at' => 'Responded', 'title' => 'Title', 'starts_at' => 'Starts',
        'organizer' => 'Organizer', 'participants' => 'Participants', 'has_minutes' => 'Minutes', 'evaluator' => 'Evaluator', 'due_at' => 'Due',
        'coi' => 'Conflict of interest', 'overall' => 'Overall score', 'recommendation' => 'Recommendation', 'type' => 'Type', 'verified' => 'Verified',
        'last_login_at' => 'Last login', 'count' => 'Count', 'group' => 'Group',
    ],

    'values' => [
        'via_nomination' => 'Via nomination',
        'direct' => 'Direct',
        'no_response' => 'No response',
    ],

    'metrics' => [
        'submitted' => 'Submitted', 'review' => 'Screening', 'completion' => 'Completion', 'evaluation' => 'Evaluation', 'owner_decision' => 'Owner decision',
        'matching' => 'Matching', 'connection' => 'Connection', 'negotiation' => 'Negotiation', 'rate_percent' => 'Rate', 'avg_days' => 'Average days',
        'days' => 'days', 'samples' => 'Samples', 'max_days' => 'Max days', 'sla_days' => 'SLA (days)',
        'needed_completion' => 'Needed completion', 'first_time_complete' => 'Complete first time', 'eligible' => 'Eligible', 'not_eligible' => 'Not eligible',
        'nominations_sent' => 'Nominations sent', 'nominations_interested' => 'Interested', 'nominations_declined' => 'Declined',
        'interests_total' => 'Total interests', 'interests' => 'Interests', 'owner_approved' => 'Owner approved', 'connected' => 'Connected',
        'closed' => 'Closed', 'under_review' => 'Under review', 'in_execution' => 'In execution',
    ],

    'errors' => [
        'unknown_dataset' => 'Unknown data source.',
        'bad_column' => 'Column “:column” is not available in this data source.',
        'bad_group' => 'This field cannot be used for grouping.',
        'no_columns' => 'Pick at least one of the columns available to you.',
        'not_drillable' => 'No record list is available for this indicator.',
        'saved_forbidden' => 'You cannot change a report created by a colleague.',
        'recipients' => 'Every recipient must be a staff member allowed to view reports.',
        'periodic_state' => 'This action is not available for the report in its current status.',
        'periodic_locked' => 'The report for this period is approved or published; return it to draft before regenerating.',
        'reviewer_invalid' => 'The selected staff member cannot review.',
        'no_pool' => 'No reviewers are available for automatic assignment.',
        'advisor_invalid' => 'The selected account is not an active advisory committee member.',
        'advisory_expired' => 'The referral has expired; the file and the opinion form are no longer available.',
        'advisory_not_yours' => 'This referral is not addressed to you.',
    ],

    'skip' => [
        'not_found' => 'Not found',
        'forbidden' => 'Not allowed',
        'terminal' => 'File closed',
        'no_pending_request' => 'No pending request',
        'recently_reminded' => 'Reminded in the last 24 hours',
        'no_reviewer' => 'No reviewer available',
    ],

    'pdf' => [
        'internal' => 'Confidential — internal Council use',
        'public' => 'Published — aggregated data',
        'generated' => 'Generated',
        'no_rows' => 'No matching data.',
        'truncated' => 'Showing the first :shown of :total rows.',
        'period' => 'Period',
        'status' => 'Status',
        'approved' => 'Approved',
        'filters' => 'Filters',
        'dataset' => 'Data source',
        'rows' => 'Rows',
        'grouped_by' => 'Grouped by',
        'all' => 'All',
    ],

    'periodic' => [
        'statuses' => ['draft' => 'Draft', 'approved' => 'Approved', 'published' => 'Published'],
        'audiences' => ['staff' => 'Council staff', 'public' => 'the public'],
        'title_monthly' => 'Monthly report — :month',
        'title_quarterly' => 'Quarterly report — Q:q :year',
        'title_annual' => 'Annual report — :year',
    ],

    'notifications' => [
        'scheduled' => [
            'subject' => 'Scheduled report: :name',
            'title' => 'Scheduled report sent',
            'line' => 'Attached: “:name” (:rows rows) as of :date.',
            'truncated' => 'The file holds the first :shown of :total rows — narrow the filters to include everything.',
        ],
        'periodic' => [
            'awaiting_subject' => 'Periodic report awaiting approval: :title',
            'awaiting_title' => 'Periodic report awaiting approval',
            'awaiting_line' => 'A draft of “:title” was generated from the current KPIs and awaits your review and approval.',
            'returned_subject' => 'Periodic report returned to draft: :title',
            'returned_title' => 'Periodic report returned to draft',
            'returned_line' => '“:title” was returned to draft. Note: :note',
            'published_subject' => 'Periodic report published: :title',
            'published_title' => 'Periodic report published',
            'published_line' => '“:title” has been published to :audience.',
            'action' => 'View report',
        ],
        'advisory' => [
            'referred_subject' => 'Advisory referral: :ref',
            'referred_title' => 'File referred to the advisory committee',
            'referred_line' => 'The file “:name” was referred to you for an advisory opinion.',
            'question' => 'Question: :question',
            'expires' => 'Access expires on :date.',
            'referred_action' => 'Open referral',
            'opinion_subject' => 'New advisory opinion: :ref',
            'opinion_title' => 'Advisory opinion received',
            'opinion_line' => ':advisor gave an opinion on “:name”.',
            'opinion_action' => 'View opinion',
        ],
        'reminders' => [
            'access_requests_subject' => 'Reminder: access requests awaiting your decision',
            'access_requests_title' => 'Access requests awaiting your decision',
            'access_requests_line' => 'You have :count access request(s) on “:name” awaiting approval or decline.',
            'access_requests_action' => 'Review requests',
            'membership_completion_subject' => 'Reminder: complete your membership file',
            'membership_completion_title' => 'Membership file awaiting completion',
            'membership_completion_line' => 'The Council asked you to complete your membership file. :note',
            'membership_submit_subject' => 'Reminder: finish your membership application',
            'membership_submit_title' => 'Membership application not submitted yet',
            'membership_submit_line' => 'Complete your investor profile and submit your membership application to access opportunities.',
            'membership_action' => 'Complete profile',
        ],
    ],
];
