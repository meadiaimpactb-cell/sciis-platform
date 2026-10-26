<?php

declare(strict_types=1);

/** RPT-03..06, journey 11.10, ADM-08/09, EVL-04 — reporting & admin productivity strings. */
return [
    'yes' => 'نعم',
    'no' => 'لا',

    'attributes' => [
        'dataset' => 'مصدر البيانات', 'columns' => 'الأعمدة', 'group_by' => 'التجميع', 'filters.from' => 'من تاريخ', 'filters.to' => 'إلى تاريخ',
        'definition' => 'تعريف التقرير', 'frequency' => 'التكرار', 'format' => 'الصيغة', 'recipients' => 'المستلمون',
        'period_type' => 'نوع الفترة', 'anchor_date' => 'تاريخ ضمن الفترة', 'summary' => 'الملخص', 'audience' => 'الجمهور', 'note' => 'الملاحظة',
        'uuids' => 'المشاريع المختارة', 'ids' => 'العضويات المختارة', 'reviewer_user_id' => 'المراجع',
        'advisory_user_id' => 'عضو اللجنة الاستشارية', 'question' => 'السؤال', 'expires_at' => 'تاريخ انتهاء الاطلاع', 'opinion' => 'الرأي',
    ],

    'datasets' => [
        'opportunities' => 'المشاريع',
        'memberships' => 'العضويات والمستثمرون',
        'interests' => 'الاهتمامات وطلبات الاطلاع',
        'nominations' => 'الترشيحات',
        'meetings' => 'الاجتماعات',
        'evaluations' => 'التقييمات',
        'accounts' => 'الحسابات المسجلة',
    ],

    'columns' => [
        'reference_no' => 'الرقم المرجعي', 'name' => 'الاسم', 'status' => 'الحالة', 'sector' => 'القطاع', 'stage' => 'المرحلة',
        'regions' => 'المناطق', 'funding_range' => 'شريحة التمويل', 'funding_amount' => 'مبلغ التمويل (ريال)', 'organization' => 'الجهة',
        'owner' => 'صاحب المشروع', 'assignee' => 'المسند إليه', 'evaluation_result' => 'نتيجة التقييم', 'submitted_at' => 'تاريخ التقديم',
        'review_due_at' => 'موعد المهلة', 'overdue' => 'متأخر', 'month' => 'الشهر', 'investor' => 'المستثمر', 'investor_type' => 'نوع المستثمر',
        'approved_at' => 'تاريخ الاعتماد', 'completion' => 'اكتمال الملف (%)', 'sectors' => 'القطاعات المفضلة', 'investment_range' => 'شريحة الاستثمار',
        'how_heard' => 'قناة الاستقطاب', 'opportunity_ref' => 'مرجع المشروع', 'opportunity' => 'المشروع', 'owner_decision' => 'قرار صاحب المشروع',
        'source' => 'المصدر', 'created_at' => 'تاريخ الإنشاء', 'updated_at' => 'آخر تحديث', 'owner_decision_due_at' => 'موعد قرار صاحب المشروع',
        'owner_decided_at' => 'تاريخ قرار صاحب المشروع', 'closed_at' => 'تاريخ الإغلاق', 'response' => 'الرد', 'match_score' => 'درجة التوافق',
        'nominator' => 'المرشِّح', 'sent_at' => 'تاريخ الإرسال', 'responded_at' => 'تاريخ الرد', 'title' => 'العنوان', 'starts_at' => 'الموعد',
        'organizer' => 'المنظم', 'participants' => 'المشاركون', 'has_minutes' => 'المحضر', 'evaluator' => 'المقيّم', 'due_at' => 'تاريخ الاستحقاق',
        'coi' => 'تعارض مصالح', 'overall' => 'الدرجة الكلية', 'recommendation' => 'التوصية', 'type' => 'النوع', 'verified' => 'موثّق',
        'last_login_at' => 'آخر دخول', 'count' => 'العدد', 'group' => 'المجموعة',
    ],

    'values' => [
        'via_nomination' => 'عبر ترشيح',
        'direct' => 'مباشر',
        'no_response' => 'بلا رد',
    ],

    'metrics' => [
        'submitted' => 'مقدّمة', 'review' => 'الفرز', 'completion' => 'الاستكمال', 'evaluation' => 'التقييم', 'owner_decision' => 'قرار صاحب المشروع',
        'matching' => 'الترشيح والربط', 'connection' => 'الربط', 'negotiation' => 'التفاوض', 'rate_percent' => 'النسبة', 'avg_days' => 'متوسط الأيام',
        'days' => 'يوم', 'samples' => 'عدد العينات', 'max_days' => 'أقصى مدة (يوم)', 'sla_days' => 'المهلة المعتمدة (يوم)',
        'needed_completion' => 'احتاجت استكمالًا', 'first_time_complete' => 'مكتملة من أول مرة', 'eligible' => 'مؤهلة', 'not_eligible' => 'غير مؤهلة',
        'nominations_sent' => 'ترشيحات مرسلة', 'nominations_interested' => 'أبدوا اهتمامًا', 'nominations_declined' => 'اعتذروا',
        'interests_total' => 'إجمالي الاهتمامات', 'interests' => 'الاهتمامات', 'owner_approved' => 'وافق صاحب المشروع', 'connected' => 'تم الربط',
        'closed' => 'مغلقة', 'under_review' => 'قيد المراجعة', 'in_execution' => 'قيد التنفيذ',
    ],

    'errors' => [
        'unknown_dataset' => 'مصدر البيانات المطلوب غير معروف.',
        'bad_column' => 'العمود «:column» غير متاح في هذا المصدر.',
        'bad_group' => 'لا يمكن التجميع حسب هذا الحقل.',
        'no_columns' => 'اختر عمودًا واحدًا على الأقل من الأعمدة المتاحة لك.',
        'not_drillable' => 'لا تتوفر قائمة تفصيلية لهذا المؤشر.',
        'saved_forbidden' => 'لا يمكنك تعديل تقرير أنشأه زميل آخر.',
        'recipients' => 'كل مستلم يجب أن يكون من فريق المجلس ولديه صلاحية عرض التقارير.',
        'periodic_state' => 'لا يمكن تنفيذ هذا الإجراء على التقرير في حالته الحالية.',
        'periodic_locked' => 'تقرير هذه الفترة معتمد أو منشور؛ أعده إلى المسودة أولًا لإعادة توليده.',
        'reviewer_invalid' => 'الموظف المختار لا يملك صلاحية المراجعة.',
        'no_pool' => 'لا يوجد مراجعون متاحون للإسناد التلقائي.',
        'advisor_invalid' => 'الحساب المختار ليس عضوًا نشطًا في اللجنة الاستشارية.',
        'advisory_expired' => 'انتهت مدة الإحالة؛ لم يعد بالإمكان الاطلاع أو إبداء الرأي.',
        'advisory_not_yours' => 'هذه الإحالة ليست موجهة إليك.',
    ],

    'skip' => [
        'not_found' => 'غير موجود',
        'forbidden' => 'غير مصرح',
        'terminal' => 'الملف مغلق',
        'no_pending_request' => 'لا يوجد طلب معلّق',
        'recently_reminded' => 'أُرسل تذكير خلال آخر 24 ساعة',
        'no_reviewer' => 'لا يوجد مراجع متاح',
    ],

    'pdf' => [
        'internal' => 'سري — للاستخدام الداخلي في المجلس',
        'public' => 'منشور للعموم — بيانات مجمّعة',
        'generated' => 'أُنشئ في',
        'no_rows' => 'لا توجد بيانات مطابقة.',
        'truncated' => 'يعرض أول :shown صف من أصل :total.',
        'period' => 'الفترة',
        'status' => 'الحالة',
        'approved' => 'تاريخ الاعتماد',
        'filters' => 'عوامل التصفية',
        'dataset' => 'مصدر البيانات',
        'rows' => 'عدد الصفوف',
        'grouped_by' => 'التجميع حسب',
        'all' => 'الكل',
    ],

    'periodic' => [
        'statuses' => ['draft' => 'مسودة', 'approved' => 'معتمد', 'published' => 'منشور'],
        'audiences' => ['staff' => 'فريق المجلس', 'public' => 'العموم'],
        'title_monthly' => 'التقرير الشهري — :month',
        'title_quarterly' => 'التقرير الربعي — الربع :q من :year',
        'title_annual' => 'التقرير السنوي — :year',
    ],

    'notifications' => [
        'scheduled' => [
            'subject' => 'تقرير مجدول: :name',
            'title' => 'أُرسل التقرير المجدول',
            'line' => 'مرفق تقرير «:name» (:rows صف) بتاريخ :date.',
            'truncated' => 'الملف يتضمن أول :shown صف من أصل :total — ضيّق عوامل التصفية لعرض الكل.',
        ],
        'periodic' => [
            'awaiting_subject' => 'تقرير دوري بانتظار الاعتماد: :title',
            'awaiting_title' => 'تقرير دوري بانتظار الاعتماد',
            'awaiting_line' => 'أُعدّت مسودة «:title» من المؤشرات الحالية وهي بانتظار مراجعتك واعتمادك.',
            'returned_subject' => 'أُعيد التقرير الدوري للمسودة: :title',
            'returned_title' => 'أُعيد التقرير الدوري للمسودة',
            'returned_line' => 'أُعيد «:title» إلى المسودة. الملاحظة: :note',
            'published_subject' => 'نُشر تقرير دوري: :title',
            'published_title' => 'نُشر تقرير دوري',
            'published_line' => 'نُشر «:title» وأصبح متاحًا لـ:audience.',
            'action' => 'عرض التقرير',
        ],
        'advisory' => [
            'referred_subject' => 'إحالة استشارية: :ref',
            'referred_title' => 'إحالة ملف للجنة الاستشارية',
            'referred_line' => 'أُحيل إليك ملف «:name» لإبداء رأيك الاستشاري.',
            'question' => 'السؤال: :question',
            'expires' => 'تنتهي صلاحية الاطلاع في :date.',
            'referred_action' => 'فتح الإحالة',
            'opinion_subject' => 'رأي استشاري جديد: :ref',
            'opinion_title' => 'وصل الرأي الاستشاري',
            'opinion_line' => 'أبدى :advisor رأيه في ملف «:name».',
            'opinion_action' => 'عرض الرأي',
        ],
        'reminders' => [
            'access_requests_subject' => 'تذكير: طلبات اطلاع بانتظار قرارك',
            'access_requests_title' => 'طلبات اطلاع بانتظار قرارك',
            'access_requests_line' => 'لديك :count طلب اطلاع على مشروع «:name» بانتظار الموافقة أو الاعتذار.',
            'access_requests_action' => 'مراجعة الطلبات',
            'membership_completion_subject' => 'تذكير: استكمال ملف العضوية',
            'membership_completion_title' => 'ملف العضوية بانتظار الاستكمال',
            'membership_completion_line' => 'طلب فريق المجلس استكمال ملف عضويتك. :note',
            'membership_submit_subject' => 'تذكير: إكمال طلب العضوية',
            'membership_submit_title' => 'طلب العضوية لم يُقدَّم بعد',
            'membership_submit_line' => 'أكمل ملفك الاستثماري وقدّم طلب العضوية للاستفادة من الفرص.',
            'membership_action' => 'استكمال الملف',
        ],
    ],
];
