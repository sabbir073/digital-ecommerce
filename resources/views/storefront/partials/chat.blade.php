{{-- Live chat widget. Shown only when chat is switched on in admin; its script loads on its own, apart from the page's. --}}
@if ($chat)
    <div class="chat {{ $chatClass ?? '' }}" data-chat data-state-url="{{ route('chat.state') }}" data-start-url="{{ route('chat.start') }}" data-send-url="{{ route('chat.send') }}">
        <button type="button" class="chat-launcher" data-chat-open aria-haspopup="dialog" aria-expanded="false" aria-controls="chat-panel">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8.5 8.5 0 0 1-12.4 7.5L4 20.5l1.1-4.2A8.5 8.5 0 1 1 21 12Z"/></svg>
            <span class="sr-only">{{ t('Chat with us') }}</span>
            <span class="chat-badge" data-chat-badge hidden></span>
        </button>

        <section class="chat-panel" id="chat-panel" role="dialog" aria-labelledby="chat-title" data-chat-panel hidden>
            <header class="chat-head">
                <div>
                    <h2 id="chat-title" class="text-[17px] font-semibold text-white">{{ t('Chat with :store', ['store' => $storeName]) }}</h2>
                    <p class="chat-status" data-chat-status>{{ t('Checking who is here…') }}</p>
                </div>
                <button type="button" class="chat-close" data-chat-close aria-label="{{ t('Close chat') }}">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </header>

            <div class="chat-body" data-chat-body tabindex="0" aria-label="{{ t('Messages') }}">
                <p class="chat-note" data-chat-intro data-welcome="{{ $chat['welcome'] }}" data-away="{{ $chat['away'] }}">{{ $chat['welcome'] }}</p>
                <ol class="chat-list" data-chat-list aria-live="polite"></ol>
            </div>

            <form class="chat-form" data-chat-form novalidate>
                <div class="chat-hp" aria-hidden="true"><label>{{ t('Website') }} <input name="website" tabindex="-1" autocomplete="off"></label></div>
                <div class="grid gap-2.5" data-chat-details>
                    <div>
                        <label class="field-label" for="chat-name">{{ t('Your name (optional)') }}</label>
                        <input class="field" id="chat-name" name="name" maxlength="60" autocomplete="name" value="{{ $chat['name'] }}">
                    </div>
                    <div>
                        <label class="field-label" for="chat-email">{{ t('Email') }} <span data-chat-email-note>{{ t('(optional)') }}</span></label>
                        <input class="field" id="chat-email" name="email" type="email" maxlength="255" autocomplete="email" value="{{ $chat['email'] }}" aria-describedby="chat-error">
                    </div>
                </div>
                <div class="flex items-end gap-2">
                    <div class="min-w-0 flex-1">
                        <label class="sr-only" for="chat-message">{{ t('Message') }}</label>
                        <textarea class="field chat-input" id="chat-message" name="message" rows="1" maxlength="2000" placeholder="{{ t('Write a message') }}" required aria-describedby="chat-error"></textarea>
                    </div>
                    <button type="submit" class="chat-send" aria-label="{{ t('Send message') }}">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    </button>
                </div>
                <p class="field-error" id="chat-error" role="alert" data-chat-error hidden></p>
            </form>
        </section>
    </div>
    @vite('resources/js/storefront/chat.ts')
@endif
