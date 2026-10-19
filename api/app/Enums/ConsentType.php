<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum ConsentType: string
{
    use HasLabel;

    case PrivacyPolicy = 'privacy_policy';
    case Terms = 'terms';
    case TeaserPublication = 'teaser_publication';
    case FullFileShare = 'full_file_share';
    case Nda = 'nda';
    case DataAccuracy = 'data_accuracy';
}
