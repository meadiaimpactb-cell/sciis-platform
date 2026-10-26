/**
 * Self-assessment tools (lead generation + SEO) — typed, bilingual question banks and pure scoring.
 *
 *  - investor-readiness: the council membership conditions (SPEC §12.3) — experience, ≥2 prior investments,
 *    social-investment experience, risk appetite, mentoring, meetings commitment, impact interest, ticket size,
 *    conflicts disclosure and due-diligence habits.
 *  - project-readiness: the three evaluation axes (SPEC §12.5) — readiness, investment feasibility, social impact.
 *
 * Everything runs in the browser: answers are never sent to the API (no PII). The page may keep them in
 * localStorage only when the visitor opts in.
 */

export interface L10n { ar: string; en: string }
export const pickL10n = (v: L10n, locale: string): string => (locale === 'en' ? v.en : v.ar)

export interface AssessmentOption {
  id: string
  label: L10n
  /** 0 (weak) … 3 (strong). */
  points: number
}

export interface AssessmentQuestion {
  id: string
  /** Axis / dimension the question contributes to (see `axes`). */
  axis: string
  /** Short name of what is measured (results list + SEO "what it measures" section). */
  short: L10n
  prompt: L10n
  help?: L10n
  options: AssessmentOption[]
  /** Relative importance (default 1). */
  weight?: number
  /** Tailored recommendation shown when the answer is weak. */
  tip: L10n
  /** Optional internal link that helps with this weak area. */
  link?: { to: string; label: L10n }
}

export interface AssessmentAxis { id: string; label: L10n; description: L10n }

export type BandKey = 'ready' | 'close' | 'prepare'

export interface AssessmentConfig {
  id: 'investor-readiness' | 'project-readiness'
  /** localStorage key (versioned: bump when the question bank changes). */
  storageKey: string
  axes: AssessmentAxis[]
  questions: AssessmentQuestion[]
  bands: Record<BandKey, { label: L10n; summary: L10n }>
  faqs: { q: L10n; a: L10n }[]
}

/** Overall score thresholds (0–100). */
export const BAND_THRESHOLDS = { ready: 75, close: 50 } as const
/** An answer below this ratio of the question maximum is a weak area → tailored recommendation. */
export const WEAK_RATIO = 2 / 3

export type Answers = Record<string, string>

export interface AxisScore { id: string; score: number }
export interface AssessmentResult {
  score: number
  band: BandKey
  axes: AxisScore[]
  /** Weak questions, weakest / most important first. */
  weak: AssessmentQuestion[]
  answered: number
  total: number
}

const maxPoints = (q: AssessmentQuestion) => Math.max(...q.options.map((o) => o.points))
const ratio = (q: AssessmentQuestion, answers: Answers) => {
  const opt = q.options.find((o) => o.id === answers[q.id])
  return opt ? opt.points / maxPoints(q) : 0
}
const weighted = (qs: AssessmentQuestion[], answers: Answers) => {
  const total = qs.reduce((s, q) => s + (q.weight ?? 1), 0)
  return total ? Math.round((qs.reduce((s, q) => s + ratio(q, answers) * (q.weight ?? 1), 0) / total) * 100) : 0
}

export const bandFor = (score: number): BandKey => (score >= BAND_THRESHOLDS.ready ? 'ready' : score >= BAND_THRESHOLDS.close ? 'close' : 'prepare')

/** Pure scoring: unanswered questions count as 0. */
export function scoreAssessment(cfg: AssessmentConfig, answers: Answers): AssessmentResult {
  const score = weighted(cfg.questions, answers)
  return {
    score,
    band: bandFor(score),
    axes: cfg.axes.map((a) => ({ id: a.id, score: weighted(cfg.questions.filter((q) => q.axis === a.id), answers) })),
    weak: cfg.questions
      .filter((q) => ratio(q, answers) < WEAK_RATIO)
      .sort((a, b) => ratio(a, answers) - ratio(b, answers) || (b.weight ?? 1) - (a.weight ?? 1)),
    answered: cfg.questions.filter((q) => q.options.some((o) => o.id === answers[q.id])).length,
    total: cfg.questions.length,
  }
}

/** Keeps only answers that still match the current question bank (stale localStorage data is dropped). */
export function sanitizeAnswers(cfg: AssessmentConfig, raw: unknown): Answers {
  if (!raw || typeof raw !== 'object') return {}
  const out: Answers = {}
  for (const q of cfg.questions) {
    const v = (raw as Record<string, unknown>)[q.id]
    if (typeof v === 'string' && q.options.some((o) => o.id === v)) out[q.id] = v
  }
  return out
}

const o = (id: string, points: number, ar: string, en: string): AssessmentOption => ({ id, points, label: { ar, en } })

