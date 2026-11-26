<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BlogController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ChatController;
use App\Http\Controllers\Admin\ContentPageController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\HomePageController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\MessageController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PaymentSettingsController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductCsvController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SecuritySettingsController;
use App\Http\Controllers\Admin\SellingPageController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StoreSettingsController;
use App\Http\Controllers\Admin\ThemeController;
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

    // Selling pages
    Route::get('selling-pages', [SellingPageController::class, 'index'])->middleware('permission:selling_pages,view')->name('selling-pages.index');
    Route::post('selling-pages', [SellingPageController::class, 'store'])->middleware('permission:selling_pages,create')->name('selling-pages.store');
    Route::get('selling-pages/{page}', [SellingPageController::class, 'edit'])->middleware('permission:selling_pages,view')->name('selling-pages.edit');
    Route::put('selling-pages/{page}', [SellingPageController::class, 'update'])->middleware('permission:selling_pages,edit')->name('selling-pages.update');
    Route::get('selling-pages/{page}/builder', [SellingPageController::class, 'builder'])->middleware('permission:selling_pages,view')->name('selling-pages.builder');
    Route::get('selling-pages/{page}/canvas', [SellingPageController::class, 'canvas'])->middleware('permission:selling_pages,view')->name('selling-pages.canvas');
    Route::post('selling-pages/{page}/render', [SellingPageController::class, 'render'])->middleware('permission:selling_pages,view')->name('selling-pages.render');
    Route::put('selling-pages/{page}/draft', [SellingPageController::class, 'saveDraft'])->middleware('permission:selling_pages,edit')->name('selling-pages.draft');
    Route::post('selling-pages/{page}/versions/{version}/restore', [SellingPageController::class, 'restore'])->middleware('permission:selling_pages,edit')->name('selling-pages.restore');
    Route::post('selling-pages/{page}/duplicate', [SellingPageController::class, 'duplicate'])->middleware('permission:selling_pages,create')->name('selling-pages.duplicate');
    Route::post('selling-pages/{page}/publish', [SellingPageController::class, 'publish'])->middleware('permission:selling_pages,publish')->name('selling-pages.publish');
    Route::post('selling-pages/{page}/unpublish', [SellingPageController::class, 'unpublish'])->middleware('permission:selling_pages,publish')->name('selling-pages.unpublish');
    Route::delete('selling-pages/{page}', [SellingPageController::class, 'destroy'])->middleware('permission:selling_pages,delete')->name('selling-pages.destroy');

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

    // Orders
    Route::get('orders', [OrderController::class, 'index'])->middleware('permission:orders,view')->name('orders.index');
    Route::get('orders/export', [OrderController::class, 'export'])->middleware('permission:orders,view')->name('orders.export');
    Route::get('orders/{order}', [OrderController::class, 'show'])->middleware('permission:orders,view')->name('orders.show');
    Route::post('orders/{order}/refund', [OrderController::class, 'refund'])->middleware(['permission:refunds,create', 'throttle:20,1'])->name('orders.refund');
    Route::post('orders/{order}/resend', [OrderController::class, 'resend'])->middleware(['permission:orders,edit', 'throttle:20,1'])->name('orders.resend');
    Route::post('orders/{order}/downloads/renew', [OrderController::class, 'renewDownloads'])->middleware('permission:orders,edit')->name('orders.downloads.renew');
    Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('permission:orders,edit')->name('orders.cancel');
    Route::post('orders/{order}/notes', [OrderController::class, 'note'])->middleware('permission:orders,edit')->name('orders.note');

    // Customers
    Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers,view')->name('customers.index');
    Route::get('customers/export', [CustomerController::class, 'export'])->middleware('permission:customers,view')->name('customers.export');
    Route::get('customers/view', [CustomerController::class, 'show'])->middleware('permission:customers,view')->name('customers.show');

    // Coupons
    Route::get('reviews', [ReviewController::class, 'index'])->middleware('permission:reviews,view')->name('reviews.index');
    Route::get('reviews/export', [ReviewController::class, 'export'])->middleware('permission:reviews,view')->name('reviews.export');
    Route::get('reviews/import-format', [ReviewController::class, 'sample'])->middleware('permission:reviews,view')->name('reviews.sample');
    Route::get('reviews/settings', [ReviewController::class, 'settings'])->middleware('permission:reviews,view')->name('reviews.settings');
    Route::put('reviews/settings', [ReviewController::class, 'saveSettings'])->middleware('permission:reviews,edit')->name('reviews.settings.update');
    Route::post('reviews', [ReviewController::class, 'store'])->middleware('permission:reviews,create')->name('reviews.store');
    Route::post('reviews/import', [ReviewController::class, 'import'])->middleware('permission:reviews,create')->name('reviews.import');
    Route::post('reviews/bulk', [ReviewController::class, 'bulk'])->middleware('permission:reviews,edit')->name('reviews.bulk');
    Route::put('reviews/{review}', [ReviewController::class, 'update'])->middleware('permission:reviews,edit')->name('reviews.update');
    Route::delete('reviews/{review}/photos/{media}', [ReviewController::class, 'removePhoto'])->middleware('permission:reviews,edit')->name('reviews.photos.destroy');
    Route::delete('reviews/{review}', [ReviewController::class, 'destroy'])->middleware('permission:reviews,delete')->name('reviews.destroy');

    Route::get('chat', [ChatController::class, 'index'])->middleware('permission:chat,view')->name('chat.index');
    Route::get('chat/poll', [ChatController::class, 'poll'])->middleware('permission:chat,view')->name('chat.poll');
    Route::put('chat/settings', [ChatController::class, 'saveSettings'])->middleware('permission:chat,edit')->name('chat.settings');
    Route::post('chat/{conversation}/messages', [ChatController::class, 'reply'])->middleware('permission:chat,edit')->name('chat.reply');
    Route::put('chat/{conversation}', [ChatController::class, 'update'])->middleware('permission:chat,edit')->name('chat.update');
    Route::delete('chat/{conversation}', [ChatController::class, 'destroy'])->middleware('permission:chat,delete')->name('chat.destroy');

    Route::get('coupons', [CouponController::class, 'index'])->middleware('permission:coupons,view')->name('coupons.index');
    Route::post('coupons', [CouponController::class, 'store'])->middleware('permission:coupons,create')->name('coupons.store');
    Route::put('coupons/{coupon}', [CouponController::class, 'update'])->middleware('permission:coupons,edit')->name('coupons.update');
    Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->middleware('permission:coupons,delete')->name('coupons.destroy');

    // Content: home page, pages, navigation, blog, contact messages
    Route::get('home', [HomePageController::class, 'edit'])->middleware('permission:content,view')->name('home.edit');
    Route::put('home', [HomePageController::class, 'update'])->middleware('permission:content,edit')->name('home.update');

    Route::get('pages', [ContentPageController::class, 'index'])->middleware('permission:content,view')->name('pages.index');
    Route::post('pages', [ContentPageController::class, 'store'])->middleware('permission:content,create')->name('pages.store');
    Route::put('pages/{page}', [ContentPageController::class, 'update'])->middleware('permission:content,edit')->name('pages.update');
    Route::get('pages/{page}/builder', [ContentPageController::class, 'builder'])->middleware('permission:content,view')->name('pages.builder');
    Route::get('pages/{page}/canvas', [ContentPageController::class, 'canvas'])->middleware('permission:content,view')->name('pages.canvas');
    Route::post('pages/{page}/render', [ContentPageController::class, 'render'])->middleware('permission:content,view')->name('pages.render');
    Route::put('pages/{page}/draft', [ContentPageController::class, 'saveDraft'])->middleware('permission:content,edit')->name('pages.draft');
    Route::post('pages/{page}/publish', [ContentPageController::class, 'publish'])->middleware('permission:content,publish')->name('pages.publish');
    Route::post('pages/{page}/unpublish', [ContentPageController::class, 'unpublish'])->middleware('permission:content,publish')->name('pages.unpublish');
    Route::delete('pages/{page}', [ContentPageController::class, 'destroy'])->middleware('permission:content,delete')->name('pages.destroy');

    Route::get('navigation', [MenuController::class, 'index'])->middleware('permission:content,view')->name('navigation.index');
    Route::put('navigation/announcement', [MenuController::class, 'saveAnnouncement'])->middleware('permission:content,edit')->name('navigation.announcement');
    Route::put('navigation/{location}', [MenuController::class, 'update'])->middleware('permission:content,edit')->name('navigation.update');

    Route::get('blog', [BlogController::class, 'index'])->middleware('permission:blog,view')->name('blog.index');
    Route::get('blog/create', [BlogController::class, 'create'])->middleware('permission:blog,create')->name('blog.create');
    Route::post('blog', [BlogController::class, 'store'])->middleware('permission:blog,create')->name('blog.store');
    Route::get('blog/{post}', [BlogController::class, 'edit'])->middleware('permission:blog,view')->name('blog.edit');
    Route::put('blog/{post}', [BlogController::class, 'update'])->middleware('permission:blog,edit')->name('blog.update');
    Route::delete('blog/{post}', [BlogController::class, 'destroy'])->middleware('permission:blog,delete')->name('blog.destroy');

    Route::get('messages', [MessageController::class, 'index'])->middleware('permission:chat,view')->name('messages.index');
    Route::put('messages/{message}', [MessageController::class, 'update'])->middleware('permission:chat,edit')->name('messages.update');
    Route::delete('messages/{message}', [MessageController::class, 'destroy'])->middleware('permission:chat,delete')->name('messages.destroy');

    // Store settings and theme
    Route::get('settings/store', [StoreSettingsController::class, 'edit'])->middleware('permission:settings,view')->name('settings.store');
    Route::put('settings/store/details', [StoreSettingsController::class, 'updateDetails'])->middleware('permission:settings,edit')->name('settings.store.details');
    Route::put('settings/store/checkout', [StoreSettingsController::class, 'updateCheckout'])->middleware('permission:settings,edit')->name('settings.store.checkout');
    Route::put('settings/store/stock', [StoreSettingsController::class, 'updateStock'])->middleware('permission:settings,edit')->name('settings.store.stock');
    Route::put('settings/store/maintenance', [StoreSettingsController::class, 'updateMaintenance'])->middleware('permission:settings,edit')->name('settings.store.maintenance');
    Route::get('settings/theme', [ThemeController::class, 'edit'])->middleware('permission:theme,view')->name('settings.theme');
    Route::put('settings/theme', [ThemeController::class, 'update'])->middleware('permission:theme,edit')->name('settings.theme.update');

    Route::get('settings/emails', [EmailTemplateController::class, 'index'])->middleware('permission:settings,view')->name('settings.emails');
    Route::put('settings/emails/{key}', [EmailTemplateController::class, 'update'])->middleware('permission:settings,edit')->name('settings.emails.update');
    Route::delete('settings/emails/{key}', [EmailTemplateController::class, 'reset'])->middleware('permission:settings,edit')->name('settings.emails.reset');
    Route::post('settings/emails/{key}/preview', [EmailTemplateController::class, 'preview'])->middleware('permission:settings,view')->name('settings.emails.preview');

    // Payment settings (Stripe keys are Super Admin only, enforced in the controller)
    Route::get('settings/payments', [PaymentSettingsController::class, 'edit'])->middleware('permission:settings,view')->name('settings.payments');
    Route::put('settings/payments/keys', [PaymentSettingsController::class, 'updateKeys'])->middleware('permission:settings,edit')->name('settings.payments.keys');
    Route::put('settings/payments/options', [PaymentSettingsController::class, 'updateOptions'])->middleware('permission:settings,edit')->name('settings.payments.options');
});
