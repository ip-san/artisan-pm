@props(['status'])

<x-badge :tone="\App\Support\Ui\IssueBadges::statusTone($status)" data-status-badge {{ $attributes }}>{{ $status->name }}</x-badge>