// ======================================================================== investor readiness (SPEC §12.3)
export const investorReadiness: AssessmentConfig = {
  id: 'investor-readiness',
  storageKey: 'sciis.tool.investor-readiness.v1',
  axes: [
    { id: 'experience', label: { ar: 'الخبرة الاستثمارية', en: 'Investment experience' }, description: { ar: 'الخبرة العامة والاستثمارات السابقة وممارسات العناية الواجبة.', en: 'General experience, prior investments and due-diligence habits.' } },
    { id: 'commitment', label: { ar: 'الالتزام والمشاركة', en: 'Commitment & engagement' }, description: { ar: 'الإرشاد وحضور اللقاءات والإفصاح عن تعارض المصالح.', en: 'Mentoring, attending meetings and disclosing conflicts of interest.' } },
    { id: 'focus', label: { ar: 'وضوح التوجه', en: 'Investment focus' }, description: { ar: 'تقبّل المخاطر وقضايا الأثر وحجم المساهمة.', en: 'Risk appetite, impact themes and ticket size.' } },
  ],
  questions: [
    {
      id: 'experience', axis: 'experience', weight: 1.2,
      short: { ar: 'الخبرة الاستثمارية العامة', en: 'General investment experience' },
      prompt: { ar: 'ما مدى خبرتك في الاستثمار بشكل عام؟', en: 'How much general investment experience do you have?' },
      options: [o('none', 0, 'لا توجد لديّ خبرة استثمارية', 'I have no investment experience'), o('lt2', 1, 'أقل من سنتين', 'Less than 2 years'), o('2to5', 2, 'من سنتين إلى 5 سنوات', '2 to 5 years'), o('gt5', 3, 'أكثر من 5 سنوات', 'More than 5 years')],
      tip: { ar: 'ابنِ خبرتك تدريجيًا عبر اللقاءات التعريفية وجلسات عرض المشاريع، ووثّق في ملفك أي خبرة مهنية ذات صلة (مالية، إدارية، قطاعية).', en: 'Build experience gradually through introductory sessions and pitch events, and document any relevant professional experience (finance, management, sector) in your profile.' },
    },
    {
      id: 'prior_investments', axis: 'experience', weight: 1.5,
      short: { ar: 'استثماران سابقان على الأقل', en: 'At least two prior investments' },
      prompt: { ar: 'كم عدد الاستثمارات السابقة التي شاركت فيها (بأي صيغة)؟', en: 'How many prior investments have you taken part in (in any form)?' },
      help: { ar: 'تشمل المساهمة في شركات أو مشاريع أو صناديق أو مجموعات استثمارية.', en: 'Includes stakes in companies, projects, funds or investment syndicates.' },
      options: [o('none', 0, 'لا يوجد', 'None'), o('one', 1, 'استثمار واحد', 'One'), o('2to4', 3, 'من 2 إلى 4 استثمارات', '2 to 4'), o('5plus', 3, '5 استثمارات أو أكثر', '5 or more')],
      tip: { ar: 'يفضّل المجلس أن يكون لدى العضو استثماران سابقان على الأقل. وثّق استثماراتك السابقة بإيجاز — بما فيها المشاركات الصغيرة أو ضمن مجموعات استثمارية.', en: 'The council prefers members with at least two prior investments. Briefly document your past investments — including small tickets or syndicate participations.' },
    },
    {
      id: 'social_experience', axis: 'experience',
      short: { ar: 'الخبرة في الاستثمار الاجتماعي', en: 'Social-investment experience' },
      prompt: { ar: 'هل سبق أن استثمرت أو موّلت مبادرة ذات أثر اجتماعي؟', en: 'Have you ever invested in or funded a social-impact initiative?' },
      options: [o('no', 0, 'لا، ولم أطّلع على المجال بعد', 'No, and I am new to the field'), o('following', 1, 'لا، لكنني أتابع المجال وأرغب في البدء', 'No, but I follow the field and want to start'), o('grants', 2, 'نعم، عبر تبرعات أو منح منظّمة', 'Yes, through structured donations or grants'), o('impact', 3, 'نعم، استثمار اجتماعي يجمع العائد والأثر', 'Yes, social investment combining return and impact')],
      tip: { ar: 'اطّلع على مفهوم الاستثمار الاجتماعي وأدواته وكيف يختلف عن التبرع والمنح، فهذا يساعدك على تقييم الفرص بواقعية.', en: 'Learn what social investment is, its instruments and how it differs from donations and grants — it helps you assess opportunities realistically.' },
      link: { to: '/impact-investing', label: { ar: 'ما هو الاستثمار الاجتماعي؟', en: 'What is impact investing?' } },
    },
    {
      id: 'due_diligence', axis: 'experience',
      short: { ar: 'ممارسات العناية الواجبة', en: 'Due-diligence habits' },
      prompt: { ar: 'كيف تتخذ قرارك الاستثماري عادةً؟', en: 'How do you usually make an investment decision?' },
      options: [o('gut', 0, 'بناءً على العلاقات أو الحدس', 'Based on relationships or gut feeling'), o('pitch', 1, 'أكتفي بالاطلاع على عرض المشروع', 'I only review the pitch'), o('docs', 2, 'أراجع المستندات المالية والنظامية الأساسية', 'I review the key financial and legal documents'), o('systematic', 3, 'أُجري تحققًا منهجيًا (مالي، نظامي، أثر) وأستعين بمختصين عند الحاجة', 'I run a systematic check (financial, legal, impact) and involve experts when needed')],
      tip: { ar: 'اعتمد قائمة تحقق للعناية الواجبة تغطي الجوانب المالية والنظامية ومؤشرات الأثر. يوفّر المجلس ملخصات تقييم للفرص لكنه لا يقدّم توصية استثمارية.', en: 'Adopt a due-diligence checklist covering financial, legal and impact aspects. The council provides evaluation summaries but never gives investment advice.' },
    },
    {
      id: 'mentoring', axis: 'commitment',
      short: { ar: 'الاستعداد للإرشاد', en: 'Mentoring readiness' },
      prompt: { ar: 'هل أنت مستعد لتقديم الإرشاد أو الخبرة لأصحاب المشاريع؟', en: 'Are you willing to mentor or share expertise with project owners?' },
      options: [o('no', 0, 'لا', 'No'), o('maybe', 1, 'ربما، عند الطلب', 'Maybe, on request'), o('specialty', 2, 'نعم، في مجال تخصصي', 'Yes, in my area of expertise'), o('regular', 3, 'نعم، بانتظام، ويمكنني فتح شبكة علاقاتي', 'Yes, regularly — and I can open my network')],
      tip: { ar: 'حدّد مجالات الخبرة التي يمكنك الإرشاد فيها (المالية، التشغيل، التسويق، الحوكمة)؛ فرأس المال الصبور يقترن عادةً بالدعم غير المالي.', en: 'List the areas you can mentor in (finance, operations, marketing, governance) — patient capital usually comes with non-financial support.' },
    },
    {
      id: 'meetings', axis: 'commitment',
      short: { ar: 'الالتزام بحضور اللقاءات', en: 'Commitment to meetings' },
      prompt: { ar: 'كم تستطيع الالتزام بحضور لقاءات المجلس وجلسات عرض المشاريع؟', en: 'How often can you attend council meetings and pitch sessions?' },
      options: [o('rarely', 0, 'نادرًا', 'Rarely'), o('yearly', 1, 'مرة أو مرتين في السنة', 'Once or twice a year'), o('quarterly', 2, 'لقاء كل ربع سنة', 'Once a quarter'), o('monthly', 3, 'لقاء شهري أو أكثر', 'Monthly or more')],
      tip: { ar: 'تتطلب العضوية الالتزام بحضور اللقاءات الدورية وجلسات العرض؛ خصّص وقتًا منتظمًا لها في جدولك.', en: 'Membership requires attending regular meetings and pitch sessions — block recurring time for them in your calendar.' },
    },
    {
      id: 'conflicts', axis: 'commitment', weight: 1.2,
      short: { ar: 'الإفصاح عن تعارض المصالح', en: 'Conflict-of-interest awareness' },
      prompt: { ar: 'هل راجعت ما قد يستوجب الإفصاح عن تعارض المصالح (علاقة بمشروع أو بجهة منافسة)؟', en: 'Have you reviewed anything that may require a conflict-of-interest disclosure (a link to a project or a competitor)?' },
      options: [o('unknown', 0, 'لا أعرف ما المقصود بذلك', 'I am not sure what that means'), o('not_reviewed', 1, 'لم أراجع ذلك بعد', 'I have not reviewed it yet'), o('none', 3, 'راجعت، ولا يوجد تعارض وأستطيع الإقرار بذلك', 'Reviewed — no conflict, and I can declare it'), o('disclose', 3, 'راجعت، ويوجد تعارض محتمل وسأفصح عنه', 'Reviewed — a potential conflict exists and I will disclose it')],
      tip: { ar: 'راجع علاقاتك بالمشاريع والجهات قبل التسجيل؛ فالإفصاح المسبق عن تعارض المصالح شرط للعضوية ويحمي جميع الأطراف.', en: 'Review your links to projects and organisations before registering — upfront conflict-of-interest disclosure is a membership condition and protects everyone.' },
    },
    {
      id: 'risk', axis: 'focus',
      short: { ar: 'تقبّل المخاطر', en: 'Risk appetite' },
      prompt: { ar: 'ما توقعك للعائد والمخاطر في الاستثمار الاجتماعي؟', en: 'What do you expect in terms of return and risk from social investment?' },
      options: [o('guaranteed', 0, 'أتوقع عائدًا مضمونًا دون مخاطر', 'A guaranteed return with no risk'), o('fast', 1, 'أتوقع عائدًا مرتفعًا وسريعًا', 'A high and fast return'), o('balanced', 3, 'أقبل عائدًا معتدلًا ومخاطر مدروسة مقابل أثر قابل للقياس', 'A moderate return and measured risk in exchange for measurable impact'), o('patient', 3, 'أقبل مخاطر أعلى وأفقًا زمنيًا طويلًا مع توزيع استثماراتي', 'Higher risk and a long horizon, with a diversified portfolio')],
      tip: { ar: 'يوازن الاستثمار الاجتماعي بين العائد والأثر وقد ينطوي على مخاطر خسارة؛ حدّد شهيتك للمخاطر وأفقك الزمني والنسبة التي تخصصها له من محفظتك.', en: 'Social investment balances return and impact and may involve losses — define your risk appetite, time horizon and the share of your portfolio you allocate to it.' },
    },
    {
      id: 'impact_interest', axis: 'focus',
      short: { ar: 'الاهتمام بقضايا الأثر', en: 'Impact interest' },
      prompt: { ar: 'هل حددت قضايا أو قطاعات الأثر التي تهمك؟', en: 'Have you identified the impact themes or sectors you care about?' },
      options: [o('no', 0, 'لم أفكر في ذلك بعد', 'Not yet'), o('general', 1, 'لديّ اهتمام عام', 'I have a general interest'), o('sectors', 2, 'حددت قطاعًا أو قطاعين', 'I picked one or two sectors'), o('detailed', 3, 'حددت قطاعات وفئات مستفيدة ومؤشرات أثر أتابعها', 'I defined sectors, beneficiary groups and impact indicators I follow')],
      tip: { ar: 'اختر من قطاع إلى ثلاثة قطاعات أثر (تعليم، صحة، بيئة…) والفئات المستفيدة التي تهمك؛ يساعد ذلك المجلس على ترشيح الفرص المناسبة لك.', en: 'Pick one to three impact sectors (education, health, environment…) and the beneficiary groups you care about — it helps the council nominate relevant opportunities.' },
    },
    {
      id: 'ticket', axis: 'focus',
      short: { ar: 'الوعي بحجم المساهمة', en: 'Ticket-size awareness' },
      prompt: { ar: 'هل تعرف حجم المبلغ الذي يمكنك تخصيصه للاستثمار الاجتماعي؟', en: 'Do you know how much you can allocate to social investment?' },
      options: [o('unknown', 0, 'لا أعرف بعد', 'Not yet'), o('rough', 1, 'لديّ فكرة تقريبية', 'I have a rough idea'), o('per_deal', 2, 'حددت نطاقًا لكل فرصة', 'I set a range per opportunity'), o('budget', 3, 'حددت نطاقًا لكل فرصة وميزانية سنوية', 'I set a per-opportunity range and an annual budget')],
      tip: { ar: 'حدّد نطاق مساهمتك لكل فرصة وميزانيتك السنوية التقريبية؛ هذه البيانات سرية ولا تُستخدم إلا مُجمّعة في التقارير.', en: 'Set your per-opportunity range and approximate annual budget — this data is confidential and only used in aggregate reports.' },
    },
  ],
  bands: {
    ready: { label: { ar: 'جاهز', en: 'Ready' }, summary: { ar: 'تبدو مستوفيًا لمعظم شروط العضوية. أكمل طلب التسجيل كمستثمر اجتماعي، وسيراجع فريق العضوية ملفك.', en: 'You appear to meet most membership conditions. Complete your registration as an impact investor and the membership team will review your file.' } },
    close: { label: { ar: 'قريب', en: 'Almost there' }, summary: { ar: 'أنت قريب من الجاهزية. عالج النقاط أدناه، ويمكنك التسجيل الآن واستكمال ملفك لاحقًا.', en: 'You are close. Work on the points below — you can register now and complete your profile later.' } },
    prepare: { label: { ar: 'يحتاج تحضير', en: 'Needs preparation' }, summary: { ar: 'يحتاج ملفك إلى تحضير قبل التقديم. ابدأ بالتوصيات أدناه واطّلع على مسار المستثمرين في المجلس.', en: 'Your profile needs some preparation before applying. Start with the recommendations below and read about the investor journey.' } },
  },
  faqs: [
    { q: { ar: 'هل تُحفظ إجاباتي أو تُرسل إلى المجلس؟', en: 'Are my answers stored or sent to the council?' }, a: { ar: 'لا. تُحسب النتيجة بالكامل داخل متصفحك ولا تُرسل الإجابات إلى أي خادم. يمكنك اختياريًا حفظها على جهازك لاستكمالها لاحقًا ومسحها في أي وقت.', en: 'No. The score is computed entirely in your browser and no answer is sent to any server. You can optionally keep them on your device to resume later, and clear them at any time.' } },
    { q: { ar: 'هل تعني النتيجة قبول عضويتي؟', en: 'Does the result mean my membership is accepted?' }, a: { ar: 'لا. الأداة استرشادية فقط؛ يصدر قرار العضوية عن فريق العضوية بعد مراجعة طلبك ومستنداتك وفق شروط المجلس.', en: 'No. The tool is indicative only — membership decisions are made by the membership team after reviewing your application and documents against the council conditions.' } },
    { q: { ar: 'ما شروط العضوية التي يقيسها الاختبار؟', en: 'Which membership conditions does the quiz cover?' }, a: { ar: 'الخبرة الاستثمارية، والاستثمارات السابقة (يُفضّل استثماران على الأقل)، والخبرة في الاستثمار الاجتماعي، وتقبّل المخاطر، والاستعداد للإرشاد، والالتزام بحضور اللقاءات، والاهتمام بقضايا الأثر، والوعي بحجم المساهمة، والإفصاح عن تعارض المصالح، وممارسات العناية الواجبة.', en: 'Investment experience, prior investments (at least two preferred), social-investment experience, risk appetite, mentoring readiness, commitment to meetings, impact interest, ticket-size awareness, conflict-of-interest disclosure and due-diligence habits.' } },
    { q: { ar: 'كم يستغرق الاختبار؟', en: 'How long does it take?' }, a: { ar: 'نحو ثلاث دقائق لعشرة أسئلة اختيار من متعدد، ويمكنك الرجوع وتعديل أي إجابة قبل عرض النتيجة.', en: 'About three minutes for ten multiple-choice questions — you can go back and change any answer before seeing the result.' } },
  ],
}

