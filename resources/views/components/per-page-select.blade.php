{{--
    Redmine's "表示件数" selector for a paginated list. Binds to the
    surrounding component's $perPage (App\Concerns\SelectsPageSize) and
    offers only the sizes App\Support\Pagination\PageSize::selectableFor()
    finds useful; renders nothing when there is nothing to choose.
--}}
@props(['selected', 'total'])

@php($sizes = \App\Support\Pagination\PageSize::selectableFor((int) $selected, (int) $total))

@if ($sizes !== [])
    <label class="flex items-center gap-1 text-sm text-gray-600" data-per-page-select>
        表示件数:
        <select wire:model.live="perPage" class="rounded-md border-gray-300 text-sm">
            @foreach ($sizes as $size)
                <option value="{{ $size }}" @selected($size === (int) $selected)>{{ $size }}</option>
            @endforeach
        </select>
    </label>
@endif
