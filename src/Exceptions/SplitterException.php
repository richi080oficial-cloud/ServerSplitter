<?php

namespace Pterodactyl\Extensions\ServerSplitter\Exceptions;

use Exception;

/**
 * Error de negocio de ServerSplitter. Siempre contiene un mensaje pensado
 * para mostrarse directamente al usuario final.
 */
class SplitterException extends Exception
{
    protected int $status;

    public function __construct(string $message, int $status = 422)
    {
        parent::__construct($message);

        $this->status = $status;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}