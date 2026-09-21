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

    /**
     * Bezpieczny opis skąd i jak plugin bierze dane (bez haseł i tokenów).
     *
     * @return array{
     *   skrot: string,
     *   tryb_aktywny: string,
     *   tryb_etykieta: string,
     *   pozycje: list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}>
     * }
     */
    public function opisPolaczenia(): array;
}
