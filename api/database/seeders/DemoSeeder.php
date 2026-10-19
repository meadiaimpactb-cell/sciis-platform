<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AssignmentStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\EvaluationResult;
use App\Enums\InstitutionType;
use App\Enums\InterestStatus;
use App\Enums\LegalForm;
use App\Enums\MembershipStatus;
use App\Enums\OpportunityStatus;
use App\Enums\OwnerDecision;
use App\Enums\ProjectStage;
use App\Enums\RevenueState;
use App\Enums\RiskAppetite;
use App\Enums\ScanResult;
use App\Enums\Sensitivity;
use App\Enums\UserType;
use App\Models\Document;
use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationCriterion;
use App\Models\EvaluationDecision;
use App\Models\FileAccessGrant;
use App\Models\ImpactIndicator;
use App\Models\Interest;
use App\Models\InvestorProfile;
use App\Models\Membership;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Models\OpportunityStatusTransition;
use App\Models\Organization;
use App\Models\TaxonomyTerm;
use App\Models\Teaser;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * SPEC §11 — synthetic demo data, every name suffixed "(افتراضي)". SEC-04: never run in production.
 * Password for every demo account: `Password123!`
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'Password123!';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder must not run in production (SEC-04).');

            return;
        }

        $this->call([RolePermissionSeeder::class, TaxonomySeeder::class, EvaluationCriteriaSeeder::class, SettingsSeeder::class, LegalPagesSeeder::class]);

        $staff = $this->staff();
        $investors = $this->investors();
        $entrepreneurs = $this->entrepreneurs();
        $this->opportunities($entrepreneurs, $staff, $investors);
    }

    /** @return array<string, User> */
    private function staff(): array
    {
        $defs = [
            'super_admin' => ['admin@sciis.test', 'مدير النظام (افتراضي)'],
            'membership_officer' => ['membership@sciis.test', 'مسؤول العضوية (افتراضي)'],
            'reviewer' => ['reviewer@sciis.test', 'المراجع (افتراضي)'],
            'evaluator' => ['evaluator1@sciis.test', 'المقيّم الأول (افتراضي)'],
            'evaluator2' => ['evaluator2@sciis.test', 'المقيّم الثاني (افتراضي)'],
            'matching_officer' => ['matching@sciis.test', 'مسؤول الربط (افتراضي)'],
            'agreements_officer' => ['agreements@sciis.test', 'مسؤول الاتفاقيات (افتراضي)'],
            'impact_officer' => ['impact@sciis.test', 'مسؤول الأثر (افتراضي)'],
            'advisory_member' => ['advisory@sciis.test', 'عضو اللجنة الاستشارية (افتراضي)'],
            'reports_viewer' => ['reports@sciis.test', 'مستعرض التقارير (افتراضي)'],
        ];

        $users = [];
        $i = 0;
        foreach ($defs as $key => [$email, $name]) {
            $role = $key === 'evaluator2' ? 'evaluator' : $key;
            $user = User::query()->updateOrCreate(['email' => $email], [
                'type' => UserType::Staff, 'name' => $name, 'phone' => '+96650000010'.$i, 'password' => self::PASSWORD,
                'locale' => 'ar', 'email_verified_at' => now(), 'phone_verified_at' => now(),
            ]);
            $user->syncRoles([$role]);
            $users[$key] = $user;
            $i++;
        }

        return $users;
    }

    /** @return array<string, User> */
    private function investors(): array
    {
        $t = fn (string $group, string $key): ?int => TaxonomyTerm::query()->group($group)->where('key', $key)->value('id');

        $defs = [
            'abdullah' => [
                'user' => ['email' => 'abdullah@investor.test', 'name' => 'عبدالله (مستثمر فرد — افتراضي)', 'type' => UserType::InvestorIndividual, 'phone' => '+966500000201'],
                'profile' => ['country' => 'السعودية', 'city' => 'الرياض', 'job_title' => 'رائد أعمال', 'experience_years' => 12, 'prior_investments_count' => 4, 'risk_appetite' => RiskAppetite::Medium, 'mentoring_available' => true, 'commit_meetings' => true, 'investment_range_id' => $t('investment_ranges', 'r2'), 'how_heard_id' => $t('how_heard', 'event'), 'conflicts_disclosure' => 'لا يوجد', 'preferred_contact' => 'email'],
                'sectors' => ['education', 'employment', 'youth'], 'stages' => ['seed', 'early_stage'], 'types' => ['equity', 'convertible'], 'regions' => ['riyadh', 'qassim'],
                'status' => MembershipStatus::Approved,
            ],
            'family_office' => [
                'user' => ['email' => 'office@investor.test', 'name' => 'مكتب آل سعد العائلي (افتراضي)', 'type' => UserType::InvestorInstitutional, 'phone' => '+966500000202'],
                'org' => ['name' => 'مكتب آل سعد العائلي (افتراضي)', 'institution_type' => InstitutionType::FamilyOffice, 'legal_form' => LegalForm::Company],
                'profile' => ['country' => 'السعودية', 'city' => 'جدة', 'job_title' => 'مدير الاستثمار', 'experience_years' => 20, 'prior_investments_count' => 15, 'risk_appetite' => RiskAppetite::Low, 'mentoring_available' => false, 'commit_meetings' => true, 'investment_range_id' => $t('investment_ranges', 'r3'), 'how_heard_id' => $t('how_heard', 'referral'), 'conflicts_disclosure' => 'لا يوجد', 'preferred_contact' => 'phone'],
                'sectors' => ['health', 'housing', 'elderly', 'disability'], 'stages' => ['early_stage', 'later_stage'], 'types' => ['debt', 'patient_capital', 'blended'], 'regions' => ['makkah', 'madinah'],
                'status' => MembershipStatus::Approved,
            ],
            'fund' => [
                'user' => ['email' => 'fund@investor.test', 'name' => 'صندوق الأثر الوطني (افتراضي)', 'type' => UserType::InvestorInstitutional, 'phone' => '+966500000203'],
                'org' => ['name' => 'صندوق الأثر الوطني (افتراضي)', 'institution_type' => InstitutionType::Fund, 'legal_form' => LegalForm::Company],
                'profile' => ['country' => 'السعودية', 'city' => 'الرياض', 'job_title' => 'شريك', 'experience_years' => 8, 'prior_investments_count' => 9, 'risk_appetite' => RiskAppetite::High, 'mentoring_available' => true, 'commit_meetings' => true, 'investment_range_id' => $t('investment_ranges', 'r3'), 'how_heard_id' => $t('how_heard', 'partner'), 'conflicts_disclosure' => 'عضوية سابقة في مجلس إدارة مبادرة دوّر', 'preferred_contact' => 'email'],
                'sectors' => ['environment', 'circular_economy', 'food_security', 'water_energy', 'employment'], 'stages' => ['seed', 'early_stage'], 'types' => ['equity', 'pay_for_results', 'revenue_share'], 'regions' => ['riyadh', 'eastern', 'asir'],
                'status' => MembershipStatus::UnderReview,
            ],
        ];

        $users = [];
        foreach ($defs as $key => $def) {
            $user = User::query()->updateOrCreate(['email' => $def['user']['email']], $def['user'] + [
                'password' => self::PASSWORD, 'locale' => 'ar', 'email_verified_at' => now(), 'phone_verified_at' => now(), 'last_login_at' => now()->subDays(2),
            ]);
            if (isset($def['org'])) {
                $org = Organization::query()->updateOrCreate(['name' => $def['org']['name']], $def['org'] + ['representative_user_id' => $user->id, 'region_id' => $t('regions', 'riyadh'), 'city' => 'الرياض', 'founded_on' => '2015-01-01']);
                $user->organization_id = $org->id;
                $user->save();
            }

            $profile = InvestorProfile::query()->updateOrCreate(['user_id' => $user->id], $def['profile']);
            $profile->sectors()->sync(TaxonomyTerm::query()->group('sectors')->whereIn('key', $def['sectors'])->pluck('id'));
            $profile->stages()->sync(TaxonomyTerm::query()->group('project_stages')->whereIn('key', $def['stages'])->pluck('id'));
            $profile->investmentTypes()->sync(TaxonomyTerm::query()->group('investment_types')->whereIn('key', $def['types'])->pluck('id'));
            $profile->regions()->sync(TaxonomyTerm::query()->group('regions')->whereIn('key', $def['regions'])->pluck('id'));
            $profile->completion_percent = $profile->computeCompletion();
            $profile->save();

            $approved = $def['status'] === MembershipStatus::Approved;
            Membership::query()->updateOrCreate(['user_id' => $user->id], [
                'status' => $def['status'], 'submitted_at' => now()->subDays(20), 'terms_accepted_at' => now()->subDays(20),
                'reviewed_at' => $approved ? now()->subDays(15) : null, 'approved_at' => $approved ? now()->subDays(15) : null,
            ]);

            $users[$key] = $user;
        }

        return $users;
    }

    /** @return array<int, User> */
    private function entrepreneurs(): array
    {
        $defs = [
            ['email' => 'sara@owner.test', 'name' => 'سارة (صاحبة مشروع — افتراضي)', 'type' => UserType::EntrepreneurIndividual, 'phone' => '+966500000301', 'org' => null],
            ['email' => 'khalid@owner.test', 'name' => 'خالد (صاحب مشروع — افتراضي)', 'type' => UserType::EntrepreneurEntity, 'phone' => '+966500000302', 'org' => 'شركة أثر للتنمية (افتراضي)'],
            ['email' => 'noura@owner.test', 'name' => 'نورة (صاحبة مشروع — افتراضي)', 'type' => UserType::EntrepreneurEntity, 'phone' => '+966500000303', 'org' => 'جمعية نماء المجتمعية (افتراضي)'],
        ];

        $users = [];
        foreach ($defs as $def) {
            $org = $def['org'];
            unset($def['org']);
            $user = User::query()->updateOrCreate(['email' => $def['email']], $def + ['password' => self::PASSWORD, 'locale' => 'ar', 'email_verified_at' => now(), 'phone_verified_at' => now(), 'last_login_at' => now()->subDay()]);
            if ($org) {
                $o = Organization::query()->updateOrCreate(['name' => $org], ['legal_form' => str_contains($org, 'جمعية') ? LegalForm::Nonprofit : LegalForm::Company, 'representative_user_id' => $user->id, 'founded_on' => '2019-06-01', 'employees_count' => 12, 'city' => 'الرياض', 'region_id' => TaxonomyTerm::query()->group('regions')->where('key', 'riyadh')->value('id')]);
                $user->organization_id = $o->id;
                $user->save();
            }
            $users[] = $user;
        }

        return $users;
    }

    /**
     * @param  array<int, User>  $owners
     * @param  array<string, User>  $staff
     * @param  array<string, User>  $investors
     */
    private function opportunities(array $owners, array $staff, array $investors): void
    {
        $t = fn (string $group, string $key): ?int => TaxonomyTerm::query()->group($group)->where('key', $key)->value('id');
        $ids = fn (string $group, array $keys) => TaxonomyTerm::query()->group($group)->whereIn('key', $keys)->pluck('id')->all();

        $defs = [
            ['سُلّم (افتراضي)', 'منصة تدريب مهني مرتبطة بالتوظيف للشباب في المناطق الطرفية.', 'education', ProjectStage::EarlyStage, OpportunityStatus::Eligible, 1_500_000, 'r3', ['riyadh', 'qassim'], ['equity', 'convertible'], 0, 'regular', 2400, 'مسار كامل: ترشيح + اهتمام + موافقة + NDA'],
            ['مهارة (افتراضي)', 'مراكز تعليم مهارات رقمية للنساء في المدن الصغيرة.', 'women_empowerment', ProjectStage::Seed, OpportunityStatus::Connected, 800_000, 'r2', ['madinah'], ['equity'], 0, 'early', 1200, 'متصلة بمستثمر'],
            ['رفق (افتراضي)', 'رعاية منزلية لكبار السن عبر تطبيق وشبكة ممرضين.', 'elderly', ProjectStage::EarlyStage, OpportunityStatus::UnderReview, 3_000_000, 'r3', ['makkah'], ['debt', 'patient_capital'], 1, 'regular', 900, 'قيد التقييم'],
            ['شكّن (افتراضي)', 'إسكان ميسّر تشاركي للأسر محدودة الدخل.', 'housing', ProjectStage::LaterStage, OpportunityStatus::New, 12_000_000, 'r4', ['eastern'], ['blended', 'guarantee'], 1, 'regular', 300, 'جديدة بانتظار الفرز'],
            ['حصاد (افتراضي)', 'زراعة مائية مجتمعية لتعزيز الأمن الغذائي.', 'food_security', ProjectStage::Prototype, OpportunityStatus::NeedsCompletion, 450_000, 'r2', ['asir'], ['grant', 'revenue_share'], 2, 'none', 500, 'بحاجة لاستكمال'],
            ['نماء (افتراضي)', 'حاضنة للمشاريع الصغيرة في الأحياء الفقيرة.', 'employment', ProjectStage::Seed, OpportunityStatus::NotEligible, 600_000, 'r2', ['riyadh'], ['grant'], 2, 'early', 150, 'غير مؤهلة — يسمح بإعادة التقديم'],
            ['دوّر (افتراضي)', 'إعادة تدوير النفايات الإلكترونية وتشغيل ذوي الإعاقة.', 'circular_economy', ProjectStage::EarlyStage, OpportunityStatus::Eligible, 2_200_000, 'r3', ['riyadh', 'eastern'], ['equity', 'pay_for_results'], 1, 'regular', 700, 'مؤهلة — تعارض مصالح مع الصندوق'],
            ['وصول (افتراضي)', 'نقل ميسّر لذوي الإعاقة إلى مراكز العمل والتأهيل.', 'transport_mobility', ProjectStage::Idea, OpportunityStatus::Draft, 350_000, 'r2', ['riyadh'], ['grant'], 0, 'none', 200, 'مسودة'],
        ];

        $criteria = EvaluationCriterion::query()->active()->get();
        $ref = 1;

        foreach ($defs as $i => [$name, $short, $sector, $stage, $status, $amount, $range, $regions, $types, $ownerIdx, $revenue, $target, $note]) {
            $owner = $owners[$ownerIdx];
            $submitted = $status !== OpportunityStatus::Draft;

            $o = Opportunity::query()->updateOrCreate(['name' => $name], [
                'owner_user_id' => $owner->id,
                'organization_id' => $owner->organization_id,
                'short_description' => $short,
                'sector_id' => $t('sectors', $sector),
                'stage' => $stage,
                'status' => $status,
                'is_listed' => true,
                'reference_no' => $submitted ? sprintf('A-%s-%04d', now()->format('Y'), $ref++) : null,
                'problem_statement' => 'المشكلة: '.$short,
                'solution' => 'الحل: نموذج تشغيلي مجرّب مع شركاء محليين. (افتراضي)',
                'expected_change' => 'التغيير المتوقع: تحسين مؤشرات الدخل والتمكين خلال 24 شهرًا. (افتراضي)',
                'beneficiaries_desc' => 'الفئات المستهدفة في المناطق المحددة. (افتراضي)',
                'beneficiaries_current' => (int) ($target / 4),
                'beneficiaries_target' => $target,
                'revenue_model_type' => 'subscription',
                'revenue_model_desc' => 'اشتراكات + عقود جهات حكومية. (افتراضي)',
                'revenue_state' => RevenueState::from($revenue),
                'funding_amount' => $amount,
                'funding_range_id' => $t('investment_ranges', $range),
                'use_of_funds' => [['item' => 'التوسع التشغيلي', 'percent' => 50], ['item' => 'التقنية', 'percent' => 30], ['item' => 'التسويق', 'percent' => 20]],
                'prior_funding' => $i % 2 === 0,
                'prior_funding_desc' => $i % 2 === 0 ? 'منحة تأسيسية 200 ألف ريال من جهة مانحة. (افتراضي)' : null,
                'legal_disputes' => false,
                'license_required' => true,
                'license_status' => 'ساري',
                'operational_risks' => 'اعتماد على شركاء تشغيل محدودين؛ خطة التخفيف: تنويع الشركاء. (افتراضي)',
                'council_relationship' => false,
                'data_accuracy_declared_at' => $submitted ? now()->subDays(30 - $i) : null,
                'submitted_at' => $submitted ? now()->subDays(30 - $i) : null,
                'assigned_reviewer_id' => $submitted ? $staff['reviewer']->id : null,
                'review_due_at' => $submitted ? now()->subDays(25 - $i) : null,
                'completion_due_at' => $status === OpportunityStatus::NeedsCompletion ? now()->addDays(7) : null,
                'evaluation_due_at' => $status === OpportunityStatus::UnderReview ? now()->addDays(5) : null,
                'evaluation_result' => in_array($status, [OpportunityStatus::Eligible, OpportunityStatus::Connected], true) ? EvaluationResult::Eligible : null,
                'evaluation_approved_at' => in_array($status, [OpportunityStatus::Eligible, OpportunityStatus::Connected], true) ? now()->subDays(10) : null,
            ]);

            $o->regions()->sync($ids('regions', $regions));
            $o->investmentTypes()->sync($ids('investment_types', $types));
            $o->beneficiaryGroups()->sync($ids('beneficiary_groups', ['youth', 'low_income']));
            $o->sdgs()->sync($ids('sdgs', ['sdg_8', 'sdg_10']));

            ImpactIndicator::query()->updateOrCreate(['opportunity_id' => $o->id, 'is_primary' => true], [
                'name' => 'عدد المستفيدين المباشرين', 'definition' => 'إجمالي المستفيدين الذين أكملوا الخدمة', 'unit' => 'مستفيد',
                'baseline' => (int) ($target / 4), 'target' => $target, 'data_source' => 'سجلات النظام', 'frequency' => 'quarterly',
                'verification_method' => 'تدقيق عينة', 'sdg_id' => $t('sdgs', 'sdg_8'), 'is_disclosed' => true,
            ]);
            ImpactIndicator::query()->updateOrCreate(['opportunity_id' => $o->id, 'name' => 'نسبة التوظيف بعد البرنامج'], [
                'unit' => '%', 'baseline' => 20, 'target' => 60, 'frequency' => 'semiannual', 'is_primary' => false, 'is_disclosed' => false,
            ]);

            // Documents (placeholder files on the local disk).
            $this->document($o, $owner, DocumentKind::PitchDeck, 'العرض التقديمي (افتراضي)');
            if ($submitted) {
                $this->document($o, $owner, DocumentKind::FinancialStatements, 'القوائم المالية (افتراضي)');
            }

            // Timeline
            if ($submitted) {
                $this->transition($o, OpportunityStatus::Draft, OpportunityStatus::New, $owner, now()->subDays(30 - $i));
            }
            if (in_array($status, [OpportunityStatus::UnderReview, OpportunityStatus::Eligible, OpportunityStatus::Connected, OpportunityStatus::NotEligible], true)) {
                $this->transition($o, OpportunityStatus::New, OpportunityStatus::UnderReview, $staff['reviewer'], now()->subDays(27 - $i));
            }
            if ($status === OpportunityStatus::NeedsCompletion) {
                $this->transition($o, OpportunityStatus::New, OpportunityStatus::NeedsCompletion, $staff['reviewer'], now()->subDays(27 - $i), 'المطلوب: السجل التجاري + تفصيل أوجه الصرف');
                $o->completionRequests()->create(['requested_by' => $staff['reviewer']->id, 'due_at' => now()->addDays(7), 'items' => [['document_kind' => 'commercial_register', 'note' => 'نسخة سارية'], ['field' => 'use_of_funds', 'note' => 'تفصيل أدق للبنود']]]);
            }
            if ($status === OpportunityStatus::NotEligible) {
                $this->transition($o, OpportunityStatus::UnderReview, OpportunityStatus::NotEligible, $staff['reviewer'], now()->subDays(20), 'خارج نطاق القطاعات المستهدفة حاليًا؛ يمكن إعادة التقديم بعد تعديل النموذج.');
            }

            // Evaluations for under_review / eligible / connected
            if (in_array($status, [OpportunityStatus::UnderReview, OpportunityStatus::Eligible, OpportunityStatus::Connected], true)) {
                foreach (['evaluator', 'evaluator2'] as $k => $evKey) {
                    $assignment = EvaluationAssignment::query()->updateOrCreate(['opportunity_id' => $o->id, 'evaluator_user_id' => $staff[$evKey]->id], [
                        'assigned_by' => $staff['reviewer']->id, 'status' => $status === OpportunityStatus::UnderReview && $k === 1 ? AssignmentStatus::CoiDeclared : AssignmentStatus::Scored,
                        'coi_declared_at' => now()->subDays(15), 'coi_has_conflict' => false, 'due_at' => now()->addDays(5),
                    ]);
                    if ($assignment->status === AssignmentStatus::Scored) {
                        $scores = [];
                        foreach ($criteria as $c) {
                            $scores[$c->key] = ['score' => 3 + (($i + $k + strlen($c->key)) % 3), 'note' => null];
                        }
                        Evaluation::query()->updateOrCreate(['assignment_id' => $assignment->id], [
                            'criteria_version' => 1, 'criteria_snapshot' => $criteria->map(fn ($c) => $c->only(['axis', 'key', 'label_ar', 'weight', 'version']))->all(),
                            'scores' => $scores, 'axis_totals' => null, 'overall' => 3.8 + $k * 0.2, 'recommendation' => EvaluationResult::Eligible, 'comment' => 'تقييم افتراضي', 'submitted_at' => now()->subDays(12),
                        ]);
                    }
                }
                if ($status !== OpportunityStatus::UnderReview) {
                    EvaluationDecision::query()->firstOrCreate(['opportunity_id' => $o->id], ['result' => EvaluationResult::Eligible, 'decided_by' => $staff['super_admin']->id, 'decided_at' => now()->subDays(10)]);
                    $this->transition($o, OpportunityStatus::UnderReview, OpportunityStatus::Eligible, $staff['super_admin'], now()->subDays(10));
                }
            }

            // Teaser for eligible / connected
            if (in_array($status, [OpportunityStatus::Eligible, OpportunityStatus::Connected], true)) {
                Teaser::query()->updateOrCreate(['opportunity_id' => $o->id], [
                    'summary_ar' => $short.' نموذج مثبت بإيرادات مبكرة وفريق متفرغ. (ملخص افتراضي)',
                    'highlights' => ['فريق متفرغ', 'شركاء حكوميون', 'مؤشرات أثر قابلة للقياس'],
                    'version' => 1, 'approved_by_owner_at' => now()->subDays(9), 'approved_by_council_at' => now()->subDays(9), 'approved_by_user_id' => $staff['matching_officer']->id,
                ]);
            }

            // Matching flows
            if ($name === 'سُلّم (افتراضي)') {
                $abdullah = $investors['abdullah'];
                $nom = Nomination::query()->updateOrCreate(['opportunity_id' => $o->id, 'investor_user_id' => $abdullah->id], [
                    'nominated_by' => $staff['matching_officer']->id, 'matched_criteria' => ['sector', 'stage', 'region', 'investment_type'], 'match_score' => 4,
                    'rationale' => 'توافق عالٍ في القطاع والمرحلة والمنطقة ونوع الاستثمار.', 'teaser_version' => 1, 'sent_at' => now()->subDays(8), 'response' => 'interested', 'responded_at' => now()->subDays(7),
                ]);
                $interest = Interest::query()->updateOrCreate(['opportunity_id' => $o->id, 'investor_user_id' => $abdullah->id], [
                    'nomination_id' => $nom->id, 'status' => InterestStatus::Shared, 'owner_decision' => OwnerDecision::Approved, 'owner_decided_at' => now()->subDays(6), 'owner_decision_due_at' => now()->subDays(1),
                ]);
                FileAccessGrant::query()->updateOrCreate(['opportunity_id' => $o->id, 'investor_user_id' => $abdullah->id], ['granted_by_owner_at' => now()->subDays(6)]);
                // Family office nominated, no response yet.
                Nomination::query()->updateOrCreate(['opportunity_id' => $o->id, 'investor_user_id' => $investors['family_office']->id], [
                    'nominated_by' => $staff['matching_officer']->id, 'matched_criteria' => ['investment_type'], 'match_score' => 1, 'rationale' => 'اهتمام سابق بالتعليم المهني.', 'teaser_version' => 1, 'sent_at' => now()->subDays(3),
                ]);
            }

            if ($name === 'مهارة (افتراضي)') {
                $fo = $investors['family_office'];
                Nomination::query()->updateOrCreate(['opportunity_id' => $o->id, 'investor_user_id' => $fo->id], [
                    'nominated_by' => $staff['matching_officer']->id, 'matched_criteria' => ['region'], 'match_score' => 1, 'rationale' => 'المنطقة مستهدفة لدى المكتب.', 'teaser_version' => 1, 'sent_at' => now()->subDays(14), 'response' => 'interested', 'responded_at' => now()->subDays(13),
                ]);
                $consent = $fo->consents()->firstOrCreate(['type' => 'nda', 'subject_type' => 'opportunity', 'subject_id' => $o->id], ['version' => '1.0', 'text_hash' => hash('sha256', 'nda|1.0'), 'accepted_at' => now()->subDays(11)]);
                FileAccessGrant::query()->updateOrCreate(['opportunity_id' => $o->id, 'investor_user_id' => $fo->id], ['granted_by_owner_at' => now()->subDays(12), 'nda_consent_id' => $consent->id]);
                Interest::query()->updateOrCreate(['opportunity_id' => $o->id, 'investor_user_id' => $fo->id], [
                    'status' => InterestStatus::Connected, 'owner_decision' => OwnerDecision::Approved, 'owner_decided_at' => now()->subDays(12),
                ]);
                $this->transition($o, OpportunityStatus::Eligible, OpportunityStatus::Connected, $staff['matching_officer'], now()->subDays(9));
            }
        }
    }

    private function document(Opportunity $o, User $owner, DocumentKind $kind, string $title): void
    {
        $disk = (string) config('sciis.documents.disk', 'local');
        $path = sprintf('opportunity/%d/demo/%s.pdf', $o->id, $kind->value);
        $content = "%PDF-1.4\n% demo placeholder (افتراضي)\n";
        try {
            $written = Storage::disk($disk)->put($path, $content);
        } catch (\Throwable) {
            $written = false;
        }
        if ($written === false) {
            $disk = 'local';
            Storage::disk($disk)->put($path, $content);
        }

        Document::query()->updateOrCreate(['documentable_type' => 'opportunity', 'documentable_id' => $o->id, 'kind' => $kind->value], [
            'owner_user_id' => $owner->id, 'title' => $title, 'disk' => $disk, 'path' => $path, 'mime' => 'application/pdf', 'size_bytes' => strlen($content),
            'sha256' => hash('sha256', $content), 'sensitivity' => Sensitivity::Confidential, 'status' => DocumentStatus::Accepted, 'scan_result' => ScanResult::Clean, 'scanned_at' => now(),
        ]);
    }

    private function transition(Opportunity $o, OpportunityStatus $from, OpportunityStatus $to, User $actor, \DateTimeInterface $at, ?string $reason = null): void
    {
        OpportunityStatusTransition::query()->firstOrCreate(
            ['opportunity_id' => $o->id, 'from_status' => $from->value, 'to_status' => $to->value],
            ['actor_user_id' => $actor->id, 'reason' => $reason, 'created_at' => $at],
        );
    }
}
