@php
    $pageTitle = $title ?? __('Magic Actions Settings');
@endphp

<div class="max-w-4xl">
    <div class="mb-6">
        <h1 class="text-2xl font-bold">{{ $pageTitle }}</h1>
    </div>

    @if ($errors->any())
        <div class="card p-4 mb-4 border border-red-300">
            <p class="font-medium text-red-600">{{ __('Please fix the errors below and try again.') }}</p>
        </div>
    @endif

    <form method="POST" action="{{ cp_route('magic-actions.settings.update') }}" class="card overflow-hidden">
        @csrf

        <div class="p-4 space-y-6">
            @foreach ($formFields as $field)
                @php
                    $fieldValue = old($field['handle'], $values[$field['handle']] ?? '');
                @endphp

                @if ($field['type'] === 'section')
                    <div class="pt-2 border-t first:border-t-0 first:pt-0">
                        <h2 class="text-base font-semibold">{{ __($field['display']) }}</h2>

                        @if ($field['instructions'] !== '')
                            <p class="mt-1 text-sm text-gray-700">{{ __($field['instructions']) }}</p>
                        @endif
                    </div>

                    @continue
                @endif

                <div>
                    <label for="{{ $field['handle'] }}" class="block mb-1 font-medium">
                        {{ __($field['display']) }}
                    </label>

                    @if ($field['type'] === 'textarea')
                        <textarea
                            id="{{ $field['handle'] }}"
                            name="{{ $field['handle'] }}"
                            rows="{{ $field['rows'] }}"
                            class="input-textarea w-full @error($field['handle']) border-red-500 @enderror"
                        >{{ $fieldValue }}</textarea>
                    @elseif ($field['type'] === 'select')
                        <select
                            id="{{ $field['handle'] }}"
                            name="{{ $field['handle'] }}"
                            class="input-text w-full @error($field['handle']) border-red-500 @enderror"
                        >
                            <option value="">{{ $field['placeholder'] ?? __('Select an option') }}</option>

                            @foreach ($field['options'] as $optionValue => $optionLabel)
                                <option value="{{ $optionValue }}" @selected((string) $fieldValue === (string) $optionValue)>
                                    {{ $optionLabel }}
                                </option>
                            @endforeach
                        </select>
                    @else
                        <input
                            id="{{ $field['handle'] }}"
                            name="{{ $field['handle'] }}"
                            type="text"
                            value="{{ $fieldValue }}"
                            class="input-text w-full @error($field['handle']) border-red-500 @enderror"
                        />
                    @endif

                    @if ($field['instructions'] !== '')
                        <p class="mt-1 text-sm text-gray-700">{{ __($field['instructions']) }}</p>
                    @endif

                    @error($field['handle'])
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </div>

        <div class="p-4 text-right bg-gray-100 border-t">
            <button type="submit" class="btn-primary">{{ __('Save') }}</button>
        </div>
    </form>
</div>
