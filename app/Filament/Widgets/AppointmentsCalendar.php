<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\AppointmentResource;
use App\Models\Appointment;
use Guava\Calendar\Filament\CalendarWidget;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Guava\Calendar\ValueObjects\EventClickInfo;
use Guava\Calendar\ValueObjects\FetchInfo;
use Illuminate\Database\Eloquent\Model;

class AppointmentsCalendar extends CalendarWidget
{
    protected static ?int $sort = 1;

    public function getEvents(FetchInfo $info): array
    {
        return Appointment::query()
            ->with(['service', 'customer', 'staff'])
            ->when(
                $info->start,
                fn ($query) => $query->where('appointment_date', '>=', $info->start)
            )
            ->when(
                $info->end,
                fn ($query) => $query->where('appointment_date', '<=', $info->end)
            )
            ->get()
            ->map(function (Appointment $appointment) {
                return CalendarEvent::make()
                    ->key($appointment->id)
                    ->title(
                        $appointment->customer->name.' - '.$appointment->service->name
                    )
                    ->start($appointment->appointment_date->format('Y-m-d').' '.$appointment->start_time->format('H:i'))
                    ->end($appointment->appointment_date->format('Y-m-d').' '.$appointment->end_time->format('H:i'))
                    ->backgroundColor($this->getEventColor($appointment->status))
                    ->textColor('#ffffff')
                    ->url(AppointmentResource::getUrl('edit', ['record' => $appointment->id]));
            })
            ->toArray();
    }

    protected function getEventColor(string $status): string
    {
        return match ($status) {
            'pending' => '#f59e0b',
            'confirmed' => '#10b981',
            'cancelled' => '#ef4444',
            'completed' => '#6b7280',
            default => '#3b82f6',
        };
    }

    public function onEventClick(EventClickInfo $info, Model $event, ?string $action = null): void
    {
        redirect(AppointmentResource::getUrl('edit', ['record' => $event->id]));
    }
}
