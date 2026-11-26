<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('display_name');
            $table->string('email')->nullable();
            $table->char('country_code', 2)->nullable()->index();
            $table->unsignedTinyInteger('rating');
            $table->string('title')->nullable();
            $table->text('body');
            $table->string('status', 20)->default('pending')->index(); // pending, approved, rejected, spam
            $table->boolean('verified')->default(false);
            $table->string('source', 20)->default('customer'); // customer, imported, manual, demo
            $table->string('source_label')->nullable();
            $table->boolean('pinned')->default(false);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->text('store_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'order_id', 'user_id']);
        });

        Schema::create('review_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
        });

        Schema::create('review_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 64);
            $table->timestamps();
            $table->unique(['review_id', 'visitor_id']);
        });

        Schema::create('review_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 64);
            $table->string('reason');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('review_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('send_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_meta', function (Blueprint $table) {
            $table->id();
            $table->morphs('seoable');
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->string('canonical_url')->nullable();
            $table->boolean('index')->default(true);
            $table->boolean('follow')->default(true);
            $table->string('og_title')->nullable();
            $table->string('og_description', 500)->nullable();
            $table->foreignId('og_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('focus_keyword')->nullable();
            $table->text('custom_tags')->nullable();
            $table->timestamps();
            $table->unique(['seoable_type', 'seoable_id']);
        });

        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 500);
            $table->string('from_hash', 64)->unique();
            $table->string('to_path', 500);
            $table->unsignedSmallInteger('type')->default(301);
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamps();
        });

        Schema::create('not_found_logs', function (Blueprint $table) {
            $table->id();
            $table->string('path', 500);
            $table->string('path_hash', 64)->unique();
            $table->unsignedBigInteger('hits')->default(1);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('service', 60)->unique();
            $table->longText('settings')->nullable(); // encrypted JSON
            $table->boolean('enabled')->default(false);
            $table->string('status', 20)->default('off'); // off, on, error
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });

        Schema::create('event_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 60);
            $table->string('service', 60);
            $table->json('payload')->nullable();
            $table->text('response')->nullable();
            $table->boolean('ok')->default(true);
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_id', 64)->index();
            $table->json('categories');
            $table->string('region', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->json('draft_blocks')->nullable();
            $table->json('published_blocks')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('excerpt', 500)->nullable();
            $table->longText('body')->nullable();
            $table->foreignId('blog_category_id')->nullable()->constrained()->nullOnDelete();
            $table->json('tags')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->string('location', 40)->unique(); // header, footer_1, footer_2, footer_3
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('menu_items')->cascadeOnDelete();
            $table->string('label');
            $table->string('link_type', 20)->default('url'); // category, page, selling_page, url
            $table->unsignedBigInteger('link_id')->nullable();
            $table->string('url', 500)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('home_sections', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->unique();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('subject');
            $table->longText('body');
            $table->timestamps();
        });

        Schema::create('ui_strings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->text('value');
            $table->timestamps();
        });

        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_token', 64)->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('status', 20)->default('open')->index(); // open, closed
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('page_url', 500)->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('sender_type', 20); // visitor, admin, system
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->text('message');
            $table->string('status', 20)->default('new');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('ui_strings');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('home_sections');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menus');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('blog_categories');
        Schema::dropIfExists('content_pages');
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('event_logs');
        Schema::dropIfExists('integrations');
        Schema::dropIfExists('not_found_logs');
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('seo_meta');
        Schema::dropIfExists('review_requests');
        Schema::dropIfExists('review_reports');
        Schema::dropIfExists('review_votes');
        Schema::dropIfExists('review_photos');
        Schema::dropIfExists('reviews');
    }
};
