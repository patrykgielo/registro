# Audit Logging - Practical Use Cases

## Quick Reference for Business Scenarios

---

## Scenario 1: Customer Disputes Data Change

**Customer says:** "I never changed my email address. Someone at your company did it without my permission!"

### Investigation Steps

1. **Navigate to Audit Logs:**
   - Go to `/admin/audit-logs`

2. **Filter by customer:**
   - Filter "Użytkownik" → Search for customer's email
   - Filter "Typ zdarzenia" → "Zaktualizowano"
   - Filter "Typ obiektu" → "Użytkownik"

3. **Find the change:**
   - Look for `email` field in "Zaktualizowano" events
   - Click "View" to see details

4. **Analyze the log:**
   ```
   ID: 12345
   Data i czas: 2025-12-20 14:30:45
   Użytkownik: admin@registro.com
   Zdarzenie: Zaktualizowano
   Typ obiektu: User #123
   Adres IP: 192.168.1.50
   URL: https://registro.local/admin/customers/123/edit

   Wartości przed zmianą:
   - email: oldcustomer@example.com

   Wartości po zmianie:
   - email: newcustomer@example.com
   ```

5. **Response to customer:**

   **If legitimate (admin changed on customer's request):**
   > "Our records show that our admin 'Jan Kowalski' (admin@registro.com) updated your email from oldcustomer@example.com to newcustomer@example.com on 2025-12-20 at 14:30. This was done in response to your phone call on the same day. We have a recording of your request. Is there an issue?"

   **If unauthorized:**
   > "You're right. Our records show unauthorized access by [user]. We have immediately revoked their access and are investigating. We sincerely apologize. Your email has been restored to oldcustomer@example.com."

---

## Scenario 2: Security Breach Investigation

**Alert:** Multiple failed login attempts detected.

### Investigation Steps

1. **Navigate to Audit Logs:**
   - Go to `/admin/audit-logs`

2. **Filter by event:**
   - Filter "Typ zdarzenia" → "Nieudane logowanie"
   - Filter "Data od" → Last 24 hours

3. **Analyze patterns:**
   - Same IP with multiple attempts? → Brute force attack
   - Different IPs with same email? → Credential stuffing attack
   - Failed attempts followed by successful login? → Possible account compromise

4. **Example log:**
   ```
   15 entries:

   ID: 12350 | 2025-12-20 03:00:15 | login_failed | IP: 185.220.101.50
   ID: 12351 | 2025-12-20 03:00:18 | login_failed | IP: 185.220.101.50
   ID: 12352 | 2025-12-20 03:00:21 | login_failed | IP: 185.220.101.50
   ...
   ID: 12365 | 2025-12-20 03:15:30 | login | IP: 185.220.101.50 ⚠️ SUCCESS!

   Metadata:
   - Target email: admin@registro.com
   - Guard: web
   ```

5. **Actions:**

   **If attack in progress:**
   - Block IP in firewall: `sudo ufw deny from 185.220.101.50`
   - Force logout all sessions: `php artisan session:clear`
   - Notify admin user: "Your account had suspicious login attempts"
   - Enable 2FA for admin accounts (future enhancement)

   **If account compromised:**
   - Reset admin password immediately
   - Check audit logs for unauthorized actions after login
   - Notify affected customers if data was accessed
   - Report to GDPR authorities if breach severity meets Art. 33 threshold

---

## Scenario 3: GDPR Data Subject Access Request

**Customer asks:** "Under GDPR Article 15, I request a list of all processing activities involving my personal data."

### Response Steps

1. **Navigate to Audit Logs:**
   - Go to `/admin/audit-logs`

2. **Filter by customer:**
   - Filter "Typ obiektu" → "Użytkownik"
   - Search "ID obiektu" → 123 (customer's user ID)

3. **Export logs:**
   - Click "Eksportuj do CSV"
   - Filter for last 12 months (standard retention)

4. **Review CSV:**
   ```csv
   ID,Data i czas,Użytkownik,Zdarzenie,Opis
   12345,2025-12-20 14:30,admin@registro.com,Zaktualizowano,Email zmieniony
   12300,2025-12-15 10:00,customer@example.com,Zalogowano,Logowanie użytkownika
   12250,2025-12-10 09:15,customer@example.com,Utworzono,Utworzono rezerwację #456
   12100,2025-12-01 16:00,admin@registro.com,Wyeksportowano,Eksport danych GDPR
   ```

5. **Prepare GDPR response:**
   > **Subject Access Request Response**
   >
   > Dear [Customer],
   >
   > In response to your request under GDPR Article 15, we provide the following information:
   >
   > **Personal Data Held:**
   > - Name: Jan Kowalski
   > - Email: customer@example.com
   > - Phone: +48 501 234 567
   > - Address: [...]
   >
   > **Processing Activities (Last 12 months):**
   > - 2025-12-20: Admin updated your email (with your consent via phone)
   > - 2025-12-15: You logged into your account
   > - 2025-12-10: You created appointment #456
   > - 2025-12-01: Admin exported your data (in response to your previous GDPR request)
   >
   > **Purpose of Processing:**
   > - Service delivery (appointment booking)
   > - Customer support
   > - Legal compliance (GDPR)
   >
   > **Who Has Access:**
   > - Admins: 2 users (admin@registro.com, staff@registro.com)
   > - You: Via customer portal
   >
   > **Retention Period:**
   > - Active account data: Until account deletion
   > - Audit logs: 12 months
   >
   > **Your Rights:**
   > - Right to rectification (update your data)
   > - Right to erasure ("right to be forgotten")
   > - Right to restrict processing
   > - Right to data portability
   > - Right to object
   >
   > Attached: Complete audit log CSV (12 months)

---

## Scenario 4: Employee Misconduct Investigation

**Complaint:** Customer reports that an employee accessed their data inappropriately.

### Investigation Steps

1. **Navigate to Audit Logs:**
   - Go to `/admin/audit-logs`

2. **Filter by employee:**
   - Filter "Użytkownik" → employee@registro.com
   - Filter "Typ obiektu" → "Użytkownik"
   - Filter "Data od" → Last 30 days

3. **Look for suspicious patterns:**
   - Accessing customers outside their service area
   - Accessing data of competitors/VIPs
   - Access at unusual times (late night)
   - Mass data exports

4. **Example finding:**
   ```
   ID: 12400 | 2025-12-20 23:30:15 | Zaktualizowano | User #789
   Użytkownik: employee@registro.com
   IP: 89.64.12.45 (personal home IP, not office)
   URL: /admin/customers/789/edit

   User #789: Competitor's CEO (VIP customer)

   Wartości przed zmianą: [none]
   Wartości po zmianie: [none]
   Metadata: { "viewed_only": true }
   ```

5. **Actions:**

   **If misconduct confirmed:**
   - Suspend employee access immediately
   - Document findings for HR investigation
   - Notify affected customer if required by GDPR Art. 34
   - Consider legal action if data was exfiltrated

   **If legitimate:**
   - Document business justification
   - Update access control policies
   - Consider restricting access to sensitive customers

---

## Scenario 5: Compliance Audit (GDPR Inspection)

**Scenario:** Data protection authority requests evidence of GDPR compliance.

### Preparation Steps

1. **Generate comprehensive report:**
   - Go to `/admin/audit-logs`
   - Filter "Data od" → Last 12 months
   - Click "Eksportuj do CSV"

2. **Prepare audit evidence:**

   **a) Consent Management:**
   ```
   Filter: Typ zdarzenia → "Udzielono zgody" OR "Wycofano zgodę"
   Export: consent-audit-2025.csv

   Shows:
   - When consents were granted
   - When consents were withdrawn
   - Who processed the consent
   - IP address of consent (proves customer gave it, not admin)
   ```

   **b) Data Deletion Compliance:**
   ```
   Filter: Typ zdarzenia → "Zanonimizowano"
   Export: deletion-audit-2025.csv

   Shows:
   - When accounts were anonymized
   - Who performed the anonymization
   - Proof of "right to be forgotten" compliance
   ```

   **c) Access Control:**
   ```
   Filter: Typ zdarzenia → "Zalogowano" OR "Wylogowano"
   Export: access-audit-2025.csv

   Shows:
   - Who accessed the system
   - Login times and IPs
   - Failed login attempts (security monitoring)
   ```

3. **Answer auditor questions:**

   **Q: "How do you ensure only authorized personnel access customer data?"**
   **A:** "We log every data access. Here's a report showing only admin/staff roles have accessed customer data. All access is tracked with IP and timestamp."

   **Q: "How do you respond to data deletion requests?"**
   **A:** "We anonymize accounts within 30 days. Here are audit logs showing 15 anonymization events in the last year."

   **Q: "How do you track consent?"**
   **A:** "Every consent grant/withdrawal is logged with timestamp and IP. Here are 234 consent events, all initiated by customers."

   **Q: "Have you had any data breaches?"**
   **A:** "No. We monitor failed login attempts. Here are audit logs showing no unusual activity."

---

## Scenario 6: Regulatory Reporting (GDPR Breach Notification)

**Scenario:** Database breach detected. Must notify GDPR authorities within 72 hours.

### Response Steps

1. **Assess breach scope:**
   - Go to `/admin/audit-logs`
   - Filter "Data od" → Time of breach
   - Filter "Typ obiektu" → All personal data models

2. **Identify affected data:**
   ```
   Filter: created_at >= "2025-12-20 02:00" (breach start time)
   Filter: user_id NOT IN (admin_ids) (exclude legitimate admin activity)

   Results:
   - 1,234 customer records accessed
   - 567 appointments viewed
   - 89 email addresses exported
   ```

