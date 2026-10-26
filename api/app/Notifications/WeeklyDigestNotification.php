<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Concerns\RespectsPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * MSG-03 — weekly e-mail digest for approved investors: new matching opportunities, nominations awaiting a reply and
 * information requests awaiting an answer. Sent only while the `digest` e-mail preference is on (opt-out, NTF-02);
 * also kept in the in-app list. Never SMS.
 */
class WeeklyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsPreferences {
        via as preferenceChannels;
    }

    private const LIST_LIMIT = 5;

    /**
     * @param  array{matches: array<int, array{uuid: string, name: string, name_en: ?string}>, nominations_pending: array<int, array{uuid: string, name: string, name_en: ?string}>, nominations_new: int, info_requests_open: int}  $digest
     */
    public function __construct(private readonly array $digest)
    {
        $this->onQueue('notifications');
    }

    public function category(): string
    {
        return 'digest';
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = $this->preferenceChannels($notifiable);

        // An e-mail digest: nothing at all once the investor switched the e-mail off.
        return in_array('mail', $channels, true) ? ['database', 'mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('notifications.digest.subject'))
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name ?? '']))
            ->line(__('notifications.digest.intro'));

        $matches = $this->digest['matches'];
        if ($matches !== []) {
            $mail->line(__('notifications.digest.matches', ['count' => count($matches)]));
            foreach (array_slice($matches, 0, self::LIST_LIMIT) as $item) {
                $mail->line(__('notifications.digest.item', ['name' => $this->name($item)]));
            }
        }

        $pending = $this->digest['nominations_pending'];
        if ($pending !== []) {
            $mail->line(__('notifications.digest.nominations', ['count' => count($pending)]));
            foreach (array_slice($pending, 0, self::LIST_LIMIT) as $item) {
                $mail->line(__('notifications.digest.item', ['name' => $this->name($item)]));
            }
        }
        if ($this->digest['nominations_new'] > 0) {
            $mail->line(__('notifications.digest.nominations_new', ['count' => $this->digest['nominations_new']]));
        }
        if ($this->digest['info_requests_open'] > 0) {
            $mail->line(__('notifications.digest.info_requests', ['count' => $this->digest['info_requests_open']]));
        }

        return $mail
            ->action(__('notifications.digest.action'), $this->frontendUrl('/app/investor/dashboard'))
            ->line(__('notifications.digest.opt_out'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'investor.digest',
            'title' => __('notifications.digest.title'),
            'body' => __('notifications.digest.body', [
                'matches' => count($this->digest['matches']),
                'nominations' => count($this->digest['nominations_pending']),
                'info' => $this->digest['info_requests_open'],
            ]),
            'matches' => count($this->digest['matches']),
            'nominations_pending' => count($this->digest['nominations_pending']),
            'nominations_new' => $this->digest['nominations_new'],
            'info_requests_open' => $this->digest['info_requests_open'],
            'url' => '/app/investor/dashboard',
        ];
    }

    /** @param array<string, mixed> $item */
    private function name(array $item): string
    {
        return app()->getLocale() === 'en' && filled($item['name_en'] ?? null) ? (string) $item['name_en'] : (string) $item['name'];
    }
}
