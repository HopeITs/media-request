<?php

namespace App\Enums;

use BenSampo\Enum\Enum;

/**
 * Where a media request is, and — the point of the whole module — whose turn
 * it is. Every open status has exactly one owner; nothing waits on "someone".
 */
final class MediaRequestStatus extends Enum
{
    const ProgramApproval = 'pm_approval';

    const MediaApproval = 'mm_approval';

    const ToClaim = 'to_claim';

    const Assigned = 'assigned';

    const InProgress = 'in_progress';

    const InternalReview = 'int_review';

    const RequesterReview = 'req_review';

    const Production = 'production';

    const Delivery = 'delivery';

    const NeedsInfo = 'needs_info';

    const OnHold = 'on_hold';

    const Delivered = 'delivered';

    const Rejected = 'rejected';

    const Cancelled = 'cancelled';

    /** Owners: requester, program_manager, media_manager, team, assignee. */
    public const OWNERS = [
        self::ProgramApproval => 'program_manager',
        self::MediaApproval => 'media_manager',
        self::InternalReview => 'media_manager',
        self::ToClaim => 'team',
        self::Assigned => 'assignee',
        self::InProgress => 'assignee',
        self::Production => 'assignee',
        self::Delivery => 'assignee',
        self::NeedsInfo => 'requester',
        self::RequesterReview => 'requester',
    ];

    /** @return array<int, string> */
    public static function open(): array
    {
        return [
            self::ProgramApproval, self::MediaApproval, self::ToClaim, self::Assigned,
            self::InProgress, self::InternalReview, self::RequesterReview,
            self::Production, self::Delivery, self::NeedsInfo, self::OnHold,
        ];
    }

    /** @return array<int, string> */
    public static function closed(): array
    {
        return [self::Delivered, self::Rejected, self::Cancelled];
    }

    /** Statuses in which the requester may still change the request directly. */
    public static function editable(): array
    {
        return [self::ProgramApproval, self::MediaApproval, self::NeedsInfo];
    }

    /** Work is running against a planned date rather than a waiting clock. */
    public static function working(): array
    {
        return [self::InProgress, self::Production, self::Delivery];
    }

    /** wait | work | attn | done | stop — drives the chip colour. */
    public static function tone(string $status): string
    {
        return match ($status) {
            self::InProgress, self::Production, self::Delivery => 'work',
            self::RequesterReview, self::NeedsInfo, self::OnHold => 'attn',
            self::Delivered => 'done',
            self::Rejected, self::Cancelled => 'stop',
            default => 'wait',
        };
    }

    /**
     * Chip classes. tokens.css has no status colours on purpose, so these
     * reuse the Tailwind defaults the app already uses for status (blue,
     * yellow, emerald) and keep brand red for actions only.
     */
    public static function chipClasses(string $status): string
    {
        return match (self::tone($status)) {
            'work' => 'bg-blue-50 text-blue-800 ring-blue-200',
            'attn' => 'bg-yellow-100 text-yellow-800 ring-yellow-200',
            'done' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'stop' => 'bg-neutral-100 text-neutral-600 ring-neutral-300',
            default => 'bg-white text-neutral-700 ring-neutral-200',
        };
    }

    public static function label(string $status, ?string $type = null): string
    {
        if ($status === self::Production && $type) {
            return __('media.st.production.'.$type);
        }

        return __('media.st.'.$status);
    }
}