// ======================================================================== project readiness (SPEC §12.5)
export const projectReadiness: AssessmentConfig = {
  id: 'project-readiness',
  storageKey: 'sciis.tool.project-readiness.v1',
  axes: [
    { id: 'readiness', label: { ar: 'الجاهزية', en: 'Readiness' }, description: { ar: 'الفريق والوضع النظامي والتشغيل والمستندات والحوكمة.', en: 'Team, legal status, operations, documents and governance.' } },
    { id: 'feasibility', label: { ar: 'الجدوى الاستثمارية', en: 'Investment feasibility' }, description: { ar: 'نموذج الإيرادات والطلب في السوق وأوجه صرف التمويل وإدارة المخاطر.', en: 'Revenue model, market demand, use of funds and risk management.' } },
    { id: 'impact', label: { ar: 'الأثر الاجتماعي', en: 'Social impact' }, description: { ar: 'وضوح المشكلة والفئات المستفيدة ومؤشرات الأثر ونظرية التغيير.', en: 'Problem clarity, beneficiaries, impact indicators and theory of change.' } },
  ],
  questions: [
    {
      id: 'team', axis: 'readiness', weight: 1.2,
      short: { ar: 'الفريق وخبراته', en: 'Team & experience' },
      prompt: { ar: 'ما وضع الفريق المؤسس والتشغيلي للمشروع؟', en: 'What is the state of the founding and operating team?' },
      options: [o('solo', 0, 'أعمل منفردًا دون فريق', 'I work alone with no team'), o('partial', 1, 'فريق جزئي بأدوار غير واضحة', 'A partial team with unclear roles'), o('core', 2, 'فريق أساسي بخبرة ذات صلة', 'A core team with relevant experience'), o('complete', 3, 'فريق مكتمل بأدوار واضحة وخبرات مثبتة', 'A complete team with clear roles and proven experience')],
      tip: { ar: 'وضّح أدوار الفريق وخبراته ذات الصلة، وسدّ الفجوات الأساسية (المالية، التشغيل) بشركاء أو مستشارين.', en: 'Clarify team roles and relevant experience, and close key gaps (finance, operations) with partners or advisers.' },
    },
    {
      id: 'legal', axis: 'readiness', weight: 1.2,
      short: { ar: 'الوضع النظامي والتراخيص', en: 'Legal status & licences' },
      prompt: { ar: 'ما الوضع النظامي للمشروع؟', en: 'What is the legal status of the project?' },
      options: [o('idea', 0, 'فكرة دون كيان نظامي', 'An idea with no legal entity'), o('forming', 1, 'الكيان قيد التأسيس', 'The entity is being set up'), o('partial', 2, 'كيان قائم وبعض التراخيص قيد الاستكمال', 'An existing entity with some licences pending'), o('complete', 3, 'كيان نظامي مكتمل وجميع التراخيص سارية', 'A fully registered entity with all licences valid')],
      tip: { ar: 'استكمل التسجيل النظامي (سجل تجاري أو ترخيص جمعية أو مؤسسة) والتراخيص القطاعية؛ فالمستثمر يحتاج طرفًا نظاميًا للتعاقد معه.', en: 'Complete the legal registration (commercial registration or a non-profit licence) and sector licences — investors need a legal counterparty to contract with.' },
    },
    {
      id: 'operations', axis: 'readiness',
      short: { ar: 'المرحلة التشغيلية', en: 'Operating stage' },
      prompt: { ar: 'ما المرحلة التشغيلية الحالية للمشروع؟', en: 'What is the current operating stage of the project?' },
      options: [o('idea', 0, 'فكرة', 'Idea'), o('pilot', 1, 'نموذج أولي أو تجربة', 'Prototype or pilot'), o('operating', 2, 'تشغيل فعلي مع عملاء أو مستفيدين', 'Operating with customers or beneficiaries'), o('scaling', 3, 'تشغيل مستقر وقابل للتوسع', 'Stable operations, ready to scale')],
      tip: { ar: 'اختبر الحل مع مستفيدين فعليين ووثّق نتائج التجربة؛ فالدليل التشغيلي يرفع ثقة المستثمر.', en: 'Test the solution with real beneficiaries and document the pilot results — operating evidence builds investor confidence.' },
    },
    {
      id: 'documents', axis: 'readiness',
      short: { ar: 'المستندات والحوكمة', en: 'Documents & governance' },
      prompt: { ar: 'هل المستندات الأساسية جاهزة (عرض تقديمي، قوائم مالية، هيكل الملكية والحوكمة)؟', en: 'Are the key documents ready (pitch deck, financial statements, ownership and governance structure)?' },
      options: [o('none', 0, 'لا يوجد شيء منها', 'None of them'), o('deck', 1, 'العرض التقديمي فقط', 'Only the pitch deck'), o('most', 2, 'معظم المستندات متوفرة', 'Most documents are available'), o('all', 3, 'جميعها متوفرة ومحدّثة مع حوكمة وإفصاحات واضحة', 'All available and up to date, with clear governance and disclosures')],
      tip: { ar: 'جهّز ملفًا موحّدًا: عرض تقديمي، وقوائم مالية أو تقديرات، وهيكل الملكية والحوكمة، وإفصاح عن تعارض المصالح.', en: 'Prepare one file: pitch deck, financial statements or projections, ownership and governance structure, and a conflict-of-interest disclosure.' },
      link: { to: '/for-entrepreneurs', label: { ar: 'المستندات المطلوبة', en: 'Required documents' } },
    },
    {
      id: 'revenue_model', axis: 'feasibility', weight: 1.2,
      short: { ar: 'نموذج الإيرادات', en: 'Revenue model' },
      prompt: { ar: 'هل لدى المشروع نموذج إيرادات واضح؟', en: 'Does the project have a clear revenue model?' },
      options: [o('donations', 0, 'يعتمد كليًا على التبرعات والمنح', 'It relies entirely on donations and grants'), o('untested', 1, 'فكرة لنموذج إيرادات لم تُختبر', 'A revenue idea that has not been tested'), o('early', 2, 'نموذج مُختبر بإيرادات أولية', 'A tested model with early revenue'), o('recurring', 3, 'إيرادات متكررة ومتنوعة', 'Recurring, diversified revenue')],
      tip: { ar: 'حدّد مصادر إيراد قابلة للاستدامة (رسوم خدمة، عقود، اشتراكات…) واختبرها؛ فالاستثمار الاجتماعي يتطلب مسارًا واضحًا للاستدامة المالية.', en: 'Define sustainable revenue sources (service fees, contracts, subscriptions…) and test them — social investment needs a clear path to financial sustainability.' },
    },
    {
      id: 'market', axis: 'feasibility',
      short: { ar: 'الطلب في السوق', en: 'Market demand' },
      prompt: { ar: 'هل لديك أدلة على الطلب أو الحاجة في السوق؟', en: 'Do you have evidence of market demand or need?' },
      options: [o('none', 0, 'لا', 'No'), o('assumptions', 1, 'افتراضات ومصادر عامة', 'Assumptions and general sources'), o('surveys', 2, 'استطلاعات أو تجارب أولية', 'Surveys or early pilots'), o('customers', 3, 'عملاء أو شركاء فعليون واتفاقيات', 'Actual customers or partners, with agreements')],
      tip: { ar: 'اجمع أدلة على الطلب: استطلاعات، وخطابات نوايا، وعقود تجريبية، وبيانات استخدام.', en: 'Gather evidence of demand: surveys, letters of intent, pilot contracts and usage data.' },
    },
    {
      id: 'use_of_funds', axis: 'feasibility', weight: 1.2,
      short: { ar: 'مبلغ التمويل وأوجه صرفه', en: 'Funding ask & use of funds' },
      prompt: { ar: 'هل حددت مبلغ التمويل المطلوب وأوجه صرفه؟', en: 'Have you defined the funding amount and how it will be used?' },
      options: [o('no', 0, 'لم أحدده بعد', 'Not yet'), o('rough', 1, 'مبلغ تقريبي دون تفصيل', 'A rough amount without detail'), o('partial', 2, 'مبلغ محدد مع تفصيل جزئي', 'A defined amount with partial breakdown'), o('detailed', 3, 'مبلغ مبرر وخطة صرف تفصيلية بالمراحل والنِّسب', 'A justified amount with a detailed, phased breakdown')],
      tip: { ar: 'فصّل أوجه الصرف بالنِّسب والمراحل (التشغيل، التوسع، التقنية…) واربطها بالمخرجات المتوقعة.', en: 'Break down the use of funds by percentage and phase (operations, expansion, technology…) and link it to expected outputs.' },
    },
    {
      id: 'risks', axis: 'feasibility',
      short: { ar: 'إدارة المخاطر', en: 'Risk management' },
      prompt: { ar: 'هل حددت المخاطر الرئيسية وخطط معالجتها؟', en: 'Have you identified the main risks and how to mitigate them?' },
      options: [o('no', 0, 'لا', 'No'), o('general', 1, 'بشكل عام', 'In general terms'), o('main', 2, 'المخاطر الرئيسية محددة', 'The main risks are identified'), o('plans', 3, 'محددة مع خطط معالجة ومؤشرات إنذار مبكر', 'Identified, with mitigation plans and early-warning indicators')],
      tip: { ar: 'أعدّ سجل مخاطر مختصرًا (تشغيلية، مالية، نظامية) مع خطة معالجة لكل خطر.', en: 'Prepare a short risk register (operational, financial, legal) with a mitigation plan for each risk.' },
    },
    {
      id: 'problem', axis: 'impact', weight: 1.2,
      short: { ar: 'وضوح المشكلة الاجتماعية', en: 'Clear social problem' },
      prompt: { ar: 'هل المشكلة الاجتماعية التي يعالجها المشروع محددة بوضوح؟', en: 'Is the social problem the project addresses clearly defined?' },
      options: [o('no', 0, 'غير محددة', 'Not defined'), o('general', 1, 'وصف عام', 'A general description'), o('some', 2, 'محددة مع بعض الأدلة', 'Defined, with some evidence'), o('evidence', 3, 'محددة بأدلة كمية عن حجمها وأسبابها', 'Defined, with quantitative evidence of its size and causes')],
      tip: { ar: 'صِف المشكلة في جملة واحدة مدعومة بأرقام من مصادر موثوقة، وحدّد أسبابها الجذرية.', en: 'Describe the problem in one sentence backed by figures from reliable sources, and identify its root causes.' },
      link: { to: '/impact-investing', label: { ar: 'معايير الأثر الاجتماعي', en: 'Social impact criteria' } },
    },
    {
      id: 'beneficiaries', axis: 'impact',
      short: { ar: 'الفئات المستفيدة', en: 'Defined beneficiaries' },
      prompt: { ar: 'هل الفئات المستفيدة من المشروع محددة؟', en: 'Are the project beneficiaries defined?' },
      options: [o('no', 0, 'لا', 'No'), o('general', 1, 'بشكل عام (المجتمع)', 'In general terms (the community)'), o('group', 2, 'فئة محددة مع عدد تقديري', 'A specific group with an estimated number'), o('detailed', 3, 'فئة محددة بعدد حالي ومستهدف وطريقة وصول', 'A specific group with current and target numbers and an outreach method')],
      tip: { ar: 'حدّد الفئة المستفيدة بدقة (العمر، المنطقة، الحالة) وعدد المستفيدين الحالي والمستهدف.', en: 'Define the beneficiary group precisely (age, region, situation) with current and target numbers.' },
    },
    {
      id: 'indicator', axis: 'impact', weight: 1.5,
      short: { ar: 'مؤشر أثر بخط أساس', en: 'Impact indicator with a baseline' },
      prompt: { ar: 'هل لديك مؤشر أثر واحد على الأقل له خط أساس؟', en: 'Do you have at least one impact indicator with a baseline?' },
      help: { ar: 'خط الأساس هو قيمة المؤشر قبل تدخل المشروع، مثل نسبة التوظيف الحالية للفئة المستهدفة.', en: 'A baseline is the indicator value before the project intervenes — e.g. the current employment rate of the target group.' },
      options: [o('none', 0, 'لا توجد مؤشرات', 'No indicators'), o('vague', 1, 'مؤشرات عامة غير قابلة للقياس', 'General indicators that cannot be measured'), o('no_baseline', 2, 'مؤشر قابل للقياس دون خط أساس', 'A measurable indicator without a baseline'), o('baseline', 3, 'مؤشر أو أكثر بخط أساس ومستهدف وطريقة تحقق', 'One or more indicators with a baseline, a target and a verification method')],
      tip: { ar: 'اختر مؤشر أثر واحدًا على الأقل (مثل نسبة التوظيف بعد التدريب) وسجّل قيمته الحالية (خط الأساس) والمستهدف وطريقة القياس.', en: 'Pick at least one impact indicator (e.g. employment rate after training) and record its current value (baseline), the target and how it is measured.' },
    },
    {
      id: 'theory_of_change', axis: 'impact',
      short: { ar: 'نظرية التغيير', en: 'Theory of change' },
      prompt: { ar: 'هل الربط واضح بين أنشطة المشروع والتغيير المتوقع (نظرية التغيير)؟', en: 'Is the link between project activities and the expected change clear (theory of change)?' },
      options: [o('no', 0, 'لا', 'No'), o('implicit', 1, 'ربط ضمني غير موثّق', 'An implicit, undocumented link'), o('chain', 2, 'سلسلة نتائج واضحة', 'A clear results chain'), o('documented', 3, 'نظرية تغيير موثقة بافتراضات مختبرة ومواءمة مع رؤية 2030', 'A documented theory of change with tested assumptions, aligned with Vision 2030')],
      tip: { ar: 'ارسم سلسلة: الأنشطة ← المخرجات ← النتائج ← الأثر، ووضّح الافتراضات وارتباطها بمستهدفات رؤية 2030.', en: 'Draw the chain activities → outputs → outcomes → impact, and state the assumptions and how they link to Vision 2030 targets.' },
    },
  ],
  bands: {
    ready: { label: { ar: 'جاهز', en: 'Ready' }, summary: { ar: 'يبدو مشروعك جاهزًا للتقديم. سجّل كصاحب مشروع وقدّم طلبك؛ سيمر بالفرز الأولي ثم التقييم على المحاور الثلاثة.', en: 'Your project looks ready to apply. Register as a project owner and submit — it goes through initial screening, then the three-axis evaluation.' } },
    close: { label: { ar: 'قريب', en: 'Almost there' }, summary: { ar: 'مشروعك قريب من الجاهزية. عالج التوصيات أدناه لرفع فرص اجتياز التقييم، ويمكنك البدء بمسودة الطلب الآن.', en: 'Your project is close. Work on the recommendations below to improve your evaluation chances — you can start a draft application now.' } },
    prepare: { label: { ar: 'يحتاج تحضير', en: 'Needs preparation' }, summary: { ar: 'يحتاج مشروعك إلى تحضير قبل التقديم. ركّز على المحاور الأضعف والتوصيات أدناه ثم أعد التقييم.', en: 'Your project needs preparation before applying. Focus on the weakest axes and recommendations below, then retake the assessment.' } },
  },
  faqs: [
    { q: { ar: 'هل تُحفظ إجاباتي أو تُرسل إلى المجلس؟', en: 'Are my answers stored or sent to the council?' }, a: { ar: 'لا. تُحسب النتيجة بالكامل داخل متصفحك ولا تُرسل الإجابات إلى أي خادم. يمكنك اختياريًا حفظها على جهازك لاستكمالها لاحقًا ومسحها في أي وقت.', en: 'No. The score is computed entirely in your browser and no answer is sent to any server. You can optionally keep them on your device to resume later, and clear them at any time.' } },
    { q: { ar: 'هل تعني النتيجة قبول مشروعي؟', en: 'Does the result mean my project is accepted?' }, a: { ar: 'لا. الأداة استرشادية فقط. بعد التقديم يمر المشروع بالفرز الأولي ثم يُقيَّم رسميًا من مقيّمين مختصين وفق معايير المجلس المعتمدة.', en: 'No. The tool is indicative only. After you apply, the project goes through initial screening and a formal evaluation by specialist evaluators against the council criteria.' } },
    { q: { ar: 'ما المحاور التي يقيسها التقييم؟', en: 'Which axes does the assessment measure?' }, a: { ar: 'المحاور الثلاثة المعتمدة في تقييم المجلس: الجاهزية (الفريق، الوضع النظامي، التشغيل، المستندات)، والجدوى الاستثمارية (نموذج الإيرادات، الطلب، أوجه صرف التمويل، المخاطر)، والأثر الاجتماعي (المشكلة، المستفيدون، مؤشرات الأثر، نظرية التغيير).', en: 'The three axes of the council evaluation: readiness (team, legal status, operations, documents), investment feasibility (revenue model, demand, use of funds, risks) and social impact (problem, beneficiaries, impact indicators, theory of change).' } },
    { q: { ar: 'ماذا أفعل إذا كانت نتيجتي منخفضة؟', en: 'What if my score is low?' }, a: { ar: 'اتبع التوصيات المخصصة لكل نقطة ضعف ثم أعد التقييم. يمكنك التسجيل الآن وحفظ مسودة مشروعك واستكمالها لاحقًا قبل الإرسال.', en: 'Follow the tailored recommendation for each weak point, then retake the assessment. You can register now, save a draft of your project and complete it before submitting.' } },
  ],
}

export const ASSESSMENTS = { 'investor-readiness': investorReadiness, 'project-readiness': projectReadiness } as const
