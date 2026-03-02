<?php

namespace App\Filament\Forms\Components;

use App\Support\Settings\SettingsManager;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;

class DurationInput extends Component
{
    protected string $view = 'filament.forms.components.duration-input';

    /**
     * Create a duration input field that stores total minutes
     * but displays as days, hours, and minutes
     */
    public static function make(string $name): static
    {
        return app(static::class, ['name' => $name]);
    }

    public function getChildComponents(): array
    {
        $totalMinutes = $this->getState() ?? 0;

        // Calculate days, hours, minutes from total minutes
        $days = floor($totalMinutes / 1440); // 1440 minutes = 1 day
        $remainingAfterDays = $totalMinutes % 1440;
        $hours = floor($remainingAfterDays / 60);
        $minutes = $remainingAfterDays % 60;

        return [
            Grid::make(3)
                ->schema([
                    TextInput::make($this->getName().'_days')
                        ->label('Dni')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(0) // Car detailing services don't span multiple days
                        ->default((int) $days)
                        ->suffix('dni')
                        ->live()
                        ->afterStateUpdated(fn () => $this->updateTotalMinutes())
                        ->helperText('Usługi wielodniowe nie są obsługiwane'),

                    TextInput::make($this->getName().'_hours')
                        ->label('Godziny')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(9) // Max 9 hours (single work day)
                        ->default((int) $hours)
                        ->suffix('godz')
                        ->live()
                        ->afterStateUpdated(fn () => $this->updateTotalMinutes())
                        ->required(),

                    TextInput::make($this->getName().'_minutes')
                        ->label('Minuty')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(59)
                        ->step(15) // 15-minute increments
                        ->default((int) $minutes)
                        ->suffix('min')
                        ->live()
                        ->afterStateUpdated(fn () => $this->updateTotalMinutes())
                        ->required(),
                ])
                ->columns(3),
        ];
    }

    protected function updateTotalMinutes(): void
    {
        $livewire = $this->getLivewire();
        $data = $livewire->data;

        $days = (int) ($data[$this->getName().'_days'] ?? 0);
        $hours = (int) ($data[$this->getName().'_hours'] ?? 0);
        $minutes = (int) ($data[$this->getName().'_minutes'] ?? 0);

        // Calculate total minutes
        $totalMinutes = ($days * 1440) + ($hours * 60) + $minutes;

        // Validate max duration based on settings
        $maxDuration = app(SettingsManager::class)->maxServiceDurationMinutes();
        if ($totalMinutes > $maxDuration) {
            $totalMinutes = $maxDuration;
        }

        // Update the main field value
        $data[$this->getName()] = $totalMinutes;
        $livewire->data = $data;
    }

    public function dehydrateStateUsing(\Closure $callback): static
    {
        $this->dehydrateStateUsing = function ($state) {
            $livewire = $this->getLivewire();
            $data = $livewire->data;

            $days = (int) ($data[$this->getName().'_days'] ?? 0);
            $hours = (int) ($data[$this->getName().'_hours'] ?? 0);
            $minutes = (int) ($data[$this->getName().'_minutes'] ?? 0);

            return ($days * 1440) + ($hours * 60) + $minutes;
        };

        return $this;
    }
}
