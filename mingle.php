<?php 

class ExportMingler {

    const dataDir = '/home/jlloyd/projects/ca/export-mingler/data/';

    function parseFile($fileName) {
        $keys = [];
        $parsed = [];

        if (($handle = fopen(self::dataDir . $fileName, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
                if (!$keys) {
                    $keys = $data;
                    continue;
                }
                $parsed[] = array_combine($keys, $data);
            }
            fclose($handle);
        }
        //foreach ($keys as $key) {
        //    echo $key . PHP_EOL;
        //}
        return($parsed);
    }

    function parseAdditionalAttributes($data) {
        $parsedAdditionalAttributes = [];
        $attributes = explode(',', $data);
        foreach ($attributes as $attributeData) {
            $exploded = explode('=', $attributeData);
            if (is_array($exploded) && count($exploded) == 2) {
                [$attributeCode, $value] = $exploded;
                $parsedAdditionalAttributes[$attributeCode] = $value;
            }
        }
        return $parsedAdditionalAttributes;
    }

    function getExportCsv($fileName) {
        $keys = [];
        $parsed = [];
        if (($handle = fopen(self::dataDir . $fileName, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
                if (!$keys) {
                    $keys = $data;
                    continue;
                }
                try {
                    if (count($keys) == count($data)) {
                        $parsedLine = array_combine($keys, $data);
                        $parsedAdditionalAttributes = $this->parseAdditionalAttributes($parsedLine['additional_attributes']);
                        $parsedAttributes = array_merge($parsedLine, $parsedAdditionalAttributes);
                        $parsed[$parsedAttributes['sku']] = $parsedAttributes;
                    } else {
                        echo 'Bad Line: ' . PHP_EOL;
                        print_r($data);
                    }
                } catch (\Error $e) {
                    print_r($data);
                }
            }
            fclose($handle);
        }
        return $parsed;
    }

    function writeCsv($data, $filename) {
        $csv = fopen(self::dataDir . $filename, 'w');
        $headRow = false;
        foreach ($data as $item) {
            if (!$headRow) {
                fputcsv($csv, array_keys($item), $delimiter = ",", $enclosure = '"', $escape_char = "\\");
                $headRow = true;
            }
            fputcsv($csv, array_values($item), $delimiter = ",", $enclosure = '"', $escape_char = "\\");
        }
        fclose($csv);
    }

    function getIsbnMap() {
        $isbnData = $this->parseFile('Product-Table 1.csv');
        $isbnMap = [];
        foreach ($isbnData as $line) {
            $isbnMap[strtolower($line['Title Id'])] = $line['ISBN'];
        }
        return $isbnMap;
    }

    function getExportDataByIsbn($exportData) {
        $byIsbn = [];
        foreach ($exportData as $line) {
            if (isset($line['isbn'])) {
                $byIsbn[$line['isbn']] = $line;
            }
        }
        return $byIsbn;
    }

    function getExportDataByTitle($exportData) {
        $titles = [];
        $duplicateTitles = [];
        foreach ($exportData as $line) {
            $title = strtolower($line['name']);
            if (in_array($title, $titles)) {
                $duplicateTitles[] = $title;
            }
            $titles[] = $title;
        }

        $byTitle = [];
        foreach ($exportData as $line) {
            $title = strtolower($line['name']);
            if (!in_array($title, $duplicateTitles)) {
                $byTitle[$title] = $line;
            }
        }
        return $byTitle;
    }

    function mingle() {
        $isbnMap = $this->getIsbnMap();
        echo 'ISBN Map: ' . count($isbnMap) . PHP_EOL;

        # Original Data
        $originalData = $this->parseFile('Title-Table 1.csv');
        $isbnNotFound = [];
        $titles = [];
        $duplicateTitles = [];
        foreach ($originalData as &$line) {
            $title = strtolower($line['Title']);
            if (in_array($title, $titles)) {
                $duplicateTitles[] = $title;
            }
            $titles[] = $title;
            $titleId = strtolower($line['Title Id']);
            if (array_key_exists($titleId, $isbnMap)) {
                $line['ISBN'] = $isbnMap[$titleId];
            } else {
                $line['ISBN'] = null;
                $isbnNotFound[] = $line['Title Id'];
            }
        }
        echo 'Duplicate Titles: ' . count($duplicateTitles) . PHP_EOL;
        print_r($duplicateTitles);
        $this->writeCsv($originalData, 'isbn-added.csv');

        echo 'ISBNs not found: ' . count($isbnNotFound) . PHP_EOL;
        print_r($isbnNotFound);

        $exportData = $this->getExportCsv('ca.export_catalog_product_20240724_152240.csv');
        //$exportData = $this->getExportCsv('abridged.csv');
        $exportDataByIsbn = $this->getExportDataByIsbn($exportData);
        $exportDataByTitle = $this->getExportDataByTitle($exportData);
        $keyMap = [
            'Author' => 'author',
            'Narrator' => 'narrator'
        ];
        $matches = [];
        $skusMatched = [];
        $isbnsMatched = [];
        $titlesMatched = [];
        foreach ($originalData as &$originalLine) {
            $isbn = isset($originalLine['ISBN']) ? strtolower($originalLine['ISBN']) : null;
            $title = isset($originalLine['Title']) ? strtolower($originalLine['Title']) : null;
            $added = [];
            $matchedOn = null;
            if ($isbn && array_key_exists($isbn, $exportDataByIsbn)) {
                $isbnsMatched[] = $isbn;
                $exportLine = $exportDataByIsbn[$isbn];
                $skusMatched[] = $exportLine['sku'];
                $matches[] = 'Matched on ISBN: "' . $originalLine['Title'] . '" to "' . $exportLine['name'] . '"';
                foreach ($keyMap as $originalKey => $exportKey) {
                    if (isset($exportLine[$exportKey])) {
                        if ($originalLine[$originalKey]) {
                            echo 'Ovewritting ' . $originalKey . ' for ' . $originalLine['Title Id'] . PHP_EOL;
                        }
                        //if (!$originalLine[$originalKey]) {
                            $originalLine[$originalKey] = $exportLine[$exportKey];
                            $matchedOn = "ISBN";
                            $added[] = $originalKey;
                        //}
                    }
                }
                //$categories = explode(',', $exportLine['categories']);
                //print_r($categories);
            } else if ($title && !in_array($title, $duplicateTitles) && array_key_exists($title, $exportDataByTitle)) {
                $titlesMatched[] = $title;
                $exportLine = $exportDataByTitle[$title];
                $skusMatched[] = $exportLine['sku'];
                $matches[] = 'Matched on Title: "' . $originalLine['Title'] . '" to "' . $exportLine['name'] . '"';
                foreach ($keyMap as $originalKey => $exportKey) {
                    if (isset($exportLine[$exportKey])) {
                        if ($originalLine[$originalKey]) {
                            echo 'Ovewritting ' . $originalKey . ' for ' . $originalLine['Title Id'] . PHP_EOL;
                        }
                        //if (!$originalLine[$originalKey]) {
                            $originalLine[$originalKey] = $exportLine[$exportKey];
                            $matchedOn = "Title";
                            $added[] = $originalKey;
                        //}
                    }
                }
            }
            $originalLine['Matched On'] = $matchedOn;
            $originalLine['Added'] = implode(', ', $added);
        }

        echo 'ISBNs matched: ' . count($isbnsMatched) . PHP_EOL;
        print_r($isbnsMatched);

        echo 'Titles matched: ' . count($titlesMatched) . PHP_EOL;
        print_r($titlesMatched);

        echo 'MATCHES: ' . count($matches) . PHP_EOL;
        $this->writeCsv($originalData, 'updated.csv');

        echo 'All Matches: ' . count($skusMatched) . PHP_EOL;
        //echo '"' . implode('", "', $skusMatched) . '"' . PHP_EOL;
    }
}

$exportParser = new ExportMingler();
$exportParser->mingle();