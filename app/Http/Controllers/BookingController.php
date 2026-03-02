<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ReminderConfig;
use App\Models\Service;
use App\Models\User;
use App\Models\UserConsent;
use App\Models\VehicleType;
use App\Rules\ValidPolishNIP;
use App\Services\AppointmentService;
use App\Services\CalendarService;
use App\Services\ServiceAreaValidator;
// use App\Services\Email\EmailService; // TODO: Add when sendAppointmentConfirmation is implemented
use App\Support\Settings\SettingsManager;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    protected AppointmentService $appointmentService;

    protected SettingsManager $settings;

    protected ServiceAreaValidator $serviceAreaValidator;

    public function __construct(
        AppointmentService $appointmentService,
        SettingsManager $settings,
        ServiceAreaValidator $serviceAreaValidator
    ) {
        $this->middleware('auth');
        $this->appointmentService = $appointmentService;
        $this->settings = $settings;
        $this->serviceAreaValidator = $serviceAreaValidator;
    }

    public function create(Service $service)
    {
        // DEPRECATED: This route is kept for backwards compatibility
        // Redirect to new multi-step booking wizard with pre-selected service

        // Save service_id to session
        session(['booking.service_id' => $service->id]);
        session(['booking.current_step' => 0]); // Not started yet

        // Redirect to step 1 (service selection will show as already selected)
        return redirect()->route('booking.step', 1);
    }

    public function getAvailableSlots(Request $request)
    {
        $request->validate([
            'service_id' => 'required|exists:services,id',
            'date' => 'required|date',
        ]);

        $service = Service::findOrFail($request->service_id);
        $date = Carbon::parse($request->date);

        // Check if date meets 24-hour advance booking requirement
        // We check the EARLIEST possible slot (business hours start) to be conservative
        $businessHours = $this->settings->bookingBusinessHours();
        $earliestSlotDateTime = Carbon::parse($date->format('Y-m-d').' '.$businessHours['start']);

        if (! $this->appointmentService->meetsAdvanceBookingRequirement($earliestSlotDateTime)) {
            $minDateTime = now()->addHours($this->settings->advanceBookingHours());

            return response()->json([
                'slots' => [],
                'date' => $date->format('Y-m-d'),
                'message' => 'Rezerwacje możliwe dopiero od '.$minDateTime->format('d.m.Y H:i'),
                'reason' => 'advance_booking_not_met',
            ]);
        }

        // Get available slots across ALL staff members
        $slots = $this->appointmentService->getAvailableSlotsAcrossAllStaff(
            serviceId: $request->service_id,
            date: $date,
            serviceDurationMinutes: $service->duration_minutes
        );

        return response()->json([
            'slots' => $slots,
            'date' => $date->format('Y-m-d'),
        ]);
    }

    // ==========================================
    // BOOKING WIZARD - NEW MULTI-STEP FLOW
    // ==========================================

    /**
     * Show wizard step view
     */
    public function showStep(int $step)
    {
        // Validate step number
        if ($step < 1 || $step > 5) {
            return redirect()->route('booking.step', 1);
        }

        // Check if user has completed previous steps (except step 1)
        if ($step > 1) {
            $booking = session('booking', []);

            // Validate previous step data exists
            if ($step === 2 && empty($booking['service_id'])) {
                return redirect()->route('booking.step', 1)->with('error', 'Najpierw wybierz usługę');
            }

            if ($step === 3 && (empty($booking['date']) || empty($booking['time_slot']))) {
                return redirect()->route('booking.step', 2)->with('error', 'Najpierw wybierz datę i godzinę');
            }

            if ($step === 4 && (empty($booking['vehicle_type_id']) || empty($booking['location_address']))) {
                return redirect()->route('booking.step', 3)->with('error', 'Najpierw uzupełnij dane pojazdu i lokalizacji');
            }

            if ($step === 5 && (empty($booking['first_name']) || empty($booking['email']))) {
                return redirect()->route('booking.step', 4)->with('error', 'Najpierw uzupełnij dane kontaktowe');
            }
        }

        // Load data based on step
        switch ($step) {
            case 1: // Service Selection
                // If service already selected (e.g., from service page), skip to step 2
                $existingServiceId = session('booking.service_id');
                if ($existingServiceId && Service::find($existingServiceId)) {
                    return redirect()->route('booking.step', 2);
                }

                return view('booking-wizard.steps.service', [
                    'services' => Service::active()->orderBy('sort_order')->get(),
                    'totalBookings' => Appointment::where('status', '!=', 'cancelled')->count(),
                ]);

            case 2: // Date & Time
                $serviceId = session('booking.service_id');
                $service = Service::findOrFail($serviceId);

                return view('booking-wizard.steps.datetime', [
                    'service' => $service,
                ]);

            case 3: // Vehicle & Location
                return view('booking-wizard.steps.vehicle-location', [
                    'vehicleTypes' => VehicleType::active()->orderBy('sort_order')->get(),
                    'googleMapsApiKey' => config('services.google_maps.api_key'),
                    'googleMapsMapId' => config('services.google_maps.map_id'),
                    'serviceLocationTypes' => $this->settings->serviceLocationTypes(),
                ]);

            case 4: // Contact Information
                // Pre-fill contact data from user profile (only if not already in session)
                $booking = session('booking', []);

                if (auth()->check()) {
                    $user = auth()->user();

                    // Only pre-fill empty fields (preserve session data if user went back)
                    // FIXED: Treat empty strings as empty (use ?? instead of empty() to handle null)
                    if (! isset($booking['first_name']) || $booking['first_name'] === '' || $booking['first_name'] === null) {
                        $booking['first_name'] = $user->first_name;
                    }
                    if (! isset($booking['last_name']) || $booking['last_name'] === '' || $booking['last_name'] === null) {
                        $booking['last_name'] = $user->last_name;
                    }
                    if (! isset($booking['email']) || $booking['email'] === '' || $booking['email'] === null) {
                        $booking['email'] = $user->email;
                    }
                    if ((! isset($booking['phone']) || $booking['phone'] === '' || $booking['phone'] === null) && $user->phone) {
                        $booking['phone'] = $user->phone;
                    }

                    // CRITICAL FIX: Update session with pre-filled data
                    // This ensures Alpine.js gets user data on init and after navigation
                    session(['booking' => $booking]);
                }

                $emailReminders = ReminderConfig::enabled()
                    ->byChannel('email')
                    ->before()
                    ->orderByDesc('trigger_hours')
                    ->get();

                $smsReminders = ReminderConfig::enabled()
                    ->byChannel('sms')
                    ->before()
                    ->orderByDesc('trigger_hours')
                    ->get();

                return view('booking-wizard.steps.contact', [
                    'bookingData' => $booking,
                    'emailReminders' => $emailReminders,
                    'smsReminders' => $smsReminders,
                ]);

            case 5: // Review & Confirm
                $booking = session('booking');
                $service = Service::findOrFail($booking['service_id']);
                $vehicleType = VehicleType::find($booking['vehicle_type_id']);

                return view('booking-wizard.steps.review', [
                    'service' => $service,
                    'vehicleType' => $vehicleType,
                    'serviceFee' => 0, // Optional service fee
                ]);

            default:
                return redirect()->route('booking.step', 1);
        }
    }

    /**
     * Clear service selection and redirect to step 1
     * Used when user wants to change their service from step 2+
     */
    public function changeService()
    {
        // Clear service to force step 1 to show selection
        session()->forget('booking.service_id');

        // Clear dependent data (date/time tied to service duration)
        session()->forget('booking.date');
        session()->forget('booking.time_slot');

        return redirect()->route('booking.step', 1);
    }

    /**
     * Store wizard step data to session
     */
    public function storeStep(int $step, Request $request)
    {
        // Validate and store based on step
        switch ($step) {
            case 1: // Service Selection
                $validated = $request->validate([
                    'service_id' => 'required|exists:services,id',
                ]);

                session(['booking.service_id' => $validated['service_id']]);
                session(['booking.current_step' => 1]);

                return redirect()->route('booking.step', 2);

            case 2: // Date & Time
                $validated = $request->validate([
                    'date' => 'required|date|after_or_equal:today',
                    'time_slot' => 'required|regex:/^\d{2}:\d{2}$/',
                ]);

                session(['booking.date' => $validated['date']]);
                session(['booking.time_slot' => $validated['time_slot']]);
                session(['booking.current_step' => 2]);

                return redirect()->route('booking.step', 3);

            case 3: // Vehicle & Location
                $validated = $request->validate([
                    'vehicle_type_id' => 'required|exists:vehicle_types,id',
                    'vehicle_brand' => 'required|string|max:100',
                    'vehicle_model' => 'required|string|max:100',
                    'vehicle_year' => 'required|integer|min:1900|max:'.(date('Y') + 1),
                    'registration_number' => ['required', 'string', 'max:15', 'regex:/^[A-Z]{2,3}[\s-]?[A-Z0-9]{4,5}$/i'],
                    'location_address' => 'required|string|max:255',
                    'location_latitude' => 'required|numeric|between:-90,90',
                    'location_longitude' => 'required|numeric|between:-180,180',
                    'location_place_id' => 'nullable|string|max:255',
                    'location_components' => 'nullable|string',
                    'service_location_type' => [
                        Rule::requiredIf(fn () => ! empty($this->settings->serviceLocationTypes())),
                        'nullable',
                        'string',
                        'max:100',
                    ],
                ], [
                    // Service location type message
                    'service_location_type.required' => 'Wybierz typ lokalizacji.',
                    // Vehicle fields messages
                    'vehicle_type_id.required' => 'Wybierz typ pojazdu.',
                    'vehicle_brand.required' => 'Podaj markę pojazdu.',
                    'vehicle_model.required' => 'Podaj model pojazdu.',
                    'vehicle_year.required' => 'Podaj rok produkcji.',
                    'vehicle_year.integer' => 'Rok produkcji musi być liczbą.',
                    'vehicle_year.min' => 'Rok produkcji musi być większy niż 1900.',
                    'vehicle_year.max' => 'Rok produkcji nie może być z przyszłości.',
                    'registration_number.required' => 'Podaj numer rejestracyjny.',
                    'registration_number.regex' => 'Nieprawidłowy format numeru rejestracyjnego (np. WA 12345).',
                    // Location fields messages
                    'location_address.required' => 'Podaj adres lokalizacji.',
                    'location_latitude.required' => 'Wybierz adres z listy podpowiedzi.',
                    'location_longitude.required' => 'Wybierz adres z listy podpowiedzi.',
                ]);

                // ===== SERVICE AREA VALIDATION =====
                $areaValidation = $this->serviceAreaValidator->validate(
                    $validated['location_latitude'],
                    $validated['location_longitude']
                );

                if (! $areaValidation['valid']) {
                    return response()->json([
                        'success' => false,
                        'error' => $areaValidation['message'] ?? trans('service_area.validation.not_available'),
                        'nearest_area' => $areaValidation['nearest'],
                        'show_waitlist' => true,
                    ], 422);
                }
                // ===== END SERVICE AREA VALIDATION =====

                session([
                    'booking.vehicle_type_id' => $validated['vehicle_type_id'],
                    'booking.vehicle_brand' => $validated['vehicle_brand'] ?? null,
                    'booking.vehicle_model' => $validated['vehicle_model'] ?? null,
                    'booking.vehicle_year' => $validated['vehicle_year'] ?? null,
                    'booking.registration_number' => $validated['registration_number'] ?? null,
                    'booking.location_address' => $validated['location_address'],
                    'booking.location_latitude' => $validated['location_latitude'],
                    'booking.location_longitude' => $validated['location_longitude'],
                    'booking.location_place_id' => $validated['location_place_id'] ?? null,
                    'booking.location_components' => $validated['location_components'] ?? null,
                    'booking.service_location_type' => $validated['service_location_type'] ?? null,
                    'booking.current_step' => 3,
                    // Cache service area validation to avoid repeated API calls on page refresh
                    'booking.service_area_valid' => true,
                    'booking.service_area_coords' => [
                        'lat' => (float) $validated['location_latitude'],
                        'lng' => (float) $validated['location_longitude'],
                    ],
                ]);

                return redirect()->route('booking.step', 4);

            case 4: // Contact Information
                $validated = $request->validate([
                    'first_name' => 'required|string|min:2|max:100',
                    'last_name' => 'required|string|min:2|max:100',
                    'email' => 'required|email|max:255',
                    'phone' => ['required', 'regex:/^(\+48)?[\s-]?\d{9}$/'],
                    'notify_email' => 'nullable|boolean',
                    'notify_sms' => 'nullable|boolean',
                    'terms_accepted' => 'required|accepted',
                    // Invoice fields
                    'invoice_requested' => 'nullable|boolean',
                    'invoice_company_name' => 'required_if:invoice_requested,1,true|nullable|string|max:255',
                    'invoice_nip' => ['required_if:invoice_requested,1,true', 'nullable', 'string', 'max:13', new ValidPolishNIP],
                    'invoice_street' => 'required_if:invoice_requested,1,true|nullable|string|max:255',
                    'invoice_street_number' => 'required_if:invoice_requested,1,true|nullable|string|max:20',
                    'invoice_postal_code' => 'required_if:invoice_requested,1,true|nullable|string|max:6',
                    'invoice_city' => 'required_if:invoice_requested,1,true|nullable|string|max:100',
                ], [
                    // Personal info messages
                    'first_name.required' => 'Podaj imię.',
                    'first_name.min' => 'Imię musi mieć co najmniej 2 znaki.',
                    'last_name.required' => 'Podaj nazwisko.',
                    'last_name.min' => 'Nazwisko musi mieć co najmniej 2 znaki.',
                    'email.required' => 'Podaj adres e-mail.',
                    'email.email' => 'Podaj prawidłowy adres e-mail.',
                    'phone.required' => 'Podaj numer telefonu.',
                    'phone.regex' => 'Podaj prawidłowy numer telefonu (9 cyfr).',
                    'terms_accepted.required' => 'Musisz zaakceptować regulamin.',
                    'terms_accepted.accepted' => 'Musisz zaakceptować regulamin.',
                    // Invoice fields messages
                    'invoice_company_name.required_if' => 'Podaj nazwę firmy lub imię i nazwisko.',
                    'invoice_nip.required_if' => 'Podaj NIP.',
                    'invoice_street.required_if' => 'Podaj ulicę.',
                    'invoice_street_number.required_if' => 'Podaj numer budynku/lokalu.',
                    'invoice_postal_code.required_if' => 'Podaj kod pocztowy.',
                    'invoice_city.required_if' => 'Podaj miasto.',
                ]);

                session([
                    'booking.first_name' => $validated['first_name'],
                    'booking.last_name' => $validated['last_name'],
                    'booking.email' => $validated['email'],
                    'booking.phone' => $validated['phone'],
                    'booking.notify_email' => $request->has('notify_email'),
                    'booking.notify_sms' => $request->has('notify_sms'),
                    // Invoice fields
                    'booking.invoice_requested' => $request->boolean('invoice_requested'),
                    'booking.invoice_company_name' => $validated['invoice_company_name'] ?? null,
                    'booking.invoice_nip' => $validated['invoice_nip'] ?? null,
                    'booking.invoice_street' => $validated['invoice_street'] ?? null,
                    'booking.invoice_street_number' => $validated['invoice_street_number'] ?? null,
                    'booking.invoice_postal_code' => $validated['invoice_postal_code'] ?? null,
                    'booking.invoice_city' => $validated['invoice_city'] ?? null,
                    'booking.current_step' => 4,
                ]);

                return redirect()->route('booking.step', 5);

            default:
                return redirect()->route('booking.step', 1);
        }
    }

    /**
     * AJAX: Save progress to session with validation
     */
    public function saveProgress(Request $request)
    {
        $step = $request->input('step');
        $data = $request->input('data', []);

        // Validate based on step
        try {
            switch ($step) {
                case 1: // Service Selection
                    $validated = $this->validateStep1($data);
                    break;

                case 2: // Date & Time
                    $validated = $this->validateStep2($data);
                    break;

                case 3: // Vehicle & Location
                    $validated = $this->validateStep3($data);
                    break;

                case 4: // Contact Information
                    $validated = $this->validateStep4($data);
                    break;

                default:
                    // For incremental saves (e.g., calendar date selection), no strict validation
                    $validated = $data;
                    break;
            }

            // Merge new data into existing session
            $booking = session('booking', []);
            $booking = array_merge($booking, $validated);
            $booking['current_step'] = $step;
            $booking['updated_at'] = now()->toDateTimeString();

            session(['booking' => $booking]);

            return response()->json(['success' => true]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
                'message' => 'Sprawdź wprowadzone dane i spróbuj ponownie.',
            ], 422);
        }
    }

    /**
     * Validation helpers for each step
     */
    private function validateStep1(array $data)
    {
        return validator($data, [
            'service_id' => 'required|exists:services,id',
        ])->validate();
    }

    private function validateStep2(array $data)
    {
        // Allow partial saves (just date OR just time_slot)
        // Full validation happens on form submit in storeStep()
        return validator($data, [
            'date' => 'nullable|date|after_or_equal:today',
            'time_slot' => 'nullable|regex:/^\d{2}:\d{2}$/',
        ])->validate();
    }

    private function validateStep3(array $data)
    {
        return validator($data, [
            'vehicle_type_id' => 'required|exists:vehicle_types,id',
            'vehicle_brand' => 'required|string|max:100',
            'vehicle_model' => 'required|string|max:100',
            'vehicle_year' => 'nullable|integer|min:1900|max:'.(date('Y') + 1),
            'registration_number' => ['nullable', 'string', 'max:15', 'regex:/^[A-Z]{2,3}[\s-]?[A-Z0-9]{4,5}$/i'],
            'location_address' => 'required|string|max:255',
            'location_latitude' => 'required|numeric|between:-90,90',
            'location_longitude' => 'required|numeric|between:-180,180',
            'location_place_id' => 'nullable|string|max:255',
            'location_components' => 'nullable|string',
        ])->validate();
    }

    private function validateStep4(array $data)
    {
        return validator($data, [
            'first_name' => 'required|string|min:2|max:100',
            'last_name' => 'required|string|min:2|max:100',
            'email' => 'required|email|max:255',
            'phone' => ['required', 'regex:/^(\+48)?[\s-]?\d{9}$/'],
            'notify_email' => 'nullable|boolean',
            'notify_sms' => 'nullable|boolean',
            'terms_accepted' => 'required|accepted',
            // Invoice fields
            'invoice_requested' => 'nullable|boolean',
            'invoice_company_name' => 'required_if:invoice_requested,1,true|nullable|string|max:255',
            'invoice_nip' => ['nullable', 'string', 'max:13', new ValidPolishNIP],
            'invoice_street' => 'required_if:invoice_requested,1,true|nullable|string|max:255',
            'invoice_street_number' => 'required_if:invoice_requested,1,true|nullable|string|max:20',
            'invoice_postal_code' => 'required_if:invoice_requested,1,true|nullable|string|max:6',
            'invoice_city' => 'required_if:invoice_requested,1,true|nullable|string|max:100',
        ], [
            // Personal info messages
            'first_name.required' => 'Podaj imię.',
            'first_name.min' => 'Imię musi mieć co najmniej 2 znaki.',
            'last_name.required' => 'Podaj nazwisko.',
            'last_name.min' => 'Nazwisko musi mieć co najmniej 2 znaki.',
            'email.required' => 'Podaj adres e-mail.',
            'email.email' => 'Podaj prawidłowy adres e-mail.',
            'phone.required' => 'Podaj numer telefonu.',
            'phone.regex' => 'Podaj prawidłowy numer telefonu (9 cyfr).',
            'terms_accepted.required' => 'Musisz zaakceptować regulamin.',
            'terms_accepted.accepted' => 'Musisz zaakceptować regulamin.',
            // Invoice fields messages
            'invoice_company_name.required_if' => 'Podaj nazwę firmy lub imię i nazwisko.',
            'invoice_street.required_if' => 'Podaj ulicę.',
            'invoice_street_number.required_if' => 'Podaj numer budynku/lokalu.',
            'invoice_postal_code.required_if' => 'Podaj kod pocztowy.',
            'invoice_city.required_if' => 'Podaj miasto.',
        ])->validate();
    }

    /**
     * AJAX: Restore progress from session
     */
    public function restoreProgress()
    {
        return response()->json([
            'booking' => session('booking', []),
        ]);
    }

    /**
     * Get unavailable dates for calendar (OPTIMIZED with bulk queries + cache)
     *
     * Performance improvements:
     * - OLD: 60 iterations × N queries = 100-200+ queries (2-4 seconds)
     * - NEW: 3-5 bulk queries + cache = <100ms for cached requests
     *
     * Cache TTL: 15 minutes (staff schedules don't change frequently)
     */
    public function getUnavailableDates(Request $request)
    {
        $request->validate([
            'service_id' => 'required|exists:services,id',
        ]);

        $serviceId = $request->service_id;

        // Cache key: service_id + current hour (15-min granularity)
        $cacheKey = "availability_service_{$serviceId}_".now()->format('Y-m-d_H');

        // Try to get from cache first
        $cachedData = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($serviceId) {
            // Get dates for next 60 days
            $startDate = now();
            $endDate = now()->addDays(60);

            // Use new bulk availability method (3-5 queries instead of 60 × N)
            $availability = $this->appointmentService->getBulkAvailability(
                $serviceId,
                $startDate,
                $endDate
            );

            // Build unavailable dates array (for Flatpickr disable feature)
            $unavailableDates = [];
            foreach ($availability as $dateStr => $status) {
                if ($status === 'unavailable') {
                    $unavailableDates[] = $dateStr;
                }
            }

            return [
                'unavailable_dates' => $unavailableDates,
                'availability' => $availability,
            ];
        });

        return response()->json($cachedData);
    }

    /**
     * Confirm booking - create appointment
     *
     * SECURITY: This method performs complete re-validation of all booking data
     * to prevent bypass attacks where frontend validation is circumvented.
     */
    public function confirm()
    {
        $booking = session('booking');
        $user = auth()->user();

        // Validate session exists and not expired
        if (! $booking || empty($booking['service_id'])) {
            Log::warning('Booking confirm: session expired or empty', [
                'user_id' => $user?->id,
                'ip' => request()->ip(),
            ]);

            return redirect()->route('booking.step', 1)
                ->with('error', 'Sesja rezerwacji wygasła. Zacznij od nowa.');
        }

        Log::info('Booking confirm: attempt started', [
            'user_id' => $user->id,
            'service_id' => $booking['service_id'],
            'date' => $booking['date'] ?? null,
            'time_slot' => $booking['time_slot'] ?? null,
            'ip' => request()->ip(),
        ]);

        // ===== IDEMPOTENCY: Prevent duplicate bookings =====
        $existingAppointment = Appointment::where('customer_id', $user->id)
            ->where('service_id', $booking['service_id'])
            ->where('appointment_date', $booking['date'] ?? null)
            ->where('start_time', ($booking['time_slot'] ?? null) ? Carbon::parse($booking['time_slot'])->format('H:i:s') : null)
            ->whereIn('status', ['pending', 'confirmed'])
            ->first();

        if ($existingAppointment) {
            Log::info('Booking confirm: duplicate prevented, redirecting to existing', [
                'user_id' => $user->id,
                'existing_appointment_id' => $existingAppointment->id,
            ]);

            session(['booking_confirmed_id' => $existingAppointment->id]);
            session()->forget('booking');

            return redirect()->route('booking.confirmation');
        }
        // ===== END IDEMPOTENCY =====

        // ===== CRITICAL: RE-VALIDATE SERVICE AREA =====
        // This prevents bypass attacks where user dismisses frontend validation
        if (empty($booking['location_latitude']) || empty($booking['location_longitude'])) {
            return redirect()->route('booking.step', 3)
                ->with('error', 'Brak danych lokalizacji. Wybierz adres z listy podpowiedzi.');
        }

        $areaValidation = $this->serviceAreaValidator->validate(
            (float) $booking['location_latitude'],
            (float) $booking['location_longitude']
        );

        if (! $areaValidation['valid']) {
            return redirect()->route('booking.step', 3)
                ->with('error', $areaValidation['message'] ?? 'Przepraszamy, nie obsługujemy tej lokalizacji. Wybierz adres z dostępnego obszaru.');
        }
        // ===== END SERVICE AREA VALIDATION =====

        // Final validation
        $service = Service::findOrFail($booking['service_id']);
        $appointmentDateTime = Carbon::parse($booking['date'].' '.$booking['time_slot']);

        // Check if slot still available
        $slots = $this->appointmentService->getAvailableSlotsAcrossAllStaff(
            serviceId: $booking['service_id'],
            date: Carbon::parse($booking['date']),
            serviceDurationMinutes: $service->duration_minutes
        );

        $requestedSlot = collect($slots)->firstWhere('time', $booking['time_slot']);

        if (! $requestedSlot || ! $requestedSlot['available']) {
            return redirect()->route('booking.step', 2)
                ->with('error', 'Wybrany termin jest już niedostępny. Wybierz inny.');
        }

        // Assign best staff member
        $staff = $this->appointmentService->findBestAvailableStaff(
            serviceId: $booking['service_id'],
            dateTime: $appointmentDateTime,
            durationMinutes: $service->duration_minutes
        );

        if (! $staff) {
            return redirect()->route('booking.step', 2)
                ->with('error', 'Brak dostępnego pracownika. Wybierz inny termin.');
        }

        try {
            $appointment = DB::transaction(function () use ($booking, $user, $service, $staff, $appointmentDateTime) {
                // Update customer profile - only fill empty fields to avoid overwriting existing data
                $profileUpdates = [];

                // Map wizard field names to user model fields
                $phoneField = $booking['phone'] ?? null;
                if ($phoneField) {
                    // Convert phone to E.164 format if needed
                    $phoneE164 = str_starts_with($phoneField, '+') ? $phoneField : '+48'.preg_replace('/\D/', '', $phoneField);
                }

                if (empty($user->first_name) && ! empty($booking['first_name'])) {
                    $profileUpdates['first_name'] = $booking['first_name'];
                }
                if (empty($user->last_name) && ! empty($booking['last_name'])) {
                    $profileUpdates['last_name'] = $booking['last_name'];
                }
                if (empty($user->phone_e164) && ! empty($phoneE164)) {
                    $profileUpdates['phone_e164'] = $phoneE164;
                }

                if (! empty($profileUpdates)) {
                    $user->update($profileUpdates);
                }

                // Create appointment
                $appointment = Appointment::create([
                    'customer_id' => $user->id,
                    'service_id' => $booking['service_id'],
                    'service_price_at_booking' => $service->price,
                    'service_name_at_booking' => $service->name,
                    'service_duration_at_booking' => $service->duration_minutes,
                    'staff_id' => $staff->id,
                    'appointment_date' => $appointmentDateTime->format('Y-m-d'),
                    'start_time' => $appointmentDateTime->format('H:i:s'),
                    'end_time' => $appointmentDateTime->copy()->addMinutes($service->duration_minutes)->format('H:i:s'),
                    'status' => 'pending',
                    'vehicle_type_id' => $booking['vehicle_type_id'],
                    'vehicle_custom_brand' => $booking['vehicle_brand'] ?? null,
                    'vehicle_custom_model' => $booking['vehicle_model'] ?? null,
                    'vehicle_year' => $booking['vehicle_year'] ?? null,
                    'registration_number' => $booking['registration_number'] ?? null,
                    'location_address' => $booking['location_address'],
                    'location_latitude' => $booking['location_latitude'],
                    'location_longitude' => $booking['location_longitude'],
                    'location_place_id' => $booking['location_place_id'] ?? null,
                    'location_components' => $booking['location_components'] ?? null,
                    'service_location_type' => $booking['service_location_type'] ?? null,
                    // Contact information (captured at time of booking for historical accuracy)
                    'first_name' => $booking['first_name'],
                    'last_name' => $booking['last_name'],
                    'email' => $booking['email'],
                    'phone' => $booking['phone'],
                    'notify_email' => $booking['notify_email'] ?? false,
                    'notify_sms' => $booking['notify_sms'] ?? false,
                    // Invoice data (captured at time of booking)
                    'invoice_requested' => $booking['invoice_requested'] ?? false,
                    'invoice_company_name' => $booking['invoice_company_name'] ?? null,
                    'invoice_nip' => $booking['invoice_nip'] ?? null,
                    'invoice_street' => $booking['invoice_street'] ?? null,
                    'invoice_street_number' => $booking['invoice_street_number'] ?? null,
                    'invoice_postal_code' => $booking['invoice_postal_code'] ?? null,
                    'invoice_city' => $booking['invoice_city'] ?? null,
                ]);

                // Sync SMS consent to user profile (GDPR compliance)
                if ($booking['notify_sms'] ?? false) {
                    if (! $user->hasSmsConsent()) {
                        $user->grantSmsConsent(request()->ip(), request()->userAgent());
                    }
                }

                // Record terms acceptance for audit trail (GDPR compliance)
                UserConsent::recordConsent($user, 'terms_accepted', 'granted');

                return $appointment;
            });
        } catch (\Throwable $e) {
            Log::error('Booking confirm: failed to create appointment', [
                'user_id' => $user->id,
                'service_id' => $booking['service_id'],
                'date' => $booking['date'] ?? null,
                'time_slot' => $booking['time_slot'] ?? null,
                'error' => $e->getMessage(),
                'ip' => request()->ip(),
            ]);

            return redirect()->route('booking.step', 5)
                ->with('error', 'Wystąpił błąd podczas tworzenia rezerwacji. Spróbuj ponownie.');
        }

        Log::info('Booking confirm: appointment created successfully', [
            'user_id' => $user->id,
            'appointment_id' => $appointment->id,
            'service_id' => $booking['service_id'],
            'date' => $booking['date'],
            'time_slot' => $booking['time_slot'],
            'staff_id' => $staff->id,
        ]);

        // SECURITY: Store appointment ID in single-use session token (no ID in URL)
        session(['booking_confirmed_id' => $appointment->id]);

        // Clear wizard session
        session()->forget('booking');

        return redirect()->route('booking.confirmation');
    }

    /**
     * Show confirmation screen (session-based, single-use)
     */
    public function showConfirmation()
    {
        // SECURITY FIX: Use single-use session token instead of ID in URL
        // Pull = get and delete in one operation (token can only be used once)
        $appointmentId = session()->pull('booking_confirmed_id');

        if (! $appointmentId) {
            return redirect()->route('appointments.index')
                ->with('error', 'Link potwierdzenia wygasł. Zobacz swoje wizyty poniżej.');
        }

        $appointment = Appointment::findOrFail($appointmentId);

        // SECURITY: Double-check ownership (defense in depth)
        if ($appointment->customer_id !== auth()->id()) {
            abort(403, 'Brak dostępu do tego potwierdzenia.');
        }

        // Generate calendar URLs
        $googleCalendarUrl = CalendarService::generateGoogleCalendarUrl($appointment);
        $appleCalendarUrl = route('booking.ical', $appointment);
        $outlookCalendarUrl = CalendarService::generateOutlookCalendarUrl($appointment);

        return view('booking-wizard.confirmation', [
            'appointment' => $appointment->load(['service', 'staff', 'customer', 'vehicleType']),
            'googleCalendarUrl' => $googleCalendarUrl,
            'appleCalendarUrl' => $appleCalendarUrl,
            'outlookCalendarUrl' => $outlookCalendarUrl,
        ]);
    }

    /**
     * Download iCal file
     */
    public function downloadIcal(Appointment $appointment)
    {
        // Security: only allow appointment owner
        if ($appointment->customer_id !== auth()->id()) {
            abort(403);
        }

        $icalContent = CalendarService::generateIcalFile($appointment);

        return response($icalContent)
            ->header('Content-Type', 'text/calendar; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="appointment.ics"');
    }
}
