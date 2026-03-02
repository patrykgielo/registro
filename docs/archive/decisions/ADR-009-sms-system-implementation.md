# ADR-009: SMS Notification System Implementation

**Date:** 2025-11-12
**Status:** Accepted
**Context:** Booking System, Email System
**Authors:** Development Team

---

## Context and Problem Statement

The Paradocks application requires an SMS notification system to complement the existing email notification system. SMS provides:
- **Higher open rates** (~98% vs ~20% for email)
- **Faster delivery** (seconds vs minutes)
- **Better reach** (no spam folders, works without internet)
- **Critical reminders** (24h and 2h before appointments)

**Key Requirements:**
1. Send SMS for appointment lifecycle events (created, confirmed, rescheduled, cancelled)
2. Send automated reminders (24h and 2h before appointment)
3. Support multi-language (Polish + English)
4. Track delivery status and costs
5. Prevent duplicate SMS (idempotency)
6. Allow users to opt-out (suppression list)
7. Admin panel for viewing SMS history and templates
8. Test mode for development

---

## Decision Drivers

### Technical Drivers
- **Existing Email System Architecture** - Proven pattern with templates, events, services
- **Laravel Ecosystem** - Use Laravel's queue, events, Blade rendering
- **Filament Admin Panel** - Consistent UI for managing SMS (like email resources)
- **GDPR Compliance** - Phone number privacy, opt-out mechanism

### Business Drivers
- **Cost Efficiency** - Choose affordable SMS provider (~0.10 PLN per SMS)
- **Reliability** - High delivery rate (>95%) for Polish market
- **Scalability** - Handle 100-500 SMS per day initially, 1000+ in future
- **Time to Market** - Implement quickly (1-2 weeks)

### Provider Selection Criteria
- **Poland Focus** - Competitive pricing for Polish numbers
- **Webhook Support** - Delivery status callbacks
- **HTTP API** - RESTful JSON API (easy integration)
- **Test Mode** - Sandbox for development
- **Dashboard** - Web UI for monitoring and debugging

---

## Considered Options

### Option 1: SMSAPI.pl (SELECTED)
**Provider:** https://www.smsapi.pl

**Pros:**
- ✅ **Polish company** - Excellent support for Polish market
- ✅ **Competitive pricing** - ~0.10 PLN per SMS (160 chars)
- ✅ **RESTful API** - Simple HTTP JSON API
- ✅ **Webhook support** - Delivery status callbacks
- ✅ **Test mode** - Sandbox for development (no costs)
- ✅ **Dashboard** - Comprehensive web UI for monitoring
- ✅ **Reliability** - 99.9% uptime SLA

**Cons:**
- ⚠️ **No webhook signatures** - Cannot verify webhook authenticity (mitigated with IP whitelist)
- ⚠️ **Manual token rotation** - Must regenerate tokens manually (no auto-rotation)

**Pricing (2025):**
- 1 SMS (160 chars): ~0.10 PLN
- 10,000 SMS: ~900 PLN (~5% discount)
- 50,000 SMS: ~4,500 PLN (~10% discount)

---

### Option 2: Twilio
**Provider:** https://www.twilio.com

**Pros:**
- ✅ **Global leader** - Used by Uber, Airbnb, etc.
- ✅ **Advanced features** - Two-way SMS, programmable voice, video
- ✅ **Webhook signatures** - HMAC verification for security
- ✅ **SDKs** - Official PHP SDK (easy integration)

**Cons:**
- ❌ **Expensive** - ~0.30 PLN per SMS (3× SMSAPI.pl)
- ❌ **Complex** - Overkill for simple SMS notifications
- ❌ **International focus** - Less optimized for Polish market

**Pricing (2025):**
- 1 SMS (Poland): ~$0.07 (~0.30 PLN)
- 10,000 SMS: ~$700 (~3,000 PLN)

---

### Option 3: Amazon SNS (Simple Notification Service)
**Provider:** https://aws.amazon.com/sns

