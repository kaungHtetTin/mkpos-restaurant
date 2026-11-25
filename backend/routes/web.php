<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessFinanceController;
use App\Http\Controllers\BusinessSupportController;
use App\Http\Controllers\GuestMenuController;
use App\Http\Controllers\GuestOrdersController;
use App\Http\Controllers\MenuImageController;
use App\Http\Controllers\OfficeController;
use App\Http\Controllers\OfficeFinanceCategoryController;
use App\Http\Controllers\OfficeFinanceController;
use App\Http\Controllers\OfficeManagementController;
use App\Http\Controllers\OfficeOperationsController;
use App\Http\Controllers\PaymentAdjustmentController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\RealtimeController;
use App\Http\Controllers\RestaurantController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\ShiftController;
use App\Http\Middleware\ActiveStaff;
use App\Http\Middleware\BusinessAccess;
use App\Http\Middleware\OfficeAccess;
use App\Http\Middleware\PublishRealtimeChanges;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(PublishRealtimeChanges::class)->group(function () {
    Route::get('platform/branding', fn () => response()->json(['name' => config('app.name')])->header('Cache-Control', 'no-store'));
    Route::get('realtime/platform', [RealtimeController::class, 'platform'])->middleware('throttle:60,1,platform-realtime');
    Route::get('realtime/guest', [RealtimeController::class, 'guest'])->middleware('throttle:guest-read');
    Route::get('guest/menu/{token}', [GuestMenuController::class, 'menu'])->middleware('throttle:guest-read');
    Route::get('guest/menu/{token}/images/{filename}', [GuestMenuController::class, 'image'])->where('filename', '[a-f0-9]{64}\.(jpg|jpeg|png|webp)')->middleware('throttle:guest-read');
    Route::post('guest/menu/{token}/requests', [GuestMenuController::class, 'submit'])->middleware('throttle:guest-submit');
    Route::post('guest/menu/{token}/bill', [GuestMenuController::class, 'requestBill'])->middleware('throttle:guest-submit');
    Route::get('guest/requests/{token}', [GuestMenuController::class, 'status'])->middleware('throttle:guest-read');
    Route::post('register', [PlatformController::class, 'register'])->middleware('throttle:5,1,owner-registration');
    Route::post('forgot-password', [PlatformController::class, 'forgotPassword'])->middleware('throttle:5,1,password-recovery');
    Route::post('reset-password', [PlatformController::class, 'resetPassword'])->middleware('throttle:5,1,password-reset');
    Route::prefix('office')->group(function () {
        Route::get('session', [OfficeController::class, 'session']);
        Route::post('login', [OfficeController::class, 'login'])->middleware('throttle:10,1,office-login');
        Route::post('logout', [OfficeController::class, 'logout']);
        Route::middleware(OfficeAccess::class)->group(function () {
            Route::get('realtime', [RealtimeController::class, 'office']);
            Route::post('account', [OfficeManagementController::class, 'account']);
            Route::get('overview', [OfficeOperationsController::class, 'overview']);
            Route::get('calendar', [OfficeOperationsController::class, 'calendar']);
            Route::get('payments', [OfficeOperationsController::class, 'payments']);
            Route::get('requests', [OfficeOperationsController::class, 'requests']);
            Route::post('requests/{id}/review', [OfficeOperationsController::class, 'review'])->whereNumber('id');
            Route::get('requests/{id}/receipt', [OfficeOperationsController::class, 'attachment'])->whereNumber('id');
            Route::get('finance/categories', [OfficeFinanceCategoryController::class, 'index']);
            Route::post('finance/categories', [OfficeFinanceCategoryController::class, 'save']);
            Route::get('finance', [OfficeFinanceController::class, 'index']);
            Route::post('finance', [OfficeFinanceController::class, 'save']);
            Route::get('health', [OfficeOperationsController::class, 'health']);
            Route::get('manage/{resource}', [OfficeManagementController::class, 'index']);
            Route::post('manage/{resource}', [OfficeManagementController::class, 'save']);
            Route::get('reports/{id}/screenshot', [OfficeManagementController::class, 'screenshot'])->whereNumber('id');
            Route::post('businesses', [OfficeController::class, 'createBusiness']);
            Route::get('businesses', [OfficeController::class, 'index']);
            Route::get('businesses/{id}', [OfficeController::class, 'show'])->whereNumber('id');
            Route::post('businesses/{id}', [OfficeController::class, 'updateBusiness'])->whereNumber('id');
            Route::post('businesses/{id}/subscription', [OfficeController::class, 'subscription'])->whereNumber('id');
            Route::post('plans', [OfficeController::class, 'savePlan']);
            Route::get('events', [OfficeController::class, 'events']);
        });
    });
    Route::get('platform-logo', [OfficeManagementController::class, 'logo']);
    Route::get('session', [AuthController::class, 'session']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1,business-login');
    Route::post('logout', [AuthController::class, 'logout']);
    Route::middleware([ActiveStaff::class, 'auth.session'])->group(function () {
        Route::get('realtime/business', [RealtimeController::class, 'business']);
        Route::get('billing', [PlatformController::class, 'billing']);
        Route::get('billing/requests', [BusinessSupportController::class, 'requests']);
        Route::post('billing/requests', [BusinessSupportController::class, 'submitPayment'])->middleware('throttle:10,1,billing-requests');
        Route::get('help', [BusinessSupportController::class, 'help']);
        Route::post('help/reports', [BusinessSupportController::class, 'submitReport'])->middleware('throttle:10,1,support-reports');
    });
    Route::middleware([ActiveStaff::class, 'auth.session', BusinessAccess::class])->group(function () {
        Route::post('setup', [PlatformController::class, 'setup']);
        Route::get('state', [RestaurantController::class, 'state']);
        Route::get('menu-images/{filename}', [MenuImageController::class, 'show'])->where('filename', '[a-f0-9]{64}\.(jpg|jpeg|png|webp)');
        Route::get('guest-orders', [GuestOrdersController::class, 'index']);
        Route::post('guest-orders/configure', [GuestOrdersController::class, 'configure']);
        Route::post('guest-orders/{id}/review', [GuestOrdersController::class, 'review'])->whereNumber('id');
        Route::get('history', [RestaurantController::class, 'history']);
        Route::get('sales', [SalesController::class, 'index']);
        Route::get('business-finance', [BusinessFinanceController::class, 'index']);
        Route::post('business-finance/categories', [BusinessFinanceController::class, 'category']);
        Route::post('business-finance/entries', [BusinessFinanceController::class, 'entry']);
        Route::get('shifts', [ShiftController::class, 'index']);
        Route::post('shifts/{action}', [ShiftController::class, 'command']);
        Route::post('payments/{id}/{action}', [PaymentAdjustmentController::class, 'command'])->whereNumber('id');
        Route::get('orders/{id}', [RestaurantController::class, 'show'])->whereNumber('id');
        Route::post('orders', [RestaurantController::class, 'create']);
        Route::post('orders/{id}/{action}', [RestaurantController::class, 'command'])->whereNumber('id');
        Route::post('manage/{resource}', [RestaurantController::class, 'manage']);
    });
    Route::any('{path}', fn () => abort(404))->where('path', '.*');
});
Route::get('/{path?}', function () {
    $file = public_path('app/index.html');
    abort_unless(file_exists($file), 503, 'Build the React frontend first.');

    $name = e(config('app.name'));
    $html = file_get_contents($file);
    $html = preg_replace_callback('/<title>.*?<\/title>/s', fn () => '<title>'.$name.' · Restaurant POS</title>', $html);
    $html = preg_replace_callback('/<meta name="application-name" content="[^"]*"\s*\/?>/', fn () => '<meta name="application-name" content="'.$name.'"/>', $html);
    $html = preg_replace_callback('/<meta name="description" content="[^"]*"\s*\/?>/', fn () => '<meta name="description" content="'.$name.' restaurant ordering, tables and kitchen"/>', $html);

    return response($html)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'no-cache');
})->where('path', '.*');
