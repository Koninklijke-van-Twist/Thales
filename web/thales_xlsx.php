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
        if ($path === null) {
            return [];
        }
        $stream = $this->zip->getStream($path);
        if ($stream === false) {
            return [];
        }
        fclose($stream);
        $reader = new XMLReader();
        if (!$reader->open('zip://' . $this->zip->filename . '#' . $path)) {
            return [];
        }
        $rows = [];
        $strings = null;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
                continue;
            }
            $ref = (string) $reader->getAttribute('r');
            if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                continue;
            }
            $row = (int) $m[2];
            if ($row > $maxRow) {
                break;
            }
            $type = (string) $reader->getAttribute('t');
            $node = $reader->expand();
            if (!$node instanceof DOMElement) {
                continue;
            }
            $value = '';
            if ($type === 'inlineStr') {
                $value = $node->textContent;
            } else {
                foreach ($node->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->localName === 'v') {
                        $value = $child->textContent;
                    }
                }
                if ($type === 's' && $value !== '') {
                    $strings ??= $this->sharedStrings();
                    $value = $strings[(int) $value] ?? '';
                }
            }
            if (trim($value) !== '') {
                $rows[$row][$m[1]] = $value;
            }
        }
        $reader->close();
        return $rows;
    }

    /** @return list<string> */
    private function sharedStrings(): array
    {
        if ($this->strings !== null) {
            return $this->strings;
        }
        $this->strings = [];
        $reader = new XMLReader();
        if ($this->zip->locateName('xl/sharedStrings.xml') === false
            || !$reader->open('zip://' . $this->zip->filename . '#xl/sharedStrings.xml')) {
            return $this->strings;
        }
        $ok = $reader->read();
        while ($ok) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                $ok = $reader->read();
                continue;
            }
            {
                $node = $reader->expand();
                $text = '';
                if ($node instanceof DOMElement) {
                    foreach ($node->getElementsByTagName('t') as $t) {
                        // fonetische hulptekst (rPh) overslaan
                        if ($t->parentNode instanceof DOMElement && $t->parentNode->localName === 'rPh') {
                            continue;
                        }
                        $text .= $t->textContent;
                    }
                }
                $this->strings[] = $text;
                $ok = $reader->next();
            }
        }
        $reader->close();
        return $this->strings;
    }
}
