{{-- Icon of an item category; the choices are ItemCategory::ICONS. --}}
<select name="icon" class="input text-sm" required>
    @foreach ($icons as $icon => $meaning)
        <option value="{{ $icon }}" @selected($icon === $selected)>Ikon: {{ $meaning }}</option>
    @endforeach
</select>
