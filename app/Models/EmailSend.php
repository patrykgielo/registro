<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Email Send Model
 *
 * Records of actual email sends with delivery status tracking.
 *
 * @property int $id
 * @property string $template_key FK to email_templates.key
 * @property string $language Language code: 'pl', 'en'
 * @property string $recipient_email Recipient email address
 * @property string $subject Rendered email subject
 * @property string $body_html Rendered HTML body
 * @property string|null $body_text Rendered plain text body
 * @property string $status Email delivery status: pending, sent, failed, bounced
 * @property \Illuminate\Support\Carbon|null $sent_at When email was sent
 * @property array|null $metadata User ID, appointment ID, etc.
 * @property string $message_key Idempotency key
 * @property string|null $error_message Error details if failed
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class EmailSend extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'template_key',
        'language',
        'recipient_email',
        'subject',
        'body_html',
        'body_text',
        'status',
        'sent_at',
        'metadata',
        'message_key',
        'error_message',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the email template associated with this send.
     */
    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'template_key', 'key');
    }

    /**
     * Get all events for this email send.
     */
    public function emailEvents(): HasMany
    {
        return $this->hasMany(EmailEvent::class);
    }

    /**
     * Scope a query to filter by status.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to filter by recipient email.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecipient($query, string $email)
    {
        return $query->where('recipient_email', $email);
    }

    /**
     * Scope a query to only include emails sent in the last 7 days.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query)
    {
        return $query->where('sent_at', '>=', now()->subDays(7));
    }

    /**
     * Mark the email as successfully sent.
     */
    public function markAsSent(): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    /**
     * Mark the email as failed with error message.
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);
    }

    /**
     * Mark the email as bounced.
     */
    public function markAsBounced(): void
    {
        $this->update([
            'status' => 'bounced',
        ]);
    }

    /**
     * Check if the email was sent successfully.
     */
    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    /**
     * Check if the email failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if the email bounced.
     */
    public function isBounced(): bool
    {
        return $this->status === 'bounced';
    }

    /**
     * Check if the email is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
