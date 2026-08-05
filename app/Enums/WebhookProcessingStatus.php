<?php

namespace App\Enums;

enum WebhookProcessingStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case IgnoredDuplicate = 'ignored_duplicate';
    case Failed = 'failed';
    case Unmatched = 'unmatched';
}
