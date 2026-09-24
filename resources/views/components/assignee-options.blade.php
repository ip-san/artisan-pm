{{--
    The <option>s of an assignee select: users and groups laid out by the
    assignee_dropdown_display_format setting (AssigneeChoice::optionGroups()).
--}}
@props(['users', 'groups'])

@foreach (\App\Support\Issues\AssigneeChoice::optionGroups($users, $groups) as $section)
    @if ($section['label'] === null)
        @foreach ($section['options'] as $option)
            <option value="{{ $option['value'] }}">{{ $option['name'] }}</option>
        @endforeach
    @else
        <optgroup label="{{ $section['label'] }}">
            @foreach ($section['options'] as $option)
                <option value="{{ $option['value'] }}">{{ $option['name'] }}</option>
            @endforeach
        </optgroup>
    @endif
@endforeach
