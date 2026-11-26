<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductCsvController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SecuritySettingsController;
use App\Http\Controllers\Admin\StaffController;
use Illuminate\Support\Facades\Route;

// Registered in bootstrap/app.php with the /admin prefix and the admin. name prefix.

Route::get('login', [AuthController::class, 'showLogin'])->name('login');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('login.store');
Route::get('two-factor', [AuthController::class, 'showTwoFactor'])->name('two-factor');
Route::post('two-factor', [AuthController::class, 'twoFactor'])->middleware('throttle:20,1')->name('two-factor.store');
Route::get('invite/{token}', [InvitationController::class, 'show'])->name('invite.show');
Route::post('invite/{token}', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invite.accept');

Route::middleware('admin.auth')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    // My account (every signed-in person)
    Route::get('account', [AccountController::class, 'edit'])->name('account');
    Route::put('account/password', [AccountController::class, 'updatePassword'])->name('account.password');
    Route::post('account/two-factor', [AccountController::class, 'startTwoFactor'])->name('account.two-factor.start');
    Route::post('account/two-factor/confirm', [AccountController::class, 'confirmTwoFactor'])->name('account.two-factor.confirm');
    Route::delete('account/two-factor', [AccountController::class, 'disableTwoFactor'])->name('account.two-factor.disable');

    // Staff
    Route::get('staff', [StaffController::class, 'index'])->middleware('permission:staff,view')->name('staff.index');
    Route::post('staff', [StaffController::class, 'store'])->middleware('permission:staff,create')->name('staff.store');
    Route::put('staff/{staff}', [StaffController::class, 'update'])->middleware('permission:staff,edit')->name('staff.update');
    Route::post('staff/{staff}/resend', [StaffController::class, 'resend'])->middleware('permission:staff,create')->name('staff.resend');
    Route::post('staff/{staff}/suspend', [StaffController::class, 'suspend'])->middleware('permission:staff,edit')->name('staff.suspend');
    Route::post('staff/{staff}/reactivate', [StaffController::class, 'reactivate'])->middleware('permission:staff,edit')->name('staff.reactivate');
    Route::post('staff/{staff}/sign-out', [StaffController::class, 'signOut'])->middleware('permission:staff,edit')->name('staff.sign-out');
    Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->middleware('permission:staff,delete')->name('staff.destroy');

    Route::get('staff-security', [SecuritySettingsController::class, 'edit'])->middleware('permission:staff,view')->name('staff.security');
    Route::put('staff-security', [SecuritySettingsController::class, 'update'])->middleware('permission:staff,edit')->name('staff.security.update');

    // Roles
    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:staff,view')->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:staff,create')->name('roles.store');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:staff,edit')->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:staff,delete')->name('roles.destroy');

    // Products
    Route::get('products', [ProductController::class, 'index'])->middleware('permission:products,view')->name('products.index');
    Route::get('products/export', [ProductCsvController::class, 'export'])->middleware('permission:products,view')->name('products.export');
    Route::post('products/import', [ProductCsvController::class, 'import'])->middleware('permission:products,create')->name('products.import');
    Route::get('products/create', [ProductController::class, 'create'])->middleware('permission:products,create')->name('products.create');
    Route::post('products', [ProductController::class, 'store'])->middleware('permission:products,create')->name('products.store');
    Route::get('products/{product}', [ProductController::class, 'edit'])->middleware('permission:products,view')->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])->middleware('permission:products,edit')->name('products.update');
    Route::post('products/{product}/duplicate', [ProductController::class, 'duplicate'])->middleware('permission:products,create')->name('products.duplicate');
    Route::post('products/{product}/status', [ProductController::class, 'setStatus'])->middleware('permission:products,publish')->name('products.status');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('permission:products,delete')->name('products.destroy');
    Route::post('products/{product}/files', [ProductController::class, 'storeFile'])->middleware('permission:products,edit')->name('products.files.store');
    Route::delete('products/{product}/files/{file}', [ProductController::class, 'destroyFile'])->middleware('permission:products,edit')->name('products.files.destroy');

    // Categories
    Route::get('categories', [CategoryController::class, 'index'])->middleware('permission:categories,view')->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->middleware('permission:categories,create')->name('categories.store');
    Route::post('categories/reorder', [CategoryController::class, 'reorder'])->middleware('permission:categories,edit')->name('categories.reorder');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->middleware('permission:categories,edit')->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('permission:categories,delete')->name('categories.destroy');

    // Media library
    Route::get('media', [MediaController::class, 'index'])->middleware('permission:media,view')->name('media.index');
    Route::get('media/browse', [MediaController::class, 'browse'])->middleware('permission:media,view')->name('media.browse');
    Route::post('media', [MediaController::class, 'store'])->middleware('permission:media,create')->name('media.store');
    Route::post('media/folders', [MediaController::class, 'storeFolder'])->middleware('permission:media,create')->name('media.folders.store');
    Route::delete('media/folders/{folder}', [MediaController::class, 'destroyFolder'])->middleware('permission:media,delete')->name('media.folders.destroy');
    Route::put('media/{media}', [MediaController::class, 'update'])->middleware('permission:media,edit')->name('media.update');
    Route::delete('media/{media}', [MediaController::class, 'destroy'])->middleware('permission:media,delete')->name('media.destroy');

    // Activity log (read only)
    Route::get('activity', [ActivityLogController::class, 'index'])->middleware('permission:activity,view')->name('activity.index');
});
