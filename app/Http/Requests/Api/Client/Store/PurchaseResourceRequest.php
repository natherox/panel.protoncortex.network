<?php

namespace Pterodactyl\Http\Requests\Api\Client\Store;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class PurchaseResourceRequest extends ClientApiRequest
{
    /**
     * Rules to validate this request against.
     */
    public function rules(): array
    {
        return [
            'resource' => 'required|string|in:slot,cpu,memory,disk,port,backup,database',
        ];
    }
}