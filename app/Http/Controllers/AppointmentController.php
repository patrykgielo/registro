<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Rules\StaffRoleRule;
use App\Services\AppointmentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AppointmentController extends Controller
{
    protected AppointmentService $appointmentService;

    public function __construct(AppointmentService $appointmentService)
    {
        $this->middleware('auth');
        $this->appointmentService = $appointmentService;
    }

    public function index()
    {
        $appointments = Auth::user()
            ->customerAppointments()
            ->with(['customer', 'staff'])
            ->orderBy('appointment_date', 'desc')
            ->orderBy('start_time', 'desc')
            ->get();

        return view('appointments.index', compact('appointments'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'staff_id' => ['nullable', 'exists:users,id', new StaffRoleRule], // Made optional for auto-assignment with role validation
            'appointment_date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'notes' => 'nullable|string|max:1000',
            // Customer profile fields
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone_e164' => ['required', 'string', 'max:20', 'regex:/^\+\d{1,3}\d{6,14}$/'],
            'street_name' => 'nullable|string|max:255',
            'street_number' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:255',
            'postal_code' => ['nullable', 'string', 'max:10', 'regex:/^\d{2}-\d{3}$/'],
            'access_notes' => 'nullable|string|max:1000',
            // Google Maps location fields (REQUIRED for service location)
            'location_address' => 'required|string|max:500',
            'location_latitude' => 'required|numeric|between:-90,90',
            'location_longitude' => 'required|numeric|between:-180,180',
            'location_place_id' => 'required|string|max:255',
            'location_components' => 'nullable|json',
            // Vehicle fields (REQUIRED)
            'vehicle_type_id' => 'required|exists:vehicle_types,id',
            'car_brand_id' => 'nullable|exists:car_brands,id',
            'car_brand_name' => 'nullable|string|max:100',
            'car_model_id' => 'nullable|exists:car_models,id',
            'car_model_name' => 'nullable|string|max:100',
            'vehicle_year' => 'required|integer|min:1990|max:'.(date('Y') + 1),
        ]);

        $date = Carbon::parse($validated['appointment_date']);
        $startTime = Carbon::parse($validated['appointment_date'].' '.$validated['start_time']);
        $endTime = Carbon::parse($validated['appointment_date'].' '.$validated['end_time']);

        // If staff_id not provided, find first available staff
        if (empty($validated['staff_id'])) {
            $staffId = $this->appointmentService->findFirstAvailableStaff(
                serviceId: $validated['service_id'],
                date: $date,
                startTime: $startTime,
                endTime: $endTime
            );

            if (! $staffId) {
                return back()
                    ->withErrors(['appointment' => ['Niestety, żaden pracownik nie jest dostępny w wybranym terminie. Proszę wybrać inny termin.']])
                    ->withInput();
            }

            $validated['staff_id'] = $staffId;
        }

        // Validate availability for assigned staff
        $validation = $this->appointmentService->validateAppointment(
            staffId: $validated['staff_id'],
            serviceId: $validated['service_id'],
            appointmentDate: $validated['appointment_date'],
            startTime: $validated['start_time'],
            endTime: $validated['end_time']
        );

        if (! $validation['valid']) {
            return back()
                ->withErrors(['appointment' => $validation['errors']])
                ->withInput();
        }

        // Update customer profile - only fill empty fields to avoid overwriting existing data
        $user = Auth::user();
        $profileUpdates = [];

        if (empty($user->first_name)) {
            $profileUpdates['first_name'] = $validated['first_name'];
        }
        if (empty($user->last_name)) {
            $profileUpdates['last_name'] = $validated['last_name'];
        }
        if (empty($user->phone_e164)) {
            $profileUpdates['phone_e164'] = $validated['phone_e164'];
        }
        if (empty($user->street_name) && ! empty($validated['street_name'])) {
            $profileUpdates['street_name'] = $validated['street_name'];
        }
        if (empty($user->street_number) && ! empty($validated['street_number'])) {
            $profileUpdates['street_number'] = $validated['street_number'];
        }
        if (empty($user->city) && ! empty($validated['city'])) {
            $profileUpdates['city'] = $validated['city'];
        }
        if (empty($user->postal_code) && ! empty($validated['postal_code'])) {
            $profileUpdates['postal_code'] = $validated['postal_code'];
        }
        if (empty($user->access_notes) && ! empty($validated['access_notes'])) {
            $profileUpdates['access_notes'] = $validated['access_notes'];
        }

        if (! empty($profileUpdates)) {
            $user->update($profileUpdates);
        }

        // Handle custom brand/model (if provided instead of IDs)
        $vehicleData = [
            'vehicle_type_id' => $validated['vehicle_type_id'],
            'vehicle_year' => $validated['vehicle_year'],
        ];

        if (! empty($validated['car_brand_id'])) {
            $vehicleData['car_brand_id'] = $validated['car_brand_id'];
        } elseif (! empty($validated['car_brand_name'])) {
            $vehicleData['vehicle_custom_brand'] = $validated['car_brand_name'];
        }

        if (! empty($validated['car_model_id'])) {
            $vehicleData['car_model_id'] = $validated['car_model_id'];
        } elseif (! empty($validated['car_model_name'])) {
            $vehicleData['vehicle_custom_model'] = $validated['car_model_name'];
        }

        // Create appointment
        $appointment = Appointment::create([
            'service_id' => $validated['service_id'],
            'customer_id' => Auth::id(),
            'staff_id' => $validated['staff_id'],
            'appointment_date' => $validated['appointment_date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'status' => 'pending',
            'notes' => $validated['notes'] ?? null,
            'location_address' => $validated['location_address'] ?? null,
            'location_latitude' => $validated['location_latitude'] ?? null,
            'location_longitude' => $validated['location_longitude'] ?? null,
            'location_place_id' => $validated['location_place_id'] ?? null,
            'location_components' => isset($validated['location_components']) ? json_decode($validated['location_components'], true) : null,
            ...$vehicleData,
        ]);

        return redirect()
            ->route('appointments.index')
            ->with('success', 'Wizyta została pomyślnie zarezerwowana! Status: Oczekująca na potwierdzenie.');
    }

    public function cancel(Appointment $appointment)
    {
        // Check if user owns this appointment
        if ($appointment->customer_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        // Check if appointment can be cancelled
        if (! $appointment->can_be_cancelled) {
            return back()->withErrors(['appointment' => 'Ta wizyta nie może być anulowana.']);
        }

        $appointment->update([
            'status' => 'cancelled',
            'cancellation_reason' => 'Anulowane przez klienta',
        ]);

        return back()->with('success', 'Wizyta została anulowana.');
    }
}