3. **Prepare breach notification:**
   > **GDPR Breach Notification (Art. 33)**
   >
   > **To:** Data Protection Authority
   >
   > **Breach Details:**
   > - Date/Time: 2025-12-20 02:30 - 04:15
   > - Type: Unauthorized access to customer database
   > - Cause: Compromised admin credentials
   >
   > **Affected Data:**
   > - 1,234 customer records (names, emails, phone numbers)
   > - 567 appointment details (dates, services)
   > - NO financial data (not stored)
   > - NO passwords (hashed)
   >
   > **Evidence:**
   > - Audit logs show unauthorized access from IP 185.220.101.50
   > - Access pattern indicates automated scraping
   > - Attached: Complete audit log CSV
   >
   > **Actions Taken:**
   > - Blocked malicious IP immediately (02:45)
   > - Reset all admin passwords (03:00)
   > - Enabled 2FA for all admin accounts (03:30)
   > - Notified affected customers (within 72 hours)
   >
   > **Risk Assessment:**
   > - Medium risk (contact info exposed, no financial data)
   > - Customers advised to watch for phishing attempts
   >
   > **Preventive Measures:**
   > - Implemented IP whitelisting for admin panel
   > - Enabled real-time alerts for suspicious activity
   > - Mandatory 2FA for all admin/staff accounts

4. **Notify affected customers:**
   > Dear [Customer],
   >
   > We are writing to inform you of a data security incident that occurred on 2025-12-20.
   >
   > **What Happened:**
   > An unauthorized user gained access to our admin panel using compromised credentials.
   >
   > **What Data Was Affected:**
   > Your name, email, and phone number may have been accessed. NO financial data or passwords were exposed.
   >
   > **What We Did:**
   > - Immediately blocked the unauthorized access
   > - Reset all admin passwords
   > - Enabled two-factor authentication
   > - Reported to GDPR authorities
   >
   > **What You Should Do:**
   > - Be cautious of phishing emails or calls
   > - Do not click links from unknown senders
   > - Contact us if you notice suspicious activity
   >
   > **Your Rights:**
   > - You may request a copy of your data (GDPR Art. 15)
   > - You may request deletion of your account (GDPR Art. 17)
   >
   > We sincerely apologize for this incident.

---

## Scenario 7: Customer Claims Unauthorized Appointment Cancellation

**Customer says:** "Someone cancelled my appointment and I didn't do it!"

### Investigation Steps

1. **Navigate to Audit Logs:**
   - Go to `/admin/audit-logs`
   - Filter "Typ obiektu" → "Appointment"
   - Search "ID obiektu" → 456 (appointment ID)

2. **Find cancellation event:**
   ```
   ID: 12500
   Data i czas: 2025-12-20 10:15:30
   Użytkownik: customer@example.com (Customer's own account)
   Zdarzenie: Zaktualizowano
   Typ obiektu: Appointment #456
   Adres IP: 89.64.12.45
   URL: /appointments/456/cancel

   Wartości przed zmianą:
   - status: confirmed

   Wartości po zmianie:
   - status: cancelled
   ```

3. **Response to customer:**

   **If customer cancelled themselves:**
   > "Our records show you cancelled the appointment from your account on 2025-12-20 at 10:15 from IP 89.64.12.45. Did someone else have access to your account?"

   **If admin cancelled:**
   > "Our admin 'Jan Kowalski' cancelled your appointment on 2025-12-20 at 10:15. This was due to [reason]. We apologize for not notifying you. Your appointment has been reinstated."

   **If suspicious:**
   > "Our records show cancellation from an unfamiliar IP address. We suspect unauthorized access. We've reset your password and reinstated your appointment. Please check your account security."

---

## Quick Reference: Common Filters

### Find All Changes by a Specific User
```
Filter: Użytkownik → admin@registro.com
Filter: Typ zdarzenia → Zaktualizowano
```

### Find All Failed Logins (Security Monitoring)
```
Filter: Typ zdarzenia → Nieudane logowanie
Filter: Data od → Last 24 hours
```

### Find All Data Exports (Compliance Tracking)
```
Filter: Typ zdarzenia → Wyeksportowano
```

### Find All Consent Changes
```
Filter: Typ zdarzenia → Udzielono zgody OR Wycofano zgodę
```

### Find All Changes to a Specific Customer
```
Filter: Typ obiektu → Użytkownik
Search: ID obiektu → 123 (customer ID)
```

### Find Activity During Specific Time
```
Filter: Data od → 2025-12-20
Filter: Data do → 2025-12-20
```

### Find Changes Made from Suspicious IP
```
Search: Adres IP → 185.220.101.50
```

---

## Summary

Audit logging is essential for:
- **Customer support** - Resolving disputes about data changes
- **Security** - Detecting and investigating breaches
- **Compliance** - GDPR subject access requests and audits
- **Legal** - Evidence in misconduct investigations
- **Accountability** - Proving proper data handling

**Key takeaway:** Audit logs provide the "who, what, when, where" evidence needed to:
- Answer customer questions confidently
- Respond to GDPR requests quickly
- Investigate security incidents thoroughly
- Prove compliance to authorities
- Hold employees accountable

**Next step:** Create the Filament admin panel to make this data accessible (see [Implementation Guide](./implementation-guide.md)).
