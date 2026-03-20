<?php

declare(strict_types=1);

namespace App\Events\{{Models}};

use App\Models\{{Model}};
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class {{Model}}Created
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly {{Model}} ${{model}},
    ) {}
}
