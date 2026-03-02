// ===================================================================
// Booking Wizard - Pure Vanilla JavaScript (No Framework)
// ===================================================================

(function() {
    'use strict';

    // ===================================================================
    // STATE MANAGEMENT
    // ===================================================================

    const state = {
        step: 1,
        totalSteps: 4,
        service: null,
        date: null,
        timeSlot: null,
        customer: {
            first_name: '',
            last_name: '',
            phone_e164: '',
            location_address: '',
            location_latitude: null,
            location_longitude: null,
            location_place_id: '',
            location_components: {},
            street_name: '',
            street_number: '',
            city: '',
            postal_code: '',
            access_notes: '',
            notes: ''
        },
        // Vehicle data
        vehicle: {
            type_id: null,
            type_name: '',
            type_slug: '',
            brand_id: null,
            brand_name: '',
            model_id: null,
            model_name: '',
            year: null
        },
        vehicleTypes: [],
        carBrands: [],
        carModels: [],
        savedVehicleData: null, // Saved vehicle from profile
        loading: false,
        errors: {},
        availableSlots: [],
        // Google Maps
        map: null,
        marker: null,
        mapInitialized: false,
        mapLoading: false,
        mapId: null,
        mapDefaultLat: 52.2297,
        mapDefaultLng: 21.0122,
        mapDefaultZoom: 15,
        mapCountryCode: 'pl',
        mapDebugPanelEnabled: true,
        advanceBookingHours: 24,
        businessHoursStart: '09:00',
        businessHoursEnd: '18:00',
        slotIntervalMinutes: 15,
        cancellationHours: 24
    };

    // ===================================================================
    // DOM ELEMENTS CACHE
    // ===================================================================

    let elements = {};

    function cacheElements() {
        elements = {
            // Steps
            steps: document.querySelectorAll('[data-step]'),

            // Progress
            progressBarFill: document.querySelector('.progress-bar-fill'),
            stepIndicators: document.querySelectorAll('.step-indicator'),
            stepLabels: document.querySelectorAll('[data-step-label]'),

            // Service info
            serviceName: document.querySelectorAll('[data-service-name]'),
            serviceDuration: document.querySelectorAll('[data-service-duration]'),
            servicePrice: document.querySelectorAll('[data-service-price]'),

            // Date & Time
            dateInput: document.getElementById('date-input'),
            dateError: document.getElementById('date-error'),
            timeSlotsContainer: document.getElementById('time-slots-container'),
            timeSlotsLoading: document.getElementById('time-slots-loading'),
            timeSlotsEmpty: document.getElementById('time-slots-empty'),
            timeSlotsError: document.getElementById('time-slots-error'),
            timeSlotError: document.getElementById('time-slot-error'),

            // Vehicle inputs
            vehicleTypesContainer: document.getElementById('vehicle-types-container'),
            vehicleTypeError: document.getElementById('vehicle-type-error'),
            vehicleDetailsSection: document.getElementById('vehicle-details-section'),
            carBrandSelect: document.getElementById('car_brand'),
            carModelSelect: document.getElementById('car_model'),
            vehicleYearSelect: document.getElementById('vehicle_year'),
            brandError: document.getElementById('brand-error'),
            modelError: document.getElementById('model-error'),
            yearError: document.getElementById('year-error'),

            // Customer inputs
            firstNameInput: document.getElementById('first_name'),
            lastNameInput: document.getElementById('last_name'),
            phoneInput: document.getElementById('phone_e164'),
            streetNameInput: document.getElementById('street_name'),
            streetNumberInput: document.getElementById('street_number'),
            cityInput: document.getElementById('city'),
            postalCodeInput: document.getElementById('postal_code'),
            accessNotesInput: document.getElementById('access_notes'),
            notesInput: document.getElementById('notes-input'),

            // Location
            placeAutocomplete: document.getElementById('place-autocomplete'),
            locationError: document.getElementById('location-error'),
            locationAddressDisplay: document.getElementById('selected-address-info'),
            locationAddressText: document.getElementById('selected-address-text'),
            locationMap: document.getElementById('location-map'),
            mapLoadingOverlay: document.getElementById('map-loading-overlay'),

            // Debug info
            debugAddress: document.getElementById('debug-address'),
            debugPlaceId: document.getElementById('debug-place-id'),
            debugLatitude: document.getElementById('debug-latitude'),
            debugLongitude: document.getElementById('debug-longitude'),
            debugMapInit: document.getElementById('debug-map-init'),
            debugMarker: document.getElementById('debug-marker'),

            // Summary
            summaryDate: document.getElementById('summary-date'),
            summaryTime: document.getElementById('summary-time'),
            summaryDuration: document.getElementById('summary-duration'),
            summaryCustomerName: document.getElementById('summary-customer-name'),
            summaryPhone: document.getElementById('summary-customer-phone'),
            summaryAddress: document.getElementById('summary-customer-address'),
            summaryAddressRow: document.getElementById('summary-customer-address-row'),
            summaryVehicleType: document.getElementById('summary-vehicle-type'),
            summaryVehicleBrand: document.getElementById('summary-vehicle-brand'),
            summaryVehicleModel: document.getElementById('summary-vehicle-model'),
            summaryVehicleYear: document.getElementById('summary-vehicle-year'),
            summaryNotes: document.getElementById('summary-notes'),
            summaryNotesContainer: document.getElementById('summary-notes-section'),
            summaryServiceName: document.getElementById('summary-service-name'),
            summaryPrice: document.getElementById('summary-price'),

            // Hidden form inputs
            hiddenServiceId: document.querySelector('[name="service_id"]'),
            hiddenDate: document.querySelector('[name="appointment_date"]'),
            hiddenStartTime: document.querySelector('[name="start_time"]'),
            hiddenEndTime: document.querySelector('[name="end_time"]'),
            hiddenNotes: document.querySelector('[name="notes"]'),
            hiddenFirstName: document.querySelector('[name="first_name"]'),
            hiddenLastName: document.querySelector('[name="last_name"]'),
            hiddenPhone: document.querySelector('[name="phone_e164"]'),
            hiddenLocationAddress: document.querySelector('[name="location_address"]'),
            hiddenLocationLatitude: document.querySelector('[name="location_latitude"]'),
            hiddenLocationLongitude: document.querySelector('[name="location_longitude"]'),
            hiddenLocationPlaceId: document.querySelector('[name="location_place_id"]'),
            hiddenLocationComponents: document.querySelector('[name="location_components"]'),
            hiddenStreetName: document.querySelector('[name="street_name"]'),
            hiddenStreetNumber: document.querySelector('[name="street_number"]'),
            hiddenCity: document.querySelector('[name="city"]'),
            hiddenPostalCode: document.querySelector('[name="postal_code"]'),
            hiddenAccessNotes: document.querySelector('[name="access_notes"]'),
            hiddenVehicleTypeId: document.querySelector('[name="vehicle_type_id"]'),
            hiddenCarBrandId: document.querySelector('[name="car_brand_id"]'),
            hiddenCarBrandName: document.querySelector('[name="car_brand_name"]'),
            hiddenCarModelId: document.querySelector('[name="car_model_id"]'),
            hiddenCarModelName: document.querySelector('[name="car_model_name"]'),
            hiddenVehicleYear: document.querySelector('[name="vehicle_year"]'),

            // Sidebar
            sidebarDate: document.getElementById('sidebar-date'),
            sidebarTime: document.getElementById('sidebar-time')
        };
    }

    // ===================================================================
    // INITIALIZATION
    // ===================================================================

    function init() {
        // ⚠️ GUARD: Only initialize on booking pages (prevents clearing form fields on other pages)
        const wizardContainer = document.querySelector('[data-wizard]');
        if (!wizardContainer) {
            return; // Not on a booking page, skip initialization
        }

        console.log('🚀 Booking Wizard - Vanilla JS initialized');

        // Cache DOM elements
        cacheElements();

        // Load initial service data (from data attributes)
        loadServiceData();

        // Load customer data (if authenticated)
        loadCustomerData();

        // Load saved vehicle and address from profile
        loadSavedVehicle();
        loadSavedAddress();

        // Setup event listeners
        setupEventListeners();

        // Initialize Google Maps
        initializeGoogleMaps();

        // Fetch vehicle types and years
        fetchVehicleTypes();
        populateVehicleYears();

        // Show first step
        updateUI();

        // Prefill address fields if saved address was loaded
        prefillAddressFields();

        console.log('✅ Booking Wizard ready');
    }

    /**
     * Prefill address form fields from state (after saved address is loaded)
     */
    function prefillAddressFields() {
        if (state.customer.location_address) {
            // Update autocomplete input
            if (elements.placeAutocomplete) {
                elements.placeAutocomplete.value = state.customer.location_address;
            }

            // Update address display
            if (elements.locationAddressDisplay) {
                elements.locationAddressDisplay.style.display = 'block';
            }
            if (elements.locationAddressText) {
                elements.locationAddressText.textContent = state.customer.location_address;
            }

            // Update form fields
            if (elements.streetNameInput) elements.streetNameInput.value = state.customer.street_name || '';
            if (elements.streetNumberInput) elements.streetNumberInput.value = state.customer.street_number || '';
            if (elements.cityInput) elements.cityInput.value = state.customer.city || '';
            if (elements.postalCodeInput) elements.postalCodeInput.value = state.customer.postal_code || '';

            console.log('✅ Address fields prefilled from saved data');
        }
    }

    function loadServiceData() {
        const wizardContainer = document.querySelector('[data-wizard]');
        if (!wizardContainer) return;

        try {
            state.service = JSON.parse(wizardContainer.dataset.service);
            state.mapId = wizardContainer.dataset.mapId || null;
            state.mapDefaultLat = parseFloat(wizardContainer.dataset.mapLat || state.mapDefaultLat);
            state.mapDefaultLng = parseFloat(wizardContainer.dataset.mapLng || state.mapDefaultLng);
            state.mapDefaultZoom = parseInt(wizardContainer.dataset.mapZoom || state.mapDefaultZoom, 10);
            state.mapCountryCode = wizardContainer.dataset.mapCountry || state.mapCountryCode;
            state.mapDebugPanelEnabled = wizardContainer.dataset.mapDebug === 'true';
            state.advanceBookingHours = parseInt(wizardContainer.dataset.advanceHours || state.advanceBookingHours, 10);
            state.businessHoursStart = wizardContainer.dataset.businessHoursStart || state.businessHoursStart;
            state.businessHoursEnd = wizardContainer.dataset.businessHoursEnd || state.businessHoursEnd;
            state.slotIntervalMinutes = parseInt(wizardContainer.dataset.slotInterval || state.slotIntervalMinutes, 10);
            state.cancellationHours = parseInt(wizardContainer.dataset.cancellationHours || state.cancellationHours, 10);

            console.log('Service loaded:', state.service);
            console.log('Map ID:', state.mapId);
        } catch (error) {
            console.error('Error loading service data:', error);
        }
    }

    function loadCustomerData() {
        const wizardContainer = document.querySelector('[data-wizard]');
        if (!wizardContainer) return;

        try {
            const customerData = JSON.parse(wizardContainer.dataset.customer || '{}');
            Object.assign(state.customer, customerData);
            console.log('Customer data loaded:', state.customer);
        } catch (error) {
            console.error('Error loading customer data:', error);
        }
    }

    /**
     * Load saved vehicle data from profile (if exists)
     */
    function loadSavedVehicle() {
        const wizardContainer = document.querySelector('[data-wizard]');
        if (!wizardContainer || !wizardContainer.dataset.savedVehicle) return;

        try {
            const savedVehicle = JSON.parse(wizardContainer.dataset.savedVehicle);
            console.log('📦 Saved vehicle loaded:', savedVehicle);

            // Store for later use after vehicle types are loaded
            state.savedVehicleData = savedVehicle;
        } catch (error) {
            console.error('Error loading saved vehicle:', error);
        }
    }

    /**
     * Load saved address data from profile (if exists)
     */
    function loadSavedAddress() {
        const wizardContainer = document.querySelector('[data-wizard]');
        if (!wizardContainer || !wizardContainer.dataset.savedAddress) return;

        try {
            const savedAddress = JSON.parse(wizardContainer.dataset.savedAddress);
            console.log('📦 Saved address loaded:', savedAddress);

            // Prefill location data
            if (savedAddress.address) {
                state.customer.location_address = savedAddress.address;
                state.customer.location_latitude = parseFloat(savedAddress.latitude);
                state.customer.location_longitude = parseFloat(savedAddress.longitude);
                state.customer.location_place_id = savedAddress.place_id || '';
                state.customer.location_components = savedAddress.address_components || {};

                // Parse address components for form fields
                const components = savedAddress.address_components || {};
                state.customer.street_name = components.route?.long_name || '';
                state.customer.street_number = components.street_number?.long_name || '';
                state.customer.city = components.locality?.long_name || components.postal_town?.long_name || '';
                state.customer.postal_code = components.postal_code?.long_name || '';

                console.log('✅ Location prefilled from saved address');
            }
        } catch (error) {
            console.error('Error loading saved address:', error);
        }
    }

    /**
     * Apply saved vehicle data after vehicle types and brands are loaded
     */
    function applySavedVehicleData() {
        if (!state.savedVehicleData) return;

        const saved = state.savedVehicleData;
        console.log('🔧 Applying saved vehicle data:', saved);

        // Find and select vehicle type
        if (saved.vehicle_type_id) {
            const vehicleType = state.vehicleTypes.find(t => t.id === saved.vehicle_type_id);
            if (vehicleType) {
                selectVehicleType(vehicleType);

                // After type is selected, apply brand/model/year with delay
                setTimeout(async () => {
                    // Wait for brands to load
                    await new Promise(resolve => setTimeout(resolve, 300));

                    // Select brand
                    if (saved.car_brand_id && elements.carBrandSelect) {
                        elements.carBrandSelect.value = saved.car_brand_id;
                        const brandEvent = new Event('change', { bubbles: true });
                        elements.carBrandSelect.dispatchEvent(brandEvent);

                        // Wait for models to load
                        await new Promise(resolve => setTimeout(resolve, 300));

                        // Select model
                        if (saved.car_model_id && elements.carModelSelect) {
                            elements.carModelSelect.value = saved.car_model_id;
                            const modelEvent = new Event('change', { bubbles: true });
                            elements.carModelSelect.dispatchEvent(modelEvent);
                        }
                    }

                    // Select year
                    if (saved.year && elements.vehicleYearSelect) {
                        elements.vehicleYearSelect.value = saved.year;
                        state.vehicle.year = saved.year;
                    }

                    console.log('✅ Saved vehicle applied');
                }, 100);
            }
        }
    }

    // ===================================================================
    // EVENT LISTENERS
    // ===================================================================

    function setupEventListeners() {
        // Navigation buttons
        document.querySelectorAll('[data-next-step]').forEach(btn => {
            btn.addEventListener('click', nextStep);
        });

        document.querySelectorAll('[data-prev-step]').forEach(btn => {
            btn.addEventListener('click', prevStep);
        });

        document.querySelectorAll('[data-go-to-step]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const targetStep = parseInt(e.currentTarget.dataset.goToStep);
                goToStep(targetStep);
            });
        });

        // Date input
        if (elements.dateInput) {
            elements.dateInput.addEventListener('change', handleDateChange);
            // Set min date
            elements.dateInput.min = getMinDate();
        }

        // Vehicle selects
        if (elements.carBrandSelect) {
            elements.carBrandSelect.addEventListener('change', handleBrandChange);
        }
        if (elements.carModelSelect) {
            elements.carModelSelect.addEventListener('change', handleModelChange);
        }
        if (elements.vehicleYearSelect) {
            elements.vehicleYearSelect.addEventListener('change', handleYearChange);
        }

        // Customer inputs - bind to state
        bindInput(elements.firstNameInput, 'customer', 'first_name');
        bindInput(elements.lastNameInput, 'customer', 'last_name');
        bindInput(elements.phoneInput, 'customer', 'phone_e164');
        bindInput(elements.streetNameInput, 'customer', 'street_name');
        bindInput(elements.streetNumberInput, 'customer', 'street_number');
        bindInput(elements.cityInput, 'customer', 'city');
        bindInput(elements.postalCodeInput, 'customer', 'postal_code');
        bindInput(elements.accessNotesInput, 'customer', 'access_notes');
        bindInput(elements.notesInput, 'customer', 'notes');

        // Form submission
        const form = document.querySelector('form[action*="appointments"]');
        if (form) {
            form.addEventListener('submit', handleFormSubmit);
        }
    }

    function bindInput(inputElement, stateKey, property) {
        if (!inputElement) return;

        // Set initial value
        if (stateKey) {
            inputElement.value = state[stateKey][property] || '';
        } else {
            inputElement.value = state[property] || '';
        }

        // Listen for changes
        inputElement.addEventListener('input', (e) => {
            if (stateKey) {
                state[stateKey][property] = e.target.value;
            } else {
                state[property] = e.target.value;
            }
        });
    }

    // ===================================================================
    // STEP NAVIGATION
    // ===================================================================

    function nextStep() {
        if (validateStep()) {
            state.step++;
            scrollToTop();
            updateUI();

            // Initialize map when entering step 3
            if (state.step === 3 && !state.mapInitialized) {
                initializeMap();
            }
        }
    }

    function prevStep() {
        if (state.step > 1) {
            state.step--;
            scrollToTop();
            updateUI();
        }
    }

    function goToStep(targetStep) {
        if (targetStep <= state.step || validateStepsUpTo(targetStep - 1)) {
            state.step = targetStep;
            scrollToTop();
            updateUI();
        }
    }

    function scrollToTop() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ===================================================================
    // UI UPDATE
    // ===================================================================

    function updateUI() {
        updateSteps();
        updateProgress();
        updateServiceInfo();
        updateSidebar();
        updateSummary();
        updateDebugInfo();
    }

    function updateSteps() {
        // Show/hide steps
        elements.steps.forEach(stepEl => {
            const stepNum = parseInt(stepEl.dataset.step);
            stepEl.style.display = stepNum === state.step ? 'block' : 'none';
        });

        // Update step indicators
        elements.stepIndicators.forEach((indicator, index) => {
            const stepNum = index + 1;

            // Remove all classes
            indicator.classList.remove('step-indicator-active', 'step-indicator-completed');

            // Add appropriate class
            if (stepNum === state.step) {
                indicator.classList.add('step-indicator-active');
            } else if (stepNum < state.step) {
                indicator.classList.add('step-indicator-completed');
            }

            // Update content (checkmark or number)
            if (stepNum < state.step) {
                indicator.innerHTML = '<svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
            } else {
                indicator.textContent = stepNum;
            }
        });
    }

    function updateProgress() {
        const percentage = (state.step / state.totalSteps) * 100;
        if (elements.progressBarFill) {
            elements.progressBarFill.style.width = `${percentage}%`;
        }
    }

    function updateServiceInfo() {
        if (!state.service) return;

        // Update service name
        elements.serviceName.forEach(el => {
            el.textContent = state.service.name;
        });

        // Update duration
        elements.serviceDuration.forEach(el => {
            el.textContent = `${state.service.duration_minutes} min`;
        });

        // Update price
        elements.servicePrice.forEach(el => {
            el.textContent = `${Math.floor(state.service.price)} zł`;
        });
    }

    function updateSidebar() {
        // Update date
        if (elements.sidebarDate) {
            elements.sidebarDate.textContent = state.date || 'Nie wybrano';
        }

        // Update time
        if (elements.sidebarTime) {
            if (state.timeSlot) {
                elements.sidebarTime.textContent = `${state.timeSlot.start} - ${state.timeSlot.end}`;
            } else {
                elements.sidebarTime.textContent = 'Nie wybrano';
            }
        }
    }

    function updateSummary() {
        if (state.step !== 4) return;

        // Service name
        if (elements.summaryServiceName && state.service) {
            elements.summaryServiceName.textContent = state.service.name;
        }

        // Summary date
        if (elements.summaryDate) {
            elements.summaryDate.textContent = state.date || '-';
        }

        // Summary time
        if (elements.summaryTime) {
            if (state.timeSlot) {
                elements.summaryTime.textContent = `${state.timeSlot.start} - ${state.timeSlot.end}`;
            } else {
                elements.summaryTime.textContent = '-';
            }
        }

        // Summary duration
        if (elements.summaryDuration && state.service) {
            elements.summaryDuration.textContent = `${state.service.duration_minutes} minut`;
        }

        // Customer name
        if (elements.summaryCustomerName) {
            elements.summaryCustomerName.textContent =
                `${state.customer.first_name} ${state.customer.last_name}`;
        }

        // Phone
        if (elements.summaryPhone) {
            elements.summaryPhone.textContent = state.customer.phone_e164;
        }

        // Address (show/hide row based on whether city is filled)
        if (elements.summaryAddressRow) {
            if (state.customer.city) {
                elements.summaryAddressRow.style.display = 'block';
                if (elements.summaryAddress) {
                    const addressParts = [
                        state.customer.street_name || '',
                        state.customer.street_number || '',
                        state.customer.postal_code || '',
                        state.customer.city || ''
                    ].filter(part => part.trim() !== '');
                    elements.summaryAddress.textContent = addressParts.join(' ');
                }
            } else {
                elements.summaryAddressRow.style.display = 'none';
            }
        }

        // Vehicle summary
        if (elements.summaryVehicleType) {
            elements.summaryVehicleType.textContent = state.vehicle.type_name || '-';
        }
        if (elements.summaryVehicleBrand) {
            elements.summaryVehicleBrand.textContent = state.vehicle.brand_name || '-';
        }
        if (elements.summaryVehicleModel) {
            elements.summaryVehicleModel.textContent = state.vehicle.model_name || '-';
        }
        if (elements.summaryVehicleYear) {
            elements.summaryVehicleYear.textContent = state.vehicle.year || '-';
        }

        // Price
        if (elements.summaryPrice && state.service) {
            elements.summaryPrice.textContent = `${Math.floor(state.service.price)} zł`;
        }

        // Notes
        if (elements.summaryNotesContainer && elements.summaryNotes) {
            if (state.customer.notes) {
                elements.summaryNotesContainer.style.display = 'block';
                elements.summaryNotes.textContent = state.customer.notes;
            } else {
                elements.summaryNotesContainer.style.display = 'none';
            }
        }
    }

    function updateDebugInfo() {
        if (elements.debugAddress) {
            elements.debugAddress.textContent = state.customer.location_address || '-';
        }
        if (elements.debugPlaceId) {
            elements.debugPlaceId.textContent = state.customer.location_place_id || '-';
        }
        if (elements.debugLatitude) {
            elements.debugLatitude.textContent = state.customer.location_latitude || '-';
        }
        if (elements.debugLongitude) {
            elements.debugLongitude.textContent = state.customer.location_longitude || '-';
        }
        if (elements.debugMapInit) {
            elements.debugMapInit.textContent = state.mapInitialized ? '✓ TAK' : '✗ NIE';
            elements.debugMapInit.className = state.mapInitialized ?
                'font-medium ml-1 text-green-600' :
                'font-medium ml-1 text-red-600';
        }
        if (elements.debugMarker) {
            elements.debugMarker.textContent = state.marker ? '✓ TAK' : '✗ NIE';
            elements.debugMarker.className = state.marker ?
                'font-medium ml-1 text-green-600' :
                'font-medium ml-1 text-gray-400';
        }
    }

    // ===================================================================
    // VALIDATION
    // ===================================================================

    function validateStep() {
        state.errors = {};

        switch(state.step) {
            case 1:
                if (!state.service) {
                    state.errors.service = 'Wybierz usługę';
                    return false;
                }
                break;

            case 2:
                if (!state.date) {
                    state.errors.date = 'Wybierz datę';
                    showError(elements.dateError, state.errors.date);
                    return false;
                }
                if (!state.timeSlot) {
                    state.errors.timeSlot = 'Wybierz godzinę';
                    showError(elements.timeSlotError, state.errors.timeSlot);
                    return false;
                }
                break;

            case 3:
                return validateStep3();
        }

        return true;
    }

    function validateStep3() {
        state.errors = {};
        let valid = true;

        console.log('🔍 Validating Step 3...', {
            vehicle: state.vehicle,
            customer: state.customer
        });

        // First name
        if (!state.customer.first_name || state.customer.first_name.trim() === '') {
            state.errors.first_name = 'Imię jest wymagane';
            showError(document.getElementById('first-name-error'), state.errors.first_name);
            addClass(elements.firstNameInput, 'form-input-error');
            valid = false;
        } else {
            removeClass(elements.firstNameInput, 'form-input-error');
        }

        // Last name
        if (!state.customer.last_name || state.customer.last_name.trim() === '') {
            state.errors.last_name = 'Nazwisko jest wymagane';
            showError(document.getElementById('last-name-error'), state.errors.last_name);
            addClass(elements.lastNameInput, 'form-input-error');
            valid = false;
        } else {
            removeClass(elements.lastNameInput, 'form-input-error');
        }

        // Phone
        if (!state.customer.phone_e164 || state.customer.phone_e164.trim() === '') {
            state.errors.phone_e164 = 'Telefon jest wymagany';
            showError(document.getElementById('phone-error'), state.errors.phone_e164);
            addClass(elements.phoneInput, 'form-input-error');
            valid = false;
        } else if (!/^\+\d{1,3}\d{6,14}$/.test(state.customer.phone_e164)) {
            state.errors.phone_e164 = 'Nieprawidłowy format telefonu (wymagany: +48501234567)';
            showError(document.getElementById('phone-error'), state.errors.phone_e164);
            addClass(elements.phoneInput, 'form-input-error');
            valid = false;
        } else {
            removeClass(elements.phoneInput, 'form-input-error');
        }

        // Location
        if (!state.customer.location_address || state.customer.location_address.trim() === '') {
            state.errors.location_address = 'Lokalizacja usługi jest wymagana. Użyj autouzupełniania aby wybrać adres.';
            showError(elements.locationError, state.errors.location_address);
            addClass(elements.placeAutocomplete, 'error');
            valid = false;
        } else {
            removeClass(elements.placeAutocomplete, 'error');
        }

        // Postal code (optional)
        if (state.customer.postal_code && !/^\d{2}-\d{3}$/.test(state.customer.postal_code)) {
            state.errors.postal_code = 'Nieprawidłowy format kodu pocztowego (wymagany: 00-000)';
            showError(document.getElementById('postal-code-error'), state.errors.postal_code);
            valid = false;
        }

        // Vehicle validation
        if (!state.vehicle.type_id) {
            state.errors.vehicle_type = 'Wybierz typ pojazdu';
            showError(elements.vehicleTypeError, state.errors.vehicle_type);
            valid = false;
        } else {
            hideError(elements.vehicleTypeError);
        }

        if (!state.vehicle.brand_id && !state.vehicle.brand_name) {
            state.errors.brand = 'Wybierz markę pojazdu';
            showError(elements.brandError, state.errors.brand);
            valid = false;
        } else {
            hideError(elements.brandError);
        }

        if (!state.vehicle.model_id && !state.vehicle.model_name) {
            state.errors.model = 'Wybierz model pojazdu';
            showError(elements.modelError, state.errors.model);
            valid = false;
        } else {
            hideError(elements.modelError);
        }

        if (!state.vehicle.year) {
            state.errors.year = 'Wybierz rocznik pojazdu';
            showError(elements.yearError, state.errors.year);
            valid = false;
        } else {
            hideError(elements.yearError);
        }

        console.log('✅ Step 3 validation result:', valid, 'Errors:', state.errors);
        return valid;
    }

    function validateStepsUpTo(targetStep) {
        const currentStep = state.step;
        let valid = true;

        for (let i = 1; i <= targetStep; i++) {
            state.step = i;
            if (!validateStep()) {
                valid = false;
                break;
            }
        }

        state.step = currentStep;
        return valid;
    }

    function showError(element, message) {
        if (!element) return;
        element.textContent = message;
        element.style.display = 'block';
    }

    function hideError(element) {
        if (!element) return;
        element.style.display = 'none';
    }

    function addClass(element, className) {
        if (element) element.classList.add(className);
    }

    function removeClass(element, className) {
        if (element) element.classList.remove(className);
    }

    // ===================================================================
    // DATE & TIME SLOTS
    // ===================================================================

    function getMinDate() {
        const minDateTime = new Date();
        minDateTime.setHours(minDateTime.getHours() + state.advanceBookingHours);

        const localTime = new Date(minDateTime.getTime() - (minDateTime.getTimezoneOffset() * 60000));
        return localTime.toISOString().split('T')[0];
    }

    function handleDateChange(e) {
        state.date = e.target.value;
        state.timeSlot = null;

        hideError(elements.dateError);

        // Disable next button when date changes (until time slot is selected)
        const nextBtn = document.getElementById('step2-next-btn');
        if (nextBtn) {
            nextBtn.disabled = true;
        }

        if (state.date) {
            // Show time slots section
            const timeSlotsSection = document.getElementById('time-slots-section');
            if (timeSlotsSection) {
                timeSlotsSection.style.display = 'block';
            }

            fetchAvailableSlots();
        } else {
            // Hide time slots section if date is cleared
            const timeSlotsSection = document.getElementById('time-slots-section');
            if (timeSlotsSection) {
                timeSlotsSection.style.display = 'none';
            }
        }

        updateSidebar();
    }

    async function fetchAvailableSlots() {
        if (!state.date) return;

        state.loading = true;
        state.availableSlots = [];

        // Show loading
        if (elements.timeSlotsLoading) {
            elements.timeSlotsLoading.style.display = 'flex';
        }
        if (elements.timeSlotsContainer) {
            elements.timeSlotsContainer.innerHTML = '';
        }
        if (elements.timeSlotsEmpty) {
            elements.timeSlotsEmpty.style.display = 'none';
        }
        if (elements.timeSlotsError) {
            elements.timeSlotsError.style.display = 'none';
        }

        try {
            const response = await fetch('/booking/available-slots', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    service_id: state.service.id,
                    date: state.date
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            state.availableSlots = data.slots || [];

            // Hide loading
            if (elements.timeSlotsLoading) {
                elements.timeSlotsLoading.style.display = 'none';
            }

            if (state.availableSlots.length === 0) {
                // Show empty state
                if (elements.timeSlotsEmpty) {
                    elements.timeSlotsEmpty.style.display = 'block';
                }
            } else {
                // Render time slots
                renderTimeSlots();
            }

        } catch (error) {
            console.error('Error fetching slots:', error);
            state.loading = false;

            // Hide loading
            if (elements.timeSlotsLoading) {
                elements.timeSlotsLoading.style.display = 'none';
            }

            // Show error
            if (elements.timeSlotsError) {
                elements.timeSlotsError.textContent = 'Nie udało się pobrać dostępnych terminów';
                elements.timeSlotsError.style.display = 'block';
            }
        } finally {
            state.loading = false;
        }
    }

    function renderTimeSlots() {
        if (!elements.timeSlotsContainer) return;

        elements.timeSlotsContainer.innerHTML = '';

        state.availableSlots.forEach(slot => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'time-slot';
            button.setAttribute('role', 'radio');
            button.setAttribute('aria-checked', 'false');

            button.innerHTML = `
                <span class="block text-lg font-semibold">${slot.start}</span>
                <span class="block text-xs text-gray-500">do ${slot.end}</span>
            `;

            button.addEventListener('click', () => selectTimeSlot(slot));

            elements.timeSlotsContainer.appendChild(button);
        });

        // Show container
        elements.timeSlotsContainer.style.display = 'grid';
    }

    function selectTimeSlot(slot) {
        state.timeSlot = slot;
        hideError(elements.timeSlotError);

        // Update UI - highlight selected slot
        document.querySelectorAll('.time-slot').forEach(btn => {
            btn.classList.remove('time-slot-selected');
            btn.setAttribute('aria-checked', 'false');
        });

        // Find and highlight clicked button
        const buttons = elements.timeSlotsContainer.querySelectorAll('.time-slot');
        buttons.forEach((btn, index) => {
            if (state.availableSlots[index] === slot) {
                btn.classList.add('time-slot-selected');
                btn.setAttribute('aria-checked', 'true');
            }
        });

        // Enable next button
        const nextBtn = document.querySelector('[data-step="2"] [data-next-step]');
        if (nextBtn) {
            nextBtn.disabled = false;
        }

        updateSidebar();
    }

    // ===================================================================
    // GOOGLE MAPS INITIALIZATION
    // ===================================================================

    function initializeGoogleMaps() {
        console.log('🗺️ Initializing Google Maps...');

        // Listen for API load
        window.addEventListener('google-maps-loaded', () => {
            console.log('✅ Google Maps API loaded');
            setupPlaceAutocomplete();
        });

        // If already loaded
        if (typeof google !== 'undefined' && google.maps && google.maps.places) {
            console.log('✅ Google Maps API already loaded');
            setupPlaceAutocomplete();
        }
    }

    async function setupPlaceAutocomplete() {
        console.log('🔧 Setting up Place Autocomplete (Modern API)...');

        try {
            if (!elements.placeAutocomplete) {
                console.error('❌ Autocomplete input element not found');
                return;
            }

            console.log('✅ Autocomplete input found');

            // Wait for Google Maps API
            if (typeof google === 'undefined' || !google.maps) {
                console.log('⏳ Waiting for Google Maps API...');
                await new Promise(resolve => {
                    window.addEventListener('google-maps-loaded', resolve, { once: true });
                });
            }

            // Import Places library
            console.log('📦 Importing Places library...');
            const { Autocomplete } = await google.maps.importLibrary("places");
            console.log('✅ Places library loaded');

            const autocompleteOptions = {
                fields: ['place_id', 'geometry', 'formatted_address', 'address_components', 'name'],
                types: ['address']
            };

            if (state.mapCountryCode) {
                autocompleteOptions.componentRestrictions = { country: state.mapCountryCode };
            }

            // Create Autocomplete instance
            const autocomplete = new Autocomplete(elements.placeAutocomplete, autocompleteOptions);

            console.log('✅ Autocomplete instance created');

            // Listen for place selection
            autocomplete.addListener('place_changed', () => {
                console.log('🎯 place_changed event fired!');

                const place = autocomplete.getPlace();

                if (!place) {
                    console.error('❌ No place returned');
                    return;
                }

                // Check if place has geometry
                if (!place.geometry || !place.geometry.location) {
                    console.error('❌ No geometry/location in place');
                    state.errors.location_address = 'Wybierz adres z listy podpowiedzi.';
                    showError(elements.locationError, state.errors.location_address);
                    return;
                }

                console.log('✅ Place data:', {
                    address: place.formatted_address,
                    lat: place.geometry.location.lat(),
                    lng: place.geometry.location.lng()
                });

                // Clear errors
                hideError(elements.locationError);
                removeClass(elements.placeAutocomplete, 'form-input-error');

                // Extract coordinates
                const lat = place.geometry.location.lat();
                const lng = place.geometry.location.lng();

                // Update state
                state.customer.location_address = place.formatted_address || '';
                state.customer.location_latitude = parseFloat(lat);
                state.customer.location_longitude = parseFloat(lng);
                state.customer.location_place_id = place.place_id || '';

                // Parse address components
                const components = {};
                if (place.address_components) {
                    place.address_components.forEach(component => {
                        const type = component.types[0];
                        components[type] = {
                            long_name: component.long_name || '',
                            short_name: component.short_name || ''
                        };
                    });
                }
                state.customer.location_components = components;

                // Auto-fill address fields
                state.customer.street_name = components.route?.long_name || '';
                state.customer.street_number = components.street_number?.long_name || '';
                state.customer.city = components.locality?.long_name || components.postal_town?.long_name || '';
                state.customer.postal_code = components.postal_code?.long_name || '';

                // Update input fields
                if (elements.streetNameInput) elements.streetNameInput.value = state.customer.street_name;
                if (elements.streetNumberInput) elements.streetNumberInput.value = state.customer.street_number;
                if (elements.cityInput) elements.cityInput.value = state.customer.city;
                if (elements.postalCodeInput) elements.postalCodeInput.value = state.customer.postal_code;

                console.log('✅ Location saved to state');

                // Update location display
                if (elements.locationAddressDisplay) {
                    elements.locationAddressDisplay.style.display = 'block';
                }
                if (elements.locationAddressText) {
                    elements.locationAddressText.textContent = state.customer.location_address;
                }

                // Update map
                if (state.mapInitialized && state.map) {
                    console.log('🗺️ Updating map marker...');
                    updateMapMarker(lat, lng);
                } else {
                    console.log('⏳ Map not yet initialized');
                }

                // Update debug info
                updateDebugInfo();
            });

            console.log('✅ Place Autocomplete ready');

        } catch (error) {
            console.error('❌ Error setting up autocomplete:', error);
        }
    }

    async function initializeMap() {
        if (state.mapInitialized) {
            console.log('Map already initialized');
            return;
        }

        console.log('🗺️ Initializing map...');

        // Show loading
        if (elements.mapLoadingOverlay) {
            elements.mapLoadingOverlay.style.display = 'flex';
        }

        try {
            // Wait for Google Maps API
            if (typeof google === 'undefined' || !google.maps) {
                console.log('⏳ Waiting for Google Maps API...');
                await new Promise(resolve => {
                    window.addEventListener('google-maps-loaded', resolve, { once: true });
                });
            }

            // Load libraries
            const { Map } = await google.maps.importLibrary("maps");
            const { Marker } = await google.maps.importLibrary("marker");

            if (!elements.locationMap) {
                console.error('❌ Map element not found');
                return;
            }

            // Create map
            const mapOptions = {
                center: { lat: state.mapDefaultLat, lng: state.mapDefaultLng },
                zoom: state.mapDefaultZoom,
                mapTypeControl: false,
                fullscreenControl: true,
                streetViewControl: false,
                zoomControl: true,
                gestureHandling: 'cooperative'
            };

            if (state.mapId) {
                mapOptions.mapId = state.mapId;
            }

            state.map = new Map(elements.locationMap, mapOptions);

            state.MarkerConstructor = Marker;
            state.mapInitialized = true;

            console.log('✅ Map initialized');

            // Hide loading
            if (elements.mapLoadingOverlay) {
                elements.mapLoadingOverlay.style.display = 'none';
            }

            // If location already selected, show marker
            if (state.customer.location_latitude && state.customer.location_longitude) {
                updateMapMarker(state.customer.location_latitude, state.customer.location_longitude);
            }

            updateDebugInfo();

        } catch (error) {
            console.error('❌ Error initializing map:', error);
            if (elements.mapLoadingOverlay) {
                elements.mapLoadingOverlay.style.display = 'none';
            }
        }
    }

    function updateMapMarker(lat, lng) {
        console.log('📍 Updating map marker:', { lat, lng });

        if (!state.map) {
            console.log('⚠️ Map not initialized yet');
            return;
        }

        try {
            // Trigger resize
            google.maps.event.trigger(state.map, 'resize');

            // Center map
            state.map.setCenter({ lat, lng });
            state.map.setZoom(17);

            // Remove old marker
            if (state.marker) {
                state.marker.setMap(null);
            }

            // Add new marker
            state.marker = new google.maps.Marker({
                map: state.map,
                position: { lat, lng },
                animation: google.maps.Animation.DROP,
                title: state.customer.location_address || 'Wybrana lokalizacja'
            });

            console.log('✅ Map marker updated');
            updateDebugInfo();

        } catch (error) {
            console.error('❌ Error updating marker:', error);
        }
    }

    // ===================================================================
    // FORM SUBMISSION
    // ===================================================================

    function handleFormSubmit(e) {
        // Update hidden inputs
        if (elements.hiddenServiceId) elements.hiddenServiceId.value = state.service.id;
        if (elements.hiddenDate) elements.hiddenDate.value = state.date || '';
        if (elements.hiddenStartTime) elements.hiddenStartTime.value = state.timeSlot?.start || '';
        if (elements.hiddenEndTime) elements.hiddenEndTime.value = state.timeSlot?.end || '';
        if (elements.hiddenNotes) elements.hiddenNotes.value = state.customer.notes || '';

        if (elements.hiddenFirstName) elements.hiddenFirstName.value = state.customer.first_name;
        if (elements.hiddenLastName) elements.hiddenLastName.value = state.customer.last_name;
        if (elements.hiddenPhone) elements.hiddenPhone.value = state.customer.phone_e164;

        if (elements.hiddenLocationAddress) elements.hiddenLocationAddress.value = state.customer.location_address || '';
        if (elements.hiddenLocationLatitude) elements.hiddenLocationLatitude.value = state.customer.location_latitude || '';
        if (elements.hiddenLocationLongitude) elements.hiddenLocationLongitude.value = state.customer.location_longitude || '';
        if (elements.hiddenLocationPlaceId) elements.hiddenLocationPlaceId.value = state.customer.location_place_id || '';
        if (elements.hiddenLocationComponents) elements.hiddenLocationComponents.value = JSON.stringify(state.customer.location_components);

        if (elements.hiddenStreetName) elements.hiddenStreetName.value = state.customer.street_name || '';
        if (elements.hiddenStreetNumber) elements.hiddenStreetNumber.value = state.customer.street_number || '';
        if (elements.hiddenCity) elements.hiddenCity.value = state.customer.city || '';
        if (elements.hiddenPostalCode) elements.hiddenPostalCode.value = state.customer.postal_code || '';
        if (elements.hiddenAccessNotes) elements.hiddenAccessNotes.value = state.customer.access_notes || '';

        // Vehicle data
        if (elements.hiddenVehicleTypeId) elements.hiddenVehicleTypeId.value = state.vehicle.type_id || '';
        if (elements.hiddenCarBrandId) elements.hiddenCarBrandId.value = state.vehicle.brand_id || '';
        if (elements.hiddenCarBrandName) elements.hiddenCarBrandName.value = state.vehicle.brand_name || '';
        if (elements.hiddenCarModelId) elements.hiddenCarModelId.value = state.vehicle.model_id || '';
        if (elements.hiddenCarModelName) elements.hiddenCarModelName.value = state.vehicle.model_name || '';
        if (elements.hiddenVehicleYear) elements.hiddenVehicleYear.value = state.vehicle.year || '';

        console.log('✅ Form data prepared for submission');
        // Form will submit normally
    }

    // ===================================================================
    // VEHICLE MANAGEMENT
    // ===================================================================

    /**
     * Fetch vehicle types from API and render cards
     */
    async function fetchVehicleTypes() {
        try {
            const response = await fetch('/api/vehicle-types');
            const data = await response.json();

            if (data.success) {
                state.vehicleTypes = data.data;
                renderVehicleTypeCards();
                console.log('✅ Vehicle types loaded:', state.vehicleTypes.length);

                // Apply saved vehicle data if exists
                if (state.savedVehicleData) {
                    applySavedVehicleData();
                }
            }
        } catch (error) {
            console.error('❌ Error fetching vehicle types:', error);
        }
    }

    /**
     * Render vehicle type selection cards with SVG icons
     */
    function renderVehicleTypeCards() {
        if (!elements.vehicleTypesContainer) return;

        elements.vehicleTypesContainer.innerHTML = '';

        // SVG icons for each vehicle type
        const icons = {
            city_car: '<svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>',
            small_car: '<svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>',
            medium_car: '<svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>',
            large_car: '<svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>',
            delivery_van: '<svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>'
        };

        state.vehicleTypes.forEach(type => {
            const card = document.createElement('button');
            card.type = 'button';
            card.className = 'vehicle-type-card';
            card.dataset.typeId = type.id;
            card.dataset.typeName = type.name;
            card.dataset.typeSlug = type.slug;

            card.innerHTML = `
                ${icons[type.slug] || icons.medium_car}
                <span class="name">${type.name}</span>
            `;

            card.addEventListener('click', () => selectVehicleType(type));
            elements.vehicleTypesContainer.appendChild(card);
        });
    }

    /**
     * Handle vehicle type selection
     */
    function selectVehicleType(type) {
        console.log('🚗 Vehicle type selected:', type.name);

        // Update state
        state.vehicle.type_id = type.id;
        state.vehicle.type_name = type.name;
        state.vehicle.type_slug = type.slug;

        // Clear errors
        hideError(elements.vehicleTypeError);

        // Update UI - highlight selected card
        document.querySelectorAll('.vehicle-type-card').forEach(card => {
            card.classList.remove('selected');
        });
        const selectedCard = document.querySelector(`[data-type-id="${type.id}"]`);
        if (selectedCard) {
            selectedCard.classList.add('selected');
        }

        // Show vehicle details section
        if (elements.vehicleDetailsSection) {
            elements.vehicleDetailsSection.style.display = 'block';
        }

        // Reset brand/model/year when type changes
        state.vehicle.brand_id = null;
        state.vehicle.brand_name = '';
        state.vehicle.model_id = null;
        state.vehicle.model_name = '';
        if (elements.carBrandSelect) elements.carBrandSelect.value = '';
        if (elements.carModelSelect) {
            elements.carModelSelect.value = '';
            elements.carModelSelect.disabled = true;
        }

        // Fetch brands for this vehicle type
        fetchCarBrands();
    }

    /**
     * Fetch all car brands (no vehicle type filtering)
     */
    async function fetchCarBrands() {
        try {
            const url = `/api/car-brands`;
            const response = await fetch(url);
            const data = await response.json();

            if (data.success) {
                state.carBrands = data.data;
                populateBrandSelect();
                console.log('✅ Brands loaded:', state.carBrands.length);
            }
        } catch (error) {
            console.error('❌ Error fetching brands:', error);
        }
    }

    /**
     * Populate brand select dropdown
     */
    function populateBrandSelect() {
        if (!elements.carBrandSelect) return;

        elements.carBrandSelect.innerHTML = '<option value="">Wybierz markę</option>';

        state.carBrands.forEach(brand => {
            const option = document.createElement('option');
            option.value = brand.id;
            option.textContent = brand.name;
            elements.carBrandSelect.appendChild(option);
        });
    }

    /**
     * Handle brand selection change
     */
    function handleBrandChange(e) {
        const brandId = parseInt(e.target.value);

        if (!brandId) {
            state.vehicle.brand_id = null;
            state.vehicle.brand_name = '';
            elements.carModelSelect.innerHTML = '<option value="">Najpierw wybierz markę</option>';
            elements.carModelSelect.disabled = true;
            return;
        }

        const selectedBrand = state.carBrands.find(b => b.id === brandId);
        state.vehicle.brand_id = brandId;
        state.vehicle.brand_name = selectedBrand?.name || '';

        // Clear model
        state.vehicle.model_id = null;
        state.vehicle.model_name = '';
        if (elements.carModelSelect) elements.carModelSelect.value = '';

        hideError(elements.brandError);

        // Fetch models for this brand
        fetchCarModels();
    }

    /**
     * Fetch car models filtered by brand only (no vehicle type filtering)
     */
    async function fetchCarModels() {
        if (!state.vehicle.brand_id) return;

        try {
            const url = `/api/car-models?car_brand_id=${state.vehicle.brand_id}`;
            const response = await fetch(url);
            const data = await response.json();

            if (data.success) {
                state.carModels = data.data;
                populateModelSelect();
                console.log('✅ Models loaded:', state.carModels.length);
            }
        } catch (error) {
            console.error('❌ Error fetching models:', error);
        }
    }

    /**
     * Populate model select dropdown
     */
    function populateModelSelect() {
        if (!elements.carModelSelect) return;

        elements.carModelSelect.innerHTML = '<option value="">Wybierz model</option>';
        elements.carModelSelect.disabled = false;

        state.carModels.forEach(model => {
            const option = document.createElement('option');
            option.value = model.id;
            option.textContent = model.name;
            elements.carModelSelect.appendChild(option);
        });
    }

    /**
     * Handle model selection change
     */
    function handleModelChange(e) {
        const modelId = parseInt(e.target.value);

        if (!modelId) {
            state.vehicle.model_id = null;
            state.vehicle.model_name = '';
            return;
        }

        const selectedModel = state.carModels.find(m => m.id === modelId);
        state.vehicle.model_id = modelId;
        state.vehicle.model_name = selectedModel?.name || '';

        hideError(elements.modelError);
    }

    /**
     * Handle year selection change
     */
    function handleYearChange(e) {
        const year = parseInt(e.target.value);

        state.vehicle.year = year || null;

        if (year) {
            hideError(elements.yearError);
        }
    }

    /**
     * Populate vehicle year dropdown (1990 to current year)
     */
    function populateVehicleYears() {
        if (!elements.vehicleYearSelect) return;

        const currentYear = new Date().getFullYear();
        const startYear = 1990;

        elements.vehicleYearSelect.innerHTML = '<option value="">Wybierz rok</option>';

        for (let year = currentYear; year >= startYear; year--) {
            const option = document.createElement('option');
            option.value = year;
            option.textContent = year;
            elements.vehicleYearSelect.appendChild(option);
        }
    }

    // ===================================================================
    // START
    // ===================================================================

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
