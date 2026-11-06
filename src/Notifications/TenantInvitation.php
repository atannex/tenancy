<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Notifications;

use Atannex\Tenancy\Models\Invitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/** Default invitation mail; replace it with tenancy.people.invitations.notification. */
class TenantInvitation extends Notification
{
    public function __construct(public readonly Invitation $invitation, public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenant = $this->invitation->tenant;

        return (new MailMessage)
            ->subject('You are invited to join '.($tenant->name ?? 'a team'))
            ->line('You have been invited to join '.($tenant->name ?? 'a team').' as '.$this->invitation->role.'.')
            ->action('Accept invitation', $this->acceptUrl())
            ->line('This invitation expires '.$this->invitation->expires_at->diffForHumans().'.');
    }

    public function acceptUrl(): string
    {
        if (Route::has('tenancy.invitations.accept')) {
            return route('tenancy.invitations.accept', $this->token);
        }

        $template = (string) (config('tenancy.people.invitations.accept_url') ?: url('/invitations/{token}'));

        return str_replace('{token}', $this->token, $template);
    }
}
