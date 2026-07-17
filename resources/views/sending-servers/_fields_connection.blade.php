{{-- Postmark — Connection tab fields --}}

<div class="mc-form-group">
    <label class="mc-form-label">{{ trans('postmark::messages.fields.server_token') }} <span class="mc-form-required">*</span></label>
    <div style="position:relative;">
        <input type="password"
               name="postmark_server_token"
               value="{{ old('postmark_server_token', $server->getConfig('postmark_server_token')) }}"
               placeholder="server token from API Tokens tab"
               class="mc-form-input @error('postmark_server_token') mc-form-input-error @enderror"
               id="postmark-server-token"
               {{ isset($disabled) && $disabled ? 'disabled' : '' }}>
        <button type="button" class="mc-password-toggle"
                onclick="togglePassword('postmark-server-token', this)"
                style="position:absolute;right:var(--space-2);top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--color-text-muted);padding:var(--space-1);">
            <span class="material-symbols-rounded" style="font-size:18px;">visibility</span>
        </button>
    </div>
    @error('postmark_server_token') <div class="mc-form-error">{{ $message }}</div> @enderror
    <div class="mc-form-help">{{ trans('postmark::messages.fields.server_token_help') }}</div>
</div>

<div class="mc-form-group">
    <label class="mc-form-label">{{ trans('postmark::messages.fields.account_token') }}</label>
    <div style="position:relative;">
        <input type="password"
               name="postmark_account_token"
               value="{{ old('postmark_account_token', $server->getConfig('postmark_account_token')) }}"
               placeholder="optional — for domain/sender management"
               class="mc-form-input @error('postmark_account_token') mc-form-input-error @enderror"
               id="postmark-account-token"
               {{ isset($disabled) && $disabled ? 'disabled' : '' }}>
        <button type="button" class="mc-password-toggle"
                onclick="togglePassword('postmark-account-token', this)"
                style="position:absolute;right:var(--space-2);top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--color-text-muted);padding:var(--space-1);">
            <span class="material-symbols-rounded" style="font-size:18px;">visibility</span>
        </button>
    </div>
    @error('postmark_account_token') <div class="mc-form-error">{{ $message }}</div> @enderror
    <div class="mc-form-help">{{ trans('postmark::messages.fields.account_token_help') }}</div>
</div>

<div class="mc-form-group">
    <label class="mc-form-label">{{ trans('postmark::messages.fields.message_stream') }}</label>
    <input type="text"
           name="postmark_message_stream"
           value="{{ old('postmark_message_stream', $server->getConfig('postmark_message_stream', 'outbound')) }}"
           placeholder="outbound"
           class="mc-form-input @error('postmark_message_stream') mc-form-input-error @enderror"
           {{ isset($disabled) && $disabled ? 'disabled' : '' }}>
    @error('postmark_message_stream') <div class="mc-form-error">{{ $message }}</div> @enderror
    <div class="mc-form-help">{{ trans('postmark::messages.fields.message_stream_help') }}</div>
</div>

<div class="mc-form-group">
    <label class="mc-form-label">{{ trans('postmark::messages.fields.webhook_url') }}</label>
    <input type="text"
           value="{{ $server->id ? $server->driver()->webhookUrl() : trans('postmark::messages.fields.webhook_url_after_save') }}"
           class="mc-form-input"
           readonly>
    <div class="mc-form-help">
        @php $webhookId = $server->id ? $server->getConfig('postmark_webhook_id') : null; @endphp
        @if ($webhookId)
            <span style="color:var(--color-success);">
                <span class="material-symbols-rounded" style="font-size:14px;vertical-align:-2px;">check_circle</span>
                {{ trans('postmark::messages.fields.webhook_status_active', ['id' => $webhookId]) }}
            </span>
        @elseif ($server->id)
            <span style="color:var(--color-text-muted);">
                <span class="material-symbols-rounded" style="font-size:14px;vertical-align:-2px;">schedule</span>
                {{ trans('postmark::messages.fields.webhook_status_pending') }}
            </span>
        @else
            {{ trans('postmark::messages.fields.webhook_url_help') }}
        @endif
    </div>
</div>
