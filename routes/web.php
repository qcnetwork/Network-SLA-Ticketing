<?php

use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\ModemTypeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TicketCategoryController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketShareController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/dashboard');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/dashboard/columns', [DashboardController::class, 'columns'])->name('dashboard.columns');

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
    Route::get('/tickets/{ticket}/attachments/{attachment}', [TicketController::class, 'attachment'])
        ->scopeBindings()->name('tickets.attachment');
    Route::get('/tickets/{ticket}/whatsapp', [TicketController::class, 'whatsapp'])->name('tickets.whatsapp');
    Route::post('/tickets/{ticket}/shares', [TicketShareController::class, 'store'])->name('tickets.shares.store');
    Route::delete('/tickets/{ticket}/shares/{share}', [TicketShareController::class, 'destroy'])
        ->scopeBindings()->name('tickets.shares.destroy');

    Route::get('/arsip', [ArchiveController::class, 'index'])->middleware('role:HELPDESK,SPV,TEKNISI')->name('archive.index');

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

    Route::middleware('role:HELPDESK,SPV,CS')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    });

    Route::middleware('role:HELPDESK,SPV')->group(function () {
        Route::get('/telegram-logs', [ReportController::class, 'telegramLogs'])->name('telegram.logs');
    });

    Route::middleware('role:SPV')->group(function () {
        Route::resource('users', UserController::class)->except('show');
        Route::resource('categories', TicketCategoryController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['categories' => 'id']);
        Route::resource('modems', ModemTypeController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['modems' => 'id']);
        Route::get('/settings', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('/settings/telegram-test', [SettingController::class, 'test'])->name('settings.test');
        Route::post('/settings/telegram-commands', [SettingController::class, 'enableCommands'])->name('settings.commands');
        Route::post('/settings/backup', [SettingController::class, 'backup'])->middleware('throttle:5,1')->name('settings.backup');
    });
});
