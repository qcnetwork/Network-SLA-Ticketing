/**
 * Penerima data gudang dari aplikasi Network SLA Ticketing.
 *
 * Cara pasang (sekali saja):
 *  1. Buat Google Spreadsheet baru (mis. "Gudang QC NET - Live").
 *  2. Menu Ekstensi → Apps Script. Hapus isi Code.gs, tempel seluruh file ini.
 *  3. Di aplikasi (login SPV): Pengaturan → Google Spreadsheet Gudang → klik Simpan walau URL masih
 *     kosong, agar kode rahasia muncul. Salin kode itu ke SECRET di bawah.
 *  4. Simpan skrip, lalu Deploy → New deployment → Select type: Web app
 *       Execute as: Me    ·    Who has access: Anyone
 *     Izinkan aksesnya, lalu salin "Web app URL" (berakhiran /exec).
 *  5. Tempel URL itu di Pengaturan aplikasi, Simpan, lalu klik "Tes & Sinkron Penuh".
 *
 * Kalau skrip ini diubah, buat versi baru: Deploy → Manage deployments → Edit → Version: New version.
 * URL tetap sama.
 */
const SECRET = 'TEMPEL_KODE_RAHASIA_DARI_APLIKASI';

const MUTASI = {
  name: 'Mutasi',
  header: ['ID', 'Waktu', 'Jenis', 'Kode', 'Barang', 'Kategori', 'Jumlah', 'Satuan', 'SN', 'MAC', 'Dari', 'Ke', 'Tiket', 'Nota', 'Oleh', 'Keterangan'],
  keys: ['id', 'waktu', 'jenis', 'kode', 'barang', 'kategori', 'jumlah', 'satuan', 'sn', 'mac', 'dari', 'ke', 'tiket', 'nota', 'oleh', 'keterangan'],
  text: ['Kode', 'SN', 'MAC', 'Tiket', 'Nota'],
};
const STOK = {
  name: 'Stok',
  header: ['Gudang', 'Kode', 'Barang', 'Kategori', 'Stok', 'Satuan', 'Ber-SN', 'Diperbarui'],
  keys: ['gudang', 'kode', 'barang', 'kategori', 'stok', 'satuan', 'sn'],
  text: ['Kode'],
};

function doPost(e) {
  const lock = LockService.getScriptLock();
  lock.waitLock(30000);
  try {
    const body = JSON.parse(e.postData.contents);
    if (body.secret !== SECRET) {
      return reply({ ok: false, error: 'Kode rahasia tidak cocok' });
    }
    if (body.ping) {
      return reply({ ok: true });
    }
    const book = SpreadsheetApp.getActiveSpreadsheet();
    if (body.movements) {
      appendMovements(book, body.movements);
    }
    if (body.stock) {
      writeStock(book, body.stock, Boolean(body.snapshot));
    }
    return reply({ ok: true });
  } catch (err) {
    return reply({ ok: false, error: String(err) });
  } finally {
    lock.releaseLock();
  }
}

// A movement already in the sheet (sent again after a timeout) is not added twice.
function appendMovements(book, rows) {
  const sheet = tab(book, MUTASI);
  const last = sheet.getLastRow();
  const seen = new Set(last > 1 ? sheet.getRange(2, 1, last - 1, 1).getValues().map((r) => String(r[0])) : []);
  const fresh = rows.filter((r) => !seen.has(String(r.id))).map((r) => MUTASI.keys.map((k) => cell(r[k])));
  if (fresh.length) {
    sheet.getRange(last + 1, 1, fresh.length, MUTASI.header.length).setValues(fresh);
  }
}

// One row per warehouse + item code. A snapshot replaces the whole tab.
function writeStock(book, rows, replace) {
  const sheet = tab(book, STOK);
  const now = new Date();
  const values = (r) => STOK.keys.map((k) => cell(r[k])).concat([now]);

  if (replace) {
    if (sheet.getLastRow() > 1) {
      sheet.getRange(2, 1, sheet.getLastRow() - 1, STOK.header.length).clearContent();
    }
    if (rows.length) {
      sheet.getRange(2, 1, rows.length, STOK.header.length).setValues(rows.map(values));
    }
    return;
  }

  const last = sheet.getLastRow();
  const keys = last > 1 ? sheet.getRange(2, 1, last - 1, 2).getValues() : [];
  const index = new Map(keys.map((r, i) => [r[0] + '|' + r[1], i + 2]));
  const added = [];
  rows.forEach((r) => {
    const row = index.get(r.gudang + '|' + r.kode);
    if (row) {
      sheet.getRange(row, 1, 1, STOK.header.length).setValues([values(r)]);
    } else {
      added.push(values(r));
    }
  });
  if (added.length) {
    sheet.getRange(sheet.getLastRow() + 1, 1, added.length, STOK.header.length).setValues(added);
  }
}

function tab(book, spec) {
  let sheet = book.getSheetByName(spec.name);
  if (!sheet) {
    sheet = book.insertSheet(spec.name);
  }
  if (sheet.getLastRow() === 0) {
    sheet.appendRow(spec.header);
    sheet.setFrozenRows(1);
    sheet.getRange(1, 1, 1, spec.header.length).setFontWeight('bold').setBackground('#e0e7ff');
    // Codes, SNs and MACs stay text, so "0012" or "1E10" are not turned into numbers.
    spec.text.forEach((name) => sheet.getRange(1, spec.header.indexOf(name) + 1, sheet.getMaxRows(), 1).setNumberFormat('@'));
  }
  return sheet;
}

// Text typed in the app that starts like a formula is kept as plain text.
function cell(value) {
  if (value === null || value === undefined) {
    return '';
  }
  return typeof value === 'string' && /^[=+\-@]/.test(value) ? "'" + value : value;
}

function reply(data) {
  return ContentService.createTextOutput(JSON.stringify(data)).setMimeType(ContentService.MimeType.JSON);
}
