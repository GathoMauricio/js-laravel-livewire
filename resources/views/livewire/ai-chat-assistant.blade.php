{{-- Renders the AI conversation and its loading-aware message composer. --}}
{{-- The section is the accessible root of the reusable Livewire chat widget. --}}
<section class="ai-chat-widget" aria-labelledby="ai-chat-title">
    {{-- The header identifies the assistant and its current availability. --}}
    <header class="ai-chat-header">
        <div class="ai-avatar" aria-hidden="true">IA</div>
        <div>
            <span class="eyebrow">Inventario Central</span>
            <h2 id="ai-chat-title">Asistente de inventario</h2>
            <p>Consulta productos y ajusta existencias con lenguaje natural.</p>
        </div>
        <span class="ai-status"><span class="state-dot" aria-hidden="true"></span>Disponible</span>
    </header>

    {{-- This scrollable live region announces new user and assistant messages. --}}
    <div class="ai-chat-messages" role="log" aria-live="polite" aria-relevant="additions text" tabindex="0">
        @forelse ($messages as $index => $message)
            {{-- Tool protocol messages stay in history but are not shown as chat bubbles. --}}
            @if (in_array($message['role'] ?? '', ['user', 'assistant'], true) && is_string($message['content'] ?? null) && trim($message['content']) !== '')
                {{-- Align the user's prompt and assistant's answer as separate message rows. --}}
                <article class="ai-message-row {{ $message['role'] === 'user' ? 'is-user' : 'is-assistant' }}" wire:key="chat-message-{{ $index }}">
                    <div class="ai-message-bubble">
                        <span class="ai-message-author">{{ $message['role'] === 'user' ? 'Tú' : 'Asistente' }}</span>
                        {{-- Laravel converts GFM while stripping raw HTML and unsafe links. --}}
                        <div class="ai-message-content">{!! \Illuminate\Support\Str::markdown($message['content'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                    </div>
                </article>
            @endif
        @empty
            {{-- Show a prompt with examples before the conversation starts. --}}
            <div class="ai-chat-welcome">
                <span class="eyebrow">¿Qué necesitas consultar?</span>
                <p>Por ejemplo: “¿Cuántas unidades de papel hay?” o “Agrega 10 unidades al producto 12”.</p>
            </div>
        @endforelse

        {{-- Reveal this status only while the sendMessage action is running. --}}
        <div class="ai-chat-pending d-none" wire:loading.class.remove="d-none" wire:target="sendMessage" role="status">
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
            <span>Consultando inventario...</span>
        </div>
    </div>

    {{-- Prevent a full-page submit and delegate the message to the Livewire action. --}}
    <form class="ai-chat-composer" wire:submit.prevent="sendMessage">
        <label class="visually-hidden" for="ai-user-message">Escribe un mensaje para el asistente</label>
        {{-- Bind the user's text and disable editing until the provider responds. --}}
        <textarea
            id="ai-user-message"
            class="form-control"
            rows="2"
            maxlength="2000"
            placeholder="Escribe tu consulta sobre el inventario..."
            wire:model="userMessage"
            wire:loading.attr="disabled"
            wire:target="sendMessage"
        ></textarea>
        {{-- Display server-side validation directly below the message editor. --}}
        @error('userMessage') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
        <div class="ai-composer-footer">
            <span>El stock solo cambia cuando solicitas un ajuste explícitamente.</span>
            {{-- Disable duplicate submissions and switch the label while waiting. --}}
            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="sendMessage">
                <span wire:loading.remove wire:target="sendMessage">Enviar mensaje</span>
                <span class="d-none" wire:loading.class.remove="d-none" wire:target="sendMessage">
                    <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                    Enviando...
                </span>
            </button>
        </div>
    </form>
</section>
