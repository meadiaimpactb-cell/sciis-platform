<?php

declare(strict_types=1);

namespace App\Support;

/**
 * MSG-02 — default confidentiality undertaking (NDA) shown to investors before the full file opens.
 * DRAFT pending legal review: staff edit the live text in Admin → Content → Pages (slug `nda`, Arabic + English)
 * and bump `legal.nda_version` in Settings whenever the wording changes. This class is only the seed / fallback.
 */
final class NdaDraft
{
    public const SLUG = 'nda';

    public const TITLE_AR = 'اتفاقية عدم الإفصاح (تعهّد السرية)';

    public const TITLE_EN = 'Non-Disclosure Agreement (Confidentiality Undertaking)';

    /** Fragment of the short placeholder seeded before MSG-02 — replaced by the draft below when still present. */
    public const LEGACY_MARKER = 'يتعهد المستثمر بالحفاظ على سرية الملف الكامل للفرصة';

    public const BODY_AR = <<<'MD'
> مسودة للمراجعة — بانتظار الاعتماد القانوني. لا تُعدّ هذه الصيغة نهائية قبل اعتمادها من المستشار القانوني للمجلس.

## 1. الأطراف والغرض

يُبرم هذا التعهّد إلكترونيًا عبر منصة المجلس السعودي للمستثمرين الاجتماعيين بين المستثمر المسجّل في المنصة («المستثمر») وصاحب الفرصة الاستثمارية («صاحب المشروع»)، لغرض واحد هو تمكين المستثمر من دراسة الفرصة وتقييمها («الغرض»).

## 2. المعلومات السرية

تشمل المعلومات السرية كل ما يُتاح للمستثمر عبر المنصة بعد موافقة صاحب المشروع، ومن ذلك: الملف الكامل للفرصة، والمستندات، والقوائم والتوقعات المالية، وبيانات الفريق والمؤسسين، وأي معلومات تجارية أو فنية أو شخصية تُشارك شفهيًا أو كتابيًا أو إلكترونيًا.

## 3. التزامات المستثمر

- عدم الإفصاح عن المعلومات السرية لأي طرف ثالث دون موافقة كتابية مسبقة من صاحب المشروع.
- عدم استخدام المعلومات لأي غرض غير الغرض المحدد في هذا التعهّد.
- قصر الاطلاع على من يحتاجه من موظفيه أو مستشاريه لأجل الغرض، بشرط التزامهم بسرية مماثلة.
- عدم نسخ المستندات أو إعادة توزيعها إلا بالقدر الذي يقتضيه الغرض.
- حماية المعلومات بدرجة عناية لا تقل عن عنايته بمعلوماته السرية.
- إبلاغ المجلس فورًا عند علمه بأي إفصاح أو استخدام غير مصرّح به.

## 4. الاستثناءات

لا يشمل هذا التعهّد المعلومات التي كانت متاحة للعموم دون إخلال من المستثمر، أو كانت معلومة له مسبقًا بصورة مشروعة، أو حصل عليها من طرف ثالث دون قيد سرية، أو طُلب الإفصاح عنها بموجب نظام أو أمر من جهة مختصة، على أن يُبلغ صاحب المشروع بذلك متى سمح النظام.

## 5. البيانات الشخصية

يلتزم المستثمر بمعالجة أي بيانات شخصية يطّلع عليها وفق نظام حماية البيانات الشخصية في المملكة العربية السعودية ولوائحه التنفيذية.

## 6. المدة وإعادة المعلومات

يسري هذا التعهّد من تاريخ قبوله ويستمر ثلاث سنوات من آخر اطلاع على المعلومات السرية، ويلتزم المستثمر عند الطلب أو عند انتهاء دراسته للفرصة بإتلاف ما لديه من نسخ أو إعادتها.

## 7. لا التزام بالاستثمار

لا يُنشئ هذا التعهّد أي التزام على أي طرف بالاستثمار أو بإبرام اتفاقية، والمجلس ليس طرفًا في أي اتفاقية استثمار بين المستثمر وصاحب المشروع.

## 8. سجل القبول

يُسجَّل قبول هذا التعهّد إلكترونيًا مع إصداره ونصّه الكامل وتاريخ ووقت القبول وعنوان IP ونوع المتصفح، ويُعدّ هذا السجل دليلًا على القبول.

## 9. النظام الواجب التطبيق

يخضع هذا التعهّد لأنظمة المملكة العربية السعودية، وتختص الجهات القضائية المختصة في المملكة بالنظر في أي نزاع ينشأ عنه.
MD;

    public const BODY_EN = <<<'MD'
> Draft for review — pending legal approval. This wording is not final until approved by the Council's legal counsel.

## 1. Parties and purpose

This undertaking is entered into electronically through the Saudi Council for Impact Investors platform between the investor registered on the platform (the "Investor") and the owner of the investment opportunity (the "Project Owner"), for the sole purpose of enabling the Investor to study and evaluate the opportunity (the "Purpose").

## 2. Confidential information

Confidential information includes everything made available to the Investor through the platform after the Project Owner's approval, including the full opportunity file, documents, financial statements and projections, team and founder details, and any commercial, technical or personal information shared orally, in writing or electronically.

## 3. Investor obligations

- Not to disclose the confidential information to any third party without the Project Owner's prior written consent.
- Not to use the information for any purpose other than the Purpose of this undertaking.
- To limit access to those of its employees or advisers who need it for the Purpose, provided they are bound by equivalent confidentiality.
- Not to copy or redistribute the documents except as required by the Purpose.
- To protect the information with no less care than it applies to its own confidential information.
- To notify the Council immediately upon becoming aware of any unauthorised disclosure or use.

## 4. Exclusions

This undertaking does not cover information that was publicly available through no breach by the Investor, was lawfully known to the Investor beforehand, was received from a third party without a confidentiality restriction, or must be disclosed under a law or an order of a competent authority, in which case the Investor shall inform the Project Owner where the law permits.

## 5. Personal data

The Investor shall process any personal data it accesses in accordance with the Personal Data Protection Law of the Kingdom of Saudi Arabia and its implementing regulations.

## 6. Term and return of information

This undertaking takes effect on acceptance and continues for three years from the last access to the confidential information. On request, or once the Investor has finished studying the opportunity, the Investor shall destroy or return any copies it holds.

## 7. No obligation to invest

This undertaking creates no obligation on any party to invest or to enter into any agreement, and the Council is not a party to any investment agreement between the Investor and the Project Owner.

## 8. Record of acceptance

Acceptance of this undertaking is recorded electronically together with its version, full text, date and time of acceptance, IP address and browser type, and this record serves as evidence of acceptance.

## 9. Governing law

This undertaking is governed by the laws of the Kingdom of Saudi Arabia, and the competent courts of the Kingdom have jurisdiction over any dispute arising from it.
MD;
}
