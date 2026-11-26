<?php

use App\Http\Controllers\Storefront\AccountController;
use App\Http\Controllers\Storefront\BlogController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\ChatController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\FeedController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\OrderController;
use App\Http\Controllers\Storefront\PageController;
use App\Http\Controllers\Storefront\ProductController;
use App\Http\Controllers\Storefront\ReviewController;
use App\Http\Controllers\Storefront\SellingPageController;
use App\Http\Controllers\Storefront\SeoFilesController;
use App\Http\Controllers\Storefront\TrackingController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// Files for search engines, built from the SEO settings
Route::get('/feeds/google.xml', [FeedController::class, 'google'])->name('feeds.google');
Route::get('/feeds/meta.csv', [FeedController::class, 'meta'])->name('feeds.meta');
Route::post('/events', [TrackingController::class, 'event'])->middleware('throttle:events')->name('events.store');
Route::post('/consent', [TrackingController::class, 'consent'])->middleware('throttle:consent')->name('consent.store');
Route::get('/robots.txt', [SeoFilesController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [SeoFilesController::class, 'index'])->name('sitemap');
Route::get('/sitemap-{name}.xml', [SeoFilesController::class, 'show'])->where('name', '[a-z-]+')->name('sitemap.show');
Route::get('/products', [CatalogController::class, 'index'])->name('products');
Route::get('/c/{slug}', [CatalogController::class, 'category'])->name('category');
Route::get('/search', [CatalogController::class, 'search'])->name('search');
Route::get('/product/{slug}', ProductController::class)->name('product');

// Reviews: of a product, of the store, and through the link in the review-request email
Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews');
Route::post('/reviews', [ReviewController::class, 'storeForStore'])->middleware('throttle:review')->name('reviews.store');
Route::post('/product/{slug}/reviews', [ReviewController::class, 'storeForProduct'])->middleware('throttle:review')->name('product.reviews.store');
Route::get('/review/{token}', [ReviewController::class, 'invited'])->name('review.invited');
Route::post('/review/{token}', [ReviewController::class, 'storeInvited'])->middleware('throttle:review')->name('review.invited.store');
Route::post('/reviews/{review}/helpful', [ReviewController::class, 'helpful'])->middleware('throttle:review-vote')->name('reviews.helpful');
Route::post('/reviews/{review}/report', [ReviewController::class, 'report'])->middleware('throttle:review-vote')->name('reviews.report');

// Content pages, the contact form and the blog
Route::get('/pages/{slug}', [PageController::class, 'show'])->name('page.show');
Route::post('/contact', [PageController::class, 'contact'])->middleware('throttle:contact')->name('contact.send');
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/category/{category}', [BlogController::class, 'index'])->name('blog.category');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');

// Live chat widget
Route::get('/chat/state', [ChatController::class, 'state'])->middleware('throttle:chat-poll')->name('chat.state');
Route::post('/chat/start', [ChatController::class, 'start'])->middleware('throttle:chat-start')->name('chat.start');
Route::post('/chat/messages', [ChatController::class, 'send'])->middleware('throttle:chat-send')->name('chat.send');

// Cart
Route::get('/cart', [CartController::class, 'show'])->name('cart');
Route::get('/cart/panel', [CartController::class, 'panel'])->name('cart.panel');
Route::post('/cart/items', [CartController::class, 'add'])->middleware('throttle:cart')->name('cart.add');
Route::patch('/cart/items/{item}', [CartController::class, 'update'])->whereNumber('item')->name('cart.update');
Route::delete('/cart/items/{item}', [CartController::class, 'remove'])->whereNumber('item')->name('cart.remove');
// Coupon and checkout attempts are rate limited (PRD section 9).
Route::post('/cart/coupon', [CartController::class, 'applyCoupon'])->middleware('throttle:coupon')->name('cart.coupon');
Route::delete('/cart/coupon', [CartController::class, 'removeCoupon'])->name('cart.coupon.remove');

// Checkout
Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');
Route::post('/checkout/quote', [CheckoutController::class, 'quote'])->middleware('throttle:quote')->name('checkout.quote');
Route::post('/checkout/start', [CheckoutController::class, 'start'])->middleware('throttle:checkout')->name('checkout.start');

// Order confirmation (signed links) and downloads
Route::get('/order/{number}', [OrderController::class, 'show'])->middleware('signed')->name('order.show');
Route::get('/order/{number}/status', [OrderController::class, 'status'])->middleware('signed')->name('order.status');
Route::get('/download/{token}', [OrderController::class, 'download'])->middleware('throttle:download')->name('download');

// One-page selling pages: read about one product and pay on the same page
Route::get('/p/{slug}', [SellingPageController::class, 'show'])->name('selling-page');
Route::post('/p/{slug}/quote', [SellingPageController::class, 'quote'])->middleware('throttle:quote')->name('selling-page.quote');
Route::post('/p/{slug}/coupon', [SellingPageController::class, 'applyCoupon'])->middleware('throttle:coupon')->name('selling-page.coupon');
Route::post('/p/{slug}/start', [SellingPageController::class, 'start'])->middleware('throttle:checkout')->name('selling-page.start');
Route::get('/p/{slug}/order/{number}', [SellingPageController::class, 'confirmation'])->middleware('signed')->name('selling-page.confirmation');

// Stripe calls this; it is verified by signature, not by a session.
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

// Customer account
Route::middleware('guest')->group(function () {
    Route::get('/login', [AccountController::class, 'showLogin'])->name('login');
    Route::post('/login', [AccountController::class, 'login'])->middleware('throttle:sign-in')->name('login.store');
    Route::get('/register', [AccountController::class, 'showRegister'])->name('register');
    Route::post('/register', [AccountController::class, 'register'])->middleware('throttle:sign-in')->name('register.store');
    Route::get('/forgot-password', [AccountController::class, 'showForgot'])->name('password.request');
    Route::post('/forgot-password', [AccountController::class, 'sendReset'])->middleware('throttle:password')->name('password.email');
    Route::get('/reset-password/{token}', [AccountController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [AccountController::class, 'reset'])->middleware('throttle:sign-in')->name('password.update');
});
Route::middleware('auth')->group(function () {
    Route::get('/account', [AccountController::class, 'index'])->name('account');
    Route::post('/logout', [AccountController::class, 'logout'])->name('logout');
    Route::post('/account/delete-request', [AccountController::class, 'requestDeletion'])->middleware('throttle:sign-in')->name('account.deletion.request');
    Route::delete('/account/delete-request', [AccountController::class, 'cancelDeletion'])->name('account.deletion.cancel');
});
