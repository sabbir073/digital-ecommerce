<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InvitationController;
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

    // Activity log (read only)
    Route::get('activity', [ActivityLogController::class, 'index'])->middleware('permission:activity,view')->name('activity.index');
});
