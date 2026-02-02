<?php
namespace App\Services;


use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Schema;

class TicketService
{
    public function reply($ticket, $message, $userId)
    {
        try {
            DB::beginTransaction();
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            if ($userId !== $ticket->user_id) {
                $ticket->reply_status = Ticket::STATUS_OPENING;
            } else {
                $ticket->reply_status = Ticket::STATUS_CLOSED;
            }
            if (!$ticketMessage || !$ticket->save()) {
                throw new \Exception();
            }
            DB::commit();

            if ($userId === $ticket->user_id) {
                $this->sendEmailNotifyAdmin($ticket, $ticketMessage, 'reply');
            }
            return $ticketMessage;
        } catch (\Exception $e) {
            DB::rollback();
            return false;
        }
    }

    public function replyByAdmin($ticketId, $message, $userId): void
    {
        $ticket = Ticket::where('id', $ticketId)
            ->first();
        if (!$ticket) {
            throw new ApiException('工单不存在');
        }
        $ticket->status = Ticket::STATUS_OPENING;
        try {
            DB::beginTransaction();
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            if ($userId !== $ticket->user_id) {
                $ticket->reply_status = Ticket::STATUS_OPENING;
            } else {
                $ticket->reply_status = Ticket::STATUS_CLOSED;
            }
            if (!$ticketMessage || !$ticket->save()) {
                throw new ApiException('工单回复失败');
            }
            DB::commit();
            HookManager::call('ticket.reply.admin.after', [$ticket, $ticketMessage]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
        $this->sendEmailNotify($ticket, $ticketMessage);
    }

    public function createTicket($userId, $subject, $level, $message)
    {
        try {
            DB::beginTransaction();
            if (Ticket::where('status', 0)->where('user_id', $userId)->lockForUpdate()->first()) {
                DB::rollBack();
                throw new ApiException('存在未关闭的工单');
            }
            $ticket = Ticket::create([
                'user_id' => $userId,
                'subject' => $subject,
                'level' => $level
            ]);
            if (!$ticket) {
                throw new ApiException('工单创建失败');
            }
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            if (!$ticketMessage) {
                DB::rollBack();
                throw new ApiException('工单消息创建失败');
            }
            DB::commit();

            $this->sendEmailNotifyAdmin($ticket, $ticketMessage, 'create');
            return $ticket;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function getAdminNotifyEmails(): array
    {
        return User::query()
            ->where('is_admin', 1)
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->pluck('email')
            ->map(fn($v) => trim((string) $v))
            ->filter(fn($v) => $v !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function sendEmailNotifyAdmin(Ticket $ticket, TicketMessage $ticketMessage, string $event): void
    {
        if (!admin_setting('email_host')) {
            return;
        }

        $adminEmails = $this->getAdminNotifyEmails();
        if (empty($adminEmails)) {
            return;
        }

        $hasNotifyAdminColumn = false;
        try {
            $hasNotifyAdminColumn = Schema::hasColumn('v2_ticket_message', 'notify_admin_status');
        } catch (\Throwable) {
            $hasNotifyAdminColumn = false;
        }

        if ($hasNotifyAdminColumn) {
            if (isset($ticketMessage->notify_admin_status) && (int) $ticketMessage->notify_admin_status >= 2) {
                return;
            }
        }

        $user = User::find($ticket->user_id);
        $appName = admin_setting('app_name', 'XBoard');

        $subject = $event === 'create'
            ? '您在' . $appName . '收到了新的工单'
            : '您在' . $appName . '的工单收到了新的回复';

        $content = "主题：{$ticket->subject}\r\n";
        if ($user) {
            $content .= "用户邮箱：{$user->email}\r\n";
        }
        $content .= "内容：{$ticketMessage->message}";

        if ($hasNotifyAdminColumn) {
            TicketMessage::where('id', $ticketMessage->id)->update([
                'notify_admin_status' => 1,
                'updated_at' => time(),
            ]);
        }

        foreach ($adminEmails as $email) {
            SendEmailJob::dispatch([
                'email' => $email,
                'subject' => $subject,
                'template_name' => 'notify',
                'template_value' => [
                    'name' => $appName,
                    'url' => admin_setting('app_url'),
                    'content' => $content
                ],
                'ticket_message_id' => $ticketMessage->id,
                'notify_type' => 'admin'
            ]);
        }
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        if (!admin_setting('email_host')) {
            return;
        }

        $hasNotifyUserColumn = false;
        try {
            $hasNotifyUserColumn = Schema::hasColumn('v2_ticket_message', 'notify_user_status');
        } catch (\Throwable) {
            $hasNotifyUserColumn = false;
        }

        if ($hasNotifyUserColumn) {
            if (isset($ticketMessage->notify_user_status) && (int) $ticketMessage->notify_user_status >= 2) {
                return;
            }
        }

        $user = User::find($ticket->user_id);
        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        if (!Cache::get($cacheKey)) {
            Cache::put($cacheKey, 1, 1800);

            if ($hasNotifyUserColumn) {
                TicketMessage::where('id', $ticketMessage->id)->update([
                    'notify_user_status' => 1,
                    'updated_at' => time(),
                ]);
            }

            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => '您在' . admin_setting('app_name', 'XBoard') . '的工单得到了回复',
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'XBoard'),
                    'url' => admin_setting('app_url'),
                    'content' => "主题：{$ticket->subject}\r\n回复内容：{$ticketMessage->message}"
                ],
                'ticket_message_id' => $ticketMessage->id,
                'notify_type' => 'user'
            ]);
        }
    }

    public function backfillEmailNotifications(int $limit = 200): void
    {
        if (!admin_setting('email_host')) {
            return;
        }

        try {
            if (
                !Schema::hasColumn('v2_ticket_message', 'notify_admin_status')
                || !Schema::hasColumn('v2_ticket_message', 'notify_user_status')
            ) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $now = time();
        $stuckSeconds = 10 * 60;

        $messages = TicketMessage::query()
            ->select('v2_ticket_message.*')
            ->join('v2_ticket', 'v2_ticket.id', '=', 'v2_ticket_message.ticket_id')
            ->where('v2_ticket.status', Ticket::STATUS_OPENING)
            ->where(function ($q) use ($now, $stuckSeconds) {
                $q->where('v2_ticket_message.notify_admin_status', 0)
                    ->orWhere(function ($qq) use ($now, $stuckSeconds) {
                        $qq->where('v2_ticket_message.notify_admin_status', 1)
                            ->where('v2_ticket_message.updated_at', '<', $now - $stuckSeconds);
                    })
                    ->orWhere('v2_ticket_message.notify_user_status', 0)
                    ->orWhere(function ($qq) use ($now, $stuckSeconds) {
                        $qq->where('v2_ticket_message.notify_user_status', 1)
                            ->where('v2_ticket_message.updated_at', '<', $now - $stuckSeconds);
                    });
            })
            ->orderBy('v2_ticket_message.id', 'asc')
            ->limit($limit)
            ->get();

        foreach ($messages as $message) {
            $ticket = Ticket::find($message->ticket_id);
            if (!$ticket || (int) $ticket->status !== Ticket::STATUS_OPENING) {
                continue;
            }

            $isFromAdmin = (int) $ticket->user_id !== (int) $message->user_id;

            if ($isFromAdmin) {
                if ((int) ($message->notify_user_status ?? 0) !== 2) {
                    $this->sendEmailNotify($ticket, $message);
                }
            } else {
                if ((int) ($message->notify_admin_status ?? 0) !== 2) {
                    $this->sendEmailNotifyAdmin($ticket, $message, 'reply');
                }
            }
        }
    }
}
