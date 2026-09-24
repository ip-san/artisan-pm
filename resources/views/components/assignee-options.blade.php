{{--
    The <option>s of an assignee select: users and groups laid out by the
    assignee_dropdown_display_format setting (AssigneeChoice::optionGroups()),
    after the issue's involved principals when editing one.
--}}
@props(['users', 'groups', 'involved' => null])

@foreach (\App\Support\Issues\AssigneeChoice::optionGroups($users, $groups, $involved) as $section)
    @if ($section['label'] === null)
        @foreach ($section['options'] as $option)
            <option value="{{ $option['value'] }}" @disabled($option['disabled'] ?? false)>{{ $option['name'] }}</option>
        @endforeach
    @else
        <optgroup label="{{ $section['label'] }}">
            @foreach ($section['options'] as $option)
                <option value="{{ $option['value'] }}" @disabled($option['disabled'] ?? false)>{{ $option['name'] }}</option>
            @endforeach
        </optgroup>
    @endif
@endforeach
