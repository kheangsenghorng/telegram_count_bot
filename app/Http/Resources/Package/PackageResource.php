<?php

namespace App\Http\Resources\Package;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'packagesID' => $this->packagesID,
            'name' => $this->name,
            'price' => $this->price,
            'billing_cycle' => $this->billing_cycle,
            'payment_limit' => $this->payment_limit,
            'group_limit' => $this->group_limit,
            'status' => $this->status,

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}