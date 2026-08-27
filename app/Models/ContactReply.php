<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer in a thread.
 *
 * The author's name is copied rather than read through the relation: a reply
 * sent two years ago was signed by whoever sent it, and that stays true after
 * they leave and their account is gone.
 */
class ContactReply extends Model
{
    protected $fillable = ['contact_message_id', 'user_id', 'author_name', 'body', 'emailed'];

    protected function casts(): array
    {
        return [
            'emailed' => 'boolean',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ContactMessage::class, 'contact_message_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
