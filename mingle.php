<?php 

class ExportMingler {

    const dataDir = '/home/jlloyd/projects/ca/export-mingler/data/';

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

    function getExportCsv($fileName, $uniqueOriginalNames) {
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
                        $parsed[$parsedAttributes['name']] = $parsedAttributes;
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
        $duplicateNames = [];
        $names = [];
        foreach ($parsed as $line) {
            $name = strtolower($line['name']);
            if (in_array($name, $names)) {
                $duplicateNames[] = $name;
            }
            $names[] = $name;
            if ($name == "the redemption of sarah cain") {
                echo $name . PHP_EOL;
            }
        }
        echo "Duplicate Export Names: " . count($duplicateNames) . PHP_EOL;
        print_r($duplicateNames);
        print_r(array_intersect($duplicateNames, $uniqueOriginalNames));
        $data = [];
        foreach ($parsed as $line) {
            $name = strtolower($line['name']);
            if (in_array($name, $uniqueOriginalNames)) {
                if (!in_array($name, $duplicateNames)) {
                    $data[$name] = $line;
                }
            }
        }
        return $data;
    }

    function getOriginalCsv($fileName) {
        $keys = [];
        $data = [];
        if (($handle = fopen(self::dataDir . $fileName, "r")) !== FALSE) {
            while (($line = fgetcsv($handle, 0, ",")) !== FALSE) {
                if (!$keys) {
                    $keys = $line;
                    continue;
                }
                try {
                    if (count($keys) == count($line)) {
                        $data[] = array_combine($keys, $line);
                    } else {
                        echo 'Bad Line: ' . PHP_EOL;
                        print_r($data);
                    }
                } catch (\Error $e) {
                    print_r($data);
                }            }
            fclose($handle);
        }
        return $data;
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

    function parseExport($data, $storeOverrides, $configs) {
        $parsedData = [];
        foreach ($data as $row) {
            $parsedRow = [];
            foreach ($configs as $config) {
                $attributeCode = $config['attributeCode'];
                $meetsConditions = true;
                if (array_key_exists('conditions', $config)) {
                    foreach ($config['conditions'] as $conditionConfig) {
                        if ($conditionConfig['type'] == 'store-view-override') {
                            $sku = $row['sku'];
                            if (array_key_exists($conditionConfig['storeViewCode'], $storeOverrides)) {
                                $storeOverridesForStore = $storeOverrides[$conditionConfig['storeViewCode']];
                                if (array_key_exists($sku, $storeOverridesForStore)) {
                                    $storeOverridesForStoreAndSku =  $storeOverridesForStore[$sku];
                                    if ($storeOverridesForStoreAndSku[$conditionConfig['attributeCode']] == $conditionConfig['neq']) {
                                        $meetsConditions = false;
                                    }
                                }
                            }
                        }
                    }
                }
                    $value = "";
                    if ($meetsConditions) {
                        if (array_key_exists($attributeCode, $row)) {
                            if ($value = $row[$attributeCode]) {
                                $value = str_replace('[' . $attributeCode . ']', $value, $config['format']);
                            }
                        }
                    }
                    $parsedRow[$config['label']] = $value;
            }
            $parsedData[$parsedRow['sku']] = $parsedRow;
        }
        return $parsedData;
    }

    function mingle() {
        $originalData = $this->getOriginalCsv('original.data.csv');
        $uniqueOriginalNames = [];
        foreach ($originalData as $line) {
            $uniqueOriginalNames[] = strtolower($line['Title']);
        }
        $uniqueOriginalNames = array_unique($uniqueOriginalNames);
        echo 'Unique Titles: ' . count($uniqueOriginalNames) . PHP_EOL;
        $exportData = $this->getExportCsv('export.data.csv', $uniqueOriginalNames);
        echo 'Unique & Matched Export Data: ' . count($exportData) . PHP_EOL;
        $keyMap = [
            'Genre' => 'genre',
            'Author' => 'author',
            'Narrator' => 'narrator'
        ];
        $matches = [];
        foreach ($originalData as &$originalLine) {
            $name = strtolower($originalLine['Title']);
            $added = [];
            if ($name && in_array($name, $uniqueOriginalNames)) {
                if (isset($exportData[$name])) {
                    $exportLine = $exportData[$name];
                    $matches[] = 'Matched "' . $originalLine['Title'] . '" to "' . $exportLine['name'] . '"';
                    foreach ($keyMap as $originalKey => $exportKey) {
                        if (isset($exportLine[$exportKey])) {
                            if (!$originalLine[$originalKey]) {
                                $originalLine[$originalKey] = $exportLine[$exportKey];
                                $added[] = $originalKey;
                            }
                        }
                    }
                }
            }
            $originalLine['Added'] = implode(', ', $added);
        }
        echo 'MATCHES: ' . count($matches) . PHP_EOL;
        $this->writeCsv($originalData, 'updated.csv');
    }
}

$exportParser = new ExportMingler();
$exportParser->mingle();