<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Appointment;
use App\Models\User;
use App\Services\CalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * AppointmentObserver requires a valid staff_id (user with the 'staff' role)
     * on create — roles are seeded by RolePermissionSeeder in TestCase.
     */
    private function makeAppointment(array $attributes = []): Appointment
    {
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        return Appointment::factory()->create(array_merge([
            'staff_id' => $staff->id,
        ], $attributes));
    }

    public function test_ical_attendee_cn_escapes_crlf_injection_attempt(): void
    {
        $appointment = $this->makeAppointment([
            'first_name' => "Jan\r\nATTENDEE;CN=Injected",
            'last_name' => 'Kowalski',
        ]);

        $ical = CalendarService::generateIcalFile($appointment);

        // No raw CRLF sequence physically reaches the ATTENDEE property value —
        // the injected "ATTENDEE;CN=Injected" text is neutered (CR/LF stripped)
        // and stays inside the single, quoted CN param-value.
        $this->assertStringNotContainsString("CN=\"Jan\r\nATTENDEE", $ical);
        $this->assertStringContainsString('ATTENDEE;CN="JanATTENDEE;CN=Injected Kowalski";RSVP=FALSE', $ical);

        // Every physical line of the generated file must be a valid iCal line
        // (either a CRLF-terminated property or a continuation) — no raw
        // unescaped CRLF sequence embedded inside a property value.
        $lines = explode("\r\n", rtrim($ical, "\r\n"));
        foreach ($lines as $line) {
            $this->assertDoesNotMatchRegularExpression('/\r|\n/', $line);
        }

        // Exactly one physical line starts with the ATTENDEE property — the
        // injected "ATTENDEE;CN=Injected" text is inert, trapped as literal
        // characters inside the single quoted CN value, not a second property.
        $attendeeLines = array_filter($lines, fn ($line) => str_starts_with($line, 'ATTENDEE;CN='));
        $this->assertCount(1, $attendeeLines);
    }

    /**
     * RFC 5545 §3.2: parameter values follow a different grammar than TEXT
     * property values — an unquoted param-value cannot contain `;`/`:`/`,` at
     * all (no backslash-escape exists for them). CN must be wrapped in a
     * quoted-string; forbidden characters (DQUOTE, control chars) are
     * stripped rather than escaped, since quoted-string has no escape
     * mechanism either. This proves a name with `;`, `:`, and `"` can no
     * longer break out of the CN parameter or inject a new parameter.
     */
    public function test_ical_attendee_cn_quotes_and_strips_forbidden_parameter_characters(): void
    {
        $appointment = $this->makeAppointment([
            'first_name' => 'Jan;RSVP=TRUE',
            'last_name' => 'Kowalski:Ev"il,Jr',
        ]);

        $ical = CalendarService::generateIcalFile($appointment);

        // ';' and ':' are preserved (safe inside a quoted-string, cannot break
        // out of the CN parameter), but '"' is stripped (would otherwise
        // terminate the quoted-string early).
        $this->assertStringContainsString(
            "ATTENDEE;CN=\"Jan;RSVP=TRUE Kowalski:Evil,Jr\";RSVP=FALSE:mailto:{$appointment->email}",
            $ical
        );

        // Exactly one ATTENDEE line — nothing smuggled in via the name.
        $this->assertSame(1, substr_count($ical, 'ATTENDEE;CN='));
    }

    public function test_ical_attendee_cn_matches_plain_name_when_no_special_characters(): void
    {
        $appointment = $this->makeAppointment([
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
        ]);

        $ical = CalendarService::generateIcalFile($appointment);

        $this->assertStringContainsString("ATTENDEE;CN=\"Jan Kowalski\";RSVP=FALSE:mailto:{$appointment->email}", $ical);
    }

    public function test_ical_organizer_cn_is_also_quoted_for_consistency(): void
    {
        $appointment = $this->makeAppointment();

        $ical = CalendarService::generateIcalFile($appointment);

        $this->assertStringContainsString('ORGANIZER;CN="'.config('app.name').'":mailto:', $ical);
    }
}
