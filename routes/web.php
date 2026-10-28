<?php

use App\Http\Controllers\MediaRequestFileController;
use App\Livewire\Auth\Login;
use App\Livewire\MediaRequests;
use App\Livewire\Notifications\Index as Notifications;
use App\Livewire\People\Index as People;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/login', Login::class)->middleware('guest')->name('login');

Route::get('/lang/{locale}', function (Request $request, string $locale) {
    $request->session()->put('locale', in_array($locale, ['ar', 'en'], true) ? $locale : 'en');

    return back();
})->name('lang');

Route::middleware('auth')->group(function () {
    // Named "dashboard" because the shared breadcrumbs link home by that name.
    Route::redirect('/', '/media-requests/home')->name('dashboard');

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    })->name('logout');

    Route::get('/notifications', Notifications::class)->name('notifications.index');
    Route::get('/people', People::class)->middleware('active_permission:people.manage')->name('people.index');

    // Same routes as in the HR app.
    Route::prefix('media-requests')
        ->name('media-requests.')
        ->group(function () {
            Route::get('/home', MediaRequests\Dashboard::class)->middleware('active_permission:media-requests.view-own')->name('home');
            Route::get('/', MediaRequests\Index::class)->middleware('active_permission:media-requests.view-own')->name('index');
            Route::get('/approvals', MediaRequests\Approvals::class)->middleware('active_permission:media-requests.approve')->name('approvals');
            Route::get('/board', MediaRequests\Board::class)->middleware('active_permission:media-requests.work')->name('board');
            Route::get('/stats', MediaRequests\Stats::class)->middleware('active_permission:media-requests.work')->name('stats');
            Route::get('/hc-pack', MediaRequests\Pack::class)->middleware('active_permission:media-requests.create')->name('pack');
            Route::get('/log', MediaRequests\Log::class)->middleware('active_permission:media-requests.view-log')->name('log');
            Route::get('/assistant', MediaRequests\AssistantLog::class)->middleware('active_permission:media-requests.view-log')->name('assistant');
            Route::get('/files/{media}', [MediaRequestFileController::class, 'download'])
                ->middleware(['active_permission:media-requests.view-own', 'throttle:60,1'])
                ->name('files');
            Route::get('/{mediaRequest}', MediaRequests\Show::class)
                ->whereNumber('mediaRequest')
                ->middleware('active_permission:media-requests.view-own')
                ->name('show');
        });
});
