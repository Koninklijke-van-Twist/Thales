<?php

/**
 * Lichte xlsx/xlsm-lezer (zip + XML), alleen gecachte celwaarden, geen formules of opmaak.
 * Leest per tabblad tot een maximum aantal rijen zodat grote rapporten weinig geheugen kosten.
 */
final class ThalesXlsx
{
    private ZipArchive $zip;
    /** @var array<string, string> tabbladnaam => pad in zip */
    private array $sheets = [];
    /** @var list<string>|null */
    private ?array $strings = null;

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive();
        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Geen geldig xlsx/xlsm-bestand');
        }
        $workbook = $this->zip->getFromName('xl/workbook.xml');
        $rels = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            throw new RuntimeException('workbook.xml ontbreekt');
        }
        $targets = [];
        $relXml = simplexml_load_string($rels);
        foreach ($relXml->Relationship ?? [] as $rel) {
            $target = ltrim((string) $rel['Target'], '/');
            $targets[(string) $rel['Id']] = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        }
        $wb = simplexml_load_string($workbook);
        foreach ($wb->sheets->sheet ?? [] as $sheet) {
            $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if (isset($targets[$rid])) {
                $this->sheets[(string) $sheet['name']] = $targets[$rid];
            }
        }
    }

    public function __destruct()
    {
        @$this->zip->close();
    }

    /** @return list<string> tabbladnamen in werkboekvolgorde */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    /**
     * @return array<int, array<string, string>> rij => [kolomletter => waarde], alleen niet-lege cellen
     */
    public function rows(string $sheet, int $maxRow = 200): array
    {
        $path = $this->sheets[$sheet] ?? null;
        $reader = $path !== null ? $this->reader($path) : null;
        if ($reader === null) {
            return [];
        }
        // Geen zip://-wrapper en geen expand()/DOM: die gaven segfaults onder mod_php.
        $rows = [];
        $col = null;
        $row = 0;
        $type = '';
        $value = '';
        $inText = false;
        while ($reader->read()) {
            $node = $reader->nodeType;
            if ($node === XMLReader::ELEMENT) {
                $name = $reader->localName;
                if ($name === 'c') {
                    $col = null;
                    if (!preg_match('/^([A-Z]+)(\d+)$/', (string) $reader->getAttribute('r'), $m)) {
                        continue;
                    }
                    $row = (int) $m[2];
                    if ($row > $maxRow) {
                        break;
                    }
                    $type = (string) $reader->getAttribute('t');
                    $value = '';
                    $col = $reader->isEmptyElement ? null : $m[1];
                } elseif ($col !== null && $name === ($type === 'inlineStr' ? 't' : 'v')) {
                    if ($name === 'v') {
                        $value = '';
                    }
                    $inText = !$reader->isEmptyElement;
                }
            } elseif ($inText && ($node === XMLReader::TEXT || $node === XMLReader::CDATA || $node === XMLReader::SIGNIFICANT_WHITESPACE)) {
                $value .= $reader->value;
            } elseif ($node === XMLReader::END_ELEMENT) {
                if ($reader->localName !== 'c') {
                    $inText = false;
                    continue;
                }
                if ($col !== null) {
                    if ($type === 's' && $value !== '') {
                        $value = $this->sharedStrings()[(int) $value] ?? '';
                    }
                    if (trim($value) !== '') {
                        $rows[$row][$col] = $value;
                    }
                }
                $col = null;
            }
        }
        $reader->close();
        return $rows;
    }

    /** XML uit de zip in het geheugen lezen (getFromName) en XMLReader daarop zetten. */
    private function reader(string $path): ?XMLReader
    {
        $xml = $this->zip->getFromName($path);
        if (!is_string($xml) || $xml === '') {
            return null;
        }
        $reader = new XMLReader();
        return $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT) ? $reader : null;
    }

    /** @return list<string> */
    private function sharedStrings(): array
    {
        if ($this->strings !== null) {
            return $this->strings;
        }
        $this->strings = [];
        $reader = $this->reader('xl/sharedStrings.xml');
        if ($reader === null) {
            return $this->strings;
        }
        $text = null;
        $inText = false;
        $phonetic = 0;
        while ($reader->read()) {
            $node = $reader->nodeType;
            $name = $reader->localName;
            if ($node === XMLReader::ELEMENT) {
                if ($name === 'si') {
                    $text = $reader->isEmptyElement ? null : '';
                    if ($text === null) {
                        $this->strings[] = '';
                    }
                } elseif ($name === 'rPh' && !$reader->isEmptyElement) {
                    $phonetic++; // fonetische hulptekst overslaan
                } elseif ($name === 't' && $text !== null && $phonetic === 0) {
                    $inText = !$reader->isEmptyElement;
                }
            } elseif ($inText && ($node === XMLReader::TEXT || $node === XMLReader::CDATA || $node === XMLReader::SIGNIFICANT_WHITESPACE)) {
                $text .= $reader->value;
            } elseif ($node === XMLReader::END_ELEMENT) {
                if ($name === 't') {
                    $inText = false;
                } elseif ($name === 'rPh') {
                    $phonetic = max(0, $phonetic - 1);
                } elseif ($name === 'si' && $text !== null) {
                    $this->strings[] = $text;
                    $text = null;
                }
            }
        }
        $reader->close();
        return $this->strings;
    }
}
