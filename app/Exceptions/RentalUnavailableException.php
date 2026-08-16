<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Equipment being unavailable for a requested date range is a normal business
 * situation, not a system failure — see app/docs/features/cart-order-system.md
 * ("Availability messaging"). Callers that show this to the customer MUST use
 * messages()/items() and render it through the dedicated `availability` error
 * bag (CartController, CheckoutController), never mix it into the default
 * validation error bag.
 *
 * Older call sites (RentalExtensionService, RentalAvailabilityService::
 * createHold()/confirmHold()) still construct this with a plain string and no
 * items — that keeps working unchanged, getMessage()/messages() both fall
 * back to it.
 */
class RentalUnavailableException extends RuntimeException
{
    /**
     * @param  array<int, array{service_name: string, requested: int, available: int, start_date: string, end_date: string}>  $items
     */
    public function __construct(
        string $message = 'Wybrany sprzęt nie jest dostępny w podanym terminie.',
        private readonly array $items = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Build the exception for a single unavailable service (addItem(), updateQuantity()).
     */
    public static function forItem(string $serviceName, int $requested, int $available, Carbon $start, Carbon $end): self
    {
        return self::forItems([self::describeItem($serviceName, $requested, $available, $start, $end)]);
    }

    /**
     * Build the exception carrying every unavailable item found in one pass —
     * lets the caller report the full picture instead of failing on the first
     * one (see CartService::convertToOrder()).
     *
     * @param  array<int, array{service_name: string, requested: int, available: int, start_date: string, end_date: string}>  $items
     */
    public static function forItems(array $items): self
    {
        return new self(
            implode(' ', array_map(self::friendlyMessage(...), $items)),
            $items
        );
    }

    /**
     * @return array{service_name: string, requested: int, available: int, start_date: string, end_date: string}
     */
    public static function describeItem(string $serviceName, int $requested, int $available, Carbon $start, Carbon $end): array
    {
        return [
            'service_name' => $serviceName,
            'requested' => $requested,
            'available' => $available,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ];
    }

    /**
     * @return array<int, array{service_name: string, requested: int, available: int, start_date: string, end_date: string}>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * One user-facing line per unavailable item, in the tone already used on
     * the service page ("Dostępnych: X szt." / "Brak dostępności w wybranym
     * terminie" — services/show.blade.php), not the generic validation
     * "Wystąpiły błędy" wording. Falls back to getMessage() for the older,
     * item-less call sites.
     *
     * @return array<int, string>
     */
    public function messages(): array
    {
        return $this->items === []
            ? [$this->getMessage()]
            : array_map(self::friendlyMessage(...), $this->items);
    }

    /**
     * @param  array{service_name: string, requested: int, available: int, start_date: string, end_date: string}  $item
     */
    private static function friendlyMessage(array $item): string
    {
        $period = self::formatPeriod($item['start_date'], $item['end_date']);

        if ($item['available'] <= 0) {
            return "„{$item['service_name']}”: brak dostępności w terminie {$period}. Wybierz inny termin lub inny sprzęt.";
        }

        return "„{$item['service_name']}”: dostępnych {$item['available']} szt. w terminie {$period} (wybrano {$item['requested']}). Zmniejsz ilość lub wybierz inny termin.";
    }

    private static function formatPeriod(string $start, string $end): string
    {
        $format = fn (string $date): string => Carbon::parse($date)->format('d.m.Y');

        return $start === $end ? $format($start) : $format($start).'–'.$format($end);
    }
}
