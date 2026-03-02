<x-mail::message>
# Alert: Limit SMS {{ $period === 'daily' ? 'dzienny' : 'miesięczny' }}

Wykorzystano **{{ $percentage }}%** limitu SMS.

| Parametr | Wartość |
|:---------|--------:|
| Okres | {{ $period === 'daily' ? 'Dzisiaj' : 'Ten miesiąc' }} |
| Wysłano SMS | {{ $currentCount }} |
| Limit | {{ $limit }} |
| Pozostało | {{ $limit - $currentCount }} |

@if($percentage >= 90)
**UWAGA:** Limit jest prawie wyczerpany. Po przekroczeniu limitu SMS-y nie będą wysyłane.
@endif

---

Wiadomość wygenerowana automatycznie przez system {{ config('app.name') }}.

<x-mail::button :url="config('app.url') . '/admin'">
Panel administracyjny
</x-mail::button>

Pozdrawiamy,<br>
{{ config('app.name') }}
</x-mail::message>
