<?php

namespace App\Console\Commands;

use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CheckTicket extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:ticket';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '工单检查任务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', -1);

        $now = time();

        $hasLastReplyUserId = false;
        $hasUserRemindAt = false;
        $hasAutoClosedAt = false;
        try {
            $hasLastReplyUserId = Schema::hasColumn('v2_ticket', 'last_reply_user_id');
            $hasUserRemindAt = Schema::hasColumn('v2_ticket', 'user_remind_at');
            $hasAutoClosedAt = Schema::hasColumn('v2_ticket', 'auto_closed_at');
        } catch (\Throwable) {
            $hasLastReplyUserId = false;
            $hasUserRemindAt = false;
            $hasAutoClosedAt = false;
        }

        if ($hasLastReplyUserId && admin_setting('email_host')) {
            if ($hasUserRemindAt) {
                $remindTickets = Ticket::query()
                    ->where('status', Ticket::STATUS_OPENING)
                    ->where('reply_status', 0)
                    ->whereNotNull('last_reply_user_id')
                    ->whereColumn('last_reply_user_id', '<>', 'user_id')
                    ->where('updated_at', '<=', $now - 30 * 60)
                    ->where(function ($q) {
                        $q->whereNull('user_remind_at')->orWhere('user_remind_at', 0);
                    })
                    ->limit(200)
                    ->get();

                foreach ($remindTickets as $ticket) {
                    $user = User::find($ticket->user_id);
                    if (!$user || !isset($user->email) || trim((string) $user->email) === '') {
                        Ticket::where('id', $ticket->id)->update([
                            'user_remind_at' => $now,
                            'updated_at' => $ticket->updated_at,
                        ]);
                        continue;
                    }

                    $appName = admin_setting('app_name', 'XBoard');
                    $subject = '您在' . $appName . '的工单等待您的回复';
                    $content = "主题：{$ticket->subject}\r\n";
                    $content .= "我们正在焦急的等待您的回复，您还在吗？";

                    Ticket::where('id', $ticket->id)->update([
                        'user_remind_at' => $now,
                        'updated_at' => $ticket->updated_at,
                    ]);

                    SendEmailJob::dispatch([
                        'email' => $user->email,
                        'subject' => $subject,
                        'template_name' => 'notify',
                        'template_value' => [
                            'name' => $appName,
                            'url' => admin_setting('app_url'),
                            'content' => $content
                        ]
                    ]);
                }
            }

            if ($hasAutoClosedAt) {
                $closeTickets = Ticket::query()
                    ->where('status', Ticket::STATUS_OPENING)
                    ->where('reply_status', 0)
                    ->whereNotNull('last_reply_user_id')
                    ->whereColumn('last_reply_user_id', '<>', 'user_id')
                    ->where('updated_at', '<=', $now - 3 * 3600)
                    ->where(function ($q) {
                        $q->whereNull('auto_closed_at')->orWhere('auto_closed_at', 0);
                    })
                    ->limit(200)
                    ->get();

                foreach ($closeTickets as $ticket) {
                    $user = User::find($ticket->user_id);
                    $appName = admin_setting('app_name', 'XBoard');

                    Ticket::where('id', $ticket->id)->update([
                        'status' => Ticket::STATUS_CLOSED,
                        'auto_closed_at' => $now,
                        'updated_at' => $ticket->updated_at,
                    ]);

                    if ($user && isset($user->email) && trim((string) $user->email) !== '') {
                        $subject = '您在' . $appName . '的工单已自动关闭';
                        $content = "主题：{$ticket->subject}\r\n";
                        $content .= "我们暂时关闭了该工单。如果您仍需要继续处理，欢迎随时重新创建工单，我们会尽快为您跟进。";

                        SendEmailJob::dispatch([
                            'email' => $user->email,
                            'subject' => $subject,
                            'template_name' => 'notify',
                            'template_value' => [
                                'name' => $appName,
                                'url' => admin_setting('app_url'),
                                'content' => $content
                            ]
                        ]);
                    }
                }
            }
        }

        $tickets = Ticket::where('status', 0)
            ->where('updated_at', '<=', $now - 24 * 3600)
            ->where('reply_status', 0)
            ->limit(500)
            ->get();
        foreach ($tickets as $ticket) {
            if ($hasLastReplyUserId && $ticket->user_id === $ticket->last_reply_user_id) continue;
            $ticket->status = Ticket::STATUS_CLOSED;
            $ticket->save();
        }
    }
}
