<?php

namespace App\Services;

use App\Models\Appointment;
use Carbon\Carbon;

class CalendarService
{
    /**
     * Get appointment start datetime combining date and time
     */
    private static function getAppointmentStartDateTime(Appointment $appointment): Carbon
    {
        return Carbon::parse(
            $appointment->appointment_date->format('Y-m-d').' '.
            $appointment->start_time->format('H:i:s')
        );
    }

    /**
     * Generate Google Calendar URL for appointment
     */
    public static function generateGoogleCalendarUrl(Appointment $appointment): string
    {
        $startDateTime = self::getAppointmentStartDateTime($appointment);
        $endDateTime = $startDateTime->copy()->addMinutes($appointment->service->duration_minutes);

        $params = [
            'action' => 'TEMPLATE',
            'text' => $appointment->service->name,
            'dates' => $startDateTime->format('Ymd\THis\Z').'/'.$endDateTime->format('Ymd\THis\Z'),
            'details' => self::generateEventDescription($appointment),
            'location' => $appointment->location_address,
        ];

        return 'https://calendar.google.com/calendar/render?'.http_build_query($params);
    }

    /**
     * Generate Outlook Calendar URL for appointment
     */
    public static function generateOutlookCalendarUrl(Appointment $appointment): string
    {
        $startDateTime = self::getAppointmentStartDateTime($appointment);
        $endDateTime = $startDateTime->copy()->addMinutes($appointment->service->duration_minutes);

        $params = [
            'path' => '/calendar/action/compose',
            'rru' => 'addevent',
            'subject' => $appointment->service->name,
            'startdt' => $startDateTime->format('Y-m-d\TH:i:s'),
            'enddt' => $endDateTime->format('Y-m-d\TH:i:s'),
            'body' => self::generateEventDescription($appointment),
            'location' => $appointment->location_address,
        ];

        return 'https://outlook.live.com/calendar/0/deeplink/compose?'.http_build_query($params);
    }

    /**
     * Generate iCal (.ics) file content for appointment
     */
    public static function generateIcalFile(Appointment $appointment): string
    {
        $startDateTime = self::getAppointmentStartDateTime($appointment);
        $endDateTime = $startDateTime->copy()->addMinutes($appointment->service->duration_minutes);

        // Generate unique UID
        $uid = 'appointment-'.$appointment->id.'@'.config('app.url');

        // Format dates for iCal (UTC format: YYYYMMDDTHHmmssZ)
        $dtStart = $startDateTime->utc()->format('Ymd\THis\Z');
        $dtEnd = $endDateTime->utc()->format('Ymd\THis\Z');
        $dtStamp = now()->utc()->format('Ymd\THis\Z');

        // Escape special characters in text fields
        $summary = self::escapeIcalText($appointment->service->name);
        $description = self::escapeIcalText(self::generateEventDescription($appointment));
        $location = self::escapeIcalText($appointment->location_address);

        // Build iCal content
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= 'PRODID:-//'.config('app.name')."//Booking System//PL\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:REQUEST\r\n";
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:{$uid}\r\n";
        $ical .= "DTSTAMP:{$dtStamp}\r\n";
        $ical .= "DTSTART:{$dtStart}\r\n";
        $ical .= "DTEND:{$dtEnd}\r\n";
        $ical .= "SUMMARY:{$summary}\r\n";
        $ical .= "DESCRIPTION:{$description}\r\n";
        $ical .= "LOCATION:{$location}\r\n";
        $ical .= "STATUS:CONFIRMED\r\n";
        $ical .= "SEQUENCE:0\r\n";

        // Add organizer (business contact)
        $organizerEmail = config('mail.from.address');
        $organizerName = self::sanitizeIcalParam(config('app.name'));
        $ical .= "ORGANIZER;CN=\"{$organizerName}\":mailto:{$organizerEmail}\r\n";

        // Add attendee (customer)
        $attendeeName = self::sanitizeIcalParam($appointment->first_name.' '.$appointment->last_name);
        $ical .= "ATTENDEE;CN=\"{$attendeeName}\";RSVP=FALSE:mailto:{$appointment->email}\r\n";

        // Add reminder (24h before)
        $ical .= "BEGIN:VALARM\r\n";
        $ical .= "TRIGGER:-PT24H\r\n";
        $ical .= "ACTION:DISPLAY\r\n";
        $ical .= "DESCRIPTION:Przypomnienie: {$summary}\r\n";
        $ical .= "END:VALARM\r\n";

        // Add second reminder (2h before)
        $ical .= "BEGIN:VALARM\r\n";
        $ical .= "TRIGGER:-PT2H\r\n";
        $ical .= "ACTION:DISPLAY\r\n";
        $ical .= "DESCRIPTION:Przypomnienie: {$summary}\r\n";
        $ical .= "END:VALARM\r\n";

        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";

        return $ical;
    }

    /**
     * Generate event description text
     */
    private static function generateEventDescription(Appointment $appointment): string
    {
        $startDateTime = self::getAppointmentStartDateTime($appointment);
        $description = "Rezerwacja: {$appointment->service->name}\n\n";
        $description .= 'Data i godzina: '.$startDateTime->format('d.m.Y H:i')."\n";
        $description .= "Czas trwania: {$appointment->service->duration_minutes} minut\n\n";

        $description .= "Pojazd:\n";
        $description .= "- Typ: {$appointment->vehicleType->name}\n";

        if ($appointment->vehicle_brand) {
            $description .= "- Marka: {$appointment->vehicle_brand}\n";
        }

        if ($appointment->vehicle_model) {
            $description .= "- Model: {$appointment->vehicle_model}\n";
        }

        if ($appointment->vehicle_year) {
            $description .= "- Rok: {$appointment->vehicle_year}\n";
        }

        $description .= "\nLokalizacja:\n{$appointment->location_address}\n\n";

        $description .= "Dane kontaktowe:\n";
        $description .= "{$appointment->first_name} {$appointment->last_name}\n";
        $description .= "Tel: {$appointment->phone}\n";
        $description .= "Email: {$appointment->email}\n\n";

        $description .= "---\n";
        $description .= 'Zarezerwowane przez '.config('app.name');

        return $description;
    }

    /**
     * Escape text for iCal format
     *
     * @param  string  $text
     * @return string
     */
    private static function escapeIcalText($text)
    {
        // Replace special characters according to RFC 5545
        $text = str_replace('\\', '\\\\', $text); // Backslash
        $text = str_replace(';', '\;', $text); // Semicolon
        $text = str_replace(',', '\,', $text); // Comma
        $text = str_replace("\n", '\n', $text); // Newline
        $text = str_replace("\r", '', $text); // Remove carriage return

        return $text;
    }

    /**
     * Sanitize a value for use inside a quoted iCal PARAMETER value (RFC 5545 §3.2),
     * e.g. ATTENDEE;CN="...". This is a DIFFERENT grammar than escapeIcalText()'s
     * TEXT property escaping: an unquoted param-value cannot contain `;`/`:`/`,`
     * at all (no backslash-escape exists for them), and a quoted-string param-value
     * (QSAFE-CHAR) cannot contain DQUOTE or control characters — also with no
     * escape mechanism. The only safe transform is stripping the forbidden
     * characters, then wrapping the caller's value in double quotes.
     */
    private static function sanitizeIcalParam(string $text): string
    {
        return preg_replace('/[\x00-\x1F\x7F"]/', '', $text);
    }
}
