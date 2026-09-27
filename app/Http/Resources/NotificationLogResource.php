<?php

namespace App\Http\Resources;

use App\Models\NotificationLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NotificationLog */
class NotificationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? ['id' => $this->customer->id, 'name' => $this->customer->name] : null),
            'channel' => $this->channel->value,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status->value,
            'error' => $this->error,
            'related_type' => $this->related_type,
            'related_id' => $this->related_id,
            'sent_at' => $this->sent_at,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }
}
