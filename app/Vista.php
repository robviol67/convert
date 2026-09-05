<?php
declare(strict_types=1);

namespace Vblite\Convert;

/** Rendering delle viste. Nessun motore di template: PHP e' gia' un template. */
final class Vista
{
    /** @param array<string,mixed> $dati */
    public static function rendi(string $nome, array $dati = []): void
    {
        $dati['utente'] ??= Auth::utente();

        // La barra applicativa si stampa prima del corpo: queste due voci devono
        // essere gia' note qui, non impostate dentro la vista.
        $dati['paginaCorrente']    ??= (string) ($_GET['p'] ?? 'home');
        $dati['tipologiaCorrente'] ??= self::tipologiaDi($dati);

        extract($dati, EXTR_SKIP);
        $vistaCorpo = Config::radice() . "/views/{$nome}.php";

        require Config::radice() . '/views/layout.php';
    }

    /**
     * Nome della tipologia da mostrare nella barra, quando la schermata ne ha una.
     *
     * @param array<string,mixed> $dati
     */
    private static function tipologiaDi(array $dati): ?string
    {
        $chiave = $dati['job']['tipologia']
            ?? $dati['bozza']['tipologia']
            ?? $dati['manifest']['chiave']
            ?? null;

        if ($chiave === null) {
            return null;
        }
        $conversione = \Vblite\Convert\Conversioni\Registro::trova((string) $chiave);

        return $conversione?->manifest()['titolo'];
    }

    public static function e(?string $testo): string
    {
        return htmlspecialchars((string) $testo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Iniziali per l'avatar della barra applicativa. */
    public static function iniziali(string $nome): string
    {
        $pezzi = preg_split('~\s+~u', trim($nome)) ?: [];
        $sigla = '';
        foreach (array_slice($pezzi, 0, 2) as $pezzo) {
            $sigla .= mb_strtoupper(mb_substr($pezzo, 0, 1));
        }

        return $sigla !== '' ? $sigla : '?';
    }

    /** «1174» → «1.174» */
    public static function numero(int|float|null $n): string
    {
        return $n === null ? '—' : number_format((float) $n, 0, ',', '.');
    }

    public static function byte(int|null $b): string
    {
        if ($b === null) {
            return '—';
        }
        $unita = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $v = (float) $b;
        while ($v >= 1024 && $i < count($unita) - 1) {
            $v /= 1024;
            $i++;
        }

        return number_format($v, $v >= 10 || $i === 0 ? 0 : 1, ',', '.') . ' ' . $unita[$i];
    }

    /** «2026-09-04 14:12:00» → «12 min fa» / «4 set, 14:12» */
    public static function quando(?string $sql): string
    {
        if ($sql === null || $sql === '') {
            return '—';
        }
        $quando = strtotime($sql . ' UTC');
        if ($quando === false) {
            return $sql;
        }
        $delta = time() - $quando;
        if ($delta < 60) {
            return 'adesso';
        }
        if ($delta < 3600) {
            return floor($delta / 60) . ' min fa';
        }
        if ($delta < 86400) {
            return floor($delta / 3600) . ' ore fa';
        }
        if ($delta < 7 * 86400) {
            return floor($delta / 86400) . ' giorni fa';
        }

        $mesi = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];

        return date('j', $quando) . ' ' . $mesi[(int) date('n', $quando) - 1] . ' ' . date('Y', $quando);
    }

    /** Seriale Excel → «15/01/2025», per le anteprime a schermo. */
    public static function data(int|float|null $seriale): string
    {
        if ($seriale === null) {
            return '';
        }
        $timestamp = ((int) $seriale - 25569) * 86400;

        return gmdate('d/m/Y', $timestamp);
    }

    public static function valuta(int|float|null $v): string
    {
        return $v === null ? '' : number_format((float) $v, 2, ',', '.');
    }
}
