<?php

use App\Http\Controllers\Storefront\CatalogController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/products', [CatalogController::class, 'index'])->name('products');
Route::get('/c/{slug}', [CatalogController::class, 'category'])->name('category');
Route::get('/search', [CatalogController::class, 'search'])->name('search');
Route::get('/product/{slug}', ProductController::class)->name('product');
