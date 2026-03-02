# Audit Logging - Complete Guide

## Table of Contents

1. [What is Audit Logging?](#what-is-audit-logging)
2. [Why is it Needed for GDPR?](#why-is-it-needed-for-gdpr)
3. [How it Works in Registro](#how-it-works-in-registro)
4. [Practical Use Cases](#practical-use-cases)
5. [How to View Audit Logs](#how-to-view-audit-logs)
6. [Access Control Recommendations](#access-control-recommendations)
7. [Implementation Details](#implementation-details)

---

## What is Audit Logging?

**Audit Logging** is a **permanent record of "who did what, when, and from where"** in your application.

Think of it like a **security camera for your database** - it records every important action:
- When a user logs in or out
- When personal data is created, modified, or deleted
- When data is exported (e.g., user downloads their GDPR data)
- When consents are granted or withdrawn

**Example audit log entry:**
```
User: admin@registro.com (IP: 192.168.1.100)
Action: Updated User #123
When: 2025-12-28 15:30:45
What changed:
  - Phone: 501234567 → 509876543
  - Email marketing consent: Not set → Granted
```

---

## Why is it Needed for GDPR?

GDPR (General Data Protection Regulation) requires businesses to:

### 1. **Accountability (Art. 5.2 GDPR)**
You must **prove** that you handle personal data properly.

**Without audit logs:**
- Data protection authority: "How do you ensure only authorized staff access customer data?"
- You: "We trust our staff..." ❌

**With audit logs:**
- Data protection authority: "How do you ensure only authorized staff access customer data?"
- You: "We log every data access. Here are the records." ✅

### 2. **Data Subject Access Requests (Art. 15 GDPR)**
Customers can ask: "What data do you have about me and who accessed it?"

**Example scenario:**
Customer: "Did anyone at your company change my phone number on December 15th?"

**With audit logs, you can answer:**
"Yes, admin user 'Jan Kowalski' updated your phone number on 2025-12-15 at 14:23 from IP 192.168.1.50. Old value: 501234567, New value: 509876543."

### 3. **Breach Investigation (Art. 33 GDPR)**
If there's a security incident, you must report it **within 72 hours**.

**Example scenario:**
Someone claims unauthorized access to customer data.

**With audit logs, you can:**
- See exactly who accessed what data and when
- Identify if it was legitimate staff activity or a breach
- Provide evidence to authorities
- Notify affected customers with specific details

### 4. **Retention Compliance (Art. 5.1(e) GDPR)**
Track when data was deleted to prove you don't keep data longer than necessary.

**Example:**
- User account deleted on 2025-12-20
- Audit log shows: Account anonymized, personal data erased
- Proof that you comply with "right to be forgotten"

---

## How it Works in Registro

### Automatic Logging (via `Auditable` Trait)

Models with the `Auditable` trait automatically log changes:

**User Model** (app/Models/User.php):
```php
use Auditable;

protected array $auditInclude = [
    'first_name',
    'last_name',
    'email',
    'phone_e164',
    'sms_consent_given_at',
    'email_marketing_consent_at',
];
```

**What gets logged:**
- ✅ Personal data changes (first_name, last_name, email, phone)
- ✅ Consent changes (SMS consent, email marketing consent)
- ❌ Passwords (excluded for security)
- ❌ Internal timestamps (created_at, updated_at)

### Authentication Events (via `LogAuthenticationEvents` Listener)

Automatically logs:
- ✅ Successful logins (with IP, user agent)
- ✅ Failed login attempts (security monitoring)
- ✅ Logouts
- ✅ Password resets

### Manual Logging (for Custom Events)

For custom events not tied to model changes:

```php
// Log data export
AuditLog::log(
    event: AuditLog::EVENT_EXPORTED,
    model: $user,
    metadata: ['export_type' => 'GDPR full data export']
);

// Log consent granted
AuditLog::logEvent(
    event: AuditLog::EVENT_CONSENT_GRANTED,
    userId: $user->id,
    metadata: ['consent_type' => 'SMS marketing']
);
```

---

## Practical Use Cases

### 1. Customer Support Scenarios

**Scenario:** Customer calls: "Someone changed my email address and I didn't do it!"

**With Audit Logs:**
1. Search for User #123
2. Filter by "updated" event
3. Find the change:
   - **When:** 2025-12-20 10:30
   - **Who:** admin@registro.com
   - **What:** Email changed from old@example.com → new@example.com
   - **Where:** IP 192.168.1.50 (office network)
4. Investigate: Was this a legitimate support request or unauthorized access?

---

### 2. Security Monitoring

**Scenario:** Multiple failed login attempts on admin accounts.

**With Audit Logs:**
1. Filter by "login_failed" event
2. See:
   - 15 failed attempts on admin@registro.com
   - All from IP 185.220.101.50 (suspicious foreign IP)
   - Time: 2025-12-20 03:00-03:15 (middle of the night)
3. **Action:** Block IP, enable 2FA, notify admin

---

### 3. GDPR Compliance Audits

**Scenario:** Data protection authority audits your business.

**Questions they might ask:**

**Q: "How do you ensure only authorized staff access customer data?"**
**A:** "We log every data access. Here's a report of all customer data access in the last 6 months."

**Q: "Can you prove you delete data when customers request it?"**
**A:** "Yes, here are audit logs showing account anonymization events."

**Q: "How do you track consent changes?"**
**A:** "Every consent grant/withdrawal is logged with timestamp and IP address."

---

### 4. Insider Threat Detection

**Scenario:** Suspicion that an employee accessed customer data without authorization.

**With Audit Logs:**
1. Filter by user: employee@registro.com
2. See all data access:
   - Normal: Accessing customers assigned to them
   - **Suspicious:** Accessing competitor's customer data at 11 PM
3. **Action:** Investigate, potential disciplinary action

---

### 5. Data Breach Response

**Scenario:** Ransomware attack. Need to report to GDPR authorities within 72 hours.

**With Audit Logs:**
1. See exactly what data was accessed before encryption
2. Identify affected customers
3. Provide detailed timeline to authorities:
   - "Breach occurred: 2025-12-20 02:30"
   - "Last legitimate access: 2025-12-19 18:45"
   - "Affected records: 1,234 customers"
4. Notify customers with specific details

---

## How to View Audit Logs

### Current Status: NO ADMIN PANEL ❌

**Problem:** Audit logs are stored in database but there's NO way to view them in Filament admin panel.

### Recommendation: CREATE FILAMENT RESOURCE ✅

You **SHOULD** create a Filament resource for audit logs because:

1. **Compliance Requirement:** GDPR requires you to be able to access audit logs quickly
2. **Security Monitoring:** Admins need to investigate suspicious activity
3. **Customer Requests:** Respond to "who accessed my data?" questions
4. **Business Need:** Support teams need to troubleshoot data changes

### Proposed Implementation

See: [Implementation Guide](./implementation-guide.md) for complete step-by-step instructions.

**Features to include:**
- ✅ Read-only resource (no editing/deleting audit logs!)
- ✅ Advanced filtering (by user, event type, date range, model type)
- ✅ Search (by IP address, user email, model ID)
- ✅ Export to CSV/PDF (for compliance reports)
- ✅ Detailed view of old/new values (JSON diff)
- ✅ Role-based access (only admins)

---

## Access Control Recommendations

### Who Should Have Access?

**Option 1: Admin-Only (Recommended for v1)**
```php
// app/Filament/Resources/AuditLogResource.php
public static function canViewAny(): bool
{
    return auth()->user()?->hasRole('admin');
}
```

**Reasoning:**
- Audit logs contain sensitive information (who accessed what)
- Staff shouldn't see what admins are doing (reduces tampering risk)
- Compliance auditors need clean chain of custody

**Who can access:**
- ✅ Admins (full access)
- ❌ Staff (no access)
- ❌ Customers (no access - use separate "My Activity" page)

---

**Option 2: Tiered Access (Advanced - Future Enhancement)**
```php
public static function canViewAny(): bool
{
    $user = auth()->user();

    // Admins see everything
    if ($user->hasRole('admin')) {
        return true;
    }

    // Staff see only their own actions
    if ($user->hasRole('staff')) {
        return true; // Limited by scope in table query
    }

    return false;
}

public static function getEloquentQuery(): Builder
{
    $query = parent::getEloquentQuery();

    // Staff see only their own actions
    if (!auth()->user()->hasRole('admin')) {
        $query->where('user_id', auth()->id());
    }

    return $query;
}
```

**Who can access:**
- ✅ Admins (see all audit logs)
- ✅ Staff (see only their own actions)
- ❌ Customers (no access)

---

### Customer Access: Separate "My Activity" Page

**DON'T** give customers access to the admin audit log resource.

**DO** create a separate customer-facing page:

**Location:** `/profile/activity`

**Features:**
- Show only their own activity (logins, data changes, consents)
- Simplified view (no technical details like IP, user agent)
- GDPR-friendly language: "Your data was updated on..."

**Example:**
```
Your Account Activity

Dec 20, 2025 - You logged in
Dec 19, 2025 - You updated your phone number
Dec 15, 2025 - You granted SMS marketing consent
Dec 10, 2025 - Admin 'Jan Kowalski' updated your appointment time
```

---

## Implementation Details

### Database Schema

**Table:** `audit_logs`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | bigint (nullable) | Who performed the action |
| auditable_type | string | Model class (e.g., App\Models\User) |
| auditable_id | bigint | Model ID |
| event | string | Action type (created, updated, deleted, login, etc.) |
| old_values | json (nullable) | Before values |
| new_values | json (nullable) | After values |
| ip_address | string (nullable) | Request IP |
| user_agent | text (nullable) | Browser/client info |
| url | string (nullable) | Request URL |
| metadata | json (nullable) | Additional context |
| created_at | timestamp | When event occurred |

**Indexes:**
- `(auditable_type, auditable_id)` - Fast lookup by model
- `event` - Filter by event type
- `user_id` - Filter by user
- `created_at` - Date range queries

### Event Types

| Event | Description | Example |
|-------|-------------|---------|
| `created` | New record | New customer registered |
| `updated` | Record modified | Customer changed phone number |
| `deleted` | Record deleted | Appointment cancelled |
| `exported` | Data exported | GDPR data export |
| `login` | Successful login | User logged in |
| `logout` | Logout | User logged out |
| `login_failed` | Failed login attempt | Wrong password |
| `consent_granted` | Consent given | SMS marketing consent granted |
| `consent_withdrawn` | Consent withdrawn | Email marketing consent withdrawn |
| `password_changed` | Password changed | User changed password |
| `password_reset` | Password reset | User reset password via email |
| `account_anonymized` | Account anonymized | GDPR deletion request processed |

### Models Using Audit Logging

**Currently enabled:**
1. **User** - All personal data changes, consents
2. **Appointment** - Booking creation/changes/cancellation

**Should be enabled in future:**
- UserAddress (when created)
- UserVehicle (when created)
- Any model storing personal data

---

## Data Retention

### How Long to Keep Audit Logs?

**GDPR Perspective:**
- Logs containing personal data should be kept **no longer than necessary**
- Typical retention: **6-12 months** for operational logs
- **3-7 years** for compliance/legal purposes

**Recommendation:**
```php
// config/audit.php (create this file)
return [
    'retention_days' => env('AUDIT_RETENTION_DAYS', 365), // 1 year
];

// Schedule cleanup (app/Console/Kernel.php)
$schedule->command('audit:cleanup')->daily();
```

**Cleanup command:**
```bash
php artisan make:command CleanupOldAuditLogs
```

**What to delete:**
- Logs older than retention period
- **Exception:** Keep logs for deleted users (legal requirement)
- **Exception:** Keep logs flagged for investigation

---

## Next Steps

### Phase 1: Create Admin Panel (PRIORITY)
- [ ] Create AuditLogResource in Filament
- [ ] Add filters (user, event type, date range, model type)
- [ ] Add export to CSV/PDF
- [ ] Restrict access to admin role only

**See:** [Implementation Guide](./implementation-guide.md)

### Phase 2: Customer Activity Page
- [ ] Create `/profile/activity` page
- [ ] Show customer's own audit logs
- [ ] Use customer-friendly language
- [ ] Hide technical details (IP, user agent)

### Phase 3: Retention & Cleanup
- [ ] Create audit log cleanup command
- [ ] Schedule automatic cleanup
- [ ] Add configuration for retention period
- [ ] Add "flagged for investigation" feature

### Phase 4: Advanced Features
- [ ] Email alerts for suspicious activity (e.g., 5+ failed logins)
- [ ] Dashboard widget: "Recent Activity"
- [ ] Advanced analytics: "Who accessed the most customer data this week?"
- [ ] Integration with SIEM (Security Information and Event Management) tools

---

## Summary for Non-Technical Stakeholders

**What is Audit Logging?**
A permanent record of who did what in the application, like a security camera for your database.

**Why do we need it?**
GDPR compliance, security monitoring, customer support, breach investigation.

**What's missing?**
No admin panel to view the logs - they exist in database but invisible.

**What should we do?**
Create a Filament admin resource so admins can view audit logs (estimated 2-3 hours development).

**Who should access it?**
Admins only (for security and compliance reasons).

**When should we build it?**
Before GDPR audits or security incidents (i.e., now).

---

## Questions?

**Q: Does audit logging slow down the application?**
A: Minimal impact. Logs are written asynchronously (after response is sent). Negligible performance impact.

**Q: Can audit logs be tampered with?**
A: No. The table has no `updated_at` column - logs are immutable. Only admins can delete (with that action itself being logged).

**Q: What if an admin deletes audit logs?**
A: Option 1: Make delete impossible (recommended). Option 2: Log deletions with additional backup.

**Q: Can customers see audit logs?**
A: Not the raw logs. Create a separate "My Activity" page with simplified view.

**Q: How much storage do audit logs use?**
A: Minimal. Text-based, compressed JSON. 100,000 logs ≈ 50-100 MB. Enable cleanup after 1 year.

**Q: What if we get audited tomorrow and don't have a UI?**
A: Manual database queries work but are slow and error-prone. Highly recommend building the admin panel ASAP.

---

## Related Documentation

- [GDPR Compliance Overview](../gdpr-compliance/README.md)
- [Security Best Practices](../../security/README.md)
- [Filament Resource Implementation Guide](./implementation-guide.md)
- [Data Retention Policies](../gdpr-compliance/data-retention.md)
