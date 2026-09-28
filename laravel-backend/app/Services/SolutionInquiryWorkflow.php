<?php

namespace App\Services;

class SolutionInquiryWorkflow
{
    public const STATUSES = ['New', 'In Review', 'Quoted', 'Won', 'Lost', 'Resolved'];

    public const TRANSITIONS = [
        'New' => ['In Review', 'Lost', 'Resolved'],
        'In Review' => ['Quoted', 'Lost', 'Resolved'],
        'Quoted' => ['Won', 'Lost', 'Resolved', 'In Review'],
        'Won' => ['Resolved'],
        'Lost' => ['In Review', 'Resolved'],
        'Resolved' => ['In Review'],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, ['New', 'In Review', 'Quoted'], true);
    }
}
