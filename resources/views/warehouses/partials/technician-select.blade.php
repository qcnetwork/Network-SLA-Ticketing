<div>
    <label class="label">Penerima (teknisi / helpdesk)</label>
    <select name="technician_id" class="input" required>
        <option value="">Pilih penerima</option>
        @foreach ($technicians->groupBy(fn ($user) => $user->role->label()) as $role => $people)
            <optgroup label="{{ $role }}">
                @foreach ($people as $technician)
                    <option value="{{ $technician->id }}" @selected((string) $selected === (string) $technician->id)>{{ $technician->name }}</option>
                @endforeach
            </optgroup>
        @endforeach
    </select>
</div>
