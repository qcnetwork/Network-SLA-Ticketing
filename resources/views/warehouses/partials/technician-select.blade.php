<div>
    <label class="label">Teknisi penerima</label>
    <select name="technician_id" class="input" required>
        <option value="">Pilih teknisi</option>
        @foreach ($technicians as $technician)
            <option value="{{ $technician->id }}" @selected((string) $selected === (string) $technician->id)>{{ $technician->name }}</option>
        @endforeach
    </select>
</div>
