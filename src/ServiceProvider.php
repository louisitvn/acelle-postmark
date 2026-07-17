<?php

namespace Acelle\Plugin\Postmark;

use App\Library\Facades\Hook;
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