**Pros:**
- ✅ **Cheap** - ~$0.00581 per SMS (~0.025 PLN)
- ✅ **Scalable** - Auto-scaling, no rate limits
- ✅ **AWS Integration** - Works with other AWS services

**Cons:**
- ❌ **Complex setup** - Requires AWS account, IAM roles, regions
- ❌ **Unreliable in Poland** - SMS delivery can be slow or fail
- ❌ **No test mode** - Must pay for testing
- ❌ **No dashboard** - Monitoring requires CloudWatch setup

---

### Option 4: Self-Hosted GSM Gateway
**Provider:** Own GSM modem + SIM card

**Pros:**
- ✅ **Full control** - No third-party dependencies
- ✅ **Cheap at scale** - Only SIM card costs (~20 PLN/month)

**Cons:**
- ❌ **Hardware required** - GSM modem ($100-200), server, power
- ❌ **Maintenance** - Must monitor modem, replace SIM cards
- ❌ **Single point of failure** - If modem fails, no SMS sent
- ❌ **Slow** - 1 SMS per 3-6 seconds (max ~600 SMS/hour)
- ❌ **No delivery tracking** - Cannot reliably track delivery status

---

## Decision Outcome

**Chosen Option: SMSAPI.pl**

**Rationale:**
1. **Best fit for Polish market** - Competitive pricing, reliable delivery
2. **Simple integration** - HTTP JSON API, easy to implement
3. **Webhook support** - Real-time delivery status updates
4. **Test mode** - Sandbox for safe development
5. **Cost-effective** - ~0.10 PLN per SMS (affordable for 100-500 SMS/day)
6. **Proven reliability** - Used by 10,000+ Polish businesses

**Rejected Options:**
- **Twilio:** Too expensive (3× cost)
- **AWS SNS:** Complex setup, unreliable in Poland
- **Self-hosted:** Too complex, single point of failure

---

## Architecture

### System Design

```
┌─────────────────────────────────────────────────────────────┐
│                    Application Layer                         │
│  (Controllers, Livewire, Jobs, Event Listeners)              │
└───────────────────┬─────────────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────────────┐
│                      SmsService                              │
│  • sendFromTemplate()                                        │
│  • Check suppression list                                    │
│  • Generate message_key (idempotency)                        │
│  • Render template (Blade)                                   │
└───────────────────┬─────────────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────────────┐
│                    SmsApiGateway                             │
│  • send() - HTTP POST to SMSAPI.pl                          │
│  • checkCredits()                                            │
│  • getMessageStatus()                                        │
└───────────────────┬─────────────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────────────┐
│                     SMSAPI.pl                                │
│  • Delivers SMS                                              │
│  • Sends webhook callbacks                                   │
└───────────────────┬─────────────────────────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────────────┐
│               SmsApiWebhookController                        │
│  • Updates SmsSend status                                    │
│  • Creates SmsEvent (audit trail)                            │
└─────────────────────────────────────────────────────────────┘
```

### Database Schema

**4 Tables:**

1. **sms_templates** - Message templates with placeholders
   - Fields: `key`, `language`, `message_body`, `variables`, `max_length`, `active`
   - 14 records: 7 types × 2 languages (PL, EN)

2. **sms_sends** - History of sent SMS
   - Fields: `phone_number`, `message_body`, `status`, `smsapi_message_id`, `message_key`, `cost`, `metadata`
   - Statuses: `pending`, `sent`, `failed`, `delivered`
   - Idempotent via `message_key` (MD5 hash)

3. **sms_events** - Audit trail of lifecycle events
   - Fields: `sms_send_id`, `event_type`, `smsapi_status`, `error_message`, `raw_response`
   - Event types: `sent`, `failed`, `delivered`, `undelivered`

4. **sms_suppressions** - Opt-out blacklist
   - Fields: `phone_number`, `reason`, `suppressed_at`
   - Reasons: `opt_out`, `invalid_number`, `manual`

