<?php

/**
 * Copyright 2018 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace Google\Cloud\Samples\Dlp;

use Exception;
# [START dlp_deidentify_date_shift]
use Google\Cloud\Dlp\V2\ContentItem;
use Google\Cloud\Dlp\V2\CryptoKey;
use Google\Cloud\Dlp\V2\DateShiftConfig;
use Google\Cloud\Dlp\V2\DeidentifyConfig;
use Google\Cloud\Dlp\V2\DlpServiceClient;
use Google\Cloud\Dlp\V2\FieldId;
use Google\Cloud\Dlp\V2\FieldTransformation;
use Google\Cloud\Dlp\V2\KmsWrappedCryptoKey;
use Google\Cloud\Dlp\V2\PrimitiveTransformation;
use Google\Cloud\Dlp\V2\RecordTransformations;
use Google\Cloud\Dlp\V2\Table;
use Google\Cloud\Dlp\V2\Table_Row;
use Google\Cloud\Dlp\V2\Value;
use Google\Type\Date;
use DateTime;

/**
 * Deidentify dates in a CSV file by pseudorandomly shifting them.
 *
 * @param string $callingProject The GCP Project ID to run the API call under
 * @param string $inputCsvFile The path to the CSV file to deidentify
 * @param string $outputCsvFile The path to save the date-shifted CSV file to
 * @param array $dateFieldNames The list of (date) fields in the CSV file to date shift
 * @param string $lowerBoundDays The maximum number of days to shift a date backward
 * @param string $upperBoundDays The maximum number of days to shift a date forward
 * @param string contextFieldName (Optional) The column to determine date shift amount based on
 *        If this is not specified, a random shift amount will be used for every row.
 *        If this is specified, then 'wrappedKey' and 'keyName' must also be set
 * @param string keyName (Optional) The encrypted ('wrapped') AES-256 key to use when shifting dates
 *        If this is specified, then 'wrappedKey' and 'contextFieldName' must also be set
 * @param string wrappedKey (Optional) The name of the Cloud KMS key used to encrypt ('wrap') the AES-256 key
 *        If this is specified, then 'keyName' and 'contextFieldName' must also be set
 */
function deidentify_dates(
    $callingProjectId,
    $inputCsvFile,
    $outputCsvFile,
    $dateFieldNames,
    $lowerBoundDays,
    $upperBoundDays,
    $contextFieldName = '',
    $keyName = '',
    $wrappedKey = ''
) {
    // Instantiate a client.
    $dlp = new DlpServiceClient();

    // Read a CSV file
    $csvLines = file($inputCsvFile, FILE_IGNORE_NEW_LINES);
    $csvHeaders = explode(',', $csvLines[0]);
    $csvRows = array_slice($csvLines, 1);

    // Convert CSV file into protobuf objects
    $tableHeaders = array_map(function ($csvHeader) {
        return (new FieldId)->setName($csvHeader);
    }, $csvHeaders);

    $tableRows = array_map(function ($csvRow) {
        $rowValues = array_map(function ($csvValue) {
            if ($csvDate = DateTime::createFromFormat('m/d/Y', $csvValue)) {
                $date = (new Date())
                    ->setYear((int) $csvDate->format('Y'))
                    ->setMonth((int) $csvDate->format('m'))
                    ->setDay((int) $csvDate->format('d'));
                return (new Value())
                    ->setDateValue($date);
            } else {
                return (new Value())
                    ->setStringValue($csvValue);
            }
        }, explode(',', $csvRow));

        return (new Table_Row())
            ->setValues($rowValues);
    }, $csvRows);

    // Convert date fields into protobuf objects
    $dateFields = array_map(function ($dateFieldName) {
        return (new FieldId())->setName($dateFieldName);
    }, $dateFieldNames);

    // Construct the table object
    $table = (new Table())
        ->setHeaders($tableHeaders)
        ->setRows($tableRows);

    $item = (new ContentItem())
        ->setTable($table);

    // Construct dateShiftConfig
    $dateShiftConfig = (new DateShiftConfig())
        ->setLowerBoundDays($lowerBoundDays)
        ->setUpperBoundDays($upperBoundDays);

    if ($contextFieldName && $keyName && $wrappedKey) {
        $contextField = (new FieldId())
            ->setName($contextFieldName);

        // Create the wrapped crypto key configuration object
        $kmsWrappedCryptoKey = (new KmsWrappedCryptoKey())
            ->setWrappedKey(base64_decode($wrappedKey))
            ->setCryptoKeyName($keyName);

        $cryptoKey = (new CryptoKey())
            ->setKmsWrapped($kmsWrappedCryptoKey);

        $dateShiftConfig
            ->setContext($contextField)
            ->setCryptoKey($cryptoKey);
    } elseif ($contextFieldName || $keyName || $wrappedKey) {
        throw new Exception('You must set either ALL or NONE of {$contextFieldName, $keyName, $wrappedKey}!');
    }

    // Create the information transform configuration objects
    $primitiveTransformation = (new PrimitiveTransformation())
        ->setDateShiftConfig($dateShiftConfig);

    $fieldTransformation = (new FieldTransformation())
        ->setPrimitiveTransformation($primitiveTransformation)
        ->setFields($dateFields);

    $recordTransformations = (new RecordTransformations())
        ->setFieldTransformations([$fieldTransformation]);

    // Create the deidentification configuration object
    $deidentifyConfig = (new DeidentifyConfig())
        ->setRecordTransformations($recordTransformations);

    $parent = $dlp->projectName($callingProjectId);

    // Run request
    $response = $dlp->deidentifyContent($parent, [
        'deidentifyConfig' => $deidentifyConfig,
        'item' => $item
    ]);

    // Check for errors
    foreach ($response->getOverview()->getTransformationSummaries() as $summary) {
        foreach ($summary->getResults() as $result) {
            if ($details = $result->getDetails()) {
                printf('Error: %s' . PHP_EOL, $details);
                return;
            }
        }
    }

    // Save the results to a file
    $csvRef = fopen($outputCsvFile, 'w');
    fputcsv($csvRef, $csvHeaders);
    foreach ($response->getItem()->getTable()->getRows() as $tableRow) {
        $values = array_map(function ($tableValue) {
            if ($tableValue->getStringValue()) {
                return $tableValue->getStringValue();
            }
            $protoDate = $tableValue->getDateValue();
            $date = mktime(0, 0, 0, $protoDate->getMonth(), $protoDate->getDay(), $protoDate->getYear());
            return strftime('%D', $date);
        }, iterator_to_array($tableRow->getValues()));
        fputcsv($csvRef, $values);
    };
    fclose($csvRef);
    printf('Deidentified dates written to %s' . PHP_EOL, $outputCsvFile);
}
# [END dlp_deidentify_date_shift]