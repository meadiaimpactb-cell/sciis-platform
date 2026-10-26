<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * OPP-07 — newly listed opportunities matching the investor's saved searches or profile, one notification per run.
 * In-app always; e-mail per the `alerts` preference (NTF-02); never SMS.
 */
class OpportunityAlertNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences {
        via as preferenceChannels;
    }

    /** Opportunities listed by name in the e-mail. */
    private const MAIL_LIMIT = 10;

    /** @param array<int, array{uuid: string, reference_no: ?string, name: string, name_en: ?string, source: string, filter_name: ?string, score: ?int}> $items */
    public function __construct(private readonly array $items)
    {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'alerts';
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return array_values(array_diff($this->preferenceChannels($notifiable), ['sms']));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->items);
        $mail = (new MailMessage)
            ->subject(__('notifications.opportunity_alert.subject', ['count' => $count]))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line($count === 1 ? __('notifications.opportunity_alert.line_one', ['name' => $this->name($this->items[0])]) : __('notifications.opportunity_alert.line'));

        if ($count > 1) {
            foreach (array_slice($this->items, 0, self::MAIL_LIMIT) as $item) {
                $mail->line($this->itemLine($item));
            }
            if ($count > self::MAIL_LIMIT) {
                $mail->line(__('notifications.opportunity_alert.more', ['count' => $count - self::MAIL_LIMIT]));
            }
        } else {
            $mail->line($this->itemLine($this->items[0]));
        }

        return $mail
            ->action(__($count === 1 ? 'notifications.opportunity_alert.action_one' : 'notifications.opportunity_alert.action'), $this->frontendUrl($this->path()))
            ->line(__('notifications.opportunity_alert.manage'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $count = count($this->items);

        return [
            'type' => 'opportunity.alert',
            'title' => __('notifications.opportunity_alert.title'),
            'body' => $count === 1
                ? __('notifications.opportunity_alert.line_one', ['name' => $this->name($this->items[0])])
                : __('notifications.opportunity_alert.subject', ['count' => $count]),
            'count' => $count,
            'opportunity_uuid' => $count === 1 ? $this->items[0]['uuid'] : null,
            'opportunity_uuids' => array_column($this->items, 'uuid'),
            'url' => $this->path(),
        ];
    }

    private function path(): string
    {
        return count($this->items) === 1 ? '/app/opportunities/'.$this->items[0]['uuid'] : '/app/opportunities';
    }

    /** @param array<string, mixed> $item */
    private function name(array $item): string
    {
        return app()->getLocale() === 'en' && filled($item['name_en'] ?? null) ? (string) $item['name_en'] : (string) $item['name'];
    }

    /** @param array<string, mixed> $item */
    private function itemLine(array $item): string
    {
        return $item['source'] === 'filter'
            ? __('notifications.opportunity_alert.item_filter', ['name' => $this->name($item), 'filter' => (string) $item['filter_name']])
            : __('notifications.opportunity_alert.item_profile', ['name' => $this->name($item), 'score' => (int) $item['score']]);
    }
}
