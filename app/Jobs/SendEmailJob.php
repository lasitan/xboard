<?php

namespace App\Jobs;

use App\Services\MailService;
use App\Models\TicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $params;

    public $tries = 3;
    public $timeout = 10;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($params, $queue = 'send_email')
    {
        $this->onQueue($queue);
        $this->params = $params;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $mailLog = MailService::sendEmail($this->params);
        $ticketMessageId = $this->params['ticket_message_id'] ?? null;
        $notifyType = $this->params['notify_type'] ?? null;

        if ($ticketMessageId && $notifyType && in_array($notifyType, ['admin', 'user'], true)) {
            $column = $notifyType === 'admin' ? 'notify_admin_status' : 'notify_user_status';
            try {
                if (Schema::hasColumn('v2_ticket_message', $column)) {
                    TicketMessage::where('id', $ticketMessageId)->update([
                        $column => $mailLog['error'] ? 0 : 2,
                        'updated_at' => time(),
                    ]);
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if ($mailLog['error']) {
            $this->release(); //发送失败将触发重试
        }
    }
}
