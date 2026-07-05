<?php

namespace App\Http\Controllers;

use App\Enums\AppointmentStatus;
use App\Exceptions\AppointmentSlotUnavailableException;
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
use App\Support\TenantFeature;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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

    // ==========================================
    // DYNAMIC STEP MAPPING
    // ==========================================

    /**
     * Get the active steps based on tenant feature flags.
     *
     * @return array<int, string> 0-indexed array of step keys
     */
    private function getActiveSteps(): array
    {
        $steps = ['service', 'datetime'];

        $needsVehicleLocation = TenantFeature::active('vehicles')
            || TenantFeature::active('mobile_service');

        if ($needsVehicleLocation) {
            $steps[] = 'vehicle-location';
        }

        $steps[] = 'contact';
        $steps[] = 'review';

        return $steps;
    }

    /**
     * Get total number of active steps.
     */
    private function getTotalSteps(): int
    {
        return count($this->getActiveSteps());
    }

    /**
     * Get step key for a given URL step number (1-indexed).
     */
    private function getStepKey(int $stepNumber): ?string
    {
        $steps = $this->getActiveSteps();

        return $steps[$stepNumber - 1] ?? null;
    }

    /**
     * Get URL step number (1-indexed) for a given step key.
     */
    private function getStepNumber(string $key): ?int
    {
        $steps = $this->getActiveSteps();
        $index = array_search($key, $steps);

        return $index !== false ? $index + 1 : null;
    }

    /**
     * Check if a step is in the active flow.
     */
    private function hasStep(string $key): bool
    {
        return in_array($key, $this->getActiveSteps());
    }

    /**
     * Get step labels for the progress indicator.
     *
     * @return array<int, array{name: string, icon: string}>
     */
    private function getStepLabels(): array
    {
        $labelMap = [
            'service' => ['name' => 'Usługa', 'icon' => 'sparkles'],
            'datetime' => ['name' => 'Termin', 'icon' => 'calendar'],
            'vehicle-location' => ['name' => 'Szczegóły', 'icon' => 'pencil'],
            'contact' => ['name' => 'Kontakt', 'icon' => 'user'],
            'review' => ['name' => 'Podsumowanie', 'icon' => 'check-circle'],
        ];

        $labels = [];
        foreach ($this->getActiveSteps() as $index => $key) {
            $labels[$index + 1] = $labelMap[$key];
        }

        return $labels;
    }

    /**
     * Get step key → step number mapping.
     *
     * @return array<string, int>
     */
    private function getStepMap(): array
    {
        $map = [];
        foreach ($this->getActiveSteps() as $index => $key) {
            $map[$key] = $index + 1;
        }

        return $map;
    }

    /**
     * Share common wizard data with all views.
     */
    private function shareWizardData(int $currentStep, string $currentStepKey): void
    {
        view()->share('totalSteps', $this->getTotalSteps());
        view()->share('currentStep', $currentStep);
        view()->share('currentStepKey', $currentStepKey);
        view()->share('stepLabels', $this->getStepLabels());
        view()->share('stepMap', $this->getStepMap());
        view()->share('showVehicle', TenantFeature::active('vehicles'));
        view()->share('showMobileService', TenantFeature::active('mobile_service'));
    }

    // ==========================================
    // ROUTES
    // ==========================================

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

        // NOTE: the 24h advance-booking rule is checked per-slot inside
        // getAvailableSlotsAcrossAllStaff() (against each candidate slot's own
        // start time), not here against the day's business-hours-open instant.
        // Gating on the latter used to hide legitimately bookable later-in-the-day
        // slots (e.g. tomorrow afternoon) whenever the earliest slot of the day
        // fell inside the advance window.

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
    // BOOKING WIZARD - MULTI-STEP FLOW
    // ==========================================

    /**
     * Show wizard step view
     */
    public function showStep(int $step)
    {
        $totalSteps = $this->getTotalSteps();

        // Validate step number
        if ($step < 1 || $step > $totalSteps) {
            return redirect()->route('booking.step', 1);
        }

        $stepKey = $this->getStepKey($step);

        if (! $stepKey) {
            return redirect()->route('booking.step', 1);
        }

        // Check if user has completed previous steps (except step 1)
        if ($step > 1) {
            $booking = session('booking', []);

            // Service data required for all steps after service
            if ($stepKey !== 'service' && empty($booking['service_id'])) {
                return redirect()->route('booking.step', 1)
                    ->with('error', 'Najpierw wybierz usługę');
            }

            // Datetime data required for vehicle-location, contact, review
            if (in_array($stepKey, ['vehicle-location', 'contact', 'review'])) {
                if (empty($booking['date']) || empty($booking['time_slot'])) {
                    return redirect()->route('booking.step', $this->getStepNumber('datetime'))
                        ->with('error', 'Najpierw wybierz datę i godzinę');
                }
            }

            // Vehicle data required for contact/review (only when vehicle step exists)
            if (in_array($stepKey, ['contact', 'review']) && $this->hasStep('vehicle-location')) {
                $needsVehicleData = TenantFeature::active('vehicles')
                    && (empty($booking['vehicle_type_id']));
                $needsLocationData = TenantFeature::active('mobile_service')
                    && (empty($booking['location_address']));

                if ($needsVehicleData || $needsLocationData) {
                    return redirect()->route('booking.step', $this->getStepNumber('vehicle-location'))
                        ->with('error', 'Najpierw uzupełnij dane pojazdu i lokalizacji');
                }
            }

            // Contact data required for review
            if ($stepKey === 'review' && (empty($booking['first_name']) || empty($booking['email']))) {
                return redirect()->route('booking.step', $this->getStepNumber('contact'))
                    ->with('error', 'Najpierw uzupełnij dane kontaktowe');
            }
        }

        // Share wizard data with all views
        $this->shareWizardData($step, $stepKey);

        // Load data based on step key
        return match ($stepKey) {
            'service' => $this->showServiceStep(),
            'datetime' => $this->showDatetimeStep(),
            'vehicle-location' => $this->showVehicleLocationStep(),
            'contact' => $this->showContactStep(),
            'review' => $this->showReviewStep(),
            default => redirect()->route('booking.step', 1),
        };
    }

    private function showServiceStep()
    {
        // If service already selected (e.g., from service page), skip to step 2
        $existingServiceId = session('booking.service_id');
        if ($existingServiceId && Service::find($existingServiceId)) {
            return redirect()->route('booking.step', 2);
        }

        return view('booking-wizard.steps.service', [
            'services' => Service::active()->orderBy('sort_order')->get(),
            'totalBookings' => Appointment::where('status', '!=', AppointmentStatus::Cancelled)->count(),
        ]);
    }

    private function showDatetimeStep()
    {
        $serviceId = session('booking.service_id');
        $service = Service::findOrFail($serviceId);

        return view('booking-wizard.steps.datetime', [
            'service' => $service,
        ]);
    }

    private function showVehicleLocationStep()
    {
        return view('booking-wizard.steps.vehicle-location', [
            'vehicleTypes' => VehicleType::active()->orderBy('sort_order')->get(),
            'googleMapsApiKey' => config('services.google_maps.api_key'),
            'googleMapsMapId' => config('services.google_maps.map_id'),
            'serviceLocationTypes' => $this->settings->serviceLocationTypes(),
        ]);
    }

    private function showContactStep()
    {
        $booking = session('booking', []);

        if (auth()->check()) {
            $user = auth()->user();

            // Only pre-fill empty fields (preserve session data if user went back)
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
    }

    private function showReviewStep()
    {
        $booking = session('booking');
        $service = Service::findOrFail($booking['service_id']);
        $vehicleType = TenantFeature::active('vehicles')
            ? VehicleType::find($booking['vehicle_type_id'] ?? null)
            : null;

        return view('booking-wizard.steps.review', [
            'service' => $service,
            'vehicleType' => $vehicleType,
            'serviceFee' => 0,
        ]);
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
        $stepKey = $this->getStepKey($step);

        if (! $stepKey) {
            return redirect()->route('booking.step', 1);
        }

        return match ($stepKey) {
            'service' => $this->storeServiceStep($step, $request),
            'datetime' => $this->storeDatetimeStep($step, $request),
            'vehicle-location' => $this->storeVehicleLocationStep($step, $request),
            'contact' => $this->storeContactStep($step, $request),
            default => redirect()->route('booking.step', 1),
        };
    }

    private function storeServiceStep(int $step, Request $request)
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
        ]);

        session(['booking.service_id' => $validated['service_id']]);
        session(['booking.current_step' => $step]);

        return redirect()->route('booking.step', $step + 1);
    }

    private function storeDatetimeStep(int $step, Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date|after_or_equal:today',
            'time_slot' => 'required|regex:/^\d{2}:\d{2}$/',
        ]);

        session(['booking.date' => $validated['date']]);
        session(['booking.time_slot' => $validated['time_slot']]);
        session(['booking.current_step' => $step]);

        return redirect()->route('booking.step', $step + 1);
    }

    private function storeVehicleLocationStep(int $step, Request $request)
    {
        $rules = [];
        $messages = [];

        // Vehicle rules (only when vehicles feature active)
        if (TenantFeature::active('vehicles')) {
            $rules = array_merge($rules, [
                'vehicle_type_id' => 'required|exists:vehicle_types,id',
                'vehicle_brand' => 'required|string|max:100',
                'vehicle_model' => 'required|string|max:100',
                'vehicle_year' => 'required|integer|min:1900|max:'.(date('Y') + 1),
                'registration_number' => ['required', 'string', 'max:15', 'regex:/^[A-Z]{2,3}[\s-]?[A-Z0-9]{4,5}$/i'],
            ]);
            $messages = array_merge($messages, [
                'vehicle_type_id.required' => 'Wybierz typ pojazdu.',
                'vehicle_brand.required' => 'Podaj markę pojazdu.',
                'vehicle_model.required' => 'Podaj model pojazdu.',
                'vehicle_year.required' => 'Podaj rok produkcji.',
                'vehicle_year.integer' => 'Rok produkcji musi być liczbą.',
                'vehicle_year.min' => 'Rok produkcji musi być większy niż 1900.',
                'vehicle_year.max' => 'Rok produkcji nie może być z przyszłości.',
                'registration_number.required' => 'Podaj numer rejestracyjny.',
                'registration_number.regex' => 'Nieprawidłowy format numeru rejestracyjnego (np. WA 12345).',
            ]);
        }

        // Location rules (only when mobile_service feature active)
        if (TenantFeature::active('mobile_service')) {
            $rules = array_merge($rules, [
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
            ]);
            $messages = array_merge($messages, [
                'service_location_type.required' => 'Wybierz typ lokalizacji.',
                'location_address.required' => 'Podaj adres lokalizacji.',
                'location_latitude.required' => 'Wybierz adres z listy podpowiedzi.',
                'location_longitude.required' => 'Wybierz adres z listy podpowiedzi.',
            ]);
        }

        $validated = $request->validate($rules, $messages);

        // ===== SERVICE AREA VALIDATION =====
        if (TenantFeature::active('service_area') && ! empty($validated['location_latitude'])) {
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
        }
        // ===== END SERVICE AREA VALIDATION =====

        $sessionData = [
            'booking.current_step' => $step,
        ];

        if (TenantFeature::active('vehicles')) {
            $sessionData['booking.vehicle_type_id'] = $validated['vehicle_type_id'];
            $sessionData['booking.vehicle_brand'] = $validated['vehicle_brand'] ?? null;
            $sessionData['booking.vehicle_model'] = $validated['vehicle_model'] ?? null;
            $sessionData['booking.vehicle_year'] = $validated['vehicle_year'] ?? null;
            $sessionData['booking.registration_number'] = $validated['registration_number'] ?? null;
        }

        if (TenantFeature::active('mobile_service')) {
            $sessionData['booking.location_address'] = $validated['location_address'];
            $sessionData['booking.location_latitude'] = $validated['location_latitude'];
            $sessionData['booking.location_longitude'] = $validated['location_longitude'];
            $sessionData['booking.location_place_id'] = $validated['location_place_id'] ?? null;
            $sessionData['booking.location_components'] = $validated['location_components'] ?? null;
            $sessionData['booking.service_location_type'] = $validated['service_location_type'] ?? null;
            $sessionData['booking.service_area_valid'] = true;
            $sessionData['booking.service_area_coords'] = [
                'lat' => (float) $validated['location_latitude'],
                'lng' => (float) $validated['location_longitude'],
            ];
        }

        session($sessionData);

        return redirect()->route('booking.step', $step + 1);
    }

    private function storeContactStep(int $step, Request $request)
    {
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
            'booking.invoice_requested' => $request->boolean('invoice_requested'),
            'booking.invoice_company_name' => $validated['invoice_company_name'] ?? null,
            'booking.invoice_nip' => $validated['invoice_nip'] ?? null,
            'booking.invoice_street' => $validated['invoice_street'] ?? null,
            'booking.invoice_street_number' => $validated['invoice_street_number'] ?? null,
            'booking.invoice_postal_code' => $validated['invoice_postal_code'] ?? null,
            'booking.invoice_city' => $validated['invoice_city'] ?? null,
            'booking.current_step' => $step,
        ]);

        return redirect()->route('booking.step', $step + 1);
    }

    /**
     * AJAX: Save progress to session with validation
     */
    public function saveProgress(Request $request)
    {
        $step = $request->input('step');
        $data = $request->input('data', []);
        $stepKey = $this->getStepKey($step);

        // Validate based on step key
        try {
            $validated = match ($stepKey) {
                'service' => $this->validateStepService($data),
                'datetime' => $this->validateStepDatetime($data),
                'vehicle-location' => $this->validateStepVehicleLocation($data),
                'contact' => $this->validateStepContact($data),
                default => $data,
            };

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
     * Validation helpers for each step key
     */
    private function validateStepService(array $data)
    {
        return validator($data, [
            'service_id' => 'required|exists:services,id',
        ])->validate();
    }

    private function validateStepDatetime(array $data)
    {
        // Allow partial saves (just date OR just time_slot)
        return validator($data, [
            'date' => 'nullable|date|after_or_equal:today',
            'time_slot' => 'nullable|regex:/^\d{2}:\d{2}$/',
        ])->validate();
    }

    private function validateStepVehicleLocation(array $data)
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

    private function validateStepContact(array $data)
    {
        return validator($data, [
            'first_name' => 'required|string|min:2|max:100',
            'last_name' => 'required|string|min:2|max:100',
            'email' => 'required|email|max:255',
            'phone' => ['required', 'regex:/^(\+48)?[\s-]?\d{9}$/'],
            'notify_email' => 'nullable|boolean',
            'notify_sms' => 'nullable|boolean',
            'terms_accepted' => 'required|accepted',
            'invoice_requested' => 'nullable|boolean',
            'invoice_company_name' => 'required_if:invoice_requested,1,true|nullable|string|max:255',
            'invoice_nip' => ['nullable', 'string', 'max:13', new ValidPolishNIP],
            'invoice_street' => 'required_if:invoice_requested,1,true|nullable|string|max:255',
            'invoice_street_number' => 'required_if:invoice_requested,1,true|nullable|string|max:20',
            'invoice_postal_code' => 'required_if:invoice_requested,1,true|nullable|string|max:6',
            'invoice_city' => 'required_if:invoice_requested,1,true|nullable|string|max:100',
        ], [
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
            ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed])
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
        // Only when mobile_service or service_area feature is active
        if (TenantFeature::active('service_area') && $this->hasStep('vehicle-location')) {
            if (empty($booking['location_latitude']) || empty($booking['location_longitude'])) {
                return redirect()->route('booking.step', $this->getStepNumber('vehicle-location'))
                    ->with('error', 'Brak danych lokalizacji. Wybierz adres z listy podpowiedzi.');
            }

            $areaValidation = $this->serviceAreaValidator->validate(
                (float) $booking['location_latitude'],
                (float) $booking['location_longitude']
            );

            if (! $areaValidation['valid']) {
                return redirect()->route('booking.step', $this->getStepNumber('vehicle-location'))
                    ->with('error', $areaValidation['message'] ?? 'Przepraszamy, nie obsługujemy tej lokalizacji. Wybierz adres z dostępnego obszaru.');
            }
        }
        // ===== END SERVICE AREA VALIDATION =====

        // Final validation
        $service = Service::findOrFail($booking['service_id']);
        $appointmentDateTime = Carbon::parse($booking['date'].' '.$booking['time_slot']);

        // CRITICAL: slot availability check + staff assignment + appointment
        // creation ALL happen inside this single DB::transaction(), with a
        // best-effort row lock acquired first. Previously the slot check and
        // staff selection ran BEFORE DB::transaction() opened at all, so two
        // concurrent confirm() calls could both see the slot as free and both
        // proceed to create an Appointment for it. The row lock narrows the
        // race window; the appointments_staff_slot_unique DB constraint is the
        // authoritative guard — caught below via isDoubleBookingViolation().
        try {
            $appointment = DB::transaction(function () use ($booking, $user, $service, $appointmentDateTime) {
                $eligibleStaffIds = $this->appointmentService->getEligibleStaffIds($booking['service_id']);
                $this->appointmentService->lockStaffAppointmentsForDate(
                    $eligibleStaffIds,
                    $appointmentDateTime->copy()->startOfDay()
                );

                // Check if slot still available (re-checked now that we hold the lock)
                $slots = $this->appointmentService->getAvailableSlotsAcrossAllStaff(
                    serviceId: $booking['service_id'],
                    date: $appointmentDateTime->copy()->startOfDay(),
                    serviceDurationMinutes: $service->duration_minutes
                );

                $requestedSlot = collect($slots)->firstWhere('time', $booking['time_slot']);

                if (! $requestedSlot || ! $requestedSlot['available']) {
                    throw new AppointmentSlotUnavailableException(['Wybrany termin jest już niedostępny. Wybierz inny.']);
                }

                // Assign best staff member
                $staff = $this->appointmentService->findBestAvailableStaff(
                    serviceId: $booking['service_id'],
                    dateTime: $appointmentDateTime,
                    durationMinutes: $service->duration_minutes
                );

                if (! $staff) {
                    throw new AppointmentSlotUnavailableException(['Brak dostępnego pracownika. Wybierz inny termin.']);
                }

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

                // Create appointment - vehicle fields are nullable, only set when feature is active
                $appointmentData = [
                    'customer_id' => $user->id,
                    'service_id' => $booking['service_id'],
                    'service_price_at_booking' => $service->price,
                    'service_name_at_booking' => $service->name,
                    'service_duration_at_booking' => $service->duration_minutes,
                    'staff_id' => $staff->id,
                    'appointment_date' => $appointmentDateTime->format('Y-m-d'),
                    'start_time' => $appointmentDateTime->format('H:i:s'),
                    'end_time' => $appointmentDateTime->copy()->addMinutes($service->duration_minutes)->format('H:i:s'),
                    'status' => AppointmentStatus::Pending,
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
                ];

                // Vehicle fields (only when feature active, columns are nullable)
                if (TenantFeature::active('vehicles')) {
                    $appointmentData['vehicle_type_id'] = $booking['vehicle_type_id'] ?? null;
                    $appointmentData['vehicle_custom_brand'] = $booking['vehicle_brand'] ?? null;
                    $appointmentData['vehicle_custom_model'] = $booking['vehicle_model'] ?? null;
                    $appointmentData['vehicle_year'] = $booking['vehicle_year'] ?? null;
                    $appointmentData['registration_number'] = $booking['registration_number'] ?? null;
                }

                // Location fields (only when feature active, columns are nullable)
                if (TenantFeature::active('mobile_service')) {
                    $appointmentData['location_address'] = $booking['location_address'] ?? null;
                    $appointmentData['location_latitude'] = $booking['location_latitude'] ?? null;
                    $appointmentData['location_longitude'] = $booking['location_longitude'] ?? null;
                    $appointmentData['location_place_id'] = $booking['location_place_id'] ?? null;
                    $appointmentData['location_components'] = $booking['location_components'] ?? null;
                    $appointmentData['service_location_type'] = $booking['service_location_type'] ?? null;
                }

                $appointment = Appointment::create($appointmentData);

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
        } catch (AppointmentSlotUnavailableException $e) {
            Log::info('Booking confirm: slot unavailable at confirm time', [
                'user_id' => $user->id,
                'service_id' => $booking['service_id'],
                'date' => $booking['date'] ?? null,
                'time_slot' => $booking['time_slot'] ?? null,
                'reason' => $e->getMessage(),
            ]);

            return redirect()->route('booking.step', 2)
                ->with('error', $e->getErrors()[0] ?? 'Wybrany termin jest już niedostępny. Wybierz inny.');
        } catch (\Throwable $e) {
            if ($this->appointmentService->isDoubleBookingViolation($e)) {
                Log::info('Booking confirm: double-booking prevented by unique constraint', [
                    'user_id' => $user->id,
                    'service_id' => $booking['service_id'],
                    'date' => $booking['date'] ?? null,
                    'time_slot' => $booking['time_slot'] ?? null,
                ]);

                return redirect()->route('booking.step', 2)
                    ->with('error', 'Wybrany termin został właśnie zarezerwowany przez inną osobę. Wybierz inny termin.');
            }

            Log::error('Booking confirm: failed to create appointment', [
                'user_id' => $user->id,
                'service_id' => $booking['service_id'],
                'date' => $booking['date'] ?? null,
                'time_slot' => $booking['time_slot'] ?? null,
                'error' => $e->getMessage(),
                'ip' => request()->ip(),
            ]);

            $reviewStep = $this->getStepNumber('review');

            return redirect()->route('booking.step', $reviewStep)
                ->with('error', 'Wystąpił błąd podczas tworzenia rezerwacji. Spróbuj ponownie.');
        }

        Log::info('Booking confirm: appointment created successfully', [
            'user_id' => $user->id,
            'appointment_id' => $appointment->id,
            'service_id' => $booking['service_id'],
            'date' => $booking['date'],
            'time_slot' => $booking['time_slot'],
            'staff_id' => $appointment->staff_id,
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
            'showVehicle' => TenantFeature::active('vehicles'),
            'showMobileService' => TenantFeature::active('mobile_service'),
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
