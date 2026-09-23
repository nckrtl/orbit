<?php

declare(strict_types=1);

namespace App\Domain\ProxyCli;

interface ProxyCliAccountControlClient
{
    public function setDisabled(string $wireguardIp, int $port, string $controlToken, string $account, bool $disabled): void;
}
