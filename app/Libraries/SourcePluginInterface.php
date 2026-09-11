<?php

namespace App\Libraries;

interface SourcePluginInterface
{
    public function zrodlo(): string;

    public function pobierz(): void;

    /**
     * @return array{ok: bool, msg: string}
     */
    public function health(): array;
}
