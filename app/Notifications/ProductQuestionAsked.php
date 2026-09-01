<?php

namespace App\Notifications;

use App\Models\ProductQuestion;
use Illuminate\Support\Str;

/**
 * A shopper has asked something.
 *
 * It is not on the product page yet — questions are held for moderation — so
 * nobody finds out about it unless they are told.
 */
class ProductQuestionAsked extends ShopNotification
{
    public function __construct(public readonly ProductQuestion $question) {}

    public function payload(object $notifiable): array
    {
        return [
            'kind' => 'question.asked',
            'title' => 'Question about '.($this->question->product?->name ?? 'a product'),
            'body' => Str::limit($this->question->question, 90),
            'url' => '/admin/questions',
            'icon' => 'question',
        ];
    }
}
