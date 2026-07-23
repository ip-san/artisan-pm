<?php

use App\Enums\CustomFieldFormat;
use App\Models\CustomField;
use App\Models\Issue;
use App\Models\JournalDetail;
use App\Models\Project;
use App\Support\Diff\WordDiffer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    public Project $project;

    public Issue $issue;

    public JournalDetail $journalDetail;

    public ?CustomField $customField = null;

    /**
     * Matches Redmine's JournalsController#diff: same authorization as
     * viewing the issue itself (its own visibility already covers Journal
     * visibility here), scoped down to a detail that actually belongs to
     * this issue and is either a description change or a long-text
     * custom field change — the two "attr"/"cf" property shapes Redmine
     * itself renders a diff link for (Redmine's own change_as_diff? for
     * custom fields is likewise limited to the "text" format).
     */
    public function mount(Project $project, Issue $issue, JournalDetail $journalDetail): void
    {
        $this->authorize('view', $issue);

        abort_unless($journalDetail->journal->issue_id === $issue->id, 404);

        $isDescription = $journalDetail->property === 'attr' && $journalDetail->prop_key === 'description';

        if (! $isDescription) {
            $customField = $journalDetail->property === 'cf' ? CustomField::find((int) $journalDetail->prop_key) : null;
            abort_unless($customField?->field_format === CustomFieldFormat::Text, 404);
            $this->customField = $customField;
        }

        $this->project = $project;
        $this->issue = $issue;
        $this->journalDetail = $journalDetail;
    }

    /**
     * @return list<array{type: 'same'|'add'|'del', text: string}>
     */
    #[Computed]
    public function diff(): array
    {
        return app(WordDiffer::class)->diff((string) $this->journalDetail->old_value, (string) $this->journalDetail->new_value);
    }
}; ?>

<div class="max-w-3xl">
    <p class="mb-2 text-sm text-gray-500">
        <a href="{{ route('issues.show', [$project, $issue]) }}" class="text-indigo-600 hover:underline">
            #{{ $issue->id }} {{ $issue->subject }}
        </a>
    </p>

    <h1 class="text-xl font-semibold text-gray-900 mb-4">{{ $customField?->name ?? '説明文' }}の差分</h1>

    <div class="whitespace-pre-wrap break-words rounded-md border border-gray-200 bg-white p-4 font-mono text-sm leading-relaxed">
        @foreach ($this->diff as $chunk)
            @if ($chunk['type'] === 'add')
                <ins class="bg-green-100 text-green-800 no-underline">{{ $chunk['text'] }}</ins>
            @elseif ($chunk['type'] === 'del')
                <del class="bg-red-100 text-red-800">{{ $chunk['text'] }}</del>
            @else
                {{ $chunk['text'] }}
            @endif
        @endforeach
    </div>
</div>