### Key Design Patterns

**1. Template Pattern**
- Reusable message templates with `{{placeholders}}`
- Blade rendering: `{{customer_name}}` → "Jan Kowalski"
- Multi-language support (PL, EN)

**2. Idempotency**
- `message_key` = MD5(phone + template + language + appointment_id + timestamp)
- Prevents duplicate SMS if job retried or event triggered twice

**3. Event-Driven**
- Domain events trigger SMS sending (e.g., `AppointmentCreated`)
- Event listeners call `SmsService->sendFromTemplate()`
- Webhook events update delivery status

**4. Service Layer**
- `SmsService` - Business logic (templates, rendering, suppression)
- `SmsApiGateway` - HTTP client (talks to SMSAPI.pl)
- Clean separation of concerns

**5. Filament Resources**
- `SmsTemplateResource` - CRUD for templates + Test Send action
- `SmsSendResource` - View-only history with filters
- `SmsEventResource` - Audit trail of events
- `SmsSuppressionResource` - Blacklist management

---

## Consequences

### Positive

**1. Improved Customer Communication**
- 98% open rate (vs 20% for email)
- Faster delivery (seconds vs minutes)
- Better engagement (customers more likely to read SMS)

**2. Reduced No-Shows**
- Automated reminders (24h + 2h before appointment)
- Confirmation SMS increases accountability
- Follow-up SMS improves satisfaction

**3. Consistent with Email System**
- Same architecture (templates, services, events, Filament resources)
- Developers familiar with pattern
- Easy to maintain and extend

**4. Cost-Effective**
- ~0.10 PLN per SMS (affordable)
- Only pay for sent SMS (no monthly fees)
- Test mode for free development

**5. GDPR Compliant**
- Users can opt-out (suppression list)
- Phone numbers stored securely (encrypted at rest)
- Audit trail (SMS events) for compliance

### Negative

**1. Ongoing Costs**
- ~0.10 PLN per SMS
- Estimate: 500 SMS/day × 30 days × 0.10 PLN = ~1,500 PLN/month
- Must monitor SMSAPI.pl account balance

**2. SMS Character Limit**
- 160 characters (GSM-7) or 70 characters (Unicode for Polish)
- Must write concise messages
- Multi-part SMS cost 2-3× more

**3. No Webhook Signature**
- SMSAPI.pl doesn't support HMAC verification
- Mitigated with IP whitelist (allow only SMSAPI.pl IPs)
- Small security risk (spoofed webhooks)

**4. Dependency on Third-Party**
- If SMSAPI.pl down, SMS not sent
- Mitigated with retry logic (3 attempts)
- Can switch to Twilio if needed (same architecture)

**5. Opt-Out Management**
- Must handle opt-out requests (suppression list)
- Must provide opt-out link in SMS (future enhancement)
- GDPR requires respect for user preferences

### Neutral

**1. Synchronous Sending (for now)**
- SMS sent synchronously within event listeners
- Acceptable for low volume (100-500 SMS/day)
- Should be queued for production (>1000 SMS/day)
- **Action:** Create `SendSmsJob` and queue it

**2. No A/B Testing**
- Cannot test different message variations
- Future enhancement: SMS template variants

**3. No Two-Way SMS**
- Users cannot reply to SMS (sender name "Paradocks" is alphanumeric)
- Future enhancement: Use phone number as sender (allows replies)

---

## Implementation Timeline

**Week 1:**
- ✅ Create models (SmsTemplate, SmsSend, SmsEvent, SmsSuppression)
- ✅ Create migrations (4 tables)
- ✅ Create SmsService and SmsApiGateway
- ✅ Create SmsTemplateSeeder (14 templates)
- ✅ Configure SMSAPI.pl integration

**Week 2:**
- ✅ Create Filament resources (4 resources)
- ✅ Create webhook controller (delivery status updates)
- ✅ Add "Test SMS Connection" button in System Settings
- ✅ Write documentation (README, architecture, templates, integration)
- ✅ Create ADR-007 (this document)

