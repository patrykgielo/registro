<?php

declare(strict_types=1);

namespace App\Http\Requests\Checkout;

use App\Rules\ValidPolishNIP;
use App\Rules\ValidPolishPESEL;
use App\Rules\ValidPolishREGON;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $settings = app(SettingsManager::class);

        return [
            // === COMMON (all customer types) ===
            'customer_type' => ['required', Rule::in(['natural_person', 'business'])],
            // Only whatever the tenant currently allows — NOT a static ['online','offline']
            // list. A tenant with offline disabled must not accept it even if a stale/
            // tampered client still submits it.
            'settlement_method' => ['required', Rule::in($settings->availableSettlementMethods())],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:20'],
            'terms_accepted' => ['required', 'accepted'],
            'rodo_accepted' => ['required', 'accepted'],
            'withdrawal_exclusion_accepted' => ['required', 'accepted'],
            'save_to_profile' => ['nullable', 'boolean'],

            // === NATURAL PERSON (required_if:customer_type,natural_person) ===
            'customer_first_name' => ['required_if:customer_type,natural_person', 'nullable', 'string', 'max:100'],
            'customer_last_name' => ['required_if:customer_type,natural_person', 'nullable', 'string', 'max:100'],
            // Mandatory only when the tenant opted in (checkout.pesel_required, default
            // false — see SettingsManager::isPeselRequired()). Whether required or not,
            // a value the customer DOES submit is always checksum-validated.
            'customer_pesel' => [
                Rule::requiredIf(fn (): bool => $this->input('customer_type') === 'natural_person' && $settings->isPeselRequired()),
                'nullable',
                new ValidPolishPESEL,
            ],
            'customer_street' => ['required_if:customer_type,natural_person', 'nullable', 'string', 'max:255'],
            'customer_building' => ['required_if:customer_type,natural_person', 'nullable', 'string', 'max:20'],
            'customer_apartment' => ['nullable', 'string', 'max:20'],
            'customer_city' => ['required_if:customer_type,natural_person', 'nullable', 'string', 'max:100'],
            'customer_postal_code' => ['required_if:customer_type,natural_person', 'nullable', 'string', 'max:10'],

            // === BUSINESS (required_if:customer_type,business) ===
            // For business: invoice IS always requested — company name, NIP, REGON, address required
            'invoice_company_name' => ['required_if:customer_type,business', 'nullable', 'string', 'max:255'],
            'invoice_nip' => ['required_if:customer_type,business', 'nullable', new ValidPolishNIP],
            'company_regon' => ['required_if:customer_type,business', 'nullable', new ValidPolishREGON],
            'company_krs' => ['nullable', 'string', 'max:20'],
            'company_contact_name' => ['required_if:customer_type,business', 'nullable', 'string', 'max:255'],
            'signatory_id_number' => ['required_if:customer_type,business', 'nullable', 'string', 'max:20'],
            'pickup_person_name' => ['nullable', 'string', 'max:255'],
            'pickup_person_id_number' => ['nullable', 'string', 'max:20', 'required_with:pickup_person_name'],
            'invoice_street' => ['required_if:customer_type,business', 'nullable', 'string', 'max:255'],
            'invoice_street_number' => ['required_if:customer_type,business', 'nullable', 'string', 'max:20'],
            'invoice_postal_code' => ['required_if:customer_type,business', 'nullable', 'string', 'max:10'],
            'invoice_city' => ['required_if:customer_type,business', 'nullable', 'string', 'max:100'],

            // === INVOICE (for natural_person — optional; for business always true in service) ===
            'invoice_requested' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_type.required' => 'Proszę wybrać typ klienta.',
            'customer_type.in' => 'Nieprawidłowy typ klienta.',
            'settlement_method.required' => 'Proszę wybrać sposób rozliczenia.',
            'settlement_method.in' => 'Wybrany sposób rozliczenia jest niedostępny.',
            'customer_email.required' => 'Adres email jest wymagany.',
            'customer_email.email' => 'Podaj prawidłowy adres email.',
            'customer_phone.required' => 'Numer telefonu jest wymagany.',
            'terms_accepted.required' => 'Akceptacja regulaminu jest wymagana.',
            'terms_accepted.accepted' => 'Musisz zaakceptować regulamin.',
            'rodo_accepted.required' => 'Akceptacja polityki prywatności (RODO) jest wymagana.',
            'rodo_accepted.accepted' => 'Musisz zapoznać się z polityką prywatności.',
            'withdrawal_exclusion_accepted.required' => 'Potwierdzenie wyłączenia prawa odstąpienia jest wymagane.',
            'withdrawal_exclusion_accepted.accepted' => 'Musisz przyjąć do wiadomości wyłączenie prawa odstąpienia od umowy.',

            // Natural person
            'customer_first_name.required_if' => 'Imię jest wymagane dla osoby fizycznej.',
            'customer_last_name.required_if' => 'Nazwisko jest wymagane dla osoby fizycznej.',
            'customer_pesel.required' => 'PESEL jest wymagany dla osoby fizycznej.',
            'customer_street.required_if' => 'Ulica jest wymagana.',
            'customer_building.required_if' => 'Numer budynku jest wymagany.',
            'customer_city.required_if' => 'Miasto jest wymagane.',
            'customer_postal_code.required_if' => 'Kod pocztowy jest wymagany.',

            // Business
            'invoice_company_name.required_if' => 'Nazwa firmy jest wymagana.',
            'invoice_nip.required_if' => 'NIP jest wymagany dla firmy.',
            'company_regon.required_if' => 'REGON jest wymagany dla firmy.',
            'company_contact_name.required_if' => 'Imię i nazwisko osoby podpisującej umowę jest wymagane.',
            'signatory_id_number.required_if' => 'PESEL lub numer dowodu osoby podpisującej jest wymagany.',
            'pickup_person_id_number.required_with' => 'Podaj numer dowodu osoby odbierającej sprzęt.',
            'invoice_street.required_if' => 'Adres siedziby firmy (ulica) jest wymagany.',
            'invoice_street_number.required_if' => 'Numer budynku siedziby firmy jest wymagany.',
            'invoice_postal_code.required_if' => 'Kod pocztowy siedziby firmy jest wymagany.',
            'invoice_city.required_if' => 'Miasto siedziby firmy jest wymagane.',
        ];
    }
}
