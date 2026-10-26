<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\InvitationStatus;
use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\NotificationDelivery;
use App\Services\InvitationTokenService;
use App\Services\NotificationDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class InvitationMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public string $deliveryNotificationId;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public Invitation $invitation)
    {
        $this->deliveryNotificationId = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'invitation:'.$invitation->id);
        $this->onQueue('notifications')->afterCommit();
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function send($mailer)
    {
        $invitation = $this->invitation->fresh();
        if ($invitation === null || $invitation->status !== InvitationStatus::Pending
            || $invitation->expires_at->isPast() || $invitation->user?->status !== UserStatus::Invited) {
            return;
        }

        $id = app(NotificationDeliveryService::class)->plan($this->deliveryNotificationId, $invitation->user, 'mail');

        return DB::transaction(function () use ($id, $mailer) {
            $delivery = NotificationDelivery::query()->lockForUpdate()->findOrFail($id);
            if ($delivery->sent_at !== null) {
                return null;
            }
            $result = parent::send($mailer);
            app(NotificationDeliveryService::class)->sent($delivery);

            return $result;
        });
    }

    public function failed(\Throwable $exception): void
    {
        $id = NotificationDelivery::query()->where('notification_id', $this->deliveryNotificationId)->value('id');
        if ($id !== null) {
            app(NotificationDeliveryService::class)->failed($id);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->invitation->email],
            subject: 'Certify LMS への招待',
        );
    }

    public function content(): Content
    {
        $url = app(InvitationTokenService::class)->generateUrl($this->invitation);

        return new Content(
            markdown: 'emails.invitation',
            with: [
                'invitation' => $this->invitation,
                'invitedBy' => $this->invitation->invitedBy,
                'roleLabel' => $this->invitation->role->label(),
                'expiresAt' => $this->invitation->expires_at,
                'url' => $url,
            ],
        );
    }
}