**Future Enhancements (Backlog):**
- [ ] Queue SMS sending (SendSmsJob)
- [ ] SMS analytics dashboard (costs, delivery rates)
- [ ] Two-way SMS (allow replies)
- [ ] SMS template A/B testing
- [ ] Batch SMS sending (marketing campaigns)

---

## Alternatives Considered

### Alternative 1: Email Only (No SMS)
**Pros:**
- No additional costs
- Simpler system (one notification channel)

**Cons:**
- ❌ **Lower engagement** - 20% open rate vs 98% for SMS
- ❌ **Slower delivery** - Email can take minutes
- ❌ **Spam folders** - Email may not reach inbox
- ❌ **No-shows** - Less effective reminders

**Verdict:** Rejected. SMS significantly improves engagement and reduces no-shows.

---

### Alternative 2: Push Notifications (Mobile App)
**Pros:**
- Free (no per-message cost)
- Rich content (images, buttons)
- Can open app directly

**Cons:**
- ❌ **Requires mobile app** - Must build iOS + Android app (months of work)
- ❌ **Lower reach** - Users must install app and enable notifications
- ❌ **Not always delivered** - Push can be blocked or delayed

**Verdict:** Rejected. Too complex for MVP. SMS is universal (no app required).

---

### Alternative 3: WhatsApp Business API
**Pros:**
- Popular in Poland (~15M users)
- Rich content (images, buttons, templates)
- Two-way messaging

**Cons:**
- ❌ **Expensive** - ~0.40 PLN per message (4× SMS)
- ❌ **Complex setup** - Requires Facebook Business Manager verification
- ❌ **Approval required** - Message templates must be pre-approved by Meta
- ❌ **24-hour window** - Can only send templates after 24h of last user message

**Verdict:** Rejected. Too expensive and complex for transactional notifications. Good for future marketing campaigns.

---

## Monitoring and Success Metrics

### Key Metrics

**Delivery Rate:**
- **Target:** >95% delivery rate
- **Measure:** `(delivered / sent) × 100`
- **Monitor:** Filament SMS Events resource

**Cost per SMS:**
- **Target:** <0.12 PLN per SMS (including multi-part)
- **Measure:** `total_cost / total_sent`
- **Monitor:** SMSAPI.pl dashboard + Filament reports

**No-Show Reduction:**
- **Target:** Reduce no-shows by 30%
- **Measure:** Compare no-show rate before/after SMS reminders
- **Monitor:** Appointments dashboard

**User Satisfaction:**
- **Target:** >80% positive feedback on communication
- **Measure:** Post-service survey ("Did you receive SMS reminders?")
- **Monitor:** Follow-up feedback forms

### Monitoring Tools

1. **SMSAPI.pl Dashboard** - Account balance, delivery rates, API logs
2. **Filament Resources** - SMS history, events, suppression list
3. **Laravel Horizon** - Queue monitoring (future: SendSmsJob)
4. **Laravel Logs** - Error logging (`storage/logs/laravel.log`)

---

## Related ADRs

- **ADR-001:** Email System Architecture (similar pattern)
- **ADR-004:** Settings System (SMS settings storage)
- **ADR-006:** User Model Name Accessor (customer_name in templates)

---

## References

- **SMSAPI.pl Documentation:** https://www.smsapi.pl/docs
- **SMS Character Encoding:** https://www.twilio.com/docs/glossary/what-is-gsm-7-character-encoding
- **GDPR Compliance:** https://gdpr.eu/
- **Laravel Queue Documentation:** https://laravel.com/docs/queues
- **Filament Resources:** https://filamentphp.com/docs/resources

---

**Last Updated:** 2025-11-12
**Authors:** Development Team
**Reviewers:** Technical Lead, Product Owner
**Status:** Accepted and Implemented
