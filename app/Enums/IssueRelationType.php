<?php

declare(strict_types=1);

namespace App\Enums;

enum IssueRelationType: string
{
    case Relates = 'relates';
    case Blocks = 'blocks';
    case Duplicates = 'duplicates';
    case Precedes = 'precedes';
    case Follows = 'follows';

    /**
     * The only direction ever stored — matches Redmine's IssueRelation,
     * which persists TYPE_COPIED_TO on the source→copy row and computes
     * "copied_from" purely as the reverse label when rendering from the
     * copy's own side (see label() and journalKey()). There is no
     * CopiedFrom case to store.
     */
    case CopiedTo = 'copied_to';

    /**
     * How the relation reads from either end of the row — issue A "blocks"
     * issue B, but viewed from B the same row reads "blocked by". relates
     * has no directional language, and precedes/follows read as each
     * other's reverse. copied_to read from the source is the copy
     * destination (コピー先), from the copy the copy source (コピー元).
     *
     * @param  bool  $fromSide  read from issue_from (true) or issue_to (false)
     */
    public function label(bool $fromSide = true): string
    {
        return match ($this) {
            self::Relates => __('関連'),
            self::Blocks => $fromSide ? __('ブロックする') : __('ブロックされている'),
            self::Duplicates => $fromSide ? __('重複する') : __('重複されている'),
            self::Precedes => $fromSide ? __('先行') : __('後続'),
            self::Follows => $fromSide ? __('後続') : __('先行'),
            self::CopiedTo => $fromSide ? __('コピー先') : __('コピー元'),
        };
    }

    /**
     * The prop_key of the relation journal written on either end — Redmine's
     * journalize_relation records the relation as seen from that issue, so
     * the receiving end gets the reversed name (blocked, duplicated,
     * copied_from, and precedes/follows swapped).
     *
     * @param  bool  $fromSide  the journal of issue_from (true) or issue_to (false)
     */
    public function journalKey(bool $fromSide = true): string
    {
        if ($fromSide) {
            return $this->value;
        }

        return match ($this) {
            self::Relates => 'relates',
            self::Blocks => 'blocked',
            self::Duplicates => 'duplicated',
            self::Precedes => 'follows',
            self::Follows => 'precedes',
            self::CopiedTo => 'copied_from',
        };
    }

    /**
     * Every relation journal prop_key with its label: a stored type read
     * from its own side, or a reversed name read from the receiving end.
     *
     * @return array<string, string>
     */
    public static function journalLabels(): array
    {
        $labels = [];

        foreach (self::cases() as $type) {
            $labels[$type->journalKey()] = $type->label();
            $labels[$type->journalKey(false)] ??= $type->label(false);
        }

        return $labels;
    }
}
