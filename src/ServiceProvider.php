<?php

namespace Acelle\Plugin\Postmark;

use App\Library\Facades\Hook;
use App\SendingServers\DomainVerification\VerificationResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider as Base;

class ServiceProvider extends Base
{
    public const PLUGIN_NAME = 'acelle/postmark';

    public function register(): void
    {
        Hook::add('add_translation_file', fn () => [
            'id'                      => '#acelle/postmark_translation_file',
            'plugin_name'             => self::PLUGIN_NAME,
            'file_title'              => 'Translation for acelle/postmark plugin',
            'translation_folder'      => storage_path('app/data/plugins/acelle/postmark/lang/'),
            'translation_prefix'      => 'postmark',
            'file_name'               => 'messages.php',
            'master_translation_file' => realpath(__DIR__ . '/../resources/lang/en/messages.php'),
        ]);
    }

    public function boot(): void
    {
        // The platform's domain-verification result carries a full status since
        // 2026-10-23 (it was a yes/no). On an older platform the plugin would
        // install, then fail at the first domain registration or Verify. Refuse
        // to load instead: the platform reports a plugin that fails to boot.
        if (!property_exists(VerificationResult::class, 'status')) {
            throw new \RuntimeException('The Postmark plugin needs a newer version of the platform: its domain-verification result has no status (VerificationResult::$status). Upgrade the platform, then activate the plugin again.');
        }

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'postmark');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');

        Hook::add('register_sending_server_driver', fn () => [
            'type'         => PostmarkDriver::TYPE,
            'driver'       => PostmarkDriver::class,
            'config_keys'  => [
                'postmark_server_token',
                'postmark_account_token',
                'postmark_message_stream',
                'postmark_webhook_id',
                'postmark_webhook_user',
                'postmark_webhook_pass',
            ],
            // Credentials — encrypted at rest by SendingServer::setConfig().
            // Message stream, webhook id and basic-auth USER are identifiers.
            'secret_config_keys' => [
                'postmark_server_token',
                'postmark_account_token',
                'postmark_webhook_pass',
            ],
            'name'         => 'Postmark',
            'description'  => 'Send via Postmark (postmarkapp.com) — server token + auto-registered webhook',
            'icon_url'     => route('plugin.acelle.postmark.icon'),
        ]);

        Hook::set('icon_url_' . self::PLUGIN_NAME, fn () => route('plugin.acelle.postmark.icon'));

        Hook::on('delete_plugin_' . self::PLUGIN_NAME, function () {
            foreach (\App\Model\SendingServer::where('type', PostmarkDriver::TYPE)->get() as $s) {
                try {
                    $s->driver()->teardownWebhook();
                } catch (\Throwable $e) {
                    Log::warning('Postmark plugin delete: webhook teardown failed', [
                        'server_id' => $s->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
            \App\Model\SendingServer::where('type', PostmarkDriver::TYPE)->forceDelete();
        });
    }
}
