<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Illuminate\Database\Connectors\ConnectorInterface;
use PDOException;

class UnreachableDatabaseConnector implements ConnectorInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): never
    {
        throw new PDOException('A database free test must never open a connection.');
    }
}
