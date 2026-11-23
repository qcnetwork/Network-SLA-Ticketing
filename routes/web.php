<?php

use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ComplaintBoardController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerMaterialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\ItemCategoryController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\MaterialUsageController;
use App\Http\Controllers\MenuAccessController;
use App\Http\Controllers\ModemTypeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ServicePackageController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TicketCategoryController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketShareController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RemovedDeviceController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockPickupController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/dashboard');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/komplain', ComplaintBoardController::class)->name('complaints.index');
    Route::post('/komplain/kolom', [ComplaintBoardController::class, 'columns'])->name('complaints.columns');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/history', [NotificationController::class, 'history'])->name('notifications.history');
    Route::get('/notifications/{id}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy'])->name('tickets.destroy');
    Route::post('/tickets/{ticket}/actions/{action}', [TicketController::class, 'perform'])->name('tickets.perform');
    Route::put('/tickets/{ticket}/customer', [TicketController::class, 'updateCustomer'])->name('tickets.customer.update');
    Route::put('/tickets/{ticket}/handling', [TicketController::class, 'updateHandling'])->name('tickets.handling.update');
    Route::post('/tickets/{ticket}/material', [TicketController::class, 'resubmitMaterial'])->name('tickets.material.resubmit');
    Route::get('/tickets/{ticket}/material/sn', [TicketController::class, 'materialUnits'])->name('tickets.material.units');
    Route::get('/tickets/{ticket}/attachments/{attachment}', [TicketController::class, 'attachment'])
        ->scopeBindings()->name('tickets.attachment');
    Route::get('/tickets/{ticket}/whatsapp', [TicketController::class, 'whatsapp'])->name('tickets.whatsapp');
    Route::post('/tickets/{ticket}/shares', [TicketShareController::class, 'store'])->name('tickets.shares.store');
    Route::delete('/tickets/{ticket}/shares/{share}', [TicketShareController::class, 'destroy'])
        ->scopeBindings()->name('tickets.shares.destroy');

    Route::get('/pelanggan', [CustomerController::class, 'index'])->middleware('menu:customers')->name('customers.index');
    // The ticket form's picker: open to whoever may make tickets, even without the customer menu.
    Route::get('/pelanggan/cari', [CustomerController::class, 'search'])->name('customers.search');

    // New installations (PSB): their own board and order form. Each order opens on the ticket page.
    Route::middleware('menu:psb')->group(function () {
        Route::get('/psb', [InstallationController::class, 'index'])->name('installations.index');
        Route::get('/psb/baru', [InstallationController::class, 'create'])->name('installations.create');
        Route::post('/psb', [InstallationController::class, 'store'])->name('installations.store');
    });
    Route::put('/psb/{ticket}/data', [InstallationController::class, 'update'])->name('installations.update');

    Route::get('/arsip', [ArchiveController::class, 'index'])->middleware('menu:archive')->name('archive.index');

    Route::middleware('menu:warehouse_manage')->group(function () {
        Route::get('/gudang/barang', [ItemController::class, 'index'])->name('items.index');
        Route::post('/gudang/barang', [ItemController::class, 'store'])->name('items.store');
        Route::put('/gudang/barang/{item}', [ItemController::class, 'update'])->name('items.update');
        Route::delete('/gudang/barang/{item}', [ItemController::class, 'destroy'])->name('items.destroy');
        Route::post('/gudang/barang/impor', [ItemController::class, 'import'])->name('items.import');
        Route::post('/gudang/kategori', [ItemCategoryController::class, 'store'])->name('item-categories.store');
        Route::put('/gudang/kategori/{category}', [ItemCategoryController::class, 'update'])->name('item-categories.update');
        Route::delete('/gudang/kategori/{category}', [ItemCategoryController::class, 'destroy'])->name('item-categories.destroy');
        Route::post('/gudang/masuk', [WarehouseController::class, 'receive'])->name('warehouses.receive');
        Route::post('/gudang/kirim', [WarehouseController::class, 'send'])->name('warehouses.send');
        Route::post('/gudang/{warehouse}/retur', [WarehouseController::class, 'returnStock'])->name('warehouses.return');
        Route::post('/gudang/masuk-sn', [WarehouseController::class, 'receiveSerials'])->name('warehouses.receive-serials');
        Route::post('/gudang/kirim-sn', [WarehouseController::class, 'sendSerials'])->name('warehouses.send-serials');
        Route::get('/gudang/unit-sn', [WarehouseController::class, 'availableSerials'])->name('warehouses.available-serials');
        Route::get('/gudang/unit-sn/cari', [WarehouseController::class, 'findSerials'])->name('warehouses.find-serials');
        Route::put('/gudang/unit-sn/{serialUnit}', [WarehouseController::class, 'updateSerial'])->name('warehouses.update-serial');
        // Units that left stock ("kotor"): where each SN went, and one unit's whole history.
        Route::get('/gudang/pengambilan', [StockPickupController::class, 'index'])->name('stock-pickups.index');
        Route::get('/gudang/tracking', [TrackingController::class, 'index'])->name('tracking.index');
        Route::get('/gudang/tracking/export', [TrackingController::class, 'export'])->name('tracking.export');
        Route::get('/gudang/unit/{serialUnit}', [TrackingController::class, 'show'])->name('tracking.unit');
        // Gudang Pelanggan: take material off a customer, back to the office or written off.
        Route::post('/gudang/pelanggan/material/{customerMaterial}/lepas', [CustomerMaterialController::class, 'remove'])->name('customer-materials.remove');
        Route::post('/gudang/{warehouse}/retur-sn', [WarehouseController::class, 'returnSerials'])->name('warehouses.return-serials');
        Route::post('/gudang/pengajuan/{materialRequest}/setujui', [WarehouseController::class, 'approveRequest'])->name('material-requests.approve');
        Route::post('/gudang/pengajuan/{materialRequest}/tolak', [WarehouseController::class, 'rejectRequest'])->name('material-requests.reject');
        // Selling equipment to partners (mitra) and other buyers.
        Route::get('/gudang/penjualan', [SaleController::class, 'index'])->name('sales.index');
        Route::get('/gudang/penjualan/baru', [SaleController::class, 'create'])->name('sales.create');
        Route::get('/gudang/penjualan/export', [SaleController::class, 'export'])->name('sales.export');
        Route::post('/gudang/penjualan', [SaleController::class, 'store'])->name('sales.store');
        Route::get('/gudang/penjualan/{sale}', [SaleController::class, 'show'])->name('sales.show');
        Route::patch('/gudang/penjualan/{sale}/pembayaran', [SaleController::class, 'payment'])->name('sales.payment');
        Route::get('/gudang/mitra', [PartnerController::class, 'index'])->name('partners.index');
        Route::post('/gudang/mitra', [PartnerController::class, 'store'])->name('partners.store');
        Route::put('/gudang/mitra/{partner}', [PartnerController::class, 'update'])->name('partners.update');
        Route::post('/gudang/pemakaian/{usage}/batal', [MaterialUsageController::class, 'cancel'])->whereNumber('usage')->name('usages.cancel');
    });
    Route::middleware('menu:warehouse')->group(function () {
        Route::get('/gudang', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::get('/gudang/{warehouse}', [WarehouseController::class, 'show'])->whereNumber('warehouse')->name('warehouses.show');
        // A technician's whole stock with every SN on one plain page, for the 4 pm closing screenshot.
        Route::get('/gudang/{warehouse}/screenshot', [WarehouseController::class, 'snapshot'])->whereNumber('warehouse')->name('warehouses.snapshot');
        // One category's stock in a warehouse, browsed by jenis → merk → model.
        Route::get('/gudang/{warehouse}/stok/{category}', [WarehouseController::class, 'category'])->whereNumber('warehouse')->name('warehouses.category');
        // Technicians check scans against their own stock; see lookupSerial.
        Route::get('/gudang/cek-sn', [WarehouseController::class, 'lookupSerial'])->name('warehouses.lookup-serial');
        // Gudang Pelanggan: material at each customer, by customer ID. The picker serves the Pemakaian Alat form.
        Route::get('/gudang/pelanggan', [CustomerMaterialController::class, 'index'])->name('customer-materials.index');
        Route::get('/gudang/pelanggan/cari', [CustomerMaterialController::class, 'search'])->name('customer-materials.search');
        Route::get('/gudang/pelanggan/{code}', [CustomerMaterialController::class, 'show'])->where('code', '.+')->name('customer-materials.show');
        // Pemakaian Alat: the warehouse records any job, a technician the jobs from their own stock.
        Route::get('/gudang/pemakaian', [MaterialUsageController::class, 'index'])->name('usages.index');
        Route::get('/gudang/pemakaian/baru', [MaterialUsageController::class, 'create'])->name('usages.create');
        Route::get('/gudang/pemakaian/export', [MaterialUsageController::class, 'export'])->name('usages.export');
        Route::post('/gudang/pemakaian', [MaterialUsageController::class, 'store'])->name('usages.store');
        Route::get('/gudang/pemakaian/{usage}', [MaterialUsageController::class, 'show'])->whereNumber('usage')->name('usages.show');
    });

    Route::get('/gamas', [IncidentController::class, 'index'])->name('incidents.index');
    Route::get('/gamas/create', [IncidentController::class, 'create'])->name('incidents.create');
    Route::post('/gamas', [IncidentController::class, 'store'])->name('incidents.store');
    Route::get('/gamas/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
    Route::post('/gamas/{incident}/actions/{action}', [IncidentController::class, 'perform'])->name('incidents.perform');
    Route::post('/gamas/{incident}/tickets', [IncidentController::class, 'linkTickets'])->name('incidents.tickets.link');
    Route::delete('/gamas/{incident}/tickets/{ticket}', [IncidentController::class, 'detachTicket'])
        ->scopeBindings()->name('incidents.tickets.detach');
    Route::get('/gamas/{incident}/attachments/{attachment}', [IncidentController::class, 'attachment'])
        ->scopeBindings()->name('incidents.attachment');

    Route::middleware('menu:reports')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('/reports/heatmap', [ReportController::class, 'heatmap'])->name('reports.heatmap');
    });

    Route::get('/telegram-logs', [ReportController::class, 'telegramLogs'])->middleware('menu:telegram_logs')->name('telegram.logs');

    // Alat cabutan: everyone involved sees the report (a technician only their own); the warehouse team and
    // supervisors receive and check the devices.
    Route::middleware('role:GUDANG,SPV,HELPDESK,TEKNISI')->group(function () {
        Route::get('/gudang/cabutan', [RemovedDeviceController::class, 'index'])->name('removed-devices.index');
        Route::get('/gudang/cabutan/export', [RemovedDeviceController::class, 'export'])->name('removed-devices.export');
    });
    Route::middleware('role:GUDANG,SPV')->group(function () {
        Route::post('/gudang/cabutan/{removedDevice}/terima', [RemovedDeviceController::class, 'receive'])->name('removed-devices.receive');
        Route::post('/gudang/cabutan/{removedDevice}/tidak-kembali', [RemovedDeviceController::class, 'missing'])->name('removed-devices.missing');
        Route::post('/gudang/cabutan/{removedDevice}/cek', [RemovedDeviceController::class, 'check'])->name('removed-devices.check');
    });

    // Manual stock corrections: warehouse team and supervisors only; the log on that page is for supervisors.
    Route::middleware('role:GUDANG,SPV')->group(function () {
        Route::get('/gudang/adjust-stok', [StockAdjustmentController::class, 'index'])->name('stock-adjustments.index');
        Route::post('/gudang/adjust-stok', [StockAdjustmentController::class, 'store'])->name('stock-adjustments.store');
    });

    Route::middleware('role:SPV')->group(function () {
        Route::resource('users', UserController::class)->except('show');
        Route::get('/hak-akses', [MenuAccessController::class, 'index'])->name('menu-access.index');
        Route::put('/hak-akses', [MenuAccessController::class, 'update'])->name('menu-access.update');
        Route::resource('categories', TicketCategoryController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['categories' => 'id']);
        Route::resource('modems', ModemTypeController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['modems' => 'id']);
        Route::resource('packages', ServicePackageController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['packages' => 'id']);
        Route::get('/settings', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('/settings/telegram-test', [SettingController::class, 'test'])->name('settings.test');
        Route::post('/settings/telegram-commands', [SettingController::class, 'enableCommands'])->name('settings.commands');
        Route::put('/settings/sheets', [SettingController::class, 'updateSheets'])->name('settings.sheets');
        Route::post('/settings/sheets-sync', [SettingController::class, 'syncSheets'])->middleware('throttle:5,1')->name('settings.sheets-sync');
        Route::post('/settings/backup', [SettingController::class, 'backup'])->middleware('throttle:5,1')->name('settings.backup');
    });
});
