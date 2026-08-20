<?php

namespace App\Domain\Projects;

enum ProjectState: string
{
    case Planned = 'planned';
    case Open = 'open';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $destination): bool
    {
        return match ($this) {
            self::Planned => in_array($destination, [self::Open, self::Cancelled], true),
            self::Open => in_array($destination, [self::Closed, self::Cancelled], true),
            self::Closed => $destination === self::Open,
            self::Cancelled => in_array($destination, [self::Planned, self::Open], true),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Pianificato',
            self::Open => 'Aperto',
            self::Closed => 'Chiuso',
            self::Cancelled => 'Cancellato',
        };
    }

    public function transitionRequiresReason(self $destination): bool
    {
        return $destination === self::Closed
            || $destination === self::Cancelled
            || ($destination === self::Open && in_array($this, [self::Closed, self::Cancelled], true));
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $state) {
            $options[$state->value] = $state->label();
        }

        return $options;
    }
}
