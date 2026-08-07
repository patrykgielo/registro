<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * email_events.organization_id and sms_events.organization_id existed since
 * 2026_03_08_000003 but were never written by any creation path — every row ever
 * created carries NULL. The application now copies the value from the owning
 * EmailSend/SmsSend at all five call sites; this repairs what already shipped.
 *
 * Why it matters now rather than before: the resources for these models were
 * super-admin-only, and a super-admin browsing them saw everything regardless.
 * Opening them to tenant admins puts them behind BelongsToOrganization's global
 * scope, and a NULL organization_id matches no tenant — so without this backfill
 * the entire delivery history goes invisible to everyone, the operator included.
 * Fail-closed rather than a leak, but it silently truncates the record of every
 * message sent before this release.
 *
 * A correlated subquery rather than UPDATE ... JOIN, which MySQL accepts and
 * SQLite (the test database) does not. Rows whose parent is itself NULL stay
 * NULL — queued sends resolve no tenant at creation, so that gap is real and is
 * fixed at the source, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('email_events')
            ->whereNull('organization_id')
            ->update([
                'organization_id' => DB::raw(
                    '(SELECT organization_id FROM email_sends WHERE email_sends.id = email_events.email_send_id)'
                ),
            ]);

        DB::table('sms_events')
            ->whereNull('organization_id')
            ->update([
                'organization_id' => DB::raw(
                    '(SELECT organization_id FROM sms_sends WHERE sms_sends.id = sms_events.sms_send_id)'
                ),
            ]);
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'Data repair, not a schema change: rolling back would have to re-blank organization_id '
            .'on rows that may since have been written correctly, and the pre-migration state is not recoverable.'
        );
    }
};
